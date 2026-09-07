<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Api response helpers - the app-facing success/error family. Each returns a
 * JsonResponse with the right status code and the uniform {"error":{code,message,fields?}}
 * error shape (success bodies are bare). No database.
 */
class Api_Response_Helpers_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __decode($response): array
    {
        return json_decode($response->getContent(), true) ?? [];
    }

    public static function test_created_is_201_with_bare_body()
    {
        $response = Rsx_Api::created(['id' => 5]);

        static::__assert_equals(201, $response->getStatusCode());
        $body = static::__decode($response);
        static::__assert_equals(5, $body['id'], 'success body is bare (no error envelope)');
        static::__assert_false(array_key_exists('error', $body));
    }

    public static function test_no_content_is_204_empty()
    {
        $response = Rsx_Api::no_content();

        static::__assert_equals(204, $response->getStatusCode());
        static::__assert_equals('', $response->getContent(), '204 body is empty');
    }

    public static function test_not_found_is_404_error_shape()
    {
        $response = Rsx_Api::not_found();

        static::__assert_equals(404, $response->getStatusCode());
        $body = static::__decode($response);
        static::__assert_equals('not_found', $body['error']['code']);
        static::__assert_not_empty($body['error']['message']);
    }

    public static function test_unauthorized_is_401()
    {
        $response = Rsx_Api::unauthorized();
        static::__assert_equals(401, $response->getStatusCode());
        static::__assert_equals('unauthorized', static::__decode($response)['error']['code']);
    }

    public static function test_forbidden_is_403()
    {
        $response = Rsx_Api::forbidden();
        static::__assert_equals(403, $response->getStatusCode());
        static::__assert_equals('forbidden', static::__decode($response)['error']['code']);
    }

    public static function test_validation_error_is_422_with_fields()
    {
        $response = Rsx_Api::validation_error(['email' => 'Required'], 'Bad input');

        static::__assert_equals(422, $response->getStatusCode());
        $body = static::__decode($response);
        static::__assert_equals('validation', $body['error']['code']);
        static::__assert_equals('Bad input', $body['error']['message']);
        static::__assert_equals('Required', $body['error']['fields']['email']);
    }

    public static function test_error_builds_arbitrary_status_and_omits_absent_fields()
    {
        $response = Rsx_Api::error('teapot', 'I am a teapot', 418);

        static::__assert_equals(418, $response->getStatusCode());
        $body = static::__decode($response);
        static::__assert_equals('teapot', $body['error']['code']);
        static::__assert_equals('I am a teapot', $body['error']['message']);
        static::__assert_false(array_key_exists('fields', $body['error']), 'fields omitted when null');
    }

    public static function test_error_includes_fields_when_present()
    {
        $response = Rsx_Api::error('validation', 'Bad', 422, ['x' => 'nope']);

        $body = static::__decode($response);
        static::__assert_equals('nope', $body['error']['fields']['x']);
    }
}
