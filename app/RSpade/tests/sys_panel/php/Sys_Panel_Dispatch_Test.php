<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The panel at the dispatch seam: the config switch, the gate, and logout.
 *
 * Driven through Dispatcher::dispatch() in process, the technique the auth-gate
 * seam tests use - the route row is what the dispatcher reads, and no test seam
 * can rewrite it.
 */
class Sys_Panel_Dispatch_Test extends Rsx_Test_Abstract
{
    /**
     * One page may render one bundle per process (Rsx_Bundle_Abstract enforces it
     * against a page rendering two). A test process dispatches several pages, so
     * the marker is cleared between them.
     */
    private static function __dispatch(string $url, string $method = 'GET')
    {
        Rsx_Bundle_Abstract::$_has_rendered = null;

        return Dispatcher::dispatch($url, $method, [], Request::create($url, $method));
    }

    /**
     * RP-CONFIG-01 - With rsx.sys_panel.enabled false, the panel is NOT FOUND -
     * for a signed-in user who would otherwise pass the gate.
     */
    public static function test_disabled_panel_is_not_found()
    {
        static::__acting_as_user(1);

        config(['rsx.sys_panel.enabled' => false]);

        try {
            $response = static::__dispatch('/_sys');

            static::__assert_equals(404, $response->getStatusCode());
        } finally {
            config(['rsx.sys_panel.enabled' => true]);
            static::__reset_session();
        }
    }

    /**
     * RP-CONFIG-02 - Enabled, a signed-in identity gets the panel.
     */
    public static function test_enabled_panel_renders_for_a_signed_in_user()
    {
        static::__acting_as_user(1);

        config(['rsx.sys_panel.enabled' => true]);

        try {
            $response = static::__dispatch('/_sys');

            static::__assert_equals(200, $response->getStatusCode());
            static::__assert_contains('_Sys_Bundle', $response->getContent());
        } finally {
            static::__reset_session();
        }
    }

    /**
     * RP-AUTH-01 - Anonymous, the is_sysadmin gate denies before pre_dispatch runs:
     * an unidentified caller is sent to the login route rather than shown a 403,
     * because re-authenticating is exactly what would fix it.
     */
    public static function test_anonymous_caller_is_sent_to_login()
    {
        static::__reset_session();

        $response = static::__dispatch('/_sys');

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/login', $response->headers->get('Location'));
    }

    /**
     * RP-LOGOUT-01 - /_sys/logout clears the session identity and leaves the panel.
     */
    public static function test_logout_clears_the_session_identity()
    {
        static::__acting_as_user(1);

        static::__assert_true(Session::is_logged_in(), 'the fixture identity did not take');

        $response = static::__dispatch('/_sys/logout');

        static::__assert_equals(302, $response->getStatusCode());
        // redirect('/') resolves against APP_URL, so compare against the same helper.
        static::__assert_equals(url('/'), $response->headers->get('Location'));
        static::__assert_false(Session::is_logged_in(), 'logout left an identity behind');

        static::__reset_session();
    }
}
