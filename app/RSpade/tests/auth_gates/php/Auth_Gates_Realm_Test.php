<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\AuthGates\Php\Auth_Gates_Check_Fixture;
use App\RSpade\Tests\AuthGates\Php\Auth_Gates_Seam_Fixture_Controller;

/**
 * REALM SEPARATION at the two shared-transport seams (Ajax, ORM).
 *
 * Each realm has its own internal-endpoint channel - /_ajax/... dispatched by
 * Dispatcher for staff, the same path under the portal base dispatched by
 * Portal_Dispatcher for the portal - so a surface's realm and the request's realm
 * are both known at the seam. When they disagree the seam DENIES before any gate
 * name is evaluated: 'is_logged_in' is defined in BOTH registries, so evaluating a
 * portal endpoint's gate in the staff realm would admit a staff session to a portal
 * endpoint (and vice versa), and the body would then run with the wrong identity.
 *
 * Covered here: the rule itself (Auth_Gates::surface_realm_permits), its enforcement
 * at the Ajax seam, and the manifest classification that assigns the realms
 * (#[Auth_Realm], the portal-root default, and the fail-closed staff default).
 *
 * Behavior of record: php artisan rsx:man auth_gates (PORTAL, AJAX REALM).
 */
class Auth_Gates_Realm_Test extends Rsx_Test_Abstract
{
    private const FIXTURE = Auth_Gates_Check_Fixture::class;

    private const AJAX_TARGET = 'Auth_Gates_Seam_Fixture_Controller::endpoint';

    // =========================================================================
    // THE RULE
    // =========================================================================

    /**
     * A realm-explicit surface permits its OWN realm and refuses the other one.
     */
    public static function test_realm_explicit_surface_permits_only_its_own_realm()
    {
        static::__install_surface(Auth_Gates::REALM_PORTAL, ['grants']);

        static::__assert_true(
            Auth_Gates::surface_realm_permits(self::AJAX_TARGET, Auth_Gates::REALM_PORTAL)
        );
        static::__assert_false(
            Auth_Gates::surface_realm_permits(self::AJAX_TARGET, Auth_Gates::REALM_STAFF)
        );

        static::__install_surface(Auth_Gates::REALM_STAFF, ['grants']);

        static::__assert_true(
            Auth_Gates::surface_realm_permits(self::AJAX_TARGET, Auth_Gates::REALM_STAFF)
        );
        static::__assert_false(
            Auth_Gates::surface_realm_permits(self::AJAX_TARGET, Auth_Gates::REALM_PORTAL)
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * An 'any' surface - a relationship, or a framework service that deliberately
     * serves both - permits either realm.
     */
    public static function test_any_realm_surface_permits_both_realms()
    {
        static::__install_surface(Auth_Gates::REALM_ANY, ['grants']);

        static::__assert_true(
            Auth_Gates::surface_realm_permits(self::AJAX_TARGET, Auth_Gates::REALM_STAFF)
        );
        static::__assert_true(
            Auth_Gates::surface_realm_permits(self::AJAX_TARGET, Auth_Gates::REALM_PORTAL)
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A target the index does not know permits: only an indexed surface can declare
     * a realm, and the gate layer above still applies.
     */
    public static function test_unindexed_target_permits()
    {
        static::__install_surface(Auth_Gates::REALM_PORTAL, ['grants']);

        static::__assert_true(
            Auth_Gates::surface_realm_permits('No_Such_Controller::nope', Auth_Gates::REALM_STAFF)
        );

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // ENFORCEMENT AT THE AJAX SEAM
    // =========================================================================

    /**
     * The seam refuses a cross-realm call even when the gate list would PASS - the
     * whole point of the rule. The internal entry point runs in the staff realm (CLI
     * is never a portal request), so a 'portal' surface must be refused there.
     */
    public static function test_ajax_seam_denies_cross_realm_surface_whose_gates_pass()
    {
        static::__install_surface(Auth_Gates::REALM_PORTAL, ['grants']);

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () {
                Ajax::internal('Auth_Gates_Seam_Fixture_Controller', 'endpoint');
            }
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * The same surface in the REQUEST's realm runs normally - the denial above is the
     * realm rule, not a broken fixture.
     */
    public static function test_ajax_seam_runs_same_realm_surface()
    {
        static::__install_surface(Auth_Gates::REALM_STAFF, ['grants']);

        $result = Ajax::internal('Auth_Gates_Seam_Fixture_Controller', 'endpoint');
        static::__assert_equals(Auth_Gates_Seam_Fixture_Controller::DISPATCHED, $result['marker']);

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A cross-realm surface is refused even with NO gates at all: the realm check
     * runs before the gate list is consulted.
     */
    public static function test_ajax_seam_denies_cross_realm_gateless_surface()
    {
        static::__install_surface(Auth_Gates::REALM_PORTAL, []);

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () {
                Ajax::internal('Auth_Gates_Seam_Fixture_Controller', 'endpoint');
            }
        );

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // MANIFEST CLASSIFICATION
    // =========================================================================

    /**
     * #[Auth_Realm('any')] on a framework service that serves both realms.
     */
    public static function test_explicit_auth_realm_declaration_is_indexed()
    {
        $surfaces = Auth_Gates::get_surfaces();

        static::__assert_equals(Auth_Gates::REALM_ANY, $surfaces['Orm_Controller::fetch']['realm']);
        static::__assert_equals(
            Auth_Gates::REALM_ANY,
            $surfaces['Realtime_Controller::get_connection_token']['realm']
        );
    }

    /**
     * The fail-closed default: an #[Ajax_Endpoint] that declares nothing and lives
     * outside a portal root serves the STAFF realm.
     */
    public static function test_undeclared_ajax_endpoint_defaults_to_staff()
    {
        $surfaces = Auth_Gates::get_surfaces();

        static::__assert_equals(
            Auth_Gates::REALM_STAFF,
            $surfaces['Rsx_Formdata_Generator_Controller::email']['realm']
        );
    }

    /**
     * The portal-root default: an #[Ajax_Endpoint] declared under a portal root
     * serves the PORTAL realm with no declaration needed.
     */
    public static function test_portal_root_ajax_endpoint_defaults_to_portal()
    {
        $surfaces = Auth_Gates::get_surfaces();
        $portal_ajax = [];

        foreach ($surfaces as $target => $surface) {
            if (!in_array('ajax', $surface['kinds'] ?? [], true)) {
                continue;
            }
            if (str_starts_with($surface['file'] ?? '', 'rsx/portal/')) {
                $portal_ajax[$target] = $surface['realm'];
            }
        }

        if (empty($portal_ajax)) {
            static::__skip('no application portal ajax endpoints installed');

            return;
        }

        foreach ($portal_ajax as $target => $realm) {
            static::__assert_equals(Auth_Gates::REALM_PORTAL, $realm, "realm of {$target}");
        }
    }

    /**
     * A malformed #[Auth_Realm] value fails the manifest build by name - a typo can
     * never silently widen or narrow a surface's reachability.
     */
    public static function test_invalid_auth_realm_value_fails_the_build()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                $manifest_data = ['data' => ['files' => [
                    'rsx/app/bogus/bogus_controller.php' => [
                        'class' => 'Bogus_Controller',
                        'fqcn' => 'Rsx\\App\\Bogus\\Bogus_Controller',
                        'attributes' => ['Auth_Realm' => [['portal_or_staff']]],
                        'public_static_methods' => [
                            'endpoint' => ['attributes' => ['Ajax_Endpoint' => [[]]]],
                        ],
                    ],
                ]]];

                Auth_ManifestSupport::process($manifest_data, array_keys($manifest_data['data']['files']), []);
            },
            'Invalid #[Auth_Realm] argument'
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Install a synthetic index carrying ONE surface - the real Ajax fixture target -
     * with the realm and gates a test wants.
     */
    private static function __install_surface(string $realm, array $gates): void
    {
        $checks = [
            Auth_Gates::REALM_STAFF => [
                'grants' => ['class' => self::FIXTURE, 'method' => 'grants'],
                'denies' => ['class' => self::FIXTURE, 'method' => 'denies'],
            ],
            Auth_Gates::REALM_PORTAL => [
                'grants' => ['class' => self::FIXTURE, 'method' => 'grants'],
                'denies' => ['class' => self::FIXTURE, 'method' => 'denies'],
            ],
        ];

        $surfaces = [
            self::AJAX_TARGET => [
                'kinds' => ['ajax'],
                'realm' => $realm,
                'auth' => $gates,
                'file' => 'fixture/realm.php',
                'member' => 'fixture',
            ],
        ];

        Auth_Gates::_set_index_for_testing($checks, $surfaces);
    }
}
