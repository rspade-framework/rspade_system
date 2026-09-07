<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Session::terminate_session() is a SELF-SERVICE device-session action: it deactivates one of
 * the CURRENT login user's own sessions. The owning-user predicate is part of the statement, so
 * another user's session id matches zero rows and the method returns false - the caller does not
 * have to remember to pre-scope. Cross-user (admin) termination lives in
 * terminate_all_sessions_for_user().
 *
 * Runs in the default per-test transaction.
 */
class Session_Terminate_Ownership_Test extends Rsx_Test_Abstract
{
    private const OWNER_LOGIN_USER_ID = 770001;
    private const OTHER_LOGIN_USER_ID = 770002;

    /**
     * Insert an active _sessions row owned by $login_user_id and return its id.
     */
    private static function __insert_session(?int $login_user_id): int
    {
        return (int) DB::table('_sessions')->insertGetId([
            'session_token' => bin2hex(random_bytes(16)),
            'csrf_token'    => bin2hex(random_bytes(16)),
            'active'        => 1,
            'site_id'       => 0,
            'version'       => 1,
            'ip_address'    => '10.0.0.7',
            'user_agent'    => 'terminate-ownership-test',
            'login_user_id' => $login_user_id,
            'last_active'   => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private static function __is_active(int $session_id): bool
    {
        return (bool) DB::table('_sessions')->where('id', $session_id)->value('active');
    }

    private static function __act_as_owner(): void
    {
        Session::logout();
        static::__reset_session();
        Session::set_login_user_id(self::OWNER_LOGIN_USER_ID);
    }

    public static function test_own_session_is_terminated()
    {
        self::__act_as_owner();
        $session_id = self::__insert_session(self::OWNER_LOGIN_USER_ID);

        static::__assert_true(
            Session::terminate_session($session_id),
            'a user may terminate their own session'
        );
        static::__assert_false(self::__is_active($session_id), 'the row is deactivated');
    }

    public static function test_another_users_session_is_untouched_and_returns_false()
    {
        self::__act_as_owner();
        $foreign_session_id = self::__insert_session(self::OTHER_LOGIN_USER_ID);

        static::__assert_false(
            Session::terminate_session($foreign_session_id),
            'terminating a session you do not own must report failure'
        );
        static::__assert_true(
            self::__is_active($foreign_session_id),
            'the other user session row must be intact'
        );
    }

    /**
     * An anonymous session row (no login_user_id) is owned by nobody and is likewise
     * unreachable through this method.
     */
    public static function test_anonymous_session_is_not_terminable()
    {
        self::__act_as_owner();
        $anonymous_session_id = self::__insert_session(null);

        static::__assert_false(Session::terminate_session($anonymous_session_id));
        static::__assert_true(self::__is_active($anonymous_session_id), 'the row is intact');
    }

    /**
     * With no login identity at all there is no ownership to establish, so the call is a
     * fail-closed no-op rather than an unscoped update.
     */
    public static function test_logged_out_caller_terminates_nothing()
    {
        Session::logout();
        static::__reset_session();

        $session_id = self::__insert_session(self::OWNER_LOGIN_USER_ID);

        static::__assert_false(Session::terminate_session($session_id));
        static::__assert_true(self::__is_active($session_id), 'the row is intact');
    }

    public static function teardown(): void
    {
        Session::logout();
        static::__reset_session();
    }
}
