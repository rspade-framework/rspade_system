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
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Api\Php\Api_Main_Hook_Probe;

/**
 * Main_Abstract::pre_dispatch() runs for an external API call - after the bearer identity
 * and the gates, before the controller - and any non-null return refuses the call with
 * 403 account_refused. This is how an application's own account policy (a suspension,
 * an unpaid invoice) reaches its API keys.
 *
 * Driven in-process through Api_Dispatcher::dispatch() against the framework's own
 * GET /api/v1/me. An application has exactly one Main, so the test points the manifest's
 * Main_Abstract subclass entry at Api_Main_Hook_Probe for one dispatch at a time.
 *
 * Behavior of record: php artisan rsx:man external_api (Main_Abstract::pre_dispatch).
 */
class Api_Main_Pre_Dispatch_Test extends Rsx_Test_Abstract
{
    private static function __bearer_request(string $key): Request
    {
        return Request::create('/api/v1/me', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
    }

    /**
     * Dispatch with the probe standing in as the application's Main.
     */
    private static function __dispatch_with_probe(Request $request)
    {
        $index = &Manifest::$data['data']['php_subclass_index'];
        $original = $index['Main_Abstract'] ?? null;
        $index['Main_Abstract'] = ['Api_Main_Hook_Probe'];

        try {
            return Api_Dispatcher::dispatch('/api/v1/me', 'GET', [], $request);
        } finally {
            if ($original === null) {
                unset($index['Main_Abstract']);
            } else {
                $index['Main_Abstract'] = $original;
            }
        }
    }

    private static function __mint_key(): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_not_null($user, 'the test baseline carries user 1');

        $user->is_api_access_enabled = 1;
        $user->save();

        return Api_Key_Model::generate(1, 'Main pre_dispatch probe (test)')['key'];
    }

    public static function test_the_hook_runs_with_the_handler_and_continues_on_null()
    {
        $key = static::__mint_key();

        Api_Main_Hook_Probe::$seen = [];
        Api_Main_Hook_Probe::$refuse_with = null;

        $response = static::__dispatch_with_probe(static::__bearer_request($key));

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_count(1, Api_Main_Hook_Probe::$seen, 'the hook ran exactly once');
        static::__assert_contains('Identity_Api_Controller', (string) (Api_Main_Hook_Probe::$seen[0]['_handler'] ?? ''));
        static::__assert_equals('GET', Api_Main_Hook_Probe::$seen[0]['_method'] ?? null);
    }

    public static function test_a_non_null_return_is_403_account_refused()
    {
        $key = static::__mint_key();

        Api_Main_Hook_Probe::$seen = [];
        Api_Main_Hook_Probe::$refuse_with = false;

        try {
            $response = static::__dispatch_with_probe(static::__bearer_request($key));
        } finally {
            Api_Main_Hook_Probe::$refuse_with = null;
        }

        static::__assert_equals(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        static::__assert_equals('account_refused', $body['error']['code'] ?? null);

        $log = Api_Request_Log_Model::find(Api_Dispatcher::last_request_log_id());
        static::__assert_not_null($log, 'the refusal is logged');
        static::__assert_equals(403, (int) $log->status);
        static::__assert_equals('account_refused', $log->response_error_code);
    }

    public static function test_an_unauthenticated_call_never_reaches_the_hook()
    {
        Api_Main_Hook_Probe::$seen = [];

        $response = static::__dispatch_with_probe(static::__bearer_request('rsx_not_a_real_key'));

        static::__assert_equals(401, $response->getStatusCode());
        static::__assert_count(0, Api_Main_Hook_Probe::$seen, 'the hook runs after the bearer identity');
    }
}
