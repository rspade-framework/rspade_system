<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Orm_Controller;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Response\Rsx_Response_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\AuthGates\Php\Auth_Gates_Check_Fixture;

/**
 * CLOSED BY DEFAULT AT RUN TIME: every dispatch seam refuses a surface the auth index
 * does not know, or knows with an empty gate list.
 *
 * The manifest build refuses to deploy a gateless surface, so reaching one at run time
 * means the build and the request disagree. Each seam must then REFUSE - loudly, via
 * shouldnt_happen() - rather than read "no gate on record" as "nothing to check".
 *
 * Every case installs a synthetic surface index through Auth_Gates::_set_index_for_testing()
 * that omits (or empties) the one target the seam is about to dispatch, then drives the
 * REAL seam against a real fixture surface.
 *
 * Behavior of record: php artisan rsx:man auth_gates (CLOSED BY DEFAULT).
 */
class Auth_Fail_Closed_Test extends Rsx_Test_Abstract
{
    private const FIXTURE = Auth_Gates_Check_Fixture::class;

    private const AJAX_TARGET = 'Auth_Gates_Seam_Fixture_Controller::endpoint';
    private const RELATIONSHIP_MODEL = 'Auth_Gates_Seam_Fixture_Model';

    // =========================================================================
    // THE PRIMITIVE
    // =========================================================================

    /**
     * An unindexed target is refused, naming the target.
     */
    public static function test_require_surface_refuses_an_unindexed_target()
    {
        static::__install([]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_Gates::require_surface('No_Such_Controller::nope'),
            "'No_Such_Controller::nope' is being dispatched, but the auth index has no entry"
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * An indexed target with an empty gate list is refused.
     */
    public static function test_require_surface_refuses_an_empty_gate_list()
    {
        static::__install([self::AJAX_TARGET => []]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_Gates::surface_gates(self::AJAX_TARGET),
            'empty gate list'
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * An empty list handed straight to the seam evaluator is refused, never an open gate.
     */
    public static function test_empty_gate_list_is_refused_at_a_seam()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_Gates::gates_pass_at_seam([], Auth_Gates::REALM_STAFF, 'probe'),
            'empty gate list'
        );
        static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_Gates::gates_pass_at_seam([], Auth_Gates::REALM_PORTAL, 'probe'),
            'empty gate list'
        );
    }

    /**
     * The realm check never permits a target it cannot see.
     */
    public static function test_realm_check_refuses_an_unindexed_target()
    {
        static::__install([self::AJAX_TARGET => ['grants']]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_Gates::surface_realm_permits('No_Such_Controller::nope', Auth_Gates::REALM_STAFF),
            'has no entry'
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * can_access() refuses an indexed-but-gateless target, and accessible_route() - which
     * collapses every refusal to "no link" - answers null for it.
     */
    public static function test_link_visibility_refuses_a_gateless_target()
    {
        static::__install([self::AJAX_TARGET => []]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Auth_Gates::can_access(self::AJAX_TARGET, Auth_Gates::REALM_STAFF),
            'empty gate list'
        );
        static::__assert_null(Auth_Gates::accessible_route(self::AJAX_TARGET, Auth_Gates::REALM_STAFF));

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // ROUTE SEAMS
    // =========================================================================

    /**
     * The staff dispatcher refuses a matched #[Route] row whose surface is not indexed.
     */
    public static function test_route_row_with_unindexed_surface_is_refused()
    {
        static::__install([]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Dispatcher::resolve_url_to_route('/_test/auth-gates/gated'),
            "'Auth_Gates_Seam_Fixture_Controller::gated'"
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * The default /_/Controller/action route synthesizes its match outside the route
     * table, and refuses an unindexed surface loudly - never a quiet 404.
     */
    public static function test_default_route_with_unindexed_surface_is_refused()
    {
        static::__reset_session();
        static::__install([]);

        $url = '/_/Auth_Gates_Seam_Fixture_Controller/gated';

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Dispatcher::dispatch($url, 'POST', [], Request::create($url, 'POST')),
            "'Auth_Gates_Seam_Fixture_Controller::gated'"
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * The portal dispatcher refuses a matched #[Portal_Route] row whose surface is not indexed.
     */
    public static function test_portal_route_row_with_unindexed_surface_is_refused()
    {
        static::__install([]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Dispatcher::resolve_url_to_route('/_test/auth-gates/portal-gated', 'GET', Auth_Gates::REALM_PORTAL),
            'has no entry'
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * The API dispatcher refuses a matched #[Api_Endpoint] row whose surface is not indexed.
     */
    public static function test_api_row_with_unindexed_surface_is_refused()
    {
        $row = null;
        foreach (Manifest::get_routes() as $pattern => $route) {
            if (($route['type'] ?? null) === 'api' && !str_contains($pattern, ':')) {
                $row = $route + ['pattern' => $pattern];
                break;
            }
        }

        if ($row === null) {
            static::__skip('no parameterless #[Api_Endpoint] row in this install');

            return;
        }

        static::__install([]);

        $match_route = new \ReflectionMethod(Api_Dispatcher::class, '_match_route');

        static::__assert_throws(
            \RuntimeException::class,
            fn () => $match_route->invoke(null, $row['pattern'], $row['methods'][0]),
            'has no entry'
        );

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // AJAX AND ORM SEAMS
    // =========================================================================

    /**
     * An Ajax endpoint the index does not know is refused, and its body never runs. The
     * Ajax transport identifies an endpoint BY its indexed surface, so the refusal comes
     * one step before the gate lookup: it is not an endpoint at all.
     */
    public static function test_ajax_endpoint_with_unindexed_surface_is_refused()
    {
        static::__install([]);

        static::__assert_throws(
            \Exception::class,
            fn () => Ajax::internal('Auth_Gates_Seam_Fixture_Controller', 'endpoint'),
            'not an Ajax endpoint'
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * An Ajax endpoint indexed with an empty gate list is refused, and its body never runs.
     */
    public static function test_gateless_ajax_endpoint_is_refused()
    {
        static::__install([self::AJAX_TARGET => []]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Ajax::internal('Auth_Gates_Seam_Fixture_Controller', 'endpoint'),
            'empty gate list'
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A model whose fetch() is attributed but whose surface is missing from the index is
     * refused before any model code runs.
     */
    public static function test_orm_fetch_with_unindexed_surface_is_refused()
    {
        static::__install([]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Orm_Controller::fetch(static::__ajax_request(), [
                'model' => self::RELATIONSHIP_MODEL,
                'ids' => [1],
            ]),
            "'" . self::RELATIONSHIP_MODEL . "::fetch'"
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A relationship indexed with an empty gate list is refused - the shape that used to
     * run a redeclared relationship with no gate at all.
     */
    public static function test_orm_relationship_with_empty_gate_list_is_refused()
    {
        static::__install([
            self::RELATIONSHIP_MODEL . '::fetch' => ['grants'],
            self::RELATIONSHIP_MODEL . '::children' => [],
        ]);

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Orm_Controller::fetch_relationship(static::__ajax_request(), [
                'model' => self::RELATIONSHIP_MODEL,
                'id' => 1,
                'relationship' => 'children',
            ]),
            'empty gate list'
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * fetch_relationship() applies fetch()'s id rule: a non-scalar or non-numeric id is a
     * validation error and never reaches the model.
     */
    public static function test_orm_relationship_rejects_a_malformed_id()
    {
        foreach ([[1, 2], 'abc', ['id' => 1]] as $bad_id) {
            $result = Orm_Controller::fetch_relationship(static::__ajax_request(), [
                'model' => self::RELATIONSHIP_MODEL,
                'id' => $bad_id,
                'relationship' => 'children',
            ]);

            static::__assert_instance_of(Rsx_Response_Abstract::class, $result);
            static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_type());
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Install a synthetic index holding EXACTLY the given surfaces (target => gate list),
     * with 'grants' and 'denies' defined in both realms.
     *
     * @param array<string, array<int, string>> $surfaces
     */
    private static function __install(array $surfaces): void
    {
        $checks = [];
        foreach ([Auth_Gates::REALM_STAFF, Auth_Gates::REALM_PORTAL] as $realm) {
            $checks[$realm] = [
                'grants' => ['class' => self::FIXTURE, 'method' => 'grants'],
                'denies' => ['class' => self::FIXTURE, 'method' => 'denies'],
            ];
        }

        $index = [];
        foreach ($surfaces as $target => $gates) {
            $index[$target] = [
                'kinds' => ['ajax'],
                'realm' => Auth_Gates::REALM_ANY,
                'auth' => $gates,
                'file' => 'fixture/fail_closed.php',
                'member' => $target,
            ];
        }

        Auth_Gates::_set_index_for_testing($checks, $index);
    }

    /**
     * A request object for the ORM endpoints (which read nothing off it).
     */
    private static function __ajax_request(): Request
    {
        return Request::create('/_ajax/Orm_Controller/fetch', 'POST');
    }
}
