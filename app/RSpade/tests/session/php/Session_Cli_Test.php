<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for Session static methods in CLI/test mode.
 *
 * In CLI mode the session class never touches the database or cookies.
 * All state lives in static properties and is set/read through the same
 * API as web mode. This test class exercises that path exclusively.
 *
 * No database writes required - per-test transaction rollback is fine.
 */
class Session_Cli_Test extends Rsx_Test_Abstract
{
    /**
     * Reset impersonation after every test so static state does not bleed
     * across methods. setup() and teardown() run outside the per-test
     * transaction but that does not matter here (no DB writes).
     */
    public static function setup()
    {
        Session::reset_impersonation();
    }

    public static function teardown()
    {
        Session::reset_impersonation();
    }

    // -------------------------------------------------------------------------
    // Initial state - before any impersonation
    // -------------------------------------------------------------------------

    public static function test_initial_state_returns_null_user_id()
    {
        static::__reset_session();
        static::__assert_null(Session::get_user_id());
    }

    public static function test_initial_state_returns_zero_site_id()
    {
        static::__reset_session();
        static::__assert_equals(0, Session::get_site_id());
    }

    public static function test_initial_state_is_not_logged_in()
    {
        static::__reset_session();
        static::__assert_false(Session::is_logged_in());
    }

    public static function test_initial_state_has_no_session()
    {
        static::__reset_session();
        static::__assert_false(Session::has_session());
    }

    // -------------------------------------------------------------------------
    // set_site_id / get_site_id
    // -------------------------------------------------------------------------

    public static function test_set_site_id_is_returned_by_get_site_id()
    {
        Session::set_site_id(42);
        static::__assert_equals(42, Session::get_site_id());
    }

    public static function test_has_session_true_after_set_site_id()
    {
        Session::set_site_id(10);
        static::__assert_true(Session::has_session());
    }

    // -------------------------------------------------------------------------
    // set_login_user_id / get_login_user_id / is_logged_in
    // -------------------------------------------------------------------------

    public static function test_set_login_user_id_is_returned_by_get_login_user_id()
    {
        Session::set_login_user_id(99);
        static::__assert_equals(99, Session::get_login_user_id());
    }

    public static function test_is_logged_in_true_after_set_login_user_id()
    {
        Session::set_login_user_id(99);
        static::__assert_true(Session::is_logged_in());
    }

    public static function test_logout_clears_login_user_id()
    {
        Session::set_login_user_id(99);
        static::__assert_true(Session::is_logged_in());

        Session::logout();
        static::__assert_false(Session::is_logged_in());
        static::__assert_null(Session::get_login_user_id());
    }

    public static function test_set_login_user_id_null_clears_user()
    {
        Session::set_login_user_id(5);
        Session::set_login_user_id(null);
        static::__assert_null(Session::get_login_user_id());
        static::__assert_false(Session::is_logged_in());
    }

    // -------------------------------------------------------------------------
    // impersonate() / reset_impersonation()
    // -------------------------------------------------------------------------

    public static function test_impersonate_sets_all_three_values()
    {
        Session::impersonate(7, 33, 55);
        static::__assert_equals(7, Session::get_site_id());
        static::__assert_equals(33, Session::get_login_user_id());
        static::__assert_equals(55, Session::get_user_id());
    }

    public static function test_impersonate_makes_is_logged_in_true()
    {
        Session::impersonate(1, 10, 20);
        static::__assert_true(Session::is_logged_in());
    }

    public static function test_reset_impersonation_clears_all_values()
    {
        Session::impersonate(7, 33, 55);
        Session::reset_impersonation();

        static::__assert_equals(0, Session::get_site_id());
        static::__assert_null(Session::get_login_user_id());
        static::__assert_null(Session::get_user_id());
        static::__assert_false(Session::is_logged_in());
        static::__assert_false(Session::has_session());
    }

    // -------------------------------------------------------------------------
    // Acting-as helpers (from Rsx_Test_Abstract)
    // -------------------------------------------------------------------------

    public static function test_acting_as_site_sets_site_id()
    {
        static::__acting_as_site(123);
        static::__assert_equals(123, Session::get_site_id());
    }

    public static function test_reset_session_clears_site()
    {
        static::__acting_as_site(123);
        static::__reset_session();
        static::__assert_equals(0, Session::get_site_id());
    }

    // -------------------------------------------------------------------------
    // get_user() behavior in CLI mode
    // -------------------------------------------------------------------------

    public static function test_get_user_returns_null_when_no_login_user_id()
    {
        static::__reset_session();
        static::__assert_null(Session::get_user());
    }

    public static function test_get_user_returns_null_when_no_site_id()
    {
        // login_user_id set but no site_id - get_user() needs both
        Session::set_login_user_id(999);
        static::__assert_null(Session::get_user());
    }

    // -------------------------------------------------------------------------
    // get_login_user() behavior in CLI mode
    // -------------------------------------------------------------------------

    public static function test_get_login_user_returns_null_when_not_logged_in()
    {
        static::__reset_session();
        static::__assert_null(Session::get_login_user());
    }

    // -------------------------------------------------------------------------
    // get_site() in CLI mode
    // -------------------------------------------------------------------------

    public static function test_get_site_returns_null_when_site_id_zero()
    {
        static::__reset_session();
        static::__assert_null(Session::get_site());
    }

    // -------------------------------------------------------------------------
    // CSRF in CLI mode - no session, so null / false
    // -------------------------------------------------------------------------

    public static function test_get_csrf_token_returns_null_in_cli_without_session()
    {
        static::__reset_session();
        static::__assert_null(Session::get_csrf_token());
    }

    public static function test_verify_csrf_token_returns_false_without_session()
    {
        static::__reset_session();
        static::__assert_false(Session::verify_csrf_token('anything'));
    }

    // -------------------------------------------------------------------------
    // has_session() logic in CLI mode
    // The code checks: $_cli_site_id !== null OR $_cli_user_id !== null
    // (not login_user_id)
    // -------------------------------------------------------------------------

    public static function test_has_session_true_when_user_id_set_via_impersonate()
    {
        // impersonate sets $_cli_user_id
        Session::impersonate(0, null, 55);
        static::__assert_true(Session::has_session());
    }

    public static function test_has_session_false_when_only_login_user_id_set()
    {
        // Start from a clean slate - prior test may have set user_id via impersonate
        static::__reset_session();

        // set_login_user_id sets $_cli_login_user_id but NOT $_cli_user_id
        // has_session checks site_id OR user_id, NOT login_user_id
        Session::set_login_user_id(100);
        // login_user_id is set but neither site_id nor user_id - has_session is false
        static::__assert_false(Session::has_session());
    }
}
