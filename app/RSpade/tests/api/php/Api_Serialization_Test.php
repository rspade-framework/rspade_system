<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\JsonResponse;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Dispatcher::serialize() and build_response() - the shared bare-JSON serializer.
 *
 * A model becomes its redacting toArray(): enum __label + __MODEL present, $neverExport
 * columns absent. Collections and nested arrays recurse; scalars and null pass through.
 * build_response() maps array->200, null->204, JsonResponse->passthrough, and fails loud
 * on a scalar. Models are constructed unsaved (attribute-only), so no database.
 */
class Api_Serialization_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __user(int $id = 1, int $role_id = 100): User_Model
    {
        $u = new User_Model();
        $u->id = $id;
        $u->role_id = $role_id;
        $u->site_id = 1;
        $u->login_user_id = 1;
        $u->email = "user{$id}@example.com";
        $u->name = "User {$id}";

        return $u;
    }

    // -------------------------------------------------------------------------
    // serialize() - model
    // -------------------------------------------------------------------------

    public static function test_model_serializes_with_model_marker_and_enum_label()
    {
        $array = Api_Dispatcher::serialize(static::__user(1, 100));

        static::__assert_equals('User_Model', $array['__MODEL'], '__MODEL identifies the class');
        static::__assert_equals('Developer', $array['role_id__label'], 'BEM-style enum label is composed');
    }

    public static function test_model_serialization_redacts_never_export_columns()
    {
        // The _sessions model declares $neverExport = [session_token, csrf_token, ip_address].
        $session = new Session();
        $session->session_token = 'secret-token';
        $session->csrf_token = 'secret-csrf';
        $session->ip_address = '10.0.0.5';
        $session->site_id = 1;

        $array = Api_Dispatcher::serialize($session);

        static::__assert_false(array_key_exists('session_token', $array), 'session_token redacted');
        static::__assert_false(array_key_exists('csrf_token', $array), 'csrf_token redacted');
        static::__assert_false(array_key_exists('ip_address', $array), 'ip_address redacted');
        static::__assert_equals('Session', $array['__MODEL']);
    }

    // -------------------------------------------------------------------------
    // serialize() - containers, scalars, null
    // -------------------------------------------------------------------------

    public static function test_serialize_array_of_models_recurses()
    {
        $out = Api_Dispatcher::serialize([static::__user(1), static::__user(2)]);

        static::__assert_count(2, $out);
        static::__assert_equals('User_Model', $out[0]['__MODEL']);
        static::__assert_equals('User_Model', $out[1]['__MODEL']);
    }

    public static function test_serialize_collection_becomes_ordered_array()
    {
        $collection = collect([static::__user(1), static::__user(2)]);
        $out = Api_Dispatcher::serialize($collection);

        static::__assert_true(is_array($out), 'collection becomes a plain array');
        static::__assert_count(2, $out);
        static::__assert_equals('User_Model', $out[0]['__MODEL']);
    }

    public static function test_serialize_nested_model_inside_array()
    {
        $out = Api_Dispatcher::serialize([
            'meta' => ['total' => 1],
            'record' => static::__user(9),
        ]);

        static::__assert_equals(1, $out['meta']['total'], 'scalars pass through');
        static::__assert_equals('User_Model', $out['record']['__MODEL'], 'nested model is serialized');
    }

    public static function test_serialize_scalar_and_null_pass_through()
    {
        static::__assert_equals('hello', Api_Dispatcher::serialize('hello'));
        static::__assert_equals(42, Api_Dispatcher::serialize(42));
        static::__assert_null(Api_Dispatcher::serialize(null));
    }

    // -------------------------------------------------------------------------
    // build_response()
    // -------------------------------------------------------------------------

    public static function test_build_response_array_is_200_json()
    {
        $response = Api_Dispatcher::build_response(['ok' => true]);

        static::__assert_equals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        static::__assert_equals(true, $decoded['ok']);
    }

    public static function test_build_response_null_is_204()
    {
        $response = Api_Dispatcher::build_response(null);
        static::__assert_equals(204, $response->getStatusCode());
    }

    public static function test_build_response_passes_through_json_response()
    {
        $original = new JsonResponse(['already' => 'built'], 201);
        $response = Api_Dispatcher::build_response($original);

        static::__assert_true($response === $original, 'the same response instance flows through untouched');
    }

    public static function test_build_response_model_is_200_json_with_marker()
    {
        $response = Api_Dispatcher::build_response(static::__user(1));

        static::__assert_equals(200, $response->getStatusCode());
        $decoded = json_decode($response->getContent(), true);
        static::__assert_equals('User_Model', $decoded['__MODEL']);
    }

    public static function test_build_response_scalar_fails_loud()
    {
        static::__assert_throws(
            \RuntimeException::class,
            function () {
                Api_Dispatcher::build_response('a bare scalar');
            },
            'unsupported type'
        );
    }
}
