<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Dispatch\Php\Ajax_Main_Hook_Probe;
use App\RSpade\Tests\Dispatch\Php\Ajax_Parity_Fixture_Controller;

/**
 * One Ajax core, two browser transports: the SAME endpoint outcome is the SAME envelope
 * whether the client sent it directly (/_ajax/<Controller>/<action>, development) or in a
 * batch (/_ajax/_batch, debug and production).
 *
 * Pinned: success, a returned validation error, a THROWN unauthorized (what
 * Permission::require_permission() raises), a returned not-found, abort(404) and a crash
 * each produce identical envelopes on both transports (the server clock and a crash's
 * error id and stack frames aside); a batched endpoint sees the REAL caller - its address
 * and headers - with only its own parameters as input; the batch refuses, whole and before
 * anything runs, a batch over rsx.ajax.batch_max_calls and a malformed call_id; and the
 * application's Main::pre_dispatch runs once per Ajax request on both transports, its
 * non-null answer halting the request before any endpoint runs.
 *
 * Behavior of record: php artisan rsx:man ajax_error_handling.
 */
class Ajax_Transport_Parity_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    private const FIXTURE = 'Ajax_Parity_Fixture_Controller';

    /** The caller every probe request comes from. */
    private const SERVER = [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_X_PARITY_PROBE' => 'parity-probe',
    ];

    public static function teardown()
    {
        // The container must hold an ordinary console request again: a later class reads
        // the ambient request (its peer address decides who sees diagnostics).
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        Ajax_Parity_Fixture_Controller::$invocations = 0;
    }

    public static function test_success_is_the_same_envelope_on_both_transports()
    {
        $direct = static::__assert_parity('succeed', ['value' => 'hello']);

        static::__assert_true($direct['_success']);
        static::__assert_equals(['echo' => 'hello'], $direct['_ajax_return_value']);
        static::__assert_true(isset($direct['_server_time']), 'the envelope carries the server clock');
    }

    public static function test_a_validation_error_is_the_same_envelope_on_both_transports()
    {
        $direct = static::__assert_parity('invalid');

        static::__assert_false($direct['_success']);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $direct['error_code']);
        static::__assert_equals('Fix the probe', $direct['reason']);
        static::__assert_equals('Name is required', $direct['metadata']['name'] ?? null);
    }

    /**
     * A THROWN AjaxUnauthorizedException is the unauthorized code on both transports - on
     * the direct one it used to be a 'fatal'.
     */
    public static function test_a_thrown_denial_is_unauthorized_on_both_transports()
    {
        $direct = static::__assert_parity('denied');

        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $direct['error_code']);
        static::__assert_equals('Probe permission denied', $direct['reason']);
    }

    public static function test_not_found_and_abort_404_are_not_found_on_both_transports()
    {
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, static::__assert_parity('missing')['error_code']);

        $aborted = static::__assert_parity('aborted');
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $aborted['error_code'], 'abort(404) is the not_found envelope');
    }

    public static function test_a_crash_is_the_same_fatal_envelope_on_both_transports()
    {
        $direct = static::__assert_parity('crash');

        static::__assert_equals(Ajax::ERROR_FATAL, $direct['error_code']);
        static::__assert_equals('probe crash', $direct['error']['error'] ?? null, 'the loopback test caller sees the detail');
    }

    /**
     * A batched endpoint is handed the real request: the caller's address and headers,
     * with its own call's parameters as the input.
     */
    public static function test_a_batched_endpoint_sees_the_real_caller()
    {
        $direct = static::__assert_parity('caller', ['value' => 'mine']);

        static::__assert_equals('203.0.113.7', $direct['_ajax_return_value']['ip']);
        static::__assert_equals('parity-probe', $direct['_ajax_return_value']['probe_header']);
        static::__assert_equals('mine', $direct['_ajax_return_value']['input_value']);
    }

    /**
     * Both transports through the front controller, as the kernel hands them over: the
     * AJAX channel answers the envelope, and a path under /_ajax/ that names no endpoint is
     * the not_found envelope.
     */
    public static function test_the_ajax_channel_dispatches_both_transports()
    {
        // No session: CSRF has nothing to forge against, as for any first request.
        static::__reset_session();

        $response = static::__handle(static::__direct_request('succeed', ['value' => 'x']));
        static::__assert_equals(200, $response->getStatusCode(), 'direct: ' . $response->getContent());
        static::__assert_true(json_decode($response->getContent(), true)['_success'] ?? false, 'direct: ' . $response->getContent());

        $response = static::__handle(static::__batch_request([static::__batch_call(3, 'succeed', ['value' => 'y'])]));
        static::__assert_equals(200, $response->getStatusCode(), 'batch: ' . $response->getContent());
        static::__assert_equals('y', json_decode($response->getContent(), true)['C_3']['_ajax_return_value']['echo'] ?? null);

        $response = static::__handle(Request::create('/_ajax/not/an/endpoint', 'POST', [], [], [], static::SERVER));
        $envelope = json_decode($response->getContent(), true);
        static::__assert_equals(200, $response->getStatusCode(), 'unknown: ' . $response->getContent());
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $envelope['error_code'] ?? null, 'unknown: ' . $response->getContent());
    }

    /**
     * A batch over rsx.ajax.batch_max_calls is refused whole, naming the key, and none of
     * its calls runs.
     */
    public static function test_a_batch_over_the_cap_is_refused_whole()
    {
        $original = config('rsx.ajax.batch_max_calls');
        config(['rsx.ajax.batch_max_calls' => 2]);
        Ajax_Parity_Fixture_Controller::$invocations = 0;

        try {
            $response = Ajax::handle_batch_request(static::__batch_request([
                static::__batch_call(0, 'succeed'),
                static::__batch_call(1, 'succeed'),
                static::__batch_call(2, 'succeed'),
            ]));

            static::__assert_equals(400, $response->getStatusCode());
            static::__assert_contains('rsx.ajax.batch_max_calls', $response->getData(true)['error'] ?? '');
            static::__assert_equals(0, Ajax_Parity_Fixture_Controller::$invocations, 'nothing in a refused batch runs');

            $response = Ajax::handle_batch_request(static::__batch_request([
                static::__batch_call(0, 'succeed'),
                static::__batch_call(1, 'succeed'),
            ]));
            static::__assert_equals(200, $response->getStatusCode(), 'a batch at the cap runs');
        } finally {
            config(['rsx.ajax.batch_max_calls' => $original]);
        }
    }

    /**
     * A call_id that is not a non-negative integer - an array, a string, a repeat - refuses
     * the batch with a 400, before anything runs (an array call_id used to die on "Array to
     * string conversion" outside the per-call handling).
     */
    public static function test_a_malformed_call_id_refuses_the_batch()
    {
        Ajax_Parity_Fixture_Controller::$invocations = 0;

        $shapes = [
            'an array call_id' => [['call_id' => ['x'], 'controller' => self::FIXTURE, 'action' => 'succeed']],
            'a string call_id' => [['call_id' => '1', 'controller' => self::FIXTURE, 'action' => 'succeed']],
            'a negative call_id' => [['call_id' => -1, 'controller' => self::FIXTURE, 'action' => 'succeed']],
            'a repeated call_id' => [static::__batch_call(1, 'succeed'), static::__batch_call(1, 'succeed')],
            'a missing action' => [['call_id' => 1, 'controller' => self::FIXTURE]],
            'params that are not an object' => [['call_id' => 1, 'controller' => self::FIXTURE, 'action' => 'succeed', 'params' => 'x']],
        ];

        foreach ($shapes as $label => $calls) {
            $response = Ajax::handle_batch_request(static::__batch_request($calls));

            static::__assert_equals(400, $response->getStatusCode(), $label);
            static::__assert_true(is_string($response->getData(true)['error'] ?? null), "{$label} names the problem");
        }

        static::__assert_equals(0, Ajax_Parity_Fixture_Controller::$invocations, 'no call of a refused batch ran');
    }

    /**
     * Main::pre_dispatch runs once per Ajax request - direct or batched - with the
     * transport's synthetic keys and, for a direct call, the endpoint it names.
     */
    public static function test_main_pre_dispatch_runs_once_per_ajax_request()
    {
        Ajax_Main_Hook_Probe::$seen = [];
        Ajax_Main_Hook_Probe::$halt_with = null;

        static::__with_probe_main(function () {
            Ajax::handle_browser_request(static::__direct_request('succeed'), self::FIXTURE, 'succeed');
            Ajax::handle_batch_request(static::__batch_request([
                static::__batch_call(0, 'succeed'),
                static::__batch_call(1, 'succeed'),
            ]));
        });

        static::__assert_count(2, Ajax_Main_Hook_Probe::$seen, 'once per request, not once per call');

        [$direct, $batch] = Ajax_Main_Hook_Probe::$seen;
        static::__assert_equals(self::FIXTURE, $direct['controller'] ?? null);
        static::__assert_equals('succeed', $direct['action'] ?? null);
        static::__assert_equals('/_ajax/:controller/:action', $direct['_route'] ?? null);
        static::__assert_equals(Ajax::class, $direct['_handler'] ?? null);
        static::__assert_equals('POST', $direct['_method'] ?? null);
        static::__assert_equals('/_ajax/_batch', $batch['_route'] ?? null);
    }

    /**
     * A non-null answer from Main::pre_dispatch halts the request: it is the response, and
     * no endpoint runs.
     */
    public static function test_main_pre_dispatch_halts_an_ajax_request()
    {
        Ajax_Main_Hook_Probe::$seen = [];
        Ajax_Main_Hook_Probe::$halt_with = response()->json(['halted' => true], 409);
        Ajax_Parity_Fixture_Controller::$invocations = 0;

        try {
            static::__with_probe_main(function () {
                $direct = Ajax::handle_browser_request(static::__direct_request('succeed'), self::FIXTURE, 'succeed');
                static::__assert_equals(409, $direct->getStatusCode());

                $batch = Ajax::handle_batch_request(static::__batch_request([static::__batch_call(0, 'succeed')]));
                static::__assert_equals(409, $batch->getStatusCode());
            });
        } finally {
            Ajax_Main_Hook_Probe::$halt_with = null;
        }

        static::__assert_equals(0, Ajax_Parity_Fixture_Controller::$invocations, 'no endpoint ran');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Call one endpoint directly and batched, assert the two envelopes are identical once
     * the per-response values are set aside, and return the direct envelope.
     */
    private static function __assert_parity(string $action, array $params = []): array
    {
        $direct = Ajax::handle_browser_request(static::__direct_request($action, $params), self::FIXTURE, $action)
            ->getData(true);

        $batch = Ajax::handle_batch_request(static::__batch_request([static::__batch_call(7, $action, $params)]))
            ->getData(true);

        static::__assert_true(isset($batch['C_7']), 'the batch answers under C_<call_id>');

        static::__assert_equals(
            static::__normalize($direct),
            static::__normalize($batch['C_7']),
            "{$action}: the direct and the batched envelope differ"
        );

        return $direct;
    }

    /**
     * Set aside what legitimately differs per response: the clock, a crash's error id and
     * its stack frames (the two transports reach the endpoint through different frames).
     */
    private static function __normalize(array $envelope): array
    {
        static::__assert_true(isset($envelope['_server_time']), 'every envelope carries _server_time');

        unset($envelope['_server_time'], $envelope['error']['error_id'], $envelope['error']['backtrace']);

        return $envelope;
    }

    private static function __direct_request(string $action, array $params = []): Request
    {
        return Request::create('/_ajax/' . self::FIXTURE . '/' . $action, 'POST', $params, [], [], static::SERVER);
    }

    private static function __batch_request(array $calls): Request
    {
        return Request::create('/_ajax/_batch', 'POST', ['batch_calls' => $calls], [], [], static::SERVER);
    }

    private static function __batch_call(int $call_id, string $action, array $params = []): array
    {
        return ['call_id' => $call_id, 'controller' => self::FIXTURE, 'action' => $action, 'params' => $params];
    }

    /**
     * Run with the probe standing in as the application's Main.
     */
    private static function __with_probe_main(callable $fn): void
    {
        $index = &Manifest::$data['data']['php_subclass_index'];
        $original = $index['Main_Abstract'] ?? null;
        $index['Main_Abstract'] = ['Ajax_Main_Hook_Probe'];

        try {
            $fn();
        } finally {
            if ($original === null) {
                unset($index['Main_Abstract']);
            } else {
                $index['Main_Abstract'] = $original;
            }
        }
    }

    private static function __handle(Request $request)
    {
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }
}
