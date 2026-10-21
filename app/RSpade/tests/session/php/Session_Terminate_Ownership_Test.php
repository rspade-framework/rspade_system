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
 * Session::terminate_session() is a SELF-SERVICE action: it signs out one of the CURRENT login
 * identity's own user sessions on another browser. The owning-account predicate is part of the
 * lookup, so another account's user session id matches nothing and the method returns false -
 * the caller does not have to remember to pre-scope. Cross-user (admin) termination lives in
 * terminate_session_for_user() / terminate_all_sessions_for_user().
 *
 * Runs in the default per-test transaction.
 */
class Session_Terminate_Ownership_Test extends Rsx_Test_Abstract
{
    private const OWNER_LOGIN_USER_ID = 770001;
    private const OTHER_LOGIN_USER_ID = 770002;

    /**
     * A browser signed in as $login_user_id; answers the fixture.
     */
    private static function __sign_in(int $login_user_id): array
    {
        return static::__insert_session_fixture([
            'staff' => ['login_user_id' => $login_user_id, 'site_id' => 1],
        ]);
    }

    private static function __is_signed_in(int $user_session_id): bool
    {
        return DB::table('_user_sessions')->where('id', $user_session_id)->value('signed_out_at') === null;
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
        $fixture = self::__sign_in(self::OWNER_LOGIN_USER_ID);

        static::__assert_true(
            Session::terminate_session($fixture['staff_user_session_id']),
            'a user may end their own session'
        );
        static::__assert_false(self::__is_signed_in($fixture['staff_user_session_id']), 'the user session is signed out');
        static::__assert_equals(
            Session::SIGN_OUT_ENDED_BY_USER,
            (int) DB::table('_user_sessions')->where('id', $fixture['staff_user_session_id'])->value('sign_out_reason_id')
        );
        static::__assert_null(
            DB::table('_sessions')->where('id', $fixture['id'])->value('staff_user_session_id'),
            "the other browser's pointer is cleared"
        );
        static::__assert_equals(
            1,
            (int) DB::table('_sessions')->where('id', $fixture['id'])->value('active'),
            'the other browser row is kept'
        );
    }

    public static function test_another_users_session_is_untouched_and_returns_false()
    {
        self::__act_as_owner();
        $foreign = self::__sign_in(self::OTHER_LOGIN_USER_ID);

        static::__assert_false(
            Session::terminate_session($foreign['staff_user_session_id']),
            'ending a session you do not own must report failure'
        );
        static::__assert_true(
            self::__is_signed_in($foreign['staff_user_session_id']),
            'the other account stays signed in'
        );
    }

    /**
     * A signed-out user session is not a live session to end.
     */
    public static function test_signed_out_session_is_not_terminable()
    {
        self::__act_as_owner();
        $fixture = static::__insert_session_fixture([
            'staff' => ['login_user_id' => self::OWNER_LOGIN_USER_ID, 'site_id' => 1, 'signed_out_at' => now(), 'sign_out_reason_id' => Session::SIGN_OUT_SIGNED_OUT],
        ]);

        static::__assert_false(Session::terminate_session($fixture['staff_user_session_id']));
    }

    /**
     * With no login identity at all there is no ownership to establish, so the call is a
     * fail-closed no-op rather than an unscoped update.
     */
    public static function test_logged_out_caller_terminates_nothing()
    {
        Session::logout();
        static::__reset_session();

        $fixture = self::__sign_in(self::OWNER_LOGIN_USER_ID);

        static::__assert_false(Session::terminate_session($fixture['staff_user_session_id']));
        static::__assert_true(self::__is_signed_in($fixture['staff_user_session_id']), 'the user session is intact');
    }

    public static function teardown(): void
    {
        Session::logout();
        static::__reset_session();
    }
}
