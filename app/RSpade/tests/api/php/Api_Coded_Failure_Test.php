<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Request_Log_Model;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A CODED failure inside an API endpoint is answered with its own status as the API's
 * JSON error - never the 500 internal_error it used to be - and the request log records
 * that status.
 *
 * abort(404) is 404 not_found; abort(418, 'msg') keeps 418 and its message as http_418;
 * a thrown AjaxUnauthorizedException (Permission::require_permission()) is 403 forbidden.
 * Driven through Rsx_Front_Controller::handle(), as the kernel hands a request over,
 * against fixture endpoints (Api_Abort_Fixture_Api_Controller).
 *
 * Behavior of record: php artisan rsx:man external_api.
 */
class Api_Coded_Failure_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        // The container must hold an ordinary console request again: a later class reads
        // the ambient request (its peer address decides who sees diagnostics).
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    public static function test_abort_404_is_a_404_not_found()
    {
        $response = static::__api_get('/api/v1/test-probe/abort-404');

        static::__assert_equals(404, $response->getStatusCode());
        static::__assert_equals('not_found', static::__code($response));
        static::__assert_logged(404);
    }

    public static function test_another_status_keeps_its_status_and_message()
    {
        $response = static::__api_get('/api/v1/test-probe/abort-418');

        static::__assert_equals(418, $response->getStatusCode());
        static::__assert_equals('http_418', static::__code($response));
        static::__assert_equals('Probe teapot', json_decode($response->getContent(), true)['error']['message'] ?? null);
        static::__assert_logged(418);
    }

    public static function test_a_thrown_denial_is_a_403_forbidden()
    {
        $response = static::__api_get('/api/v1/test-probe/denied');

        static::__assert_equals(403, $response->getStatusCode());
        static::__assert_equals('forbidden', static::__code($response));
        static::__assert_logged(403);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function __api_get(string $path)
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_not_null($user, 'the test baseline carries user 1');

        $user->is_api_access_enabled = 1;
        $user->save();

        $key = Api_Key_Model::generate(1, 'Coded failure probe (test)')['key'];

        $request = Request::create($path, 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }

    private static function __code($response): ?string
    {
        return json_decode($response->getContent(), true)['error']['code'] ?? null;
    }

    private static function __assert_logged(int $status): void
    {
        $log = Api_Request_Log_Model::find(Api_Dispatcher::last_request_log_id());

        static::__assert_not_null($log, 'the request is logged');
        static::__assert_equals($status, (int) $log->status, 'the log carries the real status');
    }
}
