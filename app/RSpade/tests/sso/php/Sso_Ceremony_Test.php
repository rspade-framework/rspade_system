<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sso\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Sso\Sso_Identity_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Tests\Sso\Php\Fake_Sso_Provider;

/**
 * The ceremony, end to end, driven through a provider that never leaves the process.
 *
 * EVERY TEST HERE IS ALSO A TEST OF THE CUSTOM-PROVIDER SEAM. Fake_Sso_Provider is reachable
 * only through rsx.sso.custom, so if the seam breaks nothing in this file runs - which is a
 * louder signal than one test named after it.
 *
 * WHAT IS PINNED, and why each one is worth a test:
 *
 *  - THE STATE IS PARKED BEFORE THE REDIRECT AND FORGOTTEN ON THE WAY BACK. That is the
 *    entire CSRF story of an OAuth flow: a callback nobody started supplies a state nobody
 *    parked. Single use matters as much as matching - a state that survived its own callback
 *    would be replayable.
 *  - A RESPONSE FROM ONE PROVIDER CANNOT REDEEM ANOTHER'S CEREMONY. The parked value carries
 *    the provider key for exactly this.
 *  - AN UNLINKED IDENTITY AUTHENTICATES NOBODY. It is parked half-authenticated, the
 *    application is asked what it means, and a silent application FAILS CLOSED - because the
 *    only thing a framework could invent here is "create an account", which is the one
 *    decision it must never make for an application.
 *  - THE LOCAL SECOND FACTOR STILL RUNS. An identity provider proved who owns the Google
 *    account; it did not answer this application's second factor, and a user who deliberately
 *    enrolled one did not consent to it being skippable by signing in another way.
 *  - EVERY FAILURE IS COUNTED EXACTLY ONCE. Login_History::record_failure() already feeds
 *    Login_Throttle, so Rsx_Sso never calls the throttle itself; double counting would halve
 *    the real budget and would only be discovered by a user locked out early.
 *
 * TWO STORES, so two isolation strategies (the same shape as tests/two_factor): rows are
 * rolled back with the per-test transaction, while throttle and failure counters are redis
 * keys outside both. So every test uses a unique email and teardown clears what it created.
 */
class Sso_Ceremony_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    /** Raw failure-counter keys created by these tests, deleted in teardown. */
    private static array $counter_keys_used = [];

    /** Config keys these tests overwrite, restored in teardown. */
    private static array $config_backup = [];

    public static function setup()
    {
        parent::setup();

        self::__override('rsx.sso.custom', [
            'fake' => [
                'enabled' => true,
                'provider' => Fake_Sso_Provider::class,
                'label' => 'Fake Provider',
                'client_id' => 'FAKE-CLIENT',
                'client_secret' => 'FAKE-SECRET',
            ],
        ]);

        Login_Throttle::reset('CLI');
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();

        foreach (self::$config_backup as $key => $value) {
            config()->set($key, $value);
        }

        self::$config_backup = [];

        Login_Throttle::reset('CLI');
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();

        if (!empty(self::$counter_keys_used)) {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
            $redis->select(0);

            foreach (self::$counter_keys_used as $key) {
                $redis->del('cache:' . sha1($key));
            }

            $redis->close();
            self::$counter_keys_used = [];
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private static function __override(string $key, $value): void
    {
        if (!array_key_exists($key, self::$config_backup)) {
            self::$config_backup[$key] = config($key);
        }

        config()->set($key, $value);
    }

    private static function __fresh_email(string $suffix): string
    {
        $email = 'sso_' . $suffix . '_' . uniqid() . '@example.com';
        self::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        return $email;
    }

    private static function __make_login_user(string $email): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * A normalized identity as a provider would assert it.
     */
    private static function __identity(string $subject, ?string $email = null): array
    {
        return [
            'id' => $subject,
            'email' => $email,
            'name' => 'Fake Person',
            'avatar' => 'https://fake-provider.test/avatar.png',
        ];
    }

    /**
     * Run a callback for a ceremony that begin() has already parked.
     *
     * $state defaults to the one that was actually parked, so a test only names it when the
     * mismatch IS the subject.
     */
    private static function __callback(array $identity, ?string $state = null)
    {
        $parked = Session::get_value(Rsx_Sso::STATE_KEY);
        $state = $state ?? (string) ($parked['state'] ?? '');

        $url = '/_sso/fake/callback?' . http_build_query([
            'code' => 'FAKE-CODE',
            'state' => $state,
            Fake_Sso_Provider::IDENTITY_PARAM => json_encode($identity),
        ]);

        return Rsx_Sso::handle_callback('fake', Request::create($url, 'GET'));
    }

    /**
     * Back to a clean anonymous browser.
     *
     * Called at the top of EVERY test because setup() and teardown() are per-CLASS, not
     * per-test: a test that signs somebody in would otherwise hand the next one a session
     * that is already authenticated, and half of what is asserted here is "nobody is signed
     * in".
     */
    private static function __anonymous(): void
    {
        Event_Registry::_clear_test_handlers();
        Login_Throttle::reset('CLI');
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    private static function __success_rows(string $email): int
    {
        return DB::table('_login_history')
            ->where('email_attempted', $email)
            ->where('status', Login_History::STATUS_SUCCESS)
            ->count();
    }

    // -------------------------------------------------------------------------
    // Beginning a ceremony
    // -------------------------------------------------------------------------

    /**
     * begin() sends the browser to the provider carrying OUR state, and parks the same value
     * where only this browser can read it. Both halves matter: the URL without the parked
     * value is unverifiable, the parked value without the URL is unredeemable.
     */
    public static function test_begin_parks_the_state_and_carries_it_in_the_url()
    {
        static::__anonymous();

        $response = Rsx_Sso::begin('fake');

        $target = $response->getTargetUrl();

        static::__assert_true(
            str_starts_with($target, Fake_Sso_Provider::AUTHORIZE_URL . '?'),
            'the browser goes to the provider'
        );

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $parked = Session::get_value(Rsx_Sso::STATE_KEY);

        static::__assert_not_null($parked, 'the ceremony is parked');
        static::__assert_equals($parked['state'], $query['state'], 'and the URL carries the parked state');
        static::__assert_equals('fake', $parked['provider'], 'against the provider it was started for');
        static::__assert_equals(Rsx_Sso::INTENT_LOGIN, $parked['intent']);
        static::__assert_equals('FAKE-CLIENT', $query['client_id']);
        static::__assert_contains('/_sso/fake/callback', $query['redirect_uri']);
        static::__assert_equals('code', $query['response_type']);
    }

    /**
     * The state is 32 bytes of CSPRNG output and never repeats. A predictable state is a
     * forgeable callback.
     */
    public static function test_each_ceremony_mints_a_fresh_state()
    {
        static::__anonymous();

        Rsx_Sso::begin('fake');
        $first = Session::get_value(Rsx_Sso::STATE_KEY)['state'];

        Rsx_Sso::begin('fake');
        $second = Session::get_value(Rsx_Sso::STATE_KEY)['state'];

        static::__assert_not_equals($first, $second, 'two ceremonies never share a state');
        static::__assert_equals(64, strlen($first), '32 bytes, hex encoded');
    }

    // -------------------------------------------------------------------------
    // The state check
    // -------------------------------------------------------------------------

    /**
     * A callback carrying a state nobody parked is the end of it: nothing is exchanged,
     * nobody is signed in, and the attempt is recorded.
     */
    public static function test_a_mismatched_state_is_refused()
    {
        static::__anonymous();

        Rsx_Sso::begin('fake');

        $response = static::__callback(static::__identity('subject-1', 'a@example.com'), 'a-state-nobody-parked');

        static::__assert_equals('/login', $response->getTargetUrl(), 'back to the login page');
        static::__assert_false(Session::is_logged_in(), 'a forged callback authenticates nobody');
        static::__assert_null(Rsx_Sso::pending(), 'and parks nothing to be redeemed later');

        // The failure IS recorded, but no address is known on this path - the code was never
        // exchanged - so there is no per-email counter to read it back from. The
        // counted-exactly-once property is asserted where an address exists, below.
    }

    /**
     * A STATE IS SINGLE USE. It is forgotten on the way in, before anything is verified, so
     * a captured callback URL cannot be replayed even with the right state.
     */
    public static function test_a_state_cannot_be_replayed()
    {
        static::__anonymous();

        Rsx_Sso::begin('fake');
        $state = Session::get_value(Rsx_Sso::STATE_KEY)['state'];

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);

        static::__callback(static::__identity('subject-replay', 'replay@example.com'), $state);

        static::__assert_null(Session::get_value(Rsx_Sso::STATE_KEY), 'the ceremony is spent');

        Rsx_Sso::abandon_pending();

        static::__callback(static::__identity('subject-replay', 'replay@example.com'), $state);

        static::__assert_null(Rsx_Sso::pending(), 'the second use of the same state does nothing');
    }

    /**
     * A response from one provider must not redeem a ceremony started for another - which is
     * why the provider key is parked alongside the state.
     */
    public static function test_another_providers_response_cannot_redeem_this_ceremony()
    {
        static::__anonymous();

        Rsx_Sso::begin('fake');
        $state = Session::get_value(Rsx_Sso::STATE_KEY)['state'];

        // Same state, arriving at a different provider's callback. Restored explicitly:
        // setup()/teardown() are per-CLASS, so a second provider left switched on would
        // follow every test after this one.
        $restore = config('rsx.sso.custom');

        config()->set('rsx.sso.custom', array_merge($restore, [
            'fake_two' => [
                'enabled' => true,
                'provider' => Fake_Sso_Provider::class,
                'label' => 'Second Fake',
                'client_id' => 'FAKE-CLIENT-2',
                'client_secret' => 'FAKE-SECRET-2',
            ],
        ]));

        try {
            $url = '/_sso/fake_two/callback?' . http_build_query([
                'code' => 'FAKE-CODE',
                'state' => $state,
                Fake_Sso_Provider::IDENTITY_PARAM => json_encode(static::__identity('subject-x', 'x@example.com')),
            ]);

            Rsx_Sso::handle_callback('fake_two', Request::create($url, 'GET'));
        } finally {
            config()->set('rsx.sso.custom', $restore);
        }

        static::__assert_null(Rsx_Sso::pending(), 'nothing is redeemed');
        static::__assert_false(Session::is_logged_in());
    }

    /**
     * THE WINDOW IS A SECURITY WINDOW. An abandoned ceremony stops being redeemable, and
     * expiry is a working outcome - the user is told to try again, nothing throws at them.
     */
    public static function test_an_expired_ceremony_cannot_be_redeemed()
    {
        static::__anonymous();

        Rsx_Sso::begin('fake');
        $parked = Session::get_value(Rsx_Sso::STATE_KEY);

        // Re-park the identical value with an expiry in the past. get_value() filters on the
        // expiry itself, so this is exactly what an abandoned ceremony looks like.
        Session::put_value(Rsx_Sso::STATE_KEY, $parked, Rsx_Time::add(Rsx_Time::now_iso(), -60));

        static::__callback(static::__identity('subject-late', 'late@example.com'), $parked['state']);

        static::__assert_null(Rsx_Sso::pending(), 'a stale ceremony redeems nothing');
        static::__assert_false(Session::is_logged_in());
    }

    // -------------------------------------------------------------------------
    // The unlinked identity
    // -------------------------------------------------------------------------

    /**
     * An unknown provider account is parked and handed to the application. NOBODY IS SIGNED
     * IN while it is pending - the provider proved who owns that account, which is not yet a
     * statement about who may use this application.
     */
    public static function test_an_unlinked_identity_is_parked_and_authenticates_nobody()
    {
        static::__anonymous();

        $seen = null;

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [
            function ($identity) use (&$seen) {
                $seen = $identity;

                return '/finish-signup';
            },
        ]);

        Rsx_Sso::begin('fake');

        $response = static::__callback(static::__identity('subject-new', 'new@example.com'));

        static::__assert_equals('/finish-signup', $response->getTargetUrl(), 'the application says where to go');
        static::__assert_false(Session::is_logged_in(), 'and nothing is authenticated on the way');

        $pending = Rsx_Sso::pending();

        static::__assert_not_null($pending, 'the identity is redeemable');
        static::__assert_equals('fake', $pending['provider_key']);
        static::__assert_equals('subject-new', $pending['provider_user_key']);
        static::__assert_equals('new@example.com', $pending['email']);
        static::__assert_false($pending['email_verified'], 'a provider that asserts nothing is not trusted');
        static::__assert_equals('Fake Person', $pending['name']);

        static::__assert_equals($pending, $seen, 'the handler saw exactly what pending() returns');
    }

    /**
     * email_verified IS A CLAIM, NOT AN INFERENCE. It is true only where the provider says
     * so, because matching an unverified third-party address onto a local account is the
     * account-takeover path the flag exists to close.
     */
    public static function test_email_verified_is_only_true_when_the_provider_asserts_it()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);

        Rsx_Sso::begin('fake');

        $identity = static::__identity('subject-verified', 'verified@example.com');
        $identity['email_verified'] = true;

        static::__callback($identity);

        static::__assert_true(Rsx_Sso::pending()['email_verified']);
    }

    /**
     * FAIL CLOSED. An application that has not said what an unknown provider identity means
     * does not get an answer invented for it - the invention would be "create an account".
     */
    public static function test_an_unlinked_identity_fails_closed_with_no_handler()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', []);

        Rsx_Sso::begin('fake');

        $email = static::__fresh_email('closed');
        $response = static::__callback(static::__identity('subject-closed', $email));

        static::__assert_equals('/login', $response->getTargetUrl(), 'back to the login page');
        static::__assert_null(Rsx_Sso::pending(), 'the pending identity is discarded, not left live');
        static::__assert_false(Session::is_logged_in());

        // COUNTED EXACTLY ONCE. Login_History::record_failure() already feeds
        // Login_Throttle, so Rsx_Sso never calls the throttle itself - a second call would
        // halve the real budget, and the halving would only ever be discovered by a user
        // locked out early.
        static::__assert_equals(1, Login_History::get_failed_attempts_count($email), 'recorded once');
    }

    /**
     * A handler that DECLINES (returns null) is the same as none: the resolve contract is
     * first-non-null-wins, and all-declined means the framework default, which here is the
     * refusal.
     */
    public static function test_a_declining_handler_fails_closed_too()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => null]);

        Rsx_Sso::begin('fake');

        $response = static::__callback(static::__identity('subject-declined', 'declined@example.com'));

        static::__assert_equals('/login', $response->getTargetUrl());
        static::__assert_null(Rsx_Sso::pending());
    }

    // -------------------------------------------------------------------------
    // Redeeming a pending identity
    // -------------------------------------------------------------------------

    /**
     * The whole of what an application's handler does once it has decided yes: connect the
     * identity, sign in, and land somewhere. The success row is written HERE, because
     * RsxAuth::login() records nothing by design.
     */
    public static function test_consume_pending_and_login_links_signs_in_and_records()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);
        Event_Registry::_set_test_handlers('sso.login.destination', [fn ($data) => '/dashboard']);

        $email = static::__fresh_email('consume');
        $login_user = static::__make_login_user($email);

        Rsx_Sso::begin('fake');
        static::__callback(static::__identity('subject-consume', $email));

        $destination = Rsx_Sso::consume_pending_and_login($login_user);

        static::__assert_equals('/dashboard', $destination, 'the application chose the landing page');
        static::__assert_true(Session::is_logged_in(), 'and the identity is signed in');
        static::__assert_equals((int) $login_user->id, Session::get_login_user_id());
        static::__assert_null(Rsx_Sso::pending(), 'the pending identity is consumed');
        static::__assert_equals(1, static::__success_rows($email), 'exactly one success row');

        $row = Sso_Identity_Model::where('login_user_id', $login_user->id)->first();

        static::__assert_not_null($row, 'the link exists');
        static::__assert_equals('subject-consume', $row->provider_user_key);
        static::__assert_not_null($row->last_login_at, 'and records when it was used');
    }

    /**
     * With no destination handler the framework lands on '/'. A default, not a guess: there
     * is nowhere else a framework could know about.
     */
    public static function test_the_default_destination_is_the_root()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);
        Event_Registry::_set_test_handlers('sso.login.destination', []);

        $email = static::__fresh_email('rootdest');
        $login_user = static::__make_login_user($email);

        Rsx_Sso::begin('fake');
        static::__callback(static::__identity('subject-rootdest', $email));

        static::__assert_equals('/', Rsx_Sso::consume_pending_and_login($login_user));
    }

    /**
     * A SECOND ceremony for an identity that is already connected signs straight in - no
     * hook, no pending state, no application involvement at all.
     */
    public static function test_a_linked_identity_signs_straight_in()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);
        Event_Registry::_set_test_handlers('sso.login.destination', [fn ($data) => '/dashboard']);

        $email = static::__fresh_email('linked');
        $login_user = static::__make_login_user($email);

        Rsx_Sso::begin('fake');
        static::__callback(static::__identity('subject-linked', $email));
        Rsx_Sso::consume_pending_and_login($login_user);

        Session::logout();
        static::__reset_session();

        Rsx_Sso::begin('fake');
        $response = static::__callback(static::__identity('subject-linked', $email));

        static::__assert_equals('/dashboard', $response->getTargetUrl());
        static::__assert_true(Session::is_logged_in(), 'signed in with no application involvement');
        static::__assert_equals((int) $login_user->id, Session::get_login_user_id());
    }

    /**
     * The gate is where an application enforces its own account vocabulary - suspended,
     * unactivated, not yet approved - exactly as its password login function does. A
     * federated sign-in must not be a way around the checks a password sign-in performs.
     */
    public static function test_the_login_authorize_gate_can_deny_a_linked_identity()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);

        $email = static::__fresh_email('gate');
        $login_user = static::__make_login_user($email);

        Rsx_Sso::begin('fake');
        static::__callback(static::__identity('subject-gate', $email));
        Rsx_Sso::consume_pending_and_login($login_user);

        Session::logout();
        static::__reset_session();

        Event_Registry::_set_test_handlers('sso.login.authorize', [fn ($data) => 'That account is suspended.']);

        Rsx_Sso::begin('fake');
        $response = static::__callback(static::__identity('subject-gate', $email));

        static::__assert_equals('/login', $response->getTargetUrl(), 'denied and sent back');
        static::__assert_false(Session::is_logged_in(), 'a denied gate authenticates nobody');
        static::__assert_equals(
            1,
            Login_History::get_failed_attempts_count($email),
            'and the denial is recorded exactly once'
        );
    }

    // -------------------------------------------------------------------------
    // The local second factor
    // -------------------------------------------------------------------------

    /**
     * THE CENTRAL 2FA PROPERTY. An identity provider proved who owns the provider account; it
     * did not answer this application's second factor. So a signed-in state is NOT reached -
     * the identity is parked as a 2FA challenge and the browser is sent to the verify page.
     */
    public static function test_a_second_factor_still_runs_after_a_provider_sign_in()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);
        Event_Registry::_set_test_handlers('sso.two_factor.verify_url', [fn ($data) => '/login/verify']);

        $email = static::__fresh_email('twofactor');
        $login_user = static::__make_login_user($email);

        Rsx_Sso::begin('fake');
        static::__callback(static::__identity('subject-2fa', $email));
        Rsx_Sso::consume_pending_and_login($login_user);

        // Enroll a factor on the now-signed-in identity, then start over anonymously.
        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        Session::logout();
        static::__reset_session();

        Rsx_Sso::begin('fake');
        $response = static::__callback(static::__identity('subject-2fa', $email));

        static::__assert_equals('/login/verify', $response->getTargetUrl(), 'sent to answer the factor');
        static::__assert_false(Session::is_logged_in(), 'and NOT signed in on the way');
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending(), 'the challenge is waiting');
    }

    /**
     * skip_two_factor is the opt-out for an install whose identity provider IS the
     * organization's authentication authority and already enforces its own MFA.
     */
    public static function test_skip_two_factor_signs_in_without_the_challenge()
    {
        static::__anonymous();

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);
        Event_Registry::_set_test_handlers('sso.login.destination', [fn ($data) => '/dashboard']);

        $email = static::__fresh_email('skip2fa');
        $login_user = static::__make_login_user($email);

        Rsx_Sso::begin('fake');
        static::__callback(static::__identity('subject-skip', $email));
        Rsx_Sso::consume_pending_and_login($login_user);

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        Session::logout();
        static::__reset_session();

        $restore = config('rsx.sso.skip_two_factor');
        config()->set('rsx.sso.skip_two_factor', true);

        try {
            Rsx_Sso::begin('fake');
            $response = static::__callback(static::__identity('subject-skip', $email));
        } finally {
            config()->set('rsx.sso.skip_two_factor', $restore);
        }

        static::__assert_equals('/dashboard', $response->getTargetUrl());
        static::__assert_true(Session::is_logged_in(), 'the install opted out of the second challenge');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'nothing is left half-answered');
    }
}
