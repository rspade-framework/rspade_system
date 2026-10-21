<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Totp;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * What a challenge accepts, and the ISSUED CODE: six digits the framework mints for a
 * pending challenge and the application delivers (an email, an SMS).
 *
 * PINNED:
 *  - begin_challenge()'s $accepts is validated before the sign-out: an unknown kind, an
 *    empty list, or a list the identity cannot answer throws;
 *  - verify_challenge() refuses an answer of a kind the challenge does not accept, even a
 *    correct one - the application's policy is enforced, not advisory;
 *  - issue_code() keeps ONE live code per identity, encrypted with the application key: a
 *    resend returns the same digits and moves no expiry, a code issued in one session
 *    verifies in another session of the same identity and never for another identity, and
 *    an expired code is replaced by a fresh mint;
 *  - codes_issued counts SENDS, and issued_code_expires_at() reports the live code's expiry;
 *  - a correct answer consumes the code, a spent challenge or a locked identity destroys it,
 *    and abandon_challenge() leaves it;
 *  - a wrong issued code counts against the challenge's attempt cap like a wrong TOTP code;
 *  - pending_identity() names whose address the code goes to;
 *  - the portal facade issues and verifies on its own realm.
 *
 * Redis-held counters (the per-IP throttle, the per-email failure count) live outside the
 * rolled-back transaction, so every test uses a unique email and teardown clears them - the
 * same shape as Two_Factor_Challenge_Test. The live issued code lives in the same store, so
 * each fixture identity starts with none (a re-provisioned test database can hand out an id
 * a previous run left a code against).
 */
class Two_Factor_Issued_Code_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    /** Raw counter keys created by these tests, deleted in teardown. */
    private static array $counter_keys_used = [];

    public static function teardown()
    {
        Login_Throttle::reset('CLI');

        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
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

    /**
     * A login identity with an enabled membership and no second factor. Leaves the session
     * anonymous.
     */
    private static function __make_login_user(string $suffix): Login_User_Model
    {
        Login_Throttle::reset('CLI');
        Session::logout();
        static::__reset_session();

        $email = 'issued_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Issued';
        $user->is_enabled = 1;
        $user->save();

        Rsx_Two_Factor::clear_failures($login_user);
        static::__engine(Rsx_Two_Factor::class, '__forget_issued_code', (int) $login_user->id);

        return $login_user;
    }

    /**
     * Call one of the engine's protected statics on a realm facade.
     */
    private static function __engine(string $facade, string $method, ...$args)
    {
        return (new \ReflectionMethod($facade, $method))->invokeArgs(null, $args);
    }

    /**
     * The identity's live code as the engine reads it: {code, expires_at}, or null.
     */
    private static function __live(Login_User_Model $login_user): ?array
    {
        return static::__engine(Rsx_Two_Factor::class, '__live_issued_code', (int) $login_user->id);
    }

    /**
     * Store a code for the identity directly, with an expiry of the test's choosing - the
     * store's own encoding, so the engine reads it as one it minted.
     */
    private static function __plant_code(Login_User_Model $login_user, string $code, string $expires_at): void
    {
        $stored = Rsx_Counter::add_value(
            static::__engine(Rsx_Two_Factor::class, '__issued_code_key', (int) $login_user->id),
            Crypt::encryptString(json_encode(['code' => $code, 'minted_at' => Rsx_Time::now_iso(), 'expires_at' => $expires_at])),
            Rsx_Time::to_ms($expires_at)
        );

        static::__assert_true($stored, 'the identity had no live code to collide with');
    }

    /**
     * A wrong six-digit answer for a known code.
     */
    private static function __wrong(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    /**
     * Enroll TOTP on the identity through the facade and return the seed. Leaves the
     * session anonymous.
     */
    private static function __enroll_totp(Login_User_Model $login_user): string
    {
        Session::set_login_user_id((int) $login_user->id);

        $started = Rsx_Two_Factor::begin_totp_enrollment();
        Rsx_Two_Factor::confirm_totp_enrollment(Totp::code_for($started['secret'], intdiv(time(), Totp::PERIOD)));

        Session::logout();
        static::__reset_session();

        return $started['secret'];
    }

    // -------------------------------------------------------------------------
    // $accepts
    // -------------------------------------------------------------------------

    /**
     * A challenge nobody could answer is refused before anything is parked or signed out.
     */
    public static function test_begin_challenge_validates_accepts()
    {
        $login_user = static::__make_login_user('validate');

        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Two_Factor::begin_challenge($login_user, []), 'empty');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Two_Factor::begin_challenge($login_user, ['sms']), 'unknown answer kind');
        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_TOTP]),
            'holds none of them'
        );
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'nothing was parked');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE, Rsx_Two_Factor::ANSWER_TOTP]);

        $pending = Rsx_Two_Factor::challenge_pending();

        static::__assert_equals([Rsx_Two_Factor::ANSWER_ISSUED_CODE], $pending['accepts'], 'only what the identity can answer with');
        static::__assert_true($pending['has_issued_code']);
        static::__assert_false($pending['has_totp']);
        static::__assert_equals(0, $pending['codes_issued']);
    }

    /**
     * An answer of a kind the challenge does not accept is a wrong answer, however correct.
     */
    public static function test_an_unaccepted_kind_is_refused_even_when_correct()
    {
        $login_user = static::__make_login_user('unaccepted');
        $secret = static::__enroll_totp($login_user);

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        static::__assert_false(Rsx_Two_Factor::challenge_pending()['has_totp'], 'the screen is not told to ask for it');

        static::__assert_throws(
            Two_Factor_Failed_Exception::class,
            fn () => Rsx_Two_Factor::verify_challenge(['code' => Totp::code_for($secret, intdiv(time(), Totp::PERIOD) + 1)]),
            'not valid'
        );
        static::__assert_false(Session::is_logged_in());
    }

    // -------------------------------------------------------------------------
    // issue_code()
    // -------------------------------------------------------------------------

    /**
     * Six digits come back, the session holds no trace of them, the store holds them only
     * encrypted, and the correct code signs the identity in.
     */
    public static function test_an_issued_code_signs_in_and_is_stored_encrypted()
    {
        $login_user = static::__make_login_user('issue');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        $code = Rsx_Two_Factor::issue_code();

        static::__assert_true((bool) preg_match('/^\d{6}$/', $code), 'six digits');
        static::__assert_equals(1, Rsx_Two_Factor::challenge_pending()['codes_issued']);
        static::__assert_equals((int) $login_user->id, (int) Rsx_Two_Factor::pending_identity()->id, 'whose address the code goes to');

        $session_value = (string) DB::table('_session_values')
            ->where('session_id', Session::get_session_id())
            ->where('value_key', Rsx_Two_Factor::CHALLENGE_KEY)
            ->value('value');

        static::__assert_false(str_contains($session_value, $code), 'the session holds no trace of the digits');

        $stored = Rsx_Counter::value(static::__engine(Rsx_Two_Factor::class, '__issued_code_key', (int) $login_user->id));

        static::__assert_not_null($stored, 'the code is stored against the identity');
        static::__assert_false(str_contains($stored, $code), 'the stored value is not the digits');
        static::__assert_equals($code, json_decode(Crypt::decryptString($stored), true)['code'], 'it is the digits, encrypted with the app key');

        $signed_in = Rsx_Two_Factor::verify_challenge(['code' => $code]);

        static::__assert_equals((int) $login_user->id, (int) $signed_in->id);
        static::__assert_true(Session::is_logged_in(), 'signed in');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the challenge is spent');
        static::__assert_null(static::__live($login_user), 'a correct answer consumes the code');
    }

    /**
     * "Resend code" re-sends the SAME digits, each send is counted, and the reader reports one
     * unmoving expiry.
     */
    public static function test_a_resend_returns_the_same_code_and_counts_the_send()
    {
        $login_user = static::__make_login_user('resend');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        static::__assert_null(Rsx_Two_Factor::issued_code_expires_at(), 'no live code before the first send');

        $first = Rsx_Two_Factor::issue_code();
        $expires_at = Rsx_Two_Factor::issued_code_expires_at();

        static::__assert_not_null($expires_at, 'the reader sees the live code');
        static::__assert_true(Rsx_Time::is_future($expires_at));

        static::__assert_equals($first, Rsx_Two_Factor::issue_code(), 'a resend re-sends the same digits');
        static::__assert_equals($first, Rsx_Two_Factor::issue_code());
        static::__assert_equals(3, Rsx_Two_Factor::challenge_pending()['codes_issued'], 'codes_issued counts sends, not mints');
        static::__assert_true(Rsx_Time::is_future(Rsx_Two_Factor::issued_code_expires_at()), 'the resent code is live');

        Rsx_Two_Factor::verify_challenge(['code' => $first]);
        static::__assert_true(Session::is_logged_in(), 'the one code signs in');
    }

    /**
     * A resend restarts the clock: a code with a minute left gets a full window again, with
     * the same digits, and the pending challenge lives as long.
     */
    public static function test_a_resend_restarts_the_code_s_clock()
    {
        $login_user = static::__make_login_user('absolute');
        $expires_at = Rsx_Time::add(Rsx_Time::now_iso(), 60);

        static::__plant_code($login_user, '246810', $expires_at);

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        static::__assert_equals($expires_at, Rsx_Two_Factor::issued_code_expires_at(), 'a fresh challenge sees the live code');
        static::__assert_equals('246810', Rsx_Two_Factor::issue_code(), 'the live code is re-sent');

        $restarted = Rsx_Two_Factor::issued_code_expires_at();
        static::__assert_true(Rsx_Time::diff_seconds(Rsx_Time::now_iso(), $restarted) > 60 * ((int) config('rsx.two_factor.challenge_window_minutes') - 1), 'a full window again');
        static::__assert_equals('246810', Rsx_Two_Factor::issue_code(), 'still the same digits');
    }

    /**
     * Once the code has expired, the next send mints a new one with a full window.
     */
    public static function test_an_expired_code_is_replaced_by_a_new_mint()
    {
        $login_user = static::__make_login_user('expired');
        $expires_at = Rsx_Time::add(Rsx_Time::now_iso(), 1);

        static::__plant_code($login_user, '135790', $expires_at);

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        // The store expires the entry at the planted time. A bounded POLL of that condition
        // (tests/CLAUDE.md, the contention principle): the bound only catches a store that
        // never expires the entry, and reaching it fails the test naming that condition.
        $deadline = microtime(true) + 120;
        while (Rsx_Two_Factor::issued_code_expires_at() !== null) {
            if (microtime(true) > $deadline) {
                static::__fail('the store never expired the planted code at ' . $expires_at);
            }
            usleep(100000);
        }

        $code = Rsx_Two_Factor::issue_code();
        $new_expiry = Rsx_Two_Factor::issued_code_expires_at();

        static::__assert_not_null($new_expiry, 'a new code was minted');
        static::__assert_greater_than(Rsx_Time::to_ms($expires_at) + 60000, Rsx_Time::to_ms($new_expiry), 'with a window of its own');

        if ($code !== '135790') {
            static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => '135790']), 'not valid');
            Login_Throttle::reset('CLI');
        }

        Rsx_Two_Factor::verify_challenge(['code' => $code]);
        static::__assert_true(Session::is_logged_in(), 'the new code signs in');
    }

    /**
     * Session cycling cannot mint a parallel code: a fresh session's challenge for the same
     * identity is answered by the code the first session sent, and re-sends it.
     */
    public static function test_a_code_issued_in_one_session_verifies_in_another()
    {
        $login_user = static::__make_login_user('sessions');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        $first_session_id = Session::get_session_id();
        $code = Rsx_Two_Factor::issue_code();

        static::__as_browser();

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        static::__assert_true(Session::get_session_id() !== $first_session_id, 'a different session');
        static::__assert_equals(0, Rsx_Two_Factor::challenge_pending()['codes_issued'], 'nothing sent from this one yet');
        static::__assert_not_null(Rsx_Two_Factor::issued_code_expires_at(), 'but the identity holds a live code');
        static::__assert_equals($code, Rsx_Two_Factor::issue_code(), 'the same digits, not a parallel code');

        Rsx_Two_Factor::verify_challenge(['code' => $code]);
        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'signed in from the second session');
    }

    /**
     * One identity's code never answers another identity's challenge.
     */
    public static function test_another_identitys_code_never_verifies()
    {
        $owner = static::__make_login_user('owner');
        $other = static::__make_login_user('other');

        Rsx_Two_Factor::begin_challenge($owner, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        $code = Rsx_Two_Factor::issue_code();

        static::__as_browser();

        Rsx_Two_Factor::begin_challenge($other, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        static::__assert_null(Rsx_Two_Factor::issued_code_expires_at(), 'the other identity holds no code');
        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => $code]), 'not valid');
        static::__assert_false(Session::is_logged_in());
        static::__assert_not_null(static::__live($owner), 'the owner\'s code is untouched');
    }

    /**
     * Cancel in one browser leaves the identity's code for any other browser answering it.
     */
    public static function test_abandoning_a_challenge_leaves_the_code()
    {
        $login_user = static::__make_login_user('abandon');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        $code = Rsx_Two_Factor::issue_code();
        Rsx_Two_Factor::abandon_challenge();

        static::__assert_equals($code, static::__live($login_user)['code'] ?? null, 'the code outlives the abandoned challenge');
    }

    /**
     * The reader answers null for a challenge that does not accept an issued code.
     */
    public static function test_the_reader_is_null_without_an_issued_code_challenge()
    {
        $login_user = static::__make_login_user('reader');
        static::__enroll_totp($login_user);

        static::__assert_null(Rsx_Two_Factor::issued_code_expires_at(), 'nothing pending');

        static::__plant_code($login_user, '864209', Rsx_Time::add(Rsx_Time::now_iso(), 60));
        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_TOTP]);

        static::__assert_null(Rsx_Two_Factor::issued_code_expires_at(), 'this challenge takes no issued code');
    }

    /**
     * issue_code() refuses a challenge that does not accept one, and says the window closed
     * when nothing is pending.
     */
    public static function test_issue_code_refuses_without_an_accepting_challenge()
    {
        $login_user = static::__make_login_user('refuse');
        static::__enroll_totp($login_user);

        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::issue_code(), 'expired');

        Rsx_Two_Factor::begin_challenge($login_user);

        static::__assert_throws(\RuntimeException::class, fn () => Rsx_Two_Factor::issue_code(), 'does not accept an issued code');
    }

    /**
     * Wrong issued codes spend the challenge's attempt budget; the answer that reaches the
     * cap destroys the challenge.
     */
    public static function test_wrong_codes_count_against_the_challenge_cap()
    {
        $login_user = static::__make_login_user('cap');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        $code = Rsx_Two_Factor::issue_code();
        $wrong = static::__wrong($code);

        for ($i = 1; $i < Rsx_Two_Factor::challenge_max_failures(); $i++) {
            static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => $wrong]), 'not valid');
            Login_Throttle::reset('CLI');
        }

        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => $wrong]), 'sign in again');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the challenge is gone');
        static::__assert_null(static::__live($login_user), 'and the code it was guessed against with it');

        Login_Throttle::reset('CLI');
        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        static::__assert_null(Rsx_Two_Factor::issued_code_expires_at(), 'the next sign-in starts with no code');
    }

    /**
     * The wrong answer that locks the identity destroys its code: nothing would accept it.
     */
    public static function test_locking_the_identity_destroys_the_code()
    {
        $login_user = static::__make_login_user('lock');
        $saved = config('rsx.two_factor.identity_max_failures');
        config(['rsx.two_factor.identity_max_failures' => 2]);

        try {
            Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
            $wrong = static::__wrong(Rsx_Two_Factor::issue_code());

            static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => $wrong]), 'not valid');
            Login_Throttle::reset('CLI');
            static::__assert_not_null(static::__live($login_user), 'one wrong answer leaves the code');

            static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => $wrong]), 'not valid');
            Login_Throttle::reset('CLI');

            static::__assert_true(Rsx_Two_Factor::is_locked($login_user), 'the identity is locked');
            static::__assert_null(static::__live($login_user), 'and its code destroyed');
        } finally {
            config(['rsx.two_factor.identity_max_failures' => $saved]);
            Rsx_Two_Factor::clear_failures($login_user);
        }
    }

    // -------------------------------------------------------------------------
    // The portal realm
    // -------------------------------------------------------------------------

    /**
     * The portal facade issues and verifies an issued code on its own realm.
     */
    public static function test_the_portal_facade_issues_and_verifies()
    {
        // The baseline site (tests/CLAUDE.md: the test baseline carries site 1).
        $site_id = 1;

        Login_Throttle::reset('CLI');
        Portal_Session::_testing_reset();
        Portal_Session::set_site_id($site_id);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_portal_user_id(0);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = $site_id;
        $portal_user->email = 'issued_portal_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        Rsx_Portal_Two_Factor::clear_failures($portal_user);
        static::__engine(Rsx_Portal_Two_Factor::class, '__forget_issued_code', (int) $portal_user->id);
        Rsx_Portal_Two_Factor::begin_challenge($portal_user, [Rsx_Portal_Two_Factor::ANSWER_ISSUED_CODE]);

        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the staff realm sees nothing');

        $code = Rsx_Portal_Two_Factor::issue_code();

        static::__assert_equals($code, Rsx_Portal_Two_Factor::issue_code(), 'a portal resend re-sends the same digits');
        static::__assert_not_null(Rsx_Portal_Two_Factor::issued_code_expires_at());

        $signed_in = Rsx_Portal_Two_Factor::verify_challenge(['code' => $code]);

        static::__assert_equals((int) $portal_user->id, (int) $signed_in->id);
        static::__assert_true(Portal_Session::is_logged_in(), 'the portal user is signed in');
    }
}
