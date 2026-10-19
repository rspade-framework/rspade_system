<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Fpc\Php;

use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\FPC\Rsx_FPC;
use App\RSpade\Core\SSR\Rsx_SSR;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Fpc\Php\Fpc_Ttl_Fixture_Controller;

/**
 * Full page caching and server-side rendering are DISABLED in this release (backlog B-132).
 *
 * An #[FPC] route dispatches like any other: its response carries no cache marker, so the
 * proxy stores nothing, and it logs Rsx_FPC::DISABLED_WARNING. Rsx_SSR::render_component()
 * logs Rsx_SSR::DISABLED_MESSAGE and throws without starting the SSR Node server. The
 * attribute itself is still parsed and baked onto the route row (Fpc_Ttl_Marker_Test).
 */
class Fpc_Ssr_Disabled_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var array<int, string> */
    private static array $warnings = [];

    private static function __capture_warnings(): void
    {
        static::$warnings = [];
        Event::forget(MessageLogged::class);
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            if ($event->level === 'warning') {
                static::$warnings[] = $event->message;
            }
        });
    }

    public static function teardown()
    {
        Event::forget(MessageLogged::class);
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    public static function test_both_features_are_switched_off()
    {
        static::__assert_false(Rsx_FPC::ENABLED);
        static::__assert_false(Rsx_SSR::ENABLED);
    }

    public static function test_an_fpc_route_dispatches_normally_and_warns()
    {
        static::__reset_session();
        static::__capture_warnings();

        $request = Request::create(Fpc_Ttl_Fixture_Controller::TTL_PATH, 'GET');
        app()->instance('request', $request);
        $response = Rsx_Front_Controller::handle($request);

        static::__assert_equals(200, $response->getStatusCode(), 'the route ran');
        static::__assert_false($response->headers->has(Rsx_FPC::MARKER_HEADER), 'nothing marks the response for the proxy');

        $warned = array_filter(static::$warnings, fn ($line) => str_contains($line, Rsx_FPC::DISABLED_WARNING)
            && str_contains($line, 'Fpc_Ttl_Fixture_Controller::ttl'));
        static::__assert_count(1, $warned, 'one warning naming the route');
    }

    public static function test_render_component_refuses_and_warns()
    {
        static::__capture_warnings();

        static::__assert_throws(\RuntimeException::class, function () {
            Rsx_SSR::render_component('Any_Component', [], 'Any_Bundle');
        }, Rsx_SSR::DISABLED_MESSAGE);

        $warned = array_filter(static::$warnings, fn ($line) => str_contains($line, Rsx_SSR::DISABLED_MESSAGE)
            && str_contains($line, 'Any_Component'));
        static::__assert_count(1, $warned, 'one warning naming the component');
    }
}
