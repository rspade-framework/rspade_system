<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Dispatch\Php\Front_Controller_Fixture_Controller;

/**
 * The default route /_/{Controller}/{action}.
 *
 * It addresses a #[Route] method, or a JS SPA action, by name. What is pinned: GET
 * redirects to the method's real URL; POST runs the method only when one of its own routes
 * accepts POST; a GET naming a JS SPA action redirects to its @route URL (the address
 * Rsx.Route() falls back to for an action outside the current bundle), and a POST to one is
 * a 404; and every target that does not qualify - not a controller, not a routed method,
 * an #[SPA] bootstrap, an Ajax endpoint, an error page, a non-scalar query value - is the
 * same 404 an unknown URL gets, with the method never run.
 */
class Default_Route_Test extends Rsx_Test_Abstract
{
    private const FIXTURE = 'Front_Controller_Fixture_Controller';

    /**
     * setup() runs once per CLASS: every test starts from a zero count and no session.
     */
    private static function __fresh(): void
    {
        Front_Controller_Fixture_Controller::$invocations = 0;
        static::__reset_session();
    }

    public static function teardown()
    {
        Rsx_Request_Channel::reset();
        Front_Controller_Fixture_Controller::$invocations = 0;
    }

    private static function __handle(string $url, string $method = 'GET')
    {
        $request = Request::create($url, $method);
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }

    public static function test_get_redirects_to_the_real_url()
    {
        static::__fresh();

        $response = static::__handle('/_/' . self::FIXTURE . '/get_only');
        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/_test/front/get-only', (string) $response->headers->get('Location'));

        $response = static::__handle('/_/' . self::FIXTURE . '/get_post?id=7');
        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_contains('/_test/front/get-post/7', (string) $response->headers->get('Location'));

        static::__assert_equals(0, Front_Controller_Fixture_Controller::$invocations, 'a GET never runs the method');
    }

    /**
     * A URL whose :tokens the query string cannot fill has no real URL to go to.
     */
    public static function test_get_without_the_route_params_is_a_404()
    {
        static::__fresh();

        static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/get_post')->getStatusCode());
    }

    public static function test_post_runs_a_method_whose_route_accepts_post()
    {
        static::__fresh();

        $response = static::__handle('/_/' . self::FIXTURE . '/get_post?id=7', 'POST');

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_equals(['ran' => 'get_post', 'id' => '7'], json_decode($response->getContent(), true));
        static::__assert_equals(1, Front_Controller_Fixture_Controller::$invocations);
    }

    public static function test_post_to_a_get_only_route_is_a_404()
    {
        static::__fresh();

        static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/get_only', 'POST')->getStatusCode());
        static::__assert_equals(0, Front_Controller_Fixture_Controller::$invocations);
    }

    public static function test_a_non_scalar_query_value_is_a_404()
    {
        static::__fresh();

        static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/get_post?id[]=7', 'POST')->getStatusCode());
        static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/get_post?id[a]=7')->getStatusCode());
        static::__assert_equals(0, Front_Controller_Fixture_Controller::$invocations);
    }

    public static function test_an_ajax_endpoint_never_qualifies()
    {
        static::__fresh();

        static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/ajax_probe')->getStatusCode());
        static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/ajax_probe', 'POST')->getStatusCode());
        static::__assert_equals(0, Front_Controller_Fixture_Controller::$invocations);
    }

    public static function test_a_class_that_is_not_a_controller_or_does_not_exist_is_a_404()
    {
        static::__fresh();

        static::__assert_equals(404, static::__handle('/_/Rsx/get_mode')->getStatusCode());
        static::__assert_equals(404, static::__handle('/_/No_Such_Probe_Controller/index')->getStatusCode());
        static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/no_such_method')->getStatusCode());
    }

    /**
     * An #[SPA] bootstrap does not qualify: it has no URL of its own (its URLs are its JS
     * actions', which the default route addresses by the action's name). Both verbs are a
     * 404.
     */
    public static function test_an_spa_bootstrap_has_no_default_route_url()
    {
        static::__fresh();

        static::__assert_equals(404, static::__handle('/_/_Sys_Spa_Controller/index')->getStatusCode());
        static::__assert_equals(404, static::__handle('/_/_Sys_Spa_Controller/index', 'POST')->getStatusCode());
    }

    /**
     * A JS SPA action is addressed by its own name: GET /_/<Action>/index redirects to the
     * action's @route URL. The framework's own panel action is the fixture - a staff
     * js_action surface with a gate, and a route of its own.
     */
    public static function test_get_naming_a_js_spa_action_redirects_to_its_route()
    {
        static::__fresh();

        $response = static::__handle('/_/_Sys_Dashboard_Action/index');

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_equals('/_sys', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    }

    /**
     * POST never qualifies for a JS SPA action, and an action segment other than 'index'
     * names nothing.
     */
    public static function test_a_js_spa_action_refuses_post_and_other_actions()
    {
        static::__fresh();

        static::__assert_equals(404, static::__handle('/_/_Sys_Dashboard_Action/index', 'POST')->getStatusCode());
        static::__assert_equals(404, static::__handle('/_/_Sys_Dashboard_Action/other')->getStatusCode());
        static::__assert_equals(404, static::__handle('/_/No_Such_Probe_Action/index')->getStatusCode());
    }

    /**
     * A method that answers an /error/ route is the error funnel's, never a page by name.
     * The suite cannot declare an /error/ fixture route (the namespace is the
     * application's), so the route table is given one for the fixture's method.
     */
    public static function test_an_error_page_handler_is_refused()
    {
        static::__fresh();

        $manifest = &Manifest::get_full_manifest();
        $routes = &$manifest['data']['routes'];
        $original = $routes;
        $routes['/error/default-route-probe'] = array_merge($routes['/_test/front/get-post/:id'], [
            'pattern' => '/error/default-route-probe',
        ]);

        try {
            static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/get_post?id=7')->getStatusCode());
            static::__assert_equals(404, static::__handle('/_/' . self::FIXTURE . '/get_post?id=7', 'POST')->getStatusCode());
            static::__assert_equals(0, Front_Controller_Fixture_Controller::$invocations);
        } finally {
            $routes = $original;
        }
    }
}
