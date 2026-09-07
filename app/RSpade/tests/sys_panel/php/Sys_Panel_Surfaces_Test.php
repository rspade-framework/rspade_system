<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The control panel's surfaces, as the manifest actually builds them.
 *
 * The panel is a framework-shipped RSpade application, so what it declares is
 * proved the same way any application's declarations would be: against the
 * persisted route index, not against the source files.
 */
class Sys_Panel_Surfaces_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Route pattern => the action class that serves it. The whole panel, so a page
     * added or removed without a decision shows up here.
     */
    private const SCREENS = [
        '/_sys'             => '_Sys_Dashboard_Action',
        '/_sys/debug-flags' => '_Sys_Debug_Flags_Action',
        '/_sys/tasks'       => '_Sys_Tasks_Action',
        '/_sys/email'       => '_Sys_Email_Action',
        '/_sys/logs'        => '_Sys_Logs_Action',
        '/_sys/sites'       => '_Sys_Sites_Action',
        '/_sys/users'       => '_Sys_Users_Action',
    ];

    private static function __routes(): array
    {
        $manifest = Manifest::get_full_manifest();

        return $manifest['data']['routes'] ?? [];
    }

    /**
     * RP-SPA-01 - Every panel screen is a SPA route on the panel's bootstrap
     * controller, gated on is_sysadmin, laid out by _Sys_Layout.
     */
    public static function test_every_panel_screen_is_indexed()
    {
        $routes = static::__routes();

        foreach (self::SCREENS as $pattern => $action_class) {
            static::__assert_array_has_key($pattern, $routes, "panel route {$pattern} is not indexed");

            $row = $routes[$pattern];

            static::__assert_equals('spa', $row['type'], "{$pattern} must be a SPA route");
            static::__assert_equals($action_class, $row['js_action_class'], "{$pattern} serves the wrong action");
            static::__assert_true(
                in_array('is_sysadmin', $row['auth'], true),
                "{$pattern} bootstrap is not gated on is_sysadmin"
            );
            static::__assert_true(
                in_array('is_sysadmin', $row['auth_action'], true),
                "{$pattern} action is not gated on is_sysadmin"
            );
            static::__assert_equals(
                'App\\RSpade\\Sys\\App\\Sys\\_Sys_Spa_Controller',
                $row['class'],
                "{$pattern} is bootstrapped by the wrong controller"
            );
        }
    }

    /**
     * RP-SPA-02 - The panel has EXACTLY these seven screens: no eighth SPA route
     * arrived on the panel's bootstrap controller unnoticed.
     */
    public static function test_the_panel_has_exactly_seven_screens()
    {
        $found = [];

        foreach (static::__routes() as $pattern => $row) {
            if (($row['type'] ?? null) === 'spa'
                && ($row['class'] ?? null) === 'App\\RSpade\\Sys\\App\\Sys\\_Sys_Spa_Controller') {
                $found[] = $pattern;
            }
        }

        sort($found);
        $expected = array_keys(self::SCREENS);
        sort($expected);

        static::__assert_equals($expected, $found);
    }

    /**
     * RP-SPA-03 - Every panel action declares _Sys_Layout.
     *
     * The layout is a DECORATOR on the JS action, so it is read from the action's
     * own file metadata rather than from the route row.
     */
    public static function test_every_panel_action_declares_the_panel_layout()
    {
        $manifest = Manifest::get_full_manifest();
        $files = $manifest['data']['files'] ?? [];

        foreach (self::SCREENS as $pattern => $action_class) {
            $metadata = null;

            foreach ($files as $path => $entry) {
                if (($entry['class'] ?? null) === $action_class && ($entry['extension'] ?? null) === 'js') {
                    $metadata = $entry;
                    break;
                }
            }

            static::__assert_not_empty($metadata, "{$action_class} is not indexed");

            $layouts = [];

            foreach ($metadata['decorators'] ?? [] as $decorator) {
                if (($decorator[0] ?? null) === 'layout') {
                    $layouts = $decorator[1] ?? [];
                }
            }

            static::__assert_equals(['_Sys_Layout'], $layouts, "{$action_class} declares the wrong layout");
        }
    }

    /**
     * RP-ROUTE-01 - The panel's one server-rendered route: logout, gated on is_sysadmin.
     */
    public static function test_logout_route_is_indexed_and_gated()
    {
        $routes = static::__routes();

        static::__assert_array_has_key('/_sys/logout', $routes);

        $row = $routes['/_sys/logout'];

        static::__assert_equals('standard', $row['type']);
        static::__assert_equals('App\\RSpade\\Sys\\App\\Sys\\_Sys_Controller', $row['class']);
        static::__assert_equals('logout', $row['method']);
        static::__assert_equals(['GET'], $row['methods']);
        static::__assert_true(in_array('is_sysadmin', $row['auth'], true));
    }
}
