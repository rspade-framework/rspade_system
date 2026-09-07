<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for the main-session (staff) web impersonation primitives on Session:
 * begin_impersonation() (in-place identity swap, records the real principal,
 * does not touch the target's last_login), stop_impersonation() (restore + clear
 * + no-op on repeat), is_impersonating()/get_impersonator_login_user_id()/
 * get_impersonator_login_user()/get_impersonation_started_at(), and the
 * reset()/logout() hard-clear semantics.
 *
 * The framework test runner is CLI-only, so these exercise the CLI branch of the
 * impersonation methods (mirroring how set_login_user_id()/set_site_id() are
 * tested in Session_Cli_Test). The CLI branch performs the same identity swap and
 * state bookkeeping as the web branch. Neither branch rotates the session_token or
 * csrf: those are minted once at session creation and are IMMUTABLE for the life of
 * the session (owner ruling 2026-07-24 - an identity change is a pure record
 * update, no token rotation, no cookie re-emission). The web-branch DB proof of
 * that stability lives in the http suite (see test_catalog.md).
 *
 * setup()/teardown() run once per class (not per method), and the impersonation
 * state lives in static properties (not the rolled-back transaction), so every
 * test resets the session first via __start_clean().
 *
 * DB-backed methods create real Login_User_Model/User_Model rows so get_user()
 * (and the Permission facade that delegates to it) resolve the effective identity.
 * Runs in the default per-test transaction (rolled back afterward).
 */
class Session_Impersonation_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * Reset all CLI session state to a clean slate. logout() clears the login
     * identity AND the impersonation statics; __reset_session() clears the
     * site/experience context. Called at the top of every test because
     * setup()/teardown() are per-class and static state does not roll back.
     */
    private static function __start_clean(): void
    {
        Session::logout();
        Session::cli_set_impersonator_login_user_id(null);
        static::__reset_session();
    }

    /**
     * Seed a login identity (authentication row). Password value is irrelevant -
     * these tests never authenticate, they only resolve identity.
     */
    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'impersonate_' . uniqid() . '@example.com';
        $login_user->password = Login_User_Model::hash_password('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * Seed a site-specific user bound to a login identity. User_Model is
     * site-scoped, so the site is impersonated for the current test first.
     */
    private static function __make_site_user(int $login_user_id, int $role_id): User_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = $login_user_id;
        $user->first_name = 'Imp';
        $user->last_name = 'Tester';
        $user->email = 'siteuser_' . uniqid() . '@example.com';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    // =====================================================================
    // begin_impersonation - happy path
    // =====================================================================

    public static function test_begin_impersonation_swaps_identity_and_flags()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);

        Session::begin_impersonation(2002);

        static::__assert_true(Session::is_impersonating(), 'session reports impersonating');
        static::__assert_equals(2002, Session::get_login_user_id(), 'effective identity is the target');
        static::__assert_equals(1001, Session::get_impersonator_login_user_id(), 'impersonator is the original principal');
        static::__assert_not_null(Session::get_impersonation_started_at(), 'started_at is recorded');
        static::__assert_true(Session::is_logged_in(), 'still logged in while impersonating');
    }

    // =====================================================================
    // stop_impersonation - restore, clear, no-op on repeat
    // =====================================================================

    public static function test_stop_impersonation_restores_and_clears()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);
        Session::begin_impersonation(2002);

        static::__assert_true(Session::stop_impersonation(), 'stop returns true when it ends an impersonation');

        static::__assert_false(Session::is_impersonating(), 'no longer impersonating after stop');
        static::__assert_equals(1001, Session::get_login_user_id(), 'original principal restored');
        static::__assert_null(Session::get_impersonator_login_user_id(), 'impersonator cleared');
        static::__assert_null(Session::get_impersonation_started_at(), 'started_at cleared');
    }

    public static function test_stop_impersonation_is_noop_when_not_impersonating()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);

        static::__assert_false(Session::stop_impersonation(), 'stop is a no-op (false) when not impersonating');
        static::__assert_equals(1001, Session::get_login_user_id(), 'identity unchanged by the no-op stop');
    }

    // =====================================================================
    // Guards - nesting and self-impersonation throw
    // =====================================================================

    public static function test_nested_begin_impersonation_throws()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);
        Session::begin_impersonation(2002);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Session::begin_impersonation(3003),
            'already impersonating'
        );
    }

    public static function test_self_impersonation_throws()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Session::begin_impersonation(1001),
            'cannot impersonate the current login user'
        );
    }

    public static function test_begin_impersonation_without_session_throws()
    {
        static::__start_clean();

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Session::begin_impersonation(2002),
            'requires an active logged-in session'
        );
    }

    // =====================================================================
    // Hard exits (logout / reset) fully clear impersonation
    // =====================================================================

    public static function test_logout_mid_impersonation_clears_everything()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);
        Session::begin_impersonation(2002);

        Session::logout();

        static::__assert_false(Session::is_impersonating(), 'not impersonating after logout');
        static::__assert_null(Session::get_login_user_id(), 'logged out (no effective identity)');
        static::__assert_null(Session::get_impersonator_login_user_id(), 'impersonator cleared by logout');
        static::__assert_null(Session::get_impersonation_started_at(), 'started_at cleared by logout');
    }

    public static function test_reset_mid_impersonation_clears_impersonation()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);
        Session::begin_impersonation(2002);

        Session::reset();

        static::__assert_false(Session::is_impersonating(), 'not impersonating after reset');
        static::__assert_null(Session::get_impersonator_login_user_id(), 'impersonator cleared by reset');
        static::__assert_null(Session::get_impersonation_started_at(), 'started_at cleared by reset');
    }

    // =====================================================================
    // DB-backed: effective user resolves the target; last_login untouched
    // =====================================================================

    public static function test_get_user_resolves_target_and_impersonator_model()
    {
        static::__start_clean();
        static::__acting_as_site(self::SITE_ID);

        $admin_login = static::__make_login_user();
        $admin_user = static::__make_site_user($admin_login->id, User_Model::ROLE_SITE_ADMIN);

        $target_login = static::__make_login_user();
        $target_user = static::__make_site_user($target_login->id, User_Model::ROLE_USER);

        Session::set_login_user_id($admin_login->id);
        static::__assert_equals($admin_user->id, Session::get_user()->id, 'baseline: effective user is the admin');

        Session::begin_impersonation($target_login->id);

        static::__assert_equals($target_login->id, Session::get_login_user_id(), 'effective login identity is the target');
        static::__assert_equals($target_user->id, Session::get_user()->id, 'get_user() resolves the target site user');

        $impersonator = Session::get_impersonator_login_user();
        static::__assert_not_null($impersonator, 'impersonator model resolves');
        static::__assert_equals($admin_login->id, $impersonator->id, 'impersonator model is the original admin identity');
    }

    public static function test_begin_impersonation_does_not_touch_target_last_login()
    {
        static::__start_clean();
        static::__acting_as_site(self::SITE_ID);

        $admin_login = static::__make_login_user();
        static::__make_site_user($admin_login->id, User_Model::ROLE_SITE_ADMIN);

        $target_login = static::__make_login_user();
        static::__assert_null($target_login->last_login, 'seeded target has no last_login');

        Session::set_login_user_id($admin_login->id);
        Session::begin_impersonation($target_login->id);

        $reloaded = Login_User_Model::find($target_login->id);
        static::__assert_null($reloaded->last_login, 'impersonation must not pollute the target last_login');
    }

    // =====================================================================
    // Permission facade composes with impersonation (Batch 2 integration)
    // =====================================================================

    public static function test_permission_facade_reflects_impersonated_target()
    {
        static::__start_clean();
        static::__acting_as_site(self::SITE_ID);

        $admin_login = static::__make_login_user();
        static::__make_site_user($admin_login->id, User_Model::ROLE_SITE_ADMIN);

        $target_login = static::__make_login_user();
        $target_user = static::__make_site_user($target_login->id, User_Model::ROLE_USER);

        Session::set_login_user_id($admin_login->id);
        Session::begin_impersonation($target_login->id);

        // Permission facade delegates to Session::get_user(), which reads the live
        // (impersonated) identity - so it must report the TARGET, not the admin.
        $permission_user = Permission::get_user();
        static::__assert_not_null($permission_user, 'Permission facade resolves a user under impersonation');
        static::__assert_equals($target_user->id, $permission_user->id, 'Permission facade reflects the impersonated target identity');
    }

    // =====================================================================
    // window.rsxapp.impersonation payload shape (as built by Rsx_Bundle_Abstract)
    // =====================================================================

    public static function test_rsxapp_impersonation_shape_when_impersonating()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);
        Session::begin_impersonation(2002);

        // Mirror the exact conditional Rsx_Bundle_Abstract uses to populate
        // window.rsxapp.impersonation for staff requests.
        $block = Session::is_impersonating() ? [
            'impersonator_login_user_id' => Session::get_impersonator_login_user_id(),
            'started_at' => Session::get_impersonation_started_at(),
        ] : null;

        static::__assert_not_null($block, 'impersonation block present while impersonating');
        static::__assert_equals(1001, $block['impersonator_login_user_id'], 'block carries the impersonator id');
        static::__assert_not_null($block['started_at'], 'block carries started_at');
    }

    public static function test_rsxapp_impersonation_null_when_not_impersonating()
    {
        static::__start_clean();
        Session::set_login_user_id(1001);

        $block = Session::is_impersonating() ? [
            'impersonator_login_user_id' => Session::get_impersonator_login_user_id(),
            'started_at' => Session::get_impersonation_started_at(),
        ] : null;

        static::__assert_null($block, 'impersonation block is null for a normal session');
    }
}
