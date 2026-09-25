<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Dispatch\Php\Front_Controller_Fixture_Controller;

/**
 * Rsx_Front_Controller - the one entry point of an HTTP request into RSX.
 *
 * Driven in process through handle(), the method App\Http\Kernel's router destination
 * calls. Pinned here: a failure anywhere in dispatch is rendered by the channel's policy
 * exactly once (a build-artifact miss is a plain-text 404; a 404 raised after the action
 * returned is a 404 page, with the action run once); a second entry while a dispatch is
 * in flight is refused; no Laravel route is reachable; and the external API does not
 * answer on the portal's dedicated domain.
 */
class Front_Controller_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

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

    /**
     * Hand one request to the front controller, as the kernel does.
     */
    private static function __handle(string $url, string $method = 'GET')
    {
        $request = Request::create($url, $method);
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }

    public static function test_the_page_dispatcher_never_resolves_an_api_row()
    {
        // #[Api_Endpoint] rows share the staff route table; only Api_Dispatcher serves them
        // (bearer auth, scopes, param validation). The page dispatcher must skip them.
        static::__assert_not_null(
            \App\RSpade\Core\Manifest\Manifest::get_routes()['/api/v1/me'] ?? null,
            'the framework /api/v1/me row is in the route table'
        );
        static::__assert_null(\App\RSpade\Core\Dispatch\Dispatcher::resolve_url_to_route('/api/v1/me', 'GET'));
    }

    public static function test_a_build_artifact_miss_is_a_plain_text_404()
    {
        static::__fresh();

        foreach (['/_vendor/00000000000000000000000000000000_nothing.js', '/_compiled/No_Such_Probe__app.00000001.js'] as $url) {
            $response = static::__handle($url);

            static::__assert_equals(404, $response->getStatusCode(), $url);
            static::__assert_contains('text/plain', (string) $response->headers->get('Content-Type'), $url);
            static::__assert_equals(Rsx_Request_Channel::ASSET, Rsx_Request_Channel::current(), $url);
        }
    }

    /**
     * A 404 raised while the action's result is being turned into a response - outside
     * the action's own seam - is rendered once by the page policy, and the action ran once.
     */
    public static function test_a_404_raised_after_the_action_is_one_dispatch_and_a_404()
    {
        static::__fresh();

        $response = static::__handle('/_test/front/abort-after-action');

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_equals(1, Front_Controller_Fixture_Controller::$invocations, 'the action ran exactly once');
        static::__assert_false(Rsx_Front_Controller::is_handling(), 'the in-flight flag is clear afterwards');
    }

    public static function test_an_unknown_page_is_a_404()
    {
        static::__fresh();

        $response = static::__handle('/_test/front/no-such-page');

        static::__assert_equals(404, $response->getStatusCode());
    }

    /**
     * Entering the front controller from inside a dispatch is refused loudly, and the
     * outer request still completes.
     */
    public static function test_reentry_is_refused()
    {
        static::__fresh();

        $response = static::__handle('/_test/front/reenter');

        static::__assert_equals(200, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        static::__assert_contains('re-entered', (string) ($payload['reentry'] ?? ''));
        static::__assert_equals(1, Front_Controller_Fixture_Controller::$invocations, 'the inner request dispatched nothing');
        static::__assert_false(Rsx_Front_Controller::is_handling());
    }

    /**
     * Laravel's router is not in the request path: a route a vendor package registers,
     * or one written in a routes file, is an ordinary unknown URL.
     */
    public static function test_laravel_routes_are_unreachable()
    {
        static::__fresh();

        foreach (['/_ignition/health-check', '/sanctum/csrf-cookie', '/test-bundle-facade'] as $url) {
            static::__assert_equals(404, static::__handle($url)->getStatusCode(), $url);
        }

        static::__assert_equals(404, static::__handle('/_ignition/execute-solution', 'POST')->getStatusCode());
    }

    /**
     * The API is the staff host's. On the portal's dedicated domain every /api/ path is
     * the API's own not_found, before any credential is asked for.
     */
    public static function test_the_api_does_not_answer_on_a_dedicated_portal_domain()
    {
        static::__fresh();

        $original = config('rsx.portal.domain');
        config(['rsx.portal.domain' => 'portal.front-controller-test.invalid']);

        try {
            $response = static::__handle('http://portal.front-controller-test.invalid/api/v1/me');

            static::__assert_equals(404, $response->getStatusCode());
            static::__assert_equals('not_found', json_decode($response->getContent(), true)['error']['code'] ?? null);
            static::__assert_true(Rsx_Request_Channel::is_portal_host());

            // The staff host still has the API: no key is a 401, not a 404.
            $response = static::__handle('http://localhost/api/v1/me');
            static::__assert_equals(401, $response->getStatusCode());
        } finally {
            config(['rsx.portal.domain' => $original]);
        }
    }
}
