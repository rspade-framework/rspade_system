<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A 'json' #[Api_Param] through the real channel: the value an endpoint receives from a
 * query string, a form field, a JSON body carrying the value structured and a JSON body
 * carrying it as text, the default, and the 422 for text that does not parse.
 *
 * Through Rsx_Front_Controller::handle(), as Api_Plain_Values_Test drives the channel.
 * Fixture: Api_Json_Param_Fixture_Api_Controller. Behavior of record: rsx:man external_api,
 * JSON PARAMS.
 */
class Api_Json_Param_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
    }

    public static function test_a_query_parameter_is_decoded()
    {
        $key = static::__api_key();
        $query = http_build_query(['filters' => '{"status": 1, "tags": ["a"]}']);

        $response = static::__api($key, 'GET', '/api/v1/test-probe/json/echo?' . $query);

        static::__assert_equals(200, $response['status'], $response['raw']);
        static::__assert_equals(['filters' => ['status' => 1, 'tags' => ['a']]], $response['body']['received']);
    }

    public static function test_a_structured_value_in_a_json_body_is_taken_as_it_is()
    {
        $key = static::__api_key();

        $response = static::__api($key, 'POST', '/api/v1/test-probe/json/submit', [
            'payload' => ['rows' => [['id' => 1], ['id' => 2]], 'dry_run' => true],
        ]);

        static::__assert_equals(200, $response['status'], $response['raw']);
        static::__assert_equals(
            ['payload' => ['rows' => [['id' => 1], ['id' => 2]], 'dry_run' => true], 'options' => ['mode' => 'fast']],
            $response['body']['received'],
            'the structured value as sent, and the array default applied'
        );
    }

    public static function test_json_text_in_a_json_body_is_decoded()
    {
        $key = static::__api_key();

        $response = static::__api($key, 'POST', '/api/v1/test-probe/json/submit', [
            'payload' => '[1, 2, 3]',
            'options' => '{"mode": "slow"}',
        ]);

        static::__assert_equals(200, $response['status'], $response['raw']);
        static::__assert_equals(['payload' => [1, 2, 3], 'options' => ['mode' => 'slow']], $response['body']['received']);
    }

    public static function test_a_scalar_in_a_json_body_reaches_the_endpoint()
    {
        $key = static::__api_key();

        $response = static::__api($key, 'POST', '/api/v1/test-probe/json/submit', ['payload' => 5]);

        static::__assert_equals(200, $response['status'], $response['raw']);
        static::__assert_equals(5, $response['body']['received']['payload']);
    }

    public static function test_a_form_field_is_decoded()
    {
        $key = static::__api_key();

        $request = Request::create('/api/v1/test-probe/json/submit', 'POST', ['payload' => '{"a": {"b": null}}'], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);
        $response = static::__send($request);

        static::__assert_equals(200, $response['status'], $response['raw']);
        static::__assert_equals(['a' => ['b' => null]], $response['body']['received']['payload']);
    }

    public static function test_text_that_does_not_parse_is_a_field_error()
    {
        $key = static::__api_key();

        $response = static::__api($key, 'POST', '/api/v1/test-probe/json/submit', ['payload' => '{"a": 1']);

        static::__assert_equals(422, $response['status'], $response['raw']);
        static::__assert_equals('validation', $response['body']['error']['code'] ?? null);
        static::__assert_equals(['payload' => 'Expected valid JSON'], $response['body']['error']['fields'] ?? null);
    }

    public static function test_a_missing_required_json_param_is_a_field_error()
    {
        $key = static::__api_key();

        $response = static::__api($key, 'POST', '/api/v1/test-probe/json/submit', ['options' => '{}']);

        static::__assert_equals(422, $response['status'], $response['raw']);
        static::__assert_equals(['payload' => 'This field is required'], $response['body']['error']['fields'] ?? null);
    }

    private static function __api_key(): string
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_not_null($user, 'the test baseline carries user 1');

        $user->is_api_access_enabled = 1;
        $user->save();
        static::grant_every_permission(1);

        return Api_Key_Model::generate(1, 'Json param probe (test)')['key'];
    }

    /** One API request with a JSON body: [status, body (decoded), raw]. */
    private static function __api(string $key, string $method, string $path, array $body = []): array
    {
        $request = Request::create($path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ], $method === 'POST' ? json_encode($body) : null);

        return static::__send($request);
    }

    private static function __send(Request $request): array
    {
        app()->instance('request', $request);

        $response = Rsx_Front_Controller::handle($request);

        // The front controller ended the API identity: act as the baseline user again.
        static::__acting_as_user(1);

        $raw = (string) $response->getContent();

        return ['status' => $response->getStatusCode(), 'body' => json_decode($raw, true) ?? [], 'raw' => $raw];
    }
}
