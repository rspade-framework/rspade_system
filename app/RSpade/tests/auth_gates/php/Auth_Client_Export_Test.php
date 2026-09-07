<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\AuthGates\Php\Auth_Gates_Check_Fixture;

/**
 * The CLIENT EXPORT builders behind window.rsxapp.auth / auth_routes.
 *
 * Auth_Gates::export_grants() and ::export_route_grants() are the whole payload
 * computation; the rsxapp assembler only chooses the realm and consults the config
 * flag. Factoring them out is what lets the grants-only, gateless-exclusion and
 * live-evaluation semantics be proven here instead of through a browser.
 *
 * Every test installs a synthetic index through Auth_Gates::_set_index_for_testing()
 * so no probe name enters the application's real vocabulary.
 *
 * NOTE: test_route_grants_excludes_surface_with_unknown_check deliberately drives an
 * unresolvable gate name through the export, which logs one Log::warning by design
 * (seam semantics: an unsatisfiable gate denies loudly rather than throwing during a
 * page render).
 */
class Auth_Client_Export_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FIXTURE = Auth_Gates_Check_Fixture::class;

    // =========================================================================
    // GRANTS MAP (window.rsxapp.auth)
    // =========================================================================

    /**
     * The map lists ONLY granted names, each with the value 1. A denied check is
     * omitted entirely - page source must never enumerate what a user lacks.
     */
    public static function test_grants_map_is_grants_only()
    {
        static::__install();

        $grants = Auth_Gates::export_grants(Auth_Gates::REALM_STAFF);

        static::__assert_equals(
            ['counted' => 1, 'grants' => 1, 'shared_check' => 1],
            $grants
        );

        // Explicitly: no key at all for the denying checks, not even => false.
        static::__assert_false(array_key_exists('denies', $grants));
        static::__assert_false(array_key_exists('returns_one', $grants));
        static::__assert_false(array_key_exists('returns_nothing', $grants));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * Realm decides meaning: the same name is a different check per realm, and the
     * portal payload carries the portal answers.
     */
    public static function test_grants_map_is_realm_scoped()
    {
        static::__install();

        $staff = Auth_Gates::export_grants(Auth_Gates::REALM_STAFF);
        $portal = Auth_Gates::export_grants(Auth_Gates::REALM_PORTAL);

        // 'shared_check' grants for staff and denies for portal.
        static::__assert_true(array_key_exists('shared_check', $staff));
        static::__assert_false(array_key_exists('shared_check', $portal));

        // A staff-only name never appears in the portal map.
        static::__assert_false(array_key_exists('returns_one', $portal));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * Building the export EVALUATES, every time: nothing an earlier ask computed is
     * reused, because a reused answer is one that can outlive its identity. The
     * price is one pass over the realm's checks per build, which is the cost the
     * check-author contract (cheap, in-memory bodies) is written to keep small.
     */
    public static function test_grants_map_evaluates_live_on_every_build()
    {
        static::__install();
        Auth_Gates_Check_Fixture::reset_counter();

        // The seam evaluates first, as it does during a real page render...
        Auth_Gates::evaluate('counted', Auth_Gates::REALM_STAFF);
        static::__assert_equals(1, Auth_Gates_Check_Fixture::$call_count);

        // ...then the assembler builds the map, twice - each build runs the body again.
        Auth_Gates::export_grants(Auth_Gates::REALM_STAFF);
        static::__assert_equals(2, Auth_Gates_Check_Fixture::$call_count);

        Auth_Gates::export_grants(Auth_Gates::REALM_STAFF);
        static::__assert_equals(3, Auth_Gates_Check_Fixture::$call_count);

        Auth_Gates_Check_Fixture::reset_counter();
        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // ROUTE GRANTS MAP (window.rsxapp.auth_routes)
    // =========================================================================

    /**
     * Only PHP PAGE surfaces of the realm appear - #[Route] and #[SPA] for staff.
     * Ajax/API/model surfaces are not link targets and never appear.
     */
    public static function test_route_grants_covers_page_surfaces_only()
    {
        static::__install();

        $routes = Auth_Gates::export_route_grants(Auth_Gates::REALM_STAFF);

        static::__assert_true(array_key_exists('Export_Open_Controller::index', $routes));
        static::__assert_true(array_key_exists('Export_Spa_Controller::index', $routes));
        static::__assert_equals(1, $routes['Export_Open_Controller::index']);

        static::__assert_false(array_key_exists('Export_Ajax_Controller::save', $routes));
        static::__assert_false(array_key_exists('Export_Model::fetch', $routes));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A surface whose gates deny is omitted - the map is grants-only, exactly like
     * the check map.
     */
    public static function test_route_grants_omits_denied_surfaces()
    {
        static::__install();

        $routes = Auth_Gates::export_route_grants(Auth_Gates::REALM_STAFF);

        static::__assert_false(array_key_exists('Export_Closed_Controller::index', $routes));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A GATELESS surface is excluded. "No gate" is an un-migrated declaration, not a
     * statement that everyone may reach the page; listing it would let the map assert
     * reachability the code never declared.
     */
    public static function test_route_grants_excludes_gateless_surfaces()
    {
        static::__install();

        $routes = Auth_Gates::export_route_grants(Auth_Gates::REALM_STAFF);

        static::__assert_false(array_key_exists('Export_Ungated_Controller::index', $routes));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A gate name that resolves in no realm denies the surface rather than throwing:
     * a misconfigured page must not 500 the render of a page that merely links to it.
     */
    public static function test_route_grants_excludes_surface_with_unknown_check()
    {
        static::__install();

        $routes = Auth_Gates::export_route_grants(Auth_Gates::REALM_STAFF);

        static::__assert_false(array_key_exists('Export_Broken_Controller::index', $routes));

        Auth_Gates::_reset_for_testing();
    }

    /**
     * The portal export covers #[Portal_Route] surfaces and never leaks staff pages
     * (and vice versa).
     */
    public static function test_route_grants_is_realm_scoped()
    {
        static::__install();

        $staff = Auth_Gates::export_route_grants(Auth_Gates::REALM_STAFF);
        $portal = Auth_Gates::export_route_grants(Auth_Gates::REALM_PORTAL);

        static::__assert_false(array_key_exists('Export_Portal_Controller::index', $staff));
        static::__assert_true(array_key_exists('Export_Portal_Controller::index', $portal));
        static::__assert_false(array_key_exists('Export_Open_Controller::index', $portal));

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // CONFIG
    // =========================================================================

    /**
     * The PHP-route export is OFF by default; when it is off the assembler never
     * defines window.rsxapp.auth_routes at all.
     */
    public static function test_php_route_grant_export_defaults_off()
    {
        static::__assert_false(config('rsx.auth.export_php_route_grants') === true);
    }

    // =========================================================================
    // FIXTURE INDEX
    // =========================================================================

    /**
     * Install the synthetic check registry and surface index.
     */
    private static function __install(): void
    {
        $checks = [
            Auth_Gates::REALM_STAFF => [
                'grants' => ['class' => self::FIXTURE, 'method' => 'grants'],
                'denies' => ['class' => self::FIXTURE, 'method' => 'denies'],
                'returns_one' => ['class' => self::FIXTURE, 'method' => 'returns_one'],
                'returns_nothing' => ['class' => self::FIXTURE, 'method' => 'returns_nothing'],
                'counted' => ['class' => self::FIXTURE, 'method' => 'count_calls'],
                // Same NAME in both realms, opposite meanings - realm decides.
                'shared_check' => ['class' => self::FIXTURE, 'method' => 'grants'],
            ],
            Auth_Gates::REALM_PORTAL => [
                'grants' => ['class' => self::FIXTURE, 'method' => 'grants'],
                'shared_check' => ['class' => self::FIXTURE, 'method' => 'denies'],
            ],
        ];

        $surfaces = [
            'Export_Open_Controller::index' => static::__surface(['route'], 'staff', ['grants']),
            'Export_Closed_Controller::index' => static::__surface(['route'], 'staff', ['grants', 'denies']),
            'Export_Ungated_Controller::index' => static::__surface(['route'], 'staff', []),
            'Export_Broken_Controller::index' => static::__surface(['route'], 'staff', ['no_such_check']),
            'Export_Spa_Controller::index' => static::__surface(['spa'], 'staff', ['grants']),
            'Export_Portal_Controller::index' => static::__surface(['portal_route'], 'portal', ['grants']),
            'Export_Ajax_Controller::save' => static::__surface(['ajax'], 'any', ['grants']),
            'Export_Model::fetch' => static::__surface(['model_fetch'], 'staff', ['grants']),
        ];

        Auth_Gates::_set_index_for_testing($checks, $surfaces);
    }

    /**
     * One synthetic surface entry.
     */
    private static function __surface(array $kinds, string $realm, array $gates): array
    {
        return [
            'kinds' => $kinds,
            'realm' => $realm,
            'auth' => $gates,
            'file' => 'fixture/export.php',
            'member' => 'fixture',
        ];
    }
}
