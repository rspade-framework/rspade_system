<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The headless, cookie-less API identity seam on Session (_set_api_identity /
 * _reset_api_identity and the accessor tier that consults it FIRST - even in CLI).
 *
 * Every test resets the identity in setup() AND teardown() so a failing assertion
 * cannot leave the static identity set and poison a later test (the API tier wins over
 * the CLI tier, so a leaked identity would silently rewrite every other Session read).
 *
 * The identity is (login_user_id, site_id, user_id) = (1, 1, 1), which are the real
 * seeded ids in the test database, though every assertion here is served from the static
 * identity or a guard throw - no row is queried.
 */
class Api_Session_Identity_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const LOGIN_USER_ID = 1;
    private const SITE_ID = 1;
    private const USER_ID = 1;

    public static function setup()
    {
        // Clear only a leaked API identity tier from a prior class. Deliberately do NOT
        // touch the ambient CLI session (site/login/user): those statics are shared suite
        // state that later classes read, and the API identity tier shadows them without
        // mutating them, so this class stays transparent to the rest of the run.
        Session::_reset_api_identity();
    }

    public static function teardown()
    {
        Session::_reset_api_identity();
    }

    private static function __establish()
    {
        // Reset first so a single test is self-contained regardless of setup()/teardown()
        // ordering - _set_api_identity() throws on a second call, so a leaked identity from
        // an earlier method would otherwise turn every subsequent establish into a failure.
        Session::_reset_api_identity();
        Session::_set_api_identity(self::LOGIN_USER_ID, self::SITE_ID, self::USER_ID);
    }

    // -------------------------------------------------------------------------
    // Accessors served from the API identity
    // -------------------------------------------------------------------------

    public static function test_accessors_return_the_established_identity()
    {
        static::__establish();

        static::__assert_equals(self::LOGIN_USER_ID, Session::get_login_user_id());
        static::__assert_equals(self::SITE_ID, Session::get_site_id());
        static::__assert_equals(self::USER_ID, Session::get_user_id());
    }

    public static function test_is_logged_in_true()
    {
        static::__establish();
        static::__assert_true(Session::is_logged_in());
    }

    public static function test_is_api_request_true()
    {
        static::__establish();
        static::__assert_true(Session::is_api_request());
    }

    // -------------------------------------------------------------------------
    // Cookie/session machinery is dead in API mode
    // -------------------------------------------------------------------------

    public static function test_session_id_is_zero()
    {
        static::__establish();
        static::__assert_equals(0, Session::get_session_id());
    }

    public static function test_csrf_token_is_null()
    {
        static::__establish();
        static::__assert_null(Session::get_csrf_token());
    }

    public static function test_has_session_false()
    {
        static::__establish();
        static::__assert_false(Session::has_session());
    }

    public static function test_get_session_throws()
    {
        static::__establish();
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Session::get_session();
            },
            'API request'
        );
    }

    // -------------------------------------------------------------------------
    // Identity is immutable in API mode
    // -------------------------------------------------------------------------

    public static function test_set_login_user_id_throws()
    {
        static::__establish();
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Session::set_login_user_id(2);
            },
            'API request'
        );
    }

    public static function test_set_site_id_throws()
    {
        static::__establish();
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Session::set_site_id(2);
            },
            'API request'
        );
    }

    public static function test_double_set_throws()
    {
        static::__establish();
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Session::_set_api_identity(9, 9, 9);
            },
            'called twice'
        );
    }

    // -------------------------------------------------------------------------
    // Reset restores the ordinary CLI tier
    // -------------------------------------------------------------------------

    public static function test_reset_clears_api_identity()
    {
        // Clear any API tier left set by an earlier method in this class FIRST, so the
        // snapshot reads the true ambient CLI identity (not a shadowing API identity) -
        // after reset the accessors must fall back to exactly that ambient.
        Session::_reset_api_identity();
        $ambient_login = Session::get_login_user_id();
        $ambient_site = Session::get_site_id();

        static::__establish();
        static::__assert_true(Session::is_api_request(), 'API tier active while established');

        Session::_reset_api_identity();

        static::__assert_false(Session::is_api_request(), 'API tier gone after reset');
        static::__assert_equals($ambient_login, Session::get_login_user_id(), 'login id falls back to the ambient CLI tier');
        static::__assert_equals($ambient_site, Session::get_site_id(), 'site id falls back to the ambient CLI tier');
    }
}
