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
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
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
 *  - issue_code() keeps only a keyed hash, a later code replaces an earlier one, and the
 *    count is reported for an application that caps resends;
 *  - a wrong issued code counts against the challenge's attempt cap like a wrong TOTP code;
 *  - pending_identity() names whose address the code goes to;
 *  - the portal facade issues and verifies on its own realm.
 *
 * Redis-held counters (the per-IP throttle, the per-email failure count) live outside the
 * rolled-back transaction, so every test uses a unique email and teardown clears them - the
 * same shape as Two_Factor_Challenge_Test.
 */
class Two_Factor_Issued_Code_Test extends Rsx_Test_Abstract
{
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

        return $login_user;
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
     * Six digits come back; only a hash is kept; the correct code signs the identity in.
     */
    public static function test_an_issued_code_signs_in_and_only_its_hash_is_kept()
    {
        $login_user = static::__make_login_user('issue');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        $code = Rsx_Two_Factor::issue_code();

        static::__assert_true((bool) preg_match('/^\d{6}$/', $code), 'six digits');
        static::__assert_equals(1, Rsx_Two_Factor::challenge_pending()['codes_issued']);
        static::__assert_equals((int) $login_user->id, (int) Rsx_Two_Factor::pending_identity()->id, 'whose address the code goes to');

        $stored = (string) DB::table('_session_values')
            ->where('session_id', Session::get_session_id())
            ->where('value_key', Rsx_Two_Factor::CHALLENGE_KEY)
            ->value('value');

        static::__assert_false(str_contains($stored, '"' . $code . '"'), 'the digits are not stored');

        $signed_in = Rsx_Two_Factor::verify_challenge(['code' => $code]);

        static::__assert_equals((int) $login_user->id, (int) $signed_in->id);
        static::__assert_true(Session::is_logged_in(), 'signed in');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the challenge is spent');
    }

    /**
     * "Send a new code" leaves exactly one live answer.
     */
    public static function test_a_later_code_replaces_the_earlier_one()
    {
        $login_user = static::__make_login_user('replace');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);

        $first = Rsx_Two_Factor::issue_code();
        do {
            $second = Rsx_Two_Factor::issue_code();
        } while ($second === $first);

        static::__assert_greater_than(1, Rsx_Two_Factor::challenge_pending()['codes_issued']);

        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => $first]), 'not valid');

        Rsx_Two_Factor::verify_challenge(['code' => $second]);
        static::__assert_true(Session::is_logged_in(), 'the latest code signs in');
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
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i < Rsx_Two_Factor::challenge_max_failures(); $i++) {
            static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => $wrong]), 'not valid');
            Login_Throttle::reset('CLI');
        }

        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['code' => $wrong]), 'sign in again');
        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the challenge is gone');
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
        Rsx_Portal_Two_Factor::begin_challenge($portal_user, [Rsx_Portal_Two_Factor::ANSWER_ISSUED_CODE]);

        static::__assert_null(Rsx_Two_Factor::challenge_pending(), 'the staff realm sees nothing');

        $code = Rsx_Portal_Two_Factor::issue_code();
        $signed_in = Rsx_Portal_Two_Factor::verify_challenge(['code' => $code]);

        static::__assert_equals((int) $portal_user->id, (int) $signed_in->id);
        static::__assert_true(Portal_Session::is_logged_in(), 'the portal user is signed in');
    }
}
