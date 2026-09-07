<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The GUARDED cross-user termination primitives:
 *   Session::terminate_session_for_user()      - one session of any user
 *   Session::terminate_all_sessions_for_user() - every session of any user
 *   Session::_deactivate_sessions_for_user()   - the unchecked framework-internal path
 *
 * The contract under test has two halves that must never be conflated:
 *   REFUSAL THROWS AjaxUnauthorizedException - the actor may not do this at all.
 *   ABSENCE RETURNS false/0 - the actor may, but there was no such active row.
 *
 * Authorization is self-or-can_admin_role: an actor may always manage their own device
 * list, and otherwise only a role STRICTLY BELOW their own (User_Model role_id
 * can_admin_roles). A PEER therefore refuses, and so does a subordinate reaching upward -
 * both fall out of that list without a rule of their own, which is exactly why the roles
 * chosen here are ROOT_ADMIN(200) over MANAGER(500), MANAGER over MANAGER, and USER(600)
 * under MANAGER.
 *
 * Sessions are inserted as raw rows (the ownership test's approach) because these
 * functions only ever read login_user_id + active; minting real sessions would add
 * cookie/token machinery the contract does not involve. Acting identity is established
 * through the CLI impersonation seam (__acting_as_user), which is what makes
 * Session::get_user() - and therefore Permission::can_admin_role() - resolve.
 *
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Session_Terminate_For_User_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * Seed a login identity plus its site user at the given role, and return the
     * User_Model (login_user_id reachable through it).
     */
    private static function __make_user(int $role_id): User_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $login_user = new Login_User_Model();
        $login_user->email = 'terminate_for_user_' . uniqid() . '@example.com';
        $login_user->password = Login_User_Model::hash_password('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user->id;
        $user->first_name = 'Terminate';
        $user->last_name = 'Fixture';
        $user->email = $login_user->email;
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    /**
     * Insert an active _sessions row owned by $login_user_id and return its id.
     */
    private static function __insert_session(?int $login_user_id): int
    {
        return (int) DB::table('_sessions')->insertGetId([
            'session_token' => bin2hex(random_bytes(16)),
            'csrf_token'    => bin2hex(random_bytes(16)),
            'active'        => 1,
            'site_id'       => self::SITE_ID,
            'version'       => 1,
            'ip_address'    => '10.0.0.9',
            'user_agent'    => 'terminate-for-user-test',
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

    private static function __act_as(User_Model $user): void
    {
        Session::logout();
        static::__reset_session();
        static::__acting_as_user((int) $user->id);
    }

    private static function __act_as_nobody(): void
    {
        Session::logout();
        static::__reset_session();
    }

    // =====================================================================
    // terminate_session_for_user - authority granted
    // =====================================================================

    public static function test_admin_terminates_a_subordinates_session()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $admin  = self::__make_user(User_Model::ROLE_ROOT_ADMIN);
        $session_id = self::__insert_session((int) $target->login_user_id);

        self::__act_as($admin);

        static::__assert_true(
            Session::terminate_session_for_user((int) $target->login_user_id, $session_id),
            'an actor whose role may administer the target role terminates the session'
        );
        static::__assert_false(self::__is_active($session_id), 'the row is deactivated');
    }

    /**
     * Self-service through the cross-user primitive is allowed - the guard's first rule -
     * so an admin screen and a "my devices" screen can share one function.
     */
    public static function test_self_termination_through_the_cross_user_function_is_allowed()
    {
        $me = self::__make_user(User_Model::ROLE_MANAGER);
        $session_id = self::__insert_session((int) $me->login_user_id);

        self::__act_as($me);

        static::__assert_true(
            Session::terminate_session_for_user((int) $me->login_user_id, $session_id),
            'a user may always terminate their own session'
        );
        static::__assert_false(self::__is_active($session_id), 'the row is deactivated');
    }

    /**
     * ABSENCE, not refusal: the actor has authority, the session id simply does not exist.
     * This is the distinction the whole guard is built around.
     */
    public static function test_unknown_session_id_under_valid_authority_returns_false()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $admin  = self::__make_user(User_Model::ROLE_ROOT_ADMIN);

        self::__act_as($admin);

        static::__assert_false(
            Session::terminate_session_for_user((int) $target->login_user_id, 999000111),
            'no such active row is false, never an exception'
        );
    }

    /**
     * The operator's own live session is never terminable from this primitive (that is
     * logout()'s job) - and it reports false rather than throwing, because authority was
     * present.
     */
    public static function test_the_actors_own_current_session_is_refused_with_false()
    {
        $me = self::__make_user(User_Model::ROLE_MANAGER);

        self::__act_as($me);

        $current_session_id = Session::get_session_id();

        static::__assert_false(
            Session::terminate_session_for_user((int) $me->login_user_id, $current_session_id),
            'the session the operator is using is not terminable here'
        );
        static::__assert_true(self::__is_active($current_session_id), 'the current session is intact');
    }

    // =====================================================================
    // terminate_session_for_user - authority refused (THROWS)
    // =====================================================================

    public static function test_a_peer_is_refused()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $peer   = self::__make_user(User_Model::ROLE_MANAGER);
        $session_id = self::__insert_session((int) $target->login_user_id);

        self::__act_as($peer);

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () use ($target, $session_id) {
                Session::terminate_session_for_user((int) $target->login_user_id, $session_id);
            },
            'Not authorized'
        );
        static::__assert_true(self::__is_active($session_id), 'the peer session row is intact');
    }

    public static function test_a_subordinate_reaching_upward_is_refused()
    {
        $superior    = self::__make_user(User_Model::ROLE_MANAGER);
        $subordinate = self::__make_user(User_Model::ROLE_USER);
        $session_id  = self::__insert_session((int) $superior->login_user_id);

        self::__act_as($subordinate);

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () use ($superior, $session_id) {
                Session::terminate_session_for_user((int) $superior->login_user_id, $session_id);
            },
            'Not authorized'
        );
        static::__assert_true(self::__is_active($session_id), 'the superior session row is intact');
    }

    public static function test_no_acting_identity_is_refused()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $session_id = self::__insert_session((int) $target->login_user_id);

        self::__act_as_nobody();

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () use ($target, $session_id) {
                Session::terminate_session_for_user((int) $target->login_user_id, $session_id);
            },
            'logged-in actor'
        );
        static::__assert_true(self::__is_active($session_id), 'the row is intact');
    }

    /**
     * A login identity the acting site has no users row for cannot be authorized against -
     * there is no role to compare - so it is refused rather than silently permitted.
     */
    public static function test_a_target_with_no_user_on_the_acting_site_is_refused()
    {
        $admin = self::__make_user(User_Model::ROLE_ROOT_ADMIN);

        $stranger = new Login_User_Model();
        $stranger->email = 'terminate_stranger_' . uniqid() . '@example.com';
        $stranger->password = Login_User_Model::hash_password('secret-password');
        $stranger->is_activated = true;
        $stranger->is_verified = true;
        $stranger->status_id = Login_User_Model::STATUS_ACTIVE;
        $stranger->save();

        $session_id = self::__insert_session((int) $stranger->id);

        self::__act_as($admin);

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () use ($stranger, $session_id) {
                Session::terminate_session_for_user((int) $stranger->id, $session_id);
            },
            'no such user on the acting site'
        );
        static::__assert_true(self::__is_active($session_id), 'the row is intact');
    }

    // =====================================================================
    // terminate_all_sessions_for_user - the same guard, in bulk
    // =====================================================================

    public static function test_bulk_termination_by_an_admin_deactivates_every_session()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $admin  = self::__make_user(User_Model::ROLE_ROOT_ADMIN);

        $first  = self::__insert_session((int) $target->login_user_id);
        $second = self::__insert_session((int) $target->login_user_id);
        $spared = self::__insert_session((int) $target->login_user_id);

        self::__act_as($admin);

        static::__assert_equals(
            2,
            Session::terminate_all_sessions_for_user((int) $target->login_user_id, $spared),
            'every active session except the spared one is counted'
        );
        static::__assert_false(self::__is_active($first), 'first row deactivated');
        static::__assert_false(self::__is_active($second), 'second row deactivated');
        static::__assert_true(self::__is_active($spared), 'the spared row survives');
    }

    public static function test_bulk_termination_by_a_peer_is_refused()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $peer   = self::__make_user(User_Model::ROLE_MANAGER);
        $session_id = self::__insert_session((int) $target->login_user_id);

        self::__act_as($peer);

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () use ($target) {
                Session::terminate_all_sessions_for_user((int) $target->login_user_id);
            },
            'Not authorized'
        );
        static::__assert_true(self::__is_active($session_id), 'no row was touched by the refused sweep');
    }

    public static function test_bulk_termination_with_no_acting_identity_is_refused()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $session_id = self::__insert_session((int) $target->login_user_id);

        self::__act_as_nobody();

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () use ($target) {
                Session::terminate_all_sessions_for_user((int) $target->login_user_id);
            },
            'logged-in actor'
        );
        static::__assert_true(self::__is_active($session_id), 'the row is intact');
    }

    // =====================================================================
    // _deactivate_sessions_for_user - the unchecked framework-internal path
    // =====================================================================

    /**
     * The internal primitive exists for callers with NO acting user (CLI maintenance,
     * app flows carrying their own authorization), so it must work from a session-less
     * context that the guarded functions refuse outright.
     */
    public static function test_the_internal_helper_works_with_no_session_context()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $first  = self::__insert_session((int) $target->login_user_id);
        $second = self::__insert_session((int) $target->login_user_id);

        self::__act_as_nobody();

        static::__assert_equals(
            2,
            Session::_deactivate_sessions_for_user((int) $target->login_user_id),
            'both rows are deactivated without any authorization'
        );
        static::__assert_false(self::__is_active($first), 'first row deactivated');
        static::__assert_false(self::__is_active($second), 'second row deactivated');
    }

    // =====================================================================
    // session.terminated event
    // =====================================================================

    /**
     * Every terminate path announces each deactivated row. The registry's test seam
     * stands in for a manifest-discovered #[OnEvent] handler (which would otherwise be
     * live in the running application); the override is cleared in a finally so a
     * failing assertion cannot leak it into the next test.
     */
    public static function test_event_payload_for_an_admin_termination()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $admin  = self::__make_user(User_Model::ROLE_ROOT_ADMIN);
        $session_id = self::__insert_session((int) $target->login_user_id);

        self::__act_as($admin);

        $captured = [];

        Event_Registry::_set_test_handlers('session.terminated', [
            function ($data) use (&$captured) {
                $captured[] = $data;
            },
        ]);

        try {
            Session::terminate_session_for_user((int) $target->login_user_id, $session_id);
        } finally {
            Event_Registry::_clear_test_handlers();
        }

        static::__assert_count(1, $captured, 'exactly one event per deactivated row');
        static::__assert_equals((int) $admin->login_user_id, $captured[0]['actor_login_user_id'], 'actor is the admin');
        static::__assert_equals((int) $target->login_user_id, $captured[0]['target_login_user_id'], 'target is the user');
        static::__assert_equals($session_id, $captured[0]['session_id'], 'the deactivated row is named');
        static::__assert_equals('admin', $captured[0]['scope'], 'a cross-user termination is scope admin');
    }

    public static function test_event_scope_is_self_for_a_users_own_session()
    {
        $me = self::__make_user(User_Model::ROLE_MANAGER);
        $session_id = self::__insert_session((int) $me->login_user_id);

        self::__act_as($me);

        $captured = [];

        Event_Registry::_set_test_handlers('session.terminated', [
            function ($data) use (&$captured) {
                $captured[] = $data;
            },
        ]);

        try {
            Session::terminate_session((int) $session_id);
        } finally {
            Event_Registry::_clear_test_handlers();
        }

        static::__assert_count(1, $captured, 'the self-service path announces too');
        static::__assert_equals('self', $captured[0]['scope'], 'scope self');
        static::__assert_equals((int) $me->login_user_id, $captured[0]['actor_login_user_id'], 'actor is the user');
    }

    /**
     * The unchecked path names no operator, and an audit must be able to tell that apart
     * from an operator-driven termination.
     */
    public static function test_event_scope_is_internal_for_the_unchecked_path()
    {
        $target = self::__make_user(User_Model::ROLE_MANAGER);
        $session_id = self::__insert_session((int) $target->login_user_id);

        self::__act_as_nobody();

        $captured = [];

        Event_Registry::_set_test_handlers('session.terminated', [
            function ($data) use (&$captured) {
                $captured[] = $data;
            },
        ]);

        try {
            Session::_deactivate_sessions_for_user((int) $target->login_user_id);
        } finally {
            Event_Registry::_clear_test_handlers();
        }

        static::__assert_count(1, $captured, 'one event for the one row');
        static::__assert_equals('internal', $captured[0]['scope'], 'scope internal');
        static::__assert_null($captured[0]['actor_login_user_id'], 'no operator is named');
        static::__assert_equals($session_id, $captured[0]['session_id'], 'the deactivated row is named');
    }

    public static function teardown(): void
    {
        Event_Registry::_clear_test_handlers();
        Session::logout();
        static::__reset_session();
    }
}
