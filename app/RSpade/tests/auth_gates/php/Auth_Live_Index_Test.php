<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The auth index as the framework actually builds it.
 *
 * Auth_Index_Test proves the algorithm over synthetic metadata; this class proves
 * the wiring - that the support module is registered, that the built-ins are in
 * both realm registries (the portal ones inherited by the template app's real
 * Portal_Permission), and that a real declaration's class-level and method-level
 * #[Auth] merge in the persisted manifest.
 */
class Auth_Live_Index_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The support module is registered and produced both halves of the index.
     */
    public static function test_auth_index_exists()
    {
        $index = Auth_Gates::get_index();

        static::__assert_array_has_key('checks', $index);
        static::__assert_array_has_key('surfaces', $index);
        static::__assert_greater_than(0, count($index['surfaces']));
    }

    /**
     * 'public', 'closed' and 'is_logged_in' are the built-ins every application
     * inherits, in both realms.
     */
    public static function test_builtin_checks_registered_in_both_realms()
    {
        foreach ([Auth_Gates::REALM_STAFF, Auth_Gates::REALM_PORTAL] as $realm) {
            $checks = Auth_Gates::get_checks($realm);

            static::__assert_array_has_key('public', $checks, "Missing 'public' in {$realm}");
            static::__assert_array_has_key('closed', $checks, "Missing 'closed' in {$realm}");
            static::__assert_array_has_key('is_logged_in', $checks, "Missing 'is_logged_in' in {$realm}");
        }
    }

    /**
     * The staff built-ins resolve to Permission_Abstract, which is where they are
     * declared and therefore the body that runs.
     */
    public static function test_staff_builtins_resolve_to_the_framework_base()
    {
        $checks = Auth_Gates::get_checks(Auth_Gates::REALM_STAFF);

        static::__assert_equals(
            'App\\RSpade\\Core\\Permission\\Permission_Abstract',
            $checks['public']['class']
        );
        static::__assert_equals(
            'App\\RSpade\\Core\\Permission\\Permission_Abstract',
            $checks['is_logged_in']['class']
        );
        static::__assert_equals(
            'App\\RSpade\\Core\\Permission\\Permission_Abstract',
            $checks['closed']['class']
        );
    }

    /**
     * The portal built-ins resolve to the framework base against real code: the
     * template app's Portal_Permission inherits them rather than restating them, so
     * the registry resolves the base declaration - the body a static call executes.
     * (Most-derived-wins itself is proved over synthetic metadata in Auth_Index_Test.)
     */
    public static function test_portal_builtins_resolve_to_the_framework_base()
    {
        $checks = Auth_Gates::get_checks(Auth_Gates::REALM_PORTAL);

        static::__assert_equals(
            'App\\RSpade\\Core\\Portal\\Portal_Permission_Abstract',
            $checks['is_logged_in']['class']
        );
        static::__assert_equals(
            'App\\RSpade\\Core\\Portal\\Portal_Permission_Abstract',
            $checks['public']['class']
        );
        static::__assert_equals(
            'App\\RSpade\\Core\\Portal\\Portal_Permission_Abstract',
            $checks['closed']['class']
        );
    }

    /**
     * The built-in 'public' check grants, and the gate engine reaches it through the
     * live registry (no test index installed).
     */
    public static function test_public_check_evaluates_true_in_both_realms()
    {
        Auth_Gates::_reset_for_testing();

        static::__assert_true(Auth_Gates::evaluate('public', Auth_Gates::REALM_STAFF));
        static::__assert_true(Auth_Gates::evaluate('public', Auth_Gates::REALM_PORTAL));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * The built-in 'closed' check DENIES, in both realms, through the live registry.
     *
     * It is the counterpart to 'public' and the whole point is that nothing opens
     * it: no mode, no identity, no configuration. A surface declaring it is
     * unreachable by declaration, which is a thing the template's dev showcase
     * relies on being true (B-99).
     */
    public static function test_closed_check_evaluates_false_in_both_realms()
    {
        Auth_Gates::_reset_for_testing();

        static::__assert_false(Auth_Gates::evaluate('closed', Auth_Gates::REALM_STAFF));
        static::__assert_false(Auth_Gates::evaluate('closed', Auth_Gates::REALM_PORTAL));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A real declaration's class-level and method-level #[Auth] merge in the
     * persisted index, in order, without repeats.
     */
    public static function test_live_surface_merges_class_and_method_gates()
    {
        $surfaces = Auth_Gates::get_surfaces();

        static::__assert_array_has_key('Auth_Gates_Surface_Fixture::merged_gates', $surfaces);
        static::__assert_equals(
            ['is_logged_in', 'is_sysadmin'],
            $surfaces['Auth_Gates_Surface_Fixture::merged_gates']['auth']
        );

        static::__assert_array_has_key('Auth_Gates_Surface_Fixture::class_gate_only', $surfaces);
        static::__assert_equals(
            ['is_logged_in'],
            $surfaces['Auth_Gates_Surface_Fixture::class_gate_only']['auth']
        );
    }

    /**
     * Real surfaces of every PHP kind are indexed with the expected realm.
     */
    public static function test_real_surfaces_are_indexed_with_their_realm()
    {
        $surfaces = Auth_Gates::get_surfaces();

        // Framework-declared surfaces only, one per PHP kind: this concern's own routed
        // fixture, the panel's #[SPA] bootstrap controller, the ORM ajax endpoint and a
        // framework model's fetch(). An application declares none of these under a name
        // this test could predict.
        $expected = [
            'Auth_Gates_Seam_Fixture_Controller::open' => ['route', 'staff'],
            '_Sys_Spa_Controller::index' => ['spa', 'staff'],
            '_Sys_Dashboard_Action' => ['js_action', 'staff'],
            'Orm_Controller::fetch' => ['ajax', 'any'],
            'User_Model::fetch' => ['model_fetch', 'staff'],
        ];

        foreach ($expected as $target => $spec) {
            static::__assert_array_has_key($target, $surfaces, "Missing surface {$target}");
            static::__assert_true(
                in_array($spec[0], $surfaces[$target]['kinds'], true),
                "Wrong kind for {$target}"
            );
            static::__assert_equals($spec[1], $surfaces[$target]['realm'], "Wrong realm for {$target}");
        }
    }

    /**
     * The JS pass runs: every @route Spa_Action is a surface, realm-tagged by
     * whether it declares @spa or @portal_spa. Their gate lists are empty until the
     * @auth decorator exists and the actions are annotated.
     */
    public static function test_js_action_surfaces_are_indexed_per_realm()
    {
        $surfaces = Auth_Gates::get_surfaces();

        $staff_actions = 0;
        $portal_actions = 0;

        foreach ($surfaces as $entry) {
            if (in_array('js_action', $entry['kinds'], true)) {
                static::__assert_equals('staff', $entry['realm']);
                $staff_actions++;
            }
            if (in_array('portal_js_action', $entry['kinds'], true)) {
                static::__assert_equals('portal', $entry['realm']);
                $portal_actions++;
            }
        }

        static::__assert_greater_than(0, $staff_actions);
        static::__assert_greater_than(0, $portal_actions);
    }

    /**
     * A ROUTE ROW NAMES ITS SURFACE; the gate list lives in the surface index and nowhere
     * else.
     *
     * It used to be stored a third time on the row itself, and this test asserted the two
     * copies agreed. They cannot disagree now - there is one copy - so what is left to
     * assert is that the reference RESOLVES, which is what every dispatcher relies on
     * (Auth_Gates::surface_gates($route['surface'])).
     */
    public static function test_route_rows_reference_an_indexed_surface()
    {
        $routes = \App\RSpade\Core\Manifest\Manifest::get_routes();
        $surfaces = Auth_Gates::get_surfaces();

        foreach ($routes as $pattern => $route) {
            if (($route['type'] ?? '') !== 'standard') {
                continue;
            }

            static::__assert_false(
                array_key_exists('auth', $route),
                "Route {$pattern} must not carry its own gate list"
            );

            $simple_class = \App\RSpade\Core\Manifest\Manifest::_normalize_class_name($route['class']);
            $target = $simple_class . '::' . $route['method'];

            static::__assert_equals(
                $target,
                $route['surface'] ?? null,
                "Route {$pattern} names the wrong surface"
            );

            static::__assert_array_has_key($target, $surfaces, "Route {$pattern} missing from surfaces");

            static::__assert_equals(
                $surfaces[$target]['auth'],
                Auth_Gates::surface_gates($route['surface']),
                "surface_gates() must resolve the row's gate list for {$target}"
            );
        }
    }
}
