<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\AuthGates\Php;

use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Orm_Controller;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Dispatcher;
use App\RSpade\Core\Response\Rsx_Response_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\AuthGates\Php\Auth_Gates_Check_Fixture;
use App\RSpade\Tests\AuthGates\Php\Auth_Gates_Seam_Fixture_Controller;
use App\RSpade\Tests\AuthGates\Php\Auth_Gates_Seam_Fixture_Model;

/**
 * The five server dispatch seams that ENFORCE declarative #[Auth] gates:
 * Dispatcher (#[Route]/#[SPA]), Portal_Dispatcher (#[Portal_Route]), Ajax (both
 * entry points), Orm_Controller (model fetch + relationships) and Api_Dispatcher
 * (#[Api_Endpoint]).
 *
 * TWO DRIVING TECHNIQUES, because the seams read their gate lists from two places:
 *
 *   - The route seams read the manifest ROUTE ROW, which no test seam can rewrite.
 *     They are driven against REAL routed fixtures
 *     (Auth_Gates_Seam_Fixture_Controller / Auth_Gates_Portal_Seam_Fixture_Controller)
 *     through Dispatcher::dispatch() in process.
 *   - The Ajax and ORM seams read the SURFACE INDEX, so
 *     Auth_Gates::_set_index_for_testing() can hand them any gate list - including a
 *     name that exists in no realm - without that name ever entering the real
 *     registry.
 *
 * Behavior of record: php artisan rsx:man auth_gates (HOW THE CHECKS RUN).
 */
class Auth_Gates_Seam_Test extends Rsx_Test_Abstract
{
    private const FIXTURE = Auth_Gates_Check_Fixture::class;

    private const AJAX_TARGET = 'Auth_Gates_Seam_Fixture_Controller::endpoint';

    // The ORM seam is driven against FRAMEWORK models only: User_Model for fetch (which
    // has a record-level body check of its own, so the two layers are seen composing) and
    // this concern's own fixture for the relationship, which is the only model declaring
    // a fetchable #[Relationship] outside an application.
    private const MODEL_TARGET = 'User_Model::fetch';
    private const RELATIONSHIP_MODEL = 'Auth_Gates_Seam_Fixture_Model';
    private const RELATIONSHIP_TARGET = 'Auth_Gates_Seam_Fixture_Model::children';

    // =========================================================================
    // ROUTE SEAM (Dispatcher)
    // =========================================================================

    /**
     * The gate list reaches the dispatcher: the resolved route match carries the
     * merged names the manifest baked onto the row.
     */
    public static function test_route_match_carries_gate_list()
    {
        $gated = Dispatcher::resolve_url_to_route('/_test/auth-gates/gated');
        static::__assert_equals(['is_logged_in'], $gated['auth']);

        $open = Dispatcher::resolve_url_to_route('/_test/auth-gates/open');
        static::__assert_equals(['public'], $open['auth']);
    }

    /**
     * A passing gate dispatches the action exactly as before.
     */
    public static function test_route_seam_dispatches_when_gates_pass()
    {
        $user_id = static::__first_user_id();
        if ($user_id === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        static::__acting_as_user($user_id);

        $response = Dispatcher::dispatch(
            '/_test/auth-gates/gated',
            'GET',
            [],
            Request::create('/_test/auth-gates/gated', 'GET')
        );

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_contains(
            Auth_Gates_Seam_Fixture_Controller::DISPATCHED,
            $response->getContent()
        );

        static::__reset_session();
    }

    /**
     * An ungated route is untouched by the seam - the transition contract, and the
     * state every real surface is in until the annotation pass lands.
     */
    public static function test_route_seam_dispatches_ungated_route()
    {
        static::__reset_session();

        // 'public' is a declared gate, so this also proves the built-in open marker
        // reaches an anonymous caller.
        $response = Dispatcher::dispatch(
            '/_test/auth-gates/open',
            'GET',
            [],
            Request::create('/_test/auth-gates/open', 'GET')
        );

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_contains(
            Auth_Gates_Seam_Fixture_Controller::DISPATCHED,
            $response->getContent()
        );
    }

    /**
     * A denied ANONYMOUS caller is sent to login rather than shown a 403 - the
     * existing unauthorized split, reused unchanged.
     */
    public static function test_route_seam_denies_anonymous_with_login_redirect()
    {
        // Gates evaluate live against whoever is signed in right now, so dropping the
        // impersonated identity is the whole setup this case needs.
        static::__reset_session();

        $response = Dispatcher::dispatch(
            '/_test/auth-gates/gated',
            'GET',
            [],
            Request::create('/_test/auth-gates/gated', 'GET')
        );

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/login', $response->headers->get('Location'));
    }

    // =========================================================================
    // PORTAL ROUTE SEAM (Portal_Dispatcher)
    // =========================================================================

    /**
     * The portal route row carries its gate list too.
     */
    public static function test_portal_route_match_carries_gate_list()
    {
        $match = Portal_Dispatcher::resolve_url_to_route('/_test/auth-gates/portal-gated');

        static::__assert_not_empty($match);
        static::__assert_equals(['is_logged_in'], $match['auth']);
    }

    /**
     * A denied anonymous portal caller lands on the PORTAL login route - the portal
     * UX, not the staff one.
     */
    public static function test_portal_seam_denies_anonymous_with_portal_login_redirect()
    {
        static::__reset_session();

        $response = Portal_Dispatcher::dispatch(
            '/_test/auth-gates/portal-gated',
            'GET',
            [],
            Request::create('/_test/auth-gates/portal-gated', 'GET')
        );

        static::__assert_equals(302, $response->getStatusCode());

        $location = $response->headers->get('Location');
        static::__assert_contains('login', $location);
        static::__assert_contains('_portal', $location);
    }

    // =========================================================================
    // AJAX SEAM - internal()
    // =========================================================================

    /**
     * A passing gate runs the endpoint body.
     */
    public static function test_ajax_internal_runs_endpoint_when_gates_pass()
    {
        static::__install(['grants']);

        $result = Ajax::internal('Auth_Gates_Seam_Fixture_Controller', 'endpoint');
        static::__assert_equals(Auth_Gates_Seam_Fixture_Controller::DISPATCHED, $result['marker']);

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A denying gate raises the coded unauthorized exception and the body never runs.
     */
    public static function test_ajax_internal_denies_before_the_body()
    {
        static::__install(['denies']);

        static::__assert_throws(
            AjaxUnauthorizedException::class,
            function () {
                Ajax::internal('Auth_Gates_Seam_Fixture_Controller', 'endpoint');
            }
        );

        Auth_Gates::_reset_for_testing();
    }

    /**
     * An endpoint declaring no gates is dispatched exactly as before.
     */
    public static function test_ajax_internal_passes_through_gateless_endpoint()
    {
        static::__install([]);

        $result = Ajax::internal('Auth_Gates_Seam_Fixture_Controller', 'endpoint');
        static::__assert_equals(Auth_Gates_Seam_Fixture_Controller::DISPATCHED, $result['marker']);

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // AJAX SEAM - handle_browser_request()
    // =========================================================================

    /**
     * The browser entry point runs the endpoint when the gates pass.
     */
    public static function test_ajax_browser_runs_endpoint_when_gates_pass()
    {
        static::__install(['grants']);

        $json = static::__browser_ajax();

        static::__assert_true($json['_success']);

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A denial returns the standard coded unauthorized error the client's generic
     * handlers already render - not an exception, not a 500.
     */
    public static function test_ajax_browser_denies_with_coded_unauthorized()
    {
        static::__install(['denies']);

        $json = static::__browser_ajax();

        static::__assert_false($json['_success']);
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $json['error_code']);

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // UNKNOWN NAME AT A SEAM
    // =========================================================================

    /**
     * A gate name that is unknown IN THE ACTIVE REALM denies at a seam (an
     * unsatisfiable gate must refuse, not 500) and logs one warning naming the
     * surface, the check and the realm. The engine itself keeps throwing for
     * programmatic callers - that contract is covered by Auth_Gates_Evaluation_Test.
     */
    public static function test_unknown_check_name_denies_at_a_seam_and_warns()
    {
        static::__install(['no_such_check_defined_anywhere']);

        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });

        $json = static::__browser_ajax();

        static::__assert_false($json['_success']);
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $json['error_code']);

        $matched = false;
        foreach ($warnings as $warning) {
            if (str_contains($warning, 'no_such_check_defined_anywhere')
                && str_contains($warning, self::AJAX_TARGET)
                && str_contains($warning, Auth_Gates::REALM_STAFF)
                && str_contains($warning, 'denying')) {
                $matched = true;
            }
        }

        static::__assert_true($matched, 'expected one warning naming the surface, the check and the realm');

        Event::forget(MessageLogged::class);
        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // ORM SEAM (Orm_Controller)
    // =========================================================================

    /**
     * A denied fetch is reported exactly like a missing row: the id is simply ABSENT
     * from the records map. A prober cannot tell "exists but denied" from "does not
     * exist" - a denial of the whole surface yields the same empty map a request for
     * nothing but nonexistent ids does.
     */
    public static function test_orm_fetch_denial_is_indistinguishable_from_not_found()
    {
        static::__install(['denies']);

        $denied = Orm_Controller::fetch(static::__ajax_request(), ['model' => 'User_Model', 'ids' => [1]]);

        static::__assert_true(is_array($denied), 'a denial returns a records map, not an error response');
        static::__assert_equals([], $denied['records']);

        // The same shape a genuinely absent record produces, with the gate open.
        static::__install(['grants']);

        $missing = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'User_Model', 'ids' => [999999999]]
        );

        static::__assert_equals($denied['records'], $missing['records']);

        Auth_Gates::_reset_for_testing();
    }

    /**
     * A passing gate reaches the model and returns the record.
     */
    public static function test_orm_fetch_reaches_the_model_when_gates_pass()
    {
        $user_id = static::__first_user_id();
        if ($user_id === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        // User_Model::fetch() has a record-level body check of its own, so this also
        // shows the two layers composing: the gate opens the surface, the body still
        // decides which record.
        static::__acting_as_user($user_id);
        static::__install(['grants']);

        $result = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'User_Model', 'ids' => [$user_id]]
        );

        static::__assert_not_empty($result['records']);

        $record = $result['records'][(string) $user_id];
        static::__assert_equals($user_id, is_array($record) ? $record['id'] : $record->id);

        Auth_Gates::_reset_for_testing();
        static::__reset_session();
    }

    /**
     * A mixed batch answers per id: the real record is present, the nonexistent one is
     * absent, and nothing in the response says which kind of miss the absent one was.
     */
    public static function test_orm_fetch_returns_only_the_ids_that_resolved()
    {
        $user_id = static::__first_user_id();
        if ($user_id === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        static::__acting_as_user($user_id);
        static::__install(['grants']);

        $result = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'User_Model', 'ids' => [$user_id, 999999999]]
        );

        // PHP coerces the numeric-string map key back to an int; on the wire it is an
        // object property name.
        static::__assert_equals([$user_id], array_keys($result['records']));

        Auth_Gates::_reset_for_testing();
        static::__reset_session();
    }

    /**
     * The relationship endpoint is gated on the RELATIONSHIP method's own list,
     * evaluated before the relation runs. Denial is the generic not-found again.
     */
    public static function test_orm_relationship_denial_is_generic()
    {
        // fetch() open, the relationship closed: the denial can only come from the
        // relationship's own gate list. It lands before any model code, so the id
        // does not have to exist.
        static::__install(['grants'], ['denies']);

        $result = Orm_Controller::fetch_relationship(static::__ajax_request(), [
            'model' => self::RELATIONSHIP_MODEL,
            'id' => 1,
            'relationship' => 'children',
        ]);

        static::__assert_instance_of(Rsx_Response_Abstract::class, $result);
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $result->get_type());

        Auth_Gates::_reset_for_testing();
    }

    // =========================================================================
    // API SEAM (Api_Dispatcher)
    // =========================================================================

    /**
     * Every #[Api_Endpoint] row NAMES ITS SURFACE, and that surface is indexed.
     *
     * The gate list lives ONCE, in auth.surfaces; a route row used to carry a third copy of
     * it. Every dispatcher - staff, portal and API alike - resolves it the same way:
     * Auth_Gates::surface_gates($route['surface']).
     */
    public static function test_api_route_rows_name_an_indexed_surface()
    {
        $api_rows = 0;
        $surfaces = Auth_Gates::get_surfaces();

        foreach (\App\RSpade\Core\Manifest\Manifest::get_routes() as $pattern => $route) {
            if (($route['type'] ?? null) !== 'api') {
                continue;
            }

            $api_rows++;

            static::__assert_false(
                array_key_exists('auth', $route),
                "api route row {$pattern} must not carry its own gate list"
            );

            static::__assert_array_has_key(
                $route['surface'] ?? '',
                $surfaces,
                "api route row {$pattern} names a surface that is not indexed"
            );
        }

        static::__assert_greater_than(0, $api_rows);
    }

    // =========================================================================
    // TRANSITION CONTRACT
    // =========================================================================

    /**
     * An empty gate list passes at a seam. This is what keeps every un-annotated
     * surface dispatching unchanged while the annotation passes land; closed-by-
     * default is a manifest-BUILD rule, never a runtime one.
     */
    public static function test_empty_gate_list_passes_at_a_seam()
    {
        static::__assert_true(Auth_Gates::gates_pass_at_seam([], Auth_Gates::REALM_STAFF, 'probe'));
        static::__assert_true(Auth_Gates::gates_pass_at_seam([], Auth_Gates::REALM_PORTAL, 'probe'));
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Install a synthetic index whose surfaces are the REAL targets these tests
     * drive, carrying whatever gate list the test wants.
     *
     * @param array $ajax_and_fetch_gates Gates for the Ajax endpoint and model fetch
     * @param array|null $relationship_gates Gates for the relationship (defaults to the same)
     */
    private static function __install(array $ajax_and_fetch_gates, ?array $relationship_gates = null): void
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
            self::AJAX_TARGET => static::__surface(Auth_Gates::REALM_ANY, $ajax_and_fetch_gates),
            self::MODEL_TARGET => static::__surface(Auth_Gates::REALM_STAFF, $ajax_and_fetch_gates),
            self::RELATIONSHIP_MODEL . '::fetch' => static::__surface(
                Auth_Gates::REALM_STAFF,
                $ajax_and_fetch_gates
            ),
            self::RELATIONSHIP_TARGET => static::__surface(
                Auth_Gates::REALM_ANY,
                $relationship_gates ?? $ajax_and_fetch_gates
            ),
        ];

        Auth_Gates::_set_index_for_testing($checks, $surfaces);
    }

    /**
     * One synthetic surface entry.
     */
    private static function __surface(string $realm, array $gates): array
    {
        return [
            'kinds' => ['ajax'],
            'realm' => $realm,
            'auth' => $gates,
            'file' => 'fixture/seam.php',
            'member' => 'fixture',
        ];
    }

    /**
     * Drive the browser Ajax entry point and decode its JSON response.
     */
    private static function __browser_ajax(): array
    {
        $url = '/_ajax/Auth_Gates_Seam_Fixture_Controller/endpoint';
        $request = Request::create($url, 'POST');

        $response = Ajax::handle_browser_request($request, [
            'controller' => 'Auth_Gates_Seam_Fixture_Controller',
            'action' => 'endpoint',
        ]);

        Ajax::set_ajax_response_mode(false);

        return json_decode($response->getContent(), true);
    }

    /**
     * A request object for the ORM endpoints (which read nothing off it).
     */
    private static function __ajax_request(): Request
    {
        return Request::create('/_ajax/Orm_Controller/fetch', 'POST');
    }

    /**
     * The lowest User_Model id in the test database, or null when there is none.
     */
    private static function __first_user_id(): ?int
    {
        $user = User_Model::without_site_scope(function () {
            return User_Model::orderBy('id')->first();
        });

        return $user ? (int) $user->id : null;
    }
}
