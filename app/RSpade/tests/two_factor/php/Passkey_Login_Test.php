<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;
use App\RSpade\Tests\TwoFactor\Php\Webauthn_Authenticator_Fixture;

/**
 * PASSWORDLESS passkey sign-in in the staff realm: begin_passkey_login() and
 * verify_passkey_login(), driven against the simulated authenticator, so the cryptography
 * actually runs.
 *
 * WHAT IS PINNED:
 *  - the begin names NO identity (no allowCredentials) and REQUIRES user verification, and
 *    its challenge parks under its own key - so a pending second-factor ceremony and a
 *    passwordless one on the same session cannot overwrite each other;
 *  - a user-verified assertion from a registered passkey SIGNS THE IDENTITY IN OUTRIGHT, the
 *    identity coming from the credential row, with exactly one success row and NO second
 *    factor owed - even when the identity has one enabled (the framework ruling);
 *  - an assertion WITHOUT the UV flag is refused: a passkey that is the only credential has
 *    to be two factors on its own;
 *  - an unknown credential, a replayed challenge and a disabled membership all answer with
 *    the one sentence, sign nobody in, and a disabled membership is counted exactly once;
 *  - a successful passwordless sign-in forgets any pending password-stage challenge.
 *
 * TWO STORES, two isolation strategies (the same shape as Two_Factor_Challenge_Test): rows
 * roll back with the per-test transaction; the throttle and per-email counters are redis keys
 * cleared in teardown.
 */
class Passkey_Login_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private static array $counter_keys_used = [];

    public static function teardown()
    {
        Login_Throttle::reset('CLI');
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();

        if (!empty(static::$counter_keys_used)) {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
            $redis->select(0);

            foreach (static::$counter_keys_used as $key) {
                $redis->del('cache:' . sha1($key));
            }

            $redis->close();
            static::$counter_keys_used = [];
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private static function __anonymous(): void
    {
        Login_Throttle::reset('CLI');
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    private static function __fresh_email(string $suffix): string
    {
        $email = 'passwordless_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        return $email;
    }

    /**
     * A login identity holding an enabled site membership - the framework will not sign in
     * anything less.
     */
    private static function __make_login_user(string $email, bool $enabled = true): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Identity';
        $user->is_enabled = $enabled ? 1 : 0;
        $user->save();

        return $login_user;
    }

    /**
     * An identity with a registered passkey, enrolled through the facade. Leaves the session
     * ANONYMOUS.
     *
     * @return array {login_user, authenticator, email}
     */
    private static function __enrolled(string $suffix, bool $enabled = true): array
    {
        static::__anonymous();

        $email = static::__fresh_email($suffix);
        $login_user = static::__make_login_user($email, $enabled);

        Session::set_login_user_id((int) $login_user->id);

        $authenticator = new Webauthn_Authenticator_Fixture(Rsx_Two_Factor::_relying_party_id());

        Rsx_Two_Factor::begin_passkey_registration();
        Rsx_Two_Factor::confirm_passkey_registration(
            $authenticator->attestation_response((string) Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY)),
            'Fixture key'
        );

        static::__anonymous();

        return ['login_user' => $login_user, 'authenticator' => $authenticator, 'email' => $email];
    }

    /**
     * Begin a passwordless ceremony and return the challenge it parked.
     */
    private static function __begin(): string
    {
        Rsx_Two_Factor::begin_passkey_login();

        return (string) Session::get_value(Rsx_Two_Factor::PASSKEY_LOGIN_CHALLENGE_KEY);
    }

    private static function __success_rows(string $email): int
    {
        return DB::table('_login_history')
            ->where('email_attempted', $email)
            ->where('status', Login_History::STATUS_SUCCESS)
            ->count();
    }

    // -------------------------------------------------------------------------
    // Beginning
    // -------------------------------------------------------------------------

    /**
     * The options name nobody and demand user verification. An empty allowCredentials is
     * what lets the authenticator offer its discoverable credential unprompted.
     */
    public static function test_begin_names_no_identity_and_requires_user_verification()
    {
        static::__anonymous();

        $options = Rsx_Two_Factor::begin_passkey_login();

        static::__assert_true(empty($options['publicKey']['allowCredentials']), 'no identity is named');
        static::__assert_equals('required', $options['publicKey']['userVerification'], 'the passkey must be two factors on its own');
        static::__assert_equals(Rsx_Two_Factor::_relying_party_id(), $options['publicKey']['rpId']);
        static::__assert_false(Session::is_logged_in(), 'beginning authenticates nobody');
    }

    /**
     * The passwordless challenge parks under ITS OWN key. A login page that offers a passkey
     * while a second-factor ceremony is in flight must not clobber that ceremony's challenge.
     */
    public static function test_the_passwordless_challenge_does_not_overwrite_a_second_factor_ceremony()
    {
        static::__anonymous();

        Session::put_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY, 'second-factor-challenge', Rsx_Two_Factor::challenge_expires_at());

        $passwordless = static::__begin();

        static::__assert_not_equals('', $passwordless, 'the passwordless challenge is parked');
        static::__assert_equals(
            'second-factor-challenge',
            Session::get_value(Rsx_Two_Factor::WEBAUTHN_CHALLENGE_KEY),
            'and the second-factor ceremony is untouched'
        );
    }

    // -------------------------------------------------------------------------
    // Signing in
    // -------------------------------------------------------------------------

    /**
     * A verified passkey signs its owner in - identified by the credential row, recorded once,
     * with no second factor owed even though a passkey IS a second factor for this identity.
     */
    public static function test_a_verified_passkey_signs_its_owner_in_outright()
    {
        $fixture = static::__enrolled('success');

        static::__assert_true(Rsx_Two_Factor::is_enabled($fixture['login_user']), 'the identity holds a second factor');

        $challenge = static::__begin();

        $signed_in = Rsx_Two_Factor::verify_passkey_login($fixture['authenticator']->assertion_response($challenge, 1));

        static::__assert_equals((int) $fixture['login_user']->id, (int) $signed_in->id, 'the owner of the credential');
        static::__assert_equals((int) $fixture['login_user']->id, (int) Session::get_login_user_id(), 'and is signed in');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'with no second-factor challenge owed');
        static::__assert_equals(1, static::__success_rows($fixture['email']), 'exactly one success row');
        static::__assert_null(Session::get_value(Rsx_Two_Factor::PASSKEY_LOGIN_CHALLENGE_KEY), 'the challenge is spent');
    }

    /**
     * An assertion without user verification is refused. As a second factor, presence is
     * enough - the password proved knowledge. As the only credential it is not.
     */
    public static function test_an_assertion_without_user_verification_is_refused()
    {
        $fixture = static::__enrolled('no_uv');

        $challenge = static::__begin();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_passkey_login($fixture['authenticator']->assertion_response($challenge, 1, false)),
            'could not sign you in'
        );

        static::__assert_false(Session::is_logged_in(), 'presence alone signs nobody in');
    }

    /**
     * A key this server never registered answers with the same sentence as every other
     * failure - it must not be usable to learn which keys exist.
     */
    public static function test_an_unknown_credential_is_refused_with_the_one_sentence()
    {
        static::__anonymous();

        $stranger = new Webauthn_Authenticator_Fixture(Rsx_Two_Factor::_relying_party_id());

        $challenge = static::__begin();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_passkey_login($stranger->assertion_response($challenge, 1)),
            'could not sign you in'
        );

        static::__assert_false(Session::is_logged_in());
    }

    /**
     * The challenge is single use: a captured assertion cannot be replayed.
     */
    public static function test_a_passwordless_assertion_cannot_be_replayed()
    {
        $fixture = static::__enrolled('replay');

        $challenge = static::__begin();
        $assertion = $fixture['authenticator']->assertion_response($challenge, 1);

        Rsx_Two_Factor::verify_passkey_login($assertion);

        static::__anonymous();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_passkey_login($assertion),
            'could not sign you in'
        );

        static::__assert_false(Session::is_logged_in(), 'the replay signs nobody in');
    }

    /**
     * A valid passkey whose owner holds no enabled site membership is refused exactly like a
     * failure, and counted exactly once.
     */
    public static function test_a_disabled_membership_is_refused_and_counted_once()
    {
        $fixture = static::__enrolled('disabled', false);

        $before = Login_History::get_failed_attempts_count($fixture['email']);

        $challenge = static::__begin();

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_passkey_login($fixture['authenticator']->assertion_response($challenge, 1)),
            'could not sign you in'
        );

        static::__assert_false(Session::is_logged_in(), 'a disabled membership signs nobody in');
        static::__assert_equals($before + 1, Login_History::get_failed_attempts_count($fixture['email']), 'counted exactly once');
        static::__assert_equals(0, static::__success_rows($fixture['email']));
    }

    /**
     * A passwordless sign-in supersedes a password-stage challenge left pending in the same
     * browser - the user is in, and nothing half-authenticated is left behind.
     */
    public static function test_a_passwordless_sign_in_forgets_a_pending_challenge()
    {
        $fixture = static::__enrolled('supersede');

        Rsx_Two_Factor::begin_challenge($fixture['login_user']);
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending(), 'a password-stage challenge is pending');

        $challenge = static::__begin();

        Rsx_Two_Factor::verify_passkey_login($fixture['authenticator']->assertion_response($challenge, 1));

        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'and is gone once the passkey signed in');
        static::__assert_true(Session::is_logged_in());
    }
}
