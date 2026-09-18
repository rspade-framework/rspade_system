<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Redis;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * users.is_enabled is the FRAMEWORK's switch, and these tests pin both halves of what that
 * means (rsx:man session, ACCOUNT STATE / SITE MEMBERSHIP):
 *
 * AT LOGIN - an identity holding no ENABLED site membership fails exactly the way a wrong
 * password fails. attempt() returns false, no session is established, and the audit trail
 * carries the classification the caller is deliberately not given: STATUS_FAILED_DISABLED.
 * One enabled membership out of several is enough, because the question is about the
 * IDENTITY and a login has not chosen a site yet. RsxAuth::login() - the door every other
 * sign-in uses, second factor and federated alike - answers the same predicate and returns
 * false without touching the session.
 *
 * AT REQUEST TIME - Session::enforce_enabled_membership() ends a session whose membership
 * for the CURRENT site has since been disabled or deleted. Disabling an account is meant to
 * take effect on the accounts that are already signed in, not on the next sign-in.
 *
 * ISOLATION, as in Rsx_Auth_Attempt_Test: the rows are rolled back with the per-test
 * transaction, while the failure counters are redis keys outside both the transaction and
 * the process - so every test uses a fresh email and deletes the keys it created.
 */
class Enabled_Membership_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    /**
     * Counter keys created by these tests, deleted in teardown. Held as the RAW key; the
     * persistent-namespace transform is applied on delete.
     */
    private static array $counter_keys_used = [];

    public static function teardown()
    {
        Session::logout();
        static::__reset_session();

        if (empty(static::$counter_keys_used)) {
            return;
        }

        $redis = new Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
        $redis->select(0);

        foreach (static::$counter_keys_used as $key) {
            $redis->del('cache:' . sha1($key));
        }

        $redis->close();
        static::$counter_keys_used = [];
    }

    /**
     * An email nothing else has ever attempted with, registered for counter teardown.
     */
    private static function __fresh_email(string $suffix): string
    {
        $email = 'enabled_' . $suffix . '_' . uniqid() . '@example.com';
        static::$counter_keys_used[] = 'login_failures:email:' . sha1(strtolower(trim($email)));

        return $email;
    }

    /**
     * A live credential row with a real bcrypt hash of self::PASSWORD and NO membership yet -
     * every test here decides what memberships the identity holds.
     */
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
     * One membership row on a site, enabled or not.
     */
    private static function __give_membership(Login_User_Model $login_user, int $site_id, bool $enabled): User_Model
    {
        // The site-scope trait FORCES site_id from the session on create, so the tenant is
        // DECLARED before the row is written rather than assigned onto it.
        $previous_site_id = Session::get_site_id();
        Session::set_site_id($site_id);

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $login_user->email;
        $user->first_name = 'Membership';
        $user->last_name = 'Fixture';
        $user->is_enabled = $enabled ? 1 : 0;
        $user->save();

        Session::set_site_id($previous_site_id);

        return $user;
    }

    /**
     * Run one write against a declared tenant. The site-scope trait refuses a save or a delete
     * whose row belongs to a site the session is not currently serving, so a fixture that
     * mutates a membership says which tenant it is acting in.
     *
     * @param int $site_id
     * @param callable $work
     * @return mixed
     */
    private static function __in_site(int $site_id, callable $work)
    {
        $previous_site_id = Session::get_site_id();
        Session::set_site_id($site_id);

        try {
            return $work();
        } finally {
            Session::set_site_id($previous_site_id);
        }
    }

    /**
     * A second tenant, so "one of two memberships" is a real pair - (login_user_id, site_id)
     * is unique, so two rows need two sites.
     */
    private static function __make_site(): Site_Model
    {
        $slug = 'membership-' . uniqid();

        $site = new Site_Model();
        $site->slug = $slug;
        $site->name = 'Membership Fixture Site';
        $site->save();

        return $site;
    }

    private static function __start_anonymous(): void
    {
        Session::logout();
        static::__reset_session();
    }

    /**
     * The tail of the application log, where record_failure() writes its forensic line -
     * failures are never database rows (see Login_History).
     */
    private static function __log_tail(int $bytes = 65536): string
    {
        $path = storage_path('logs/laravel.log');

        if (!is_readable($path)) {
            return '';
        }

        $size = filesize($path);
        $handle = fopen($path, 'rb');
        fseek($handle, max(0, $size - $bytes));
        $tail = stream_get_contents($handle);
        fclose($handle);

        return $tail === false ? '' : $tail;
    }

    // -------------------------------------------------------------------------
    // At login
    // -------------------------------------------------------------------------

    /**
     * The only membership is disabled: attempt() is false, nothing is signed in, and the
     * outcome is classified STATUS_FAILED_DISABLED - which is the whole difference between
     * this and a wrong password, and it lives in the audit trail rather than in the answer.
     */
    public static function test_attempt_refuses_an_identity_whose_only_membership_is_disabled()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('only_disabled');
        $login_user = static::__make_login_user($email);
        static::__give_membership($login_user, 1, false);

        $rows_before = DB::table('_login_history')->count();

        static::__assert_false(
            RsxAuth::attempt(['email' => $email, 'password' => self::PASSWORD]),
            'a disabled membership cannot authenticate, even with the right password'
        );

        static::__assert_null(Session::get_login_user_id(), 'no identity is established');
        static::__assert_equals($rows_before, DB::table('_login_history')->count(), 'no success row');
        static::__assert_equals(1, Login_History::get_failed_attempts_count($email), 'the failure is counted');

        $log = static::__log_tail();
        if ($log === '') {
            static::__skip('application log is not readable in this environment');
            return;
        }

        static::__assert_contains($email, $log, 'the failure is logged');
        static::__assert_contains(Login_History::STATUS_FAILED_DISABLED, $log, 'classified FAILED_DISABLED');
    }

    /**
     * An identity with no membership AT ALL is the same answer: the predicate asks for one
     * enabled row, and none is none.
     */
    public static function test_attempt_refuses_an_identity_with_no_membership_at_all()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('no_membership');
        static::__make_login_user($email);

        static::__assert_false(
            RsxAuth::attempt(['email' => $email, 'password' => self::PASSWORD]),
            'a credential with no membership behind it cannot authenticate'
        );

        static::__assert_null(Session::get_login_user_id(), 'no identity is established');
    }

    /**
     * ONE enabled membership is enough. The question is about the identity - a login has not
     * chosen a site yet, so a disabled membership on one site cannot lock an identity out of
     * the site it is still a member of.
     */
    public static function test_attempt_succeeds_when_one_of_two_memberships_is_enabled()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('one_of_two');
        $login_user = static::__make_login_user($email);
        $other_site = static::__make_site();

        static::__give_membership($login_user, 1, false);
        static::__give_membership($login_user, (int) $other_site->id, true);

        static::__assert_true(
            RsxAuth::attempt(['email' => $email, 'password' => self::PASSWORD]),
            'one enabled membership authenticates'
        );

        static::__assert_equals(
            (int) $login_user->id,
            (int) Session::get_login_user_id(),
            'the session carries the authenticated identity'
        );

        $row = DB::table('_login_history')->where('email_attempted', $email)->first();
        static::__assert_not_null($row, 'the success is recorded');
        static::__assert_equals(Login_History::STATUS_SUCCESS, $row->status);
    }

    /**
     * has_enabled_membership() is the public predicate behind both halves.
     */
    public static function test_has_enabled_membership_reads_across_every_site()
    {
        static::__start_anonymous();
        $login_user = static::__make_login_user(static::__fresh_email('predicate'));

        static::__assert_false(
            RsxAuth::has_enabled_membership($login_user),
            'no membership at all'
        );

        $membership = static::__give_membership($login_user, 1, false);
        static::__assert_false(
            RsxAuth::has_enabled_membership($login_user),
            'a disabled membership does not count'
        );

        static::__in_site(1, function () use ($membership) {
            $membership->is_enabled = 1;
            $membership->save();
        });
        static::__assert_true(
            RsxAuth::has_enabled_membership($login_user),
            'and an enabled one does'
        );
    }

    /**
     * login() is the door every sign-in that never saw attempt() goes through - a second
     * factor, a federated sign-in, the development harness. It refuses the same identity, and
     * it refuses it WITHOUT touching the session: whoever was signed in before stays signed
     * in, and nothing is recorded (the caller owns the recording).
     */
    public static function test_login_refuses_a_disabled_identity_without_touching_the_session()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('login_refused');
        $login_user = static::__make_login_user($email);
        static::__give_membership($login_user, 1, false);

        $rows_before = DB::table('_login_history')->count();
        $before = Session::get_login_user_id();

        static::__assert_false(RsxAuth::login($login_user), 'login() refuses a disabled identity');
        static::__assert_equals($before, Session::get_login_user_id(), 'the session identity is untouched');
        static::__assert_equals($rows_before, DB::table('_login_history')->count(), 'login() records nothing');
    }

    // -------------------------------------------------------------------------
    // At request time
    // -------------------------------------------------------------------------

    /**
     * The request-time half. A session that was legitimate a moment ago stops being one the
     * instant its membership is disabled: enforce_enabled_membership() returns false AND logs
     * the session out, which is what turns the transports' ordinary auth-required answer into
     * a sign-in prompt rather than a permission error.
     */
    public static function test_enforce_ends_a_session_whose_membership_is_disabled()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('enforce_disabled');
        $login_user = static::__make_login_user($email);
        $membership = static::__give_membership($login_user, 1, true);

        Session::impersonate(1, (int) $login_user->id, (int) $membership->id);

        static::__assert_true(
            Session::enforce_enabled_membership(),
            'an enabled membership passes'
        );

        static::__in_site(1, function () use ($membership) {
            $membership->is_enabled = 0;
            $membership->save();
        });

        // Re-declare the same context: the point is the ROW changing under a session that
        // has not, so the memoized user is dropped and nothing else about the context moves.
        Session::impersonate(1, (int) $login_user->id, (int) $membership->id);

        static::__assert_false(
            Session::enforce_enabled_membership(),
            'a disabled membership does not'
        );
        static::__assert_null(Session::get_login_user_id(), 'and the session was logged out');
    }

    /**
     * A DELETED membership is the same outcome as a disabled one - the row is gone, so the
     * identity is not a member of the site its session names.
     */
    public static function test_enforce_ends_a_session_whose_membership_was_deleted()
    {
        static::__start_anonymous();
        $email = static::__fresh_email('enforce_deleted');
        $login_user = static::__make_login_user($email);
        $membership = static::__give_membership($login_user, 1, true);

        static::__in_site(1, fn () => $membership->delete());
        Session::impersonate(1, (int) $login_user->id, (int) $membership->id);

        static::__assert_false(Session::enforce_enabled_membership());
        static::__assert_null(Session::get_login_user_id(), 'the session was logged out');
    }

    /**
     * "There is nobody to disable" is not a denial: an anonymous caller passes, and asking
     * the question mints nothing.
     */
    public static function test_enforce_permits_an_anonymous_caller_and_creates_nothing()
    {
        static::__start_anonymous();

        static::__assert_true(Session::enforce_enabled_membership(), 'anonymous is permitted');
        static::__assert_false(Session::has_session(), 'and asking created no session');
    }
}
