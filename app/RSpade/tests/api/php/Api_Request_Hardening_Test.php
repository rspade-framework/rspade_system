<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Param_Validator;
use App\RSpade\Core\Api\Api_Request_Log_Model;
use App\RSpade\Core\Api\Rsx_Api_Bearer;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * What the external API stores and accepts at its edges.
 *
 *   - an unauthenticated request is logged WITHOUT its body;
 *   - an unparseable JSON body is logged as a size marker, never as its text (it cannot be
 *     redacted);
 *   - purging a key keeps its request history, with api_key_id cleared (ON DELETE SET NULL);
 *   - the Bearer scheme name is case-insensitive;
 *   - an int param is whole-string digits (no trailing newline) inside PHP's integer range;
 *   - the API identity is still in place when the response is rendered, and is gone after.
 *
 * Driven through Rsx_Front_Controller::handle(), as the kernel hands a request over.
 */
class Api_Request_Hardening_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    public static function test_an_unauthenticated_body_is_not_stored()
    {
        $response = static::__send('/api/v1/me', 'POST', 'Bearer rsx_live_' . str_repeat('z', 32), '{"password":"hunter2","note":"x"}');

        static::__assert_equals(401, $response->getStatusCode());
        static::__assert_null(static::__last_log()->request_body);
    }

    public static function test_an_unparseable_body_is_stored_as_a_marker()
    {
        $body = '{"password":"hunter2", broken';
        $response = static::__send('/api/v1/files', 'POST', 'Bearer ' . static::__mint_key(), $body);

        static::__assert_equals(400, $response->getStatusCode());
        $stored = (string) static::__last_log()->request_body;
        static::__assert_false(str_contains($stored, 'hunter2'), 'the unredactable text is not stored');
        static::__assert_equals('[unparseable JSON body, ' . strlen($body) . ' bytes, not stored]', $stored);
    }

    public static function test_purging_a_key_keeps_its_history()
    {
        $key = static::__mint_key();
        static::__send('/api/v1/me', 'GET', 'Bearer ' . $key);
        $log = static::__last_log();
        static::__assert_not_null($log->api_key_id);

        DB::statement('DELETE FROM _api_keys WHERE id = ?', [(int) $log->api_key_id]);

        $after = Api_Request_Log_Model::find($log->id);
        static::__assert_not_null($after, 'the log row survives the purge');
        static::__assert_null($after->api_key_id);
    }

    public static function test_the_bearer_scheme_is_case_insensitive()
    {
        foreach (['bearer ', 'BEARER ', 'Bearer '] as $scheme) {
            $request = Request::create('/api/v1/me', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => $scheme . 'abc']);
            static::__assert_equals('abc', Rsx_Api_Bearer::token_from($request), "scheme '{$scheme}'");
        }

        $request = Request::create('/api/v1/me', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic abc']);
        static::__assert_null(Rsx_Api_Bearer::token_from($request));
    }

    public static function test_int_params_are_whole_digits_in_range()
    {
        $spec = [['name' => 'n', 'type' => 'int', 'required' => true]];

        $ok = Api_Param_Validator::validate($spec, ['n' => '-0042']);
        static::__assert_true($ok['valid']);
        static::__assert_equals(-42, $ok['params']['n']);

        static::__assert_true(Api_Param_Validator::validate($spec, ['n' => (string) PHP_INT_MAX])['valid']);

        foreach (["5\n", '99999999999999999999', '-99999999999999999999', '1e3', ' 5'] as $bad) {
            static::__assert_false(Api_Param_Validator::validate($spec, ['n' => $bad])['valid'], json_encode($bad));
        }
    }

    public static function test_the_identity_ends_with_the_response()
    {
        $response = static::__send('/api/v1/me', 'GET', 'Bearer ' . static::__mint_key());

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_false(Session::is_api_request(), 'the identity is torn down after the response');
        static::__assert_null(Api_Dispatcher::current_key());
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function __mint_key(): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_not_null($user, 'the test baseline carries user 1');

        $user->is_api_access_enabled = 1;
        $user->save();

        return Api_Key_Model::generate(1, 'Hardening probe (test)')['key'];
    }

    private static function __send(string $path, string $method, string $authorization, ?string $json = null)
    {
        $server = [
            'HTTP_AUTHORIZATION' => $authorization,
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $request = Request::create($path, $method, [], [], [], $server, $json);
        app()->instance('request', $request);

        return Rsx_Front_Controller::handle($request);
    }

    private static function __last_log(): Api_Request_Log_Model
    {
        $log = Api_Request_Log_Model::find(Api_Dispatcher::last_request_log_id());
        static::__assert_not_null($log, 'the request is logged');

        return $log;
    }
}
