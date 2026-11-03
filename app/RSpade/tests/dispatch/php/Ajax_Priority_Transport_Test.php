<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Dispatch\Php\Ajax_Priority_Fixture_Controller;

/**
 * /_ajax/_priority - the priority Ajax channel (Ajax::handle_priority_request()).
 *
 * Pinned: a #[Priority] endpoint is answered there in the _batch envelope; an endpoint
 * without the directive is answered the not_found envelope and does not run; a body that is
 * not exactly one well-formed call is refused whole (400) before anything runs; a #[Priority]
 * endpoint still runs through /_ajax/_batch; a refusal of the whole request answers the
 * call by its id; the surface index carries the directive; and the generated controller
 * stub marks a #[Priority] method with `.priority = true` (and only it).
 *
 * The client half (Ajax.js sending a marked call alone to /_ajax/_priority) is
 * playwright/ajax_priority.js.
 */
class Ajax_Priority_Transport_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    private const FIXTURE = 'Ajax_Priority_Fixture_Controller';

    private const SERVER = ['REMOTE_ADDR' => '203.0.113.9'];

    public static function setup()
    {
        // No session: CSRF has nothing to forge against, as for any first request.
        static::__reset_session();
    }

    public static function teardown()
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        Ajax_Priority_Fixture_Controller::$invocations = 0;
    }

    public static function test_the_surface_index_carries_the_directive()
    {
        $surfaces = Auth_Gates::get_surfaces();

        static::__assert_equals('priority', $surfaces[self::FIXTURE . '::urgent']['throttle'] ?? null);
        static::__assert_false(isset($surfaces[self::FIXTURE . '::ordinary']['throttle']), 'an undeclared endpoint carries no directive');
    }

    public static function test_a_priority_endpoint_is_answered_on_the_priority_channel()
    {
        Ajax_Priority_Fixture_Controller::$invocations = 0;

        $answer = static::__json(static::__dispatch(static::__ajax_request('/_ajax/_priority', [static::__one_call(4, 'urgent', ['value' => 'now'])])));

        static::__assert_equals(['C_4'], array_keys($answer), 'answered in the batch envelope, by call id');
        static::__assert_true($answer['C_4']['_success'] ?? false, json_encode($answer));
        static::__assert_equals('now', $answer['C_4']['_ajax_return_value']['echo'] ?? null);
        static::__assert_equals(1, Ajax_Priority_Fixture_Controller::$invocations);
    }

    public static function test_an_endpoint_without_the_directive_is_not_found_there_and_never_runs()
    {
        Ajax_Priority_Fixture_Controller::$invocations = 0;

        $answer = static::__json(static::__dispatch(static::__ajax_request('/_ajax/_priority', [static::__one_call(2, 'ordinary', ['value' => 'x'])])));

        static::__assert_false($answer['C_2']['_success'] ?? true, json_encode($answer));
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $answer['C_2']['error_code'] ?? null);
        static::__assert_equals(0, Ajax_Priority_Fixture_Controller::$invocations, 'the endpoint body did not run');

        // A name that is no endpoint at all is answered the same way.
        $answer = static::__json(static::__dispatch(static::__ajax_request('/_ajax/_priority', [
            ['call_id' => 1, 'controller' => 'No_Such_Controller', 'action' => 'nothing', 'params' => []],
        ])));
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $answer['C_1']['error_code'] ?? null);
    }

    public static function test_anything_but_exactly_one_call_is_refused_whole()
    {
        Ajax_Priority_Fixture_Controller::$invocations = 0;

        $two = static::__dispatch(static::__ajax_request('/_ajax/_priority', [
            static::__one_call(1, 'urgent'),
            static::__one_call(2, 'urgent'),
        ]));
        static::__assert_equals(400, $two->getStatusCode(), $two->getContent());
        static::__assert_contains('exactly one call', static::__json($two)['error'] ?? '');

        $none = static::__dispatch(static::__ajax_request('/_ajax/_priority', []));
        static::__assert_equals(400, $none->getStatusCode(), $none->getContent());

        $malformed = static::__dispatch(static::__ajax_request('/_ajax/_priority', [['call_id' => -1, 'controller' => self::FIXTURE, 'action' => 'urgent']]));
        static::__assert_equals(400, $malformed->getStatusCode(), $malformed->getContent());

        static::__assert_equals(0, Ajax_Priority_Fixture_Controller::$invocations, 'nothing in a refused request ran');
    }

    public static function test_a_priority_endpoint_still_runs_through_the_batch()
    {
        Ajax_Priority_Fixture_Controller::$invocations = 0;

        $answer = static::__json(static::__dispatch(static::__ajax_request('/_ajax/_batch', [
            static::__one_call(1, 'urgent', ['value' => 'a']),
            static::__one_call(2, 'ordinary', ['value' => 'b']),
        ])));

        static::__assert_equals('a', $answer['C_1']['_ajax_return_value']['echo'] ?? null, json_encode($answer));
        static::__assert_equals('b', $answer['C_2']['_ajax_return_value']['echo'] ?? null, json_encode($answer));
        static::__assert_equals(2, Ajax_Priority_Fixture_Controller::$invocations);
    }

    public static function test_a_request_refusal_answers_the_priority_call_by_its_id()
    {
        $request = Request::create('/_ajax/_priority', 'POST', [], [], [], static::SERVER + ['CONTENT_TYPE' => 'application/json'],
            json_encode(['batch_calls' => [static::__one_call(7, 'urgent')]]));

        $answer = Ajax::request_refusal($request, ['_success' => false, 'error_code' => 'unauthorized', 'reason' => 'CSRF token mismatch'])->getData(true);

        static::__assert_equals(['C_7'], array_keys($answer), json_encode($answer));
        static::__assert_equals('CSRF token mismatch', $answer['C_7']['reason'] ?? null);
    }

    public static function test_the_generated_stub_marks_only_the_priority_method()
    {
        // Both halves are checked directly rather than through the stub file on disk: a test
        // fixture's stub is removed by any manifest rebuild without the test trees (an
        // ordinary request in the same worker does one), so reading the file is order-dependent.
        $surfaces = Auth_Gates::get_surfaces();
        static::__assert_equals('priority', $surfaces[self::FIXTURE . '::urgent']['throttle'] ?? null, 'the surface index marks the priority endpoint');
        static::__assert_true(($surfaces[self::FIXTURE . '::ordinary']['throttle'] ?? null) === null, 'the ordinary endpoint carries no mark');

        $generate = new \ReflectionMethod(\App\RSpade\Core\Controller\Controller_Stub_ManifestSupport::class, '_generate_stub_content');
        $content = $generate->invoke(null, self::FIXTURE, [
            'urgent' => ['name' => 'urgent', 'priority' => true],
            'ordinary' => ['name' => 'ordinary', 'priority' => false],
        ]);

        static::__assert_contains(self::FIXTURE . ".urgent.priority = true;", $content);
        static::__assert_false(str_contains($content, self::FIXTURE . '.ordinary.priority'), 'the ordinary method is not marked');
        static::__assert_contains('return Ajax.call(' . self::FIXTURE . '.urgent, params);', $content, 'the method hands itself to Ajax.call()');
    }

    private static function __one_call(int $call_id, string $action, array $params = []): array
    {
        return ['call_id' => $call_id, 'controller' => self::FIXTURE, 'action' => $action, 'params' => $params];
    }

    private static function __ajax_request(string $path, array $calls): Request
    {
        return Request::create($path, 'POST', ['batch_calls' => $calls], [], [], static::SERVER);
    }

    private static function __dispatch(Request $request)
    {
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }

    private static function __json($response): array
    {
        return json_decode($response->getContent(), true) ?? [];
    }
}
