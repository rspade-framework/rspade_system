<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Request_Log_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Request_Log_Model - the per-request observability row. A row persists with the
 * fields the dispatcher writes, reads back intact, and accepts NULL identity columns
 * (a pre-auth failure has no api_key_id/user_id/site_id). Default transaction isolation.
 *
 * api_key_id is a REAL foreign key with ON DELETE CASCADE, so a row must name a key that
 * exists - a fabricated id is rejected by the database, which is the point. Purging a key
 * takes its request history with it; revoking (is_revoked) keeps both.
 */
class Api_Request_Log_Test extends Rsx_Test_Abstract
{
    /**
     * A real key, because api_key_id is a foreign key. Returns the model.
     */
    private static function __make_key(string $name = 'log test'): Api_Key_Model
    {
        $generated = Api_Key_Model::generate(1, $name . ' ' . uniqid());

        return $generated['model'];
    }

    public static function test_row_persists_and_reads_back()
    {
        $key = static::__make_key();

        $log = new Api_Request_Log_Model();
        $log->api_key_id = $key->id;
        $log->user_id = 3;
        $log->site_id = 1;
        $log->verb = 'GET';
        $log->path = '/api/v1/contacts';
        $log->handler = 'Foo_Api_Controller::list';
        $log->status = 200;
        $log->duration_ms = 12;
        $log->ip = '203.0.113.7';
        $log->request_body = '{"search":"acme"}';
        $log->response_error_code = null;
        $log->response_error_message = null;
        $log->response_bytes = 2247;
        $log->save();

        $fresh = Api_Request_Log_Model::find($log->id);
        static::__assert_not_null($fresh);
        static::__assert_equals('GET', $fresh->verb);
        static::__assert_equals('/api/v1/contacts', $fresh->path);
        static::__assert_equals('Foo_Api_Controller::list', $fresh->handler);
        static::__assert_equals(200, $fresh->status);
        static::__assert_equals(12, $fresh->duration_ms);
        static::__assert_equals('203.0.113.7', $fresh->ip);
        static::__assert_equals('{"search":"acme"}', $fresh->request_body);
        static::__assert_equals(2247, $fresh->response_bytes);
        static::__assert_null($fresh->response_error_code, 'a success carries no error code');
    }

    /**
     * PURGING a key destroys its request history - the cascade, at the database.
     */
    public static function test_purging_a_key_cascades_its_log_rows()
    {
        $key = static::__make_key('cascade');

        $log = new Api_Request_Log_Model();
        $log->api_key_id = $key->id;
        $log->verb = 'GET';
        $log->path = '/api/v1/me';
        $log->status = 200;
        $log->duration_ms = 4;
        $log->save();

        $log_id = $log->id;
        static::__assert_not_null(Api_Request_Log_Model::find($log_id), 'the row exists first');

        $key->delete();

        static::__assert_null(Api_Request_Log_Model::find($log_id), 'purging the key took its history');
    }

    /**
     * REVOKING keeps both - it is a flag on a surviving row, not a delete.
     */
    public static function test_revoking_a_key_keeps_its_log_rows()
    {
        $key = static::__make_key('revoke');

        $log = new Api_Request_Log_Model();
        $log->api_key_id = $key->id;
        $log->verb = 'GET';
        $log->path = '/api/v1/me';
        $log->status = 200;
        $log->duration_ms = 4;
        $log->save();

        $key->revoke();

        static::__assert_not_null(Api_Request_Log_Model::find($log->id), 'revoking preserves history');
    }

    /**
     * An error row carries the envelope's code and message.
     */
    public static function test_error_row_records_the_envelope_values()
    {
        $log = new Api_Request_Log_Model();
        $log->verb = 'POST';
        $log->path = '/api/v1/clients/create';
        $log->status = 422;
        $log->duration_ms = 6;
        $log->response_error_code = 'validation';
        $log->response_error_message = 'Validation failed';
        $log->response_bytes = 138;
        $log->save();

        $fresh = Api_Request_Log_Model::find($log->id);
        static::__assert_equals('validation', $fresh->response_error_code);
        static::__assert_equals('Validation failed', $fresh->response_error_message);
        static::__assert_equals(138, $fresh->response_bytes);
    }

    public static function test_nullable_identity_columns_accept_null()
    {
        // A failed-auth (401) row: no api_key_id/user_id/site_id, no resolved handler.
        $log = new Api_Request_Log_Model();
        $log->api_key_id = null;
        $log->user_id = null;
        $log->site_id = null;
        $log->verb = 'POST';
        $log->path = '/api/v1/contacts/create';
        $log->handler = null;
        $log->status = 401;
        $log->duration_ms = 3;
        $log->ip = null;
        $log->save();

        $fresh = Api_Request_Log_Model::find($log->id);
        static::__assert_not_null($fresh);
        static::__assert_null($fresh->api_key_id);
        static::__assert_null($fresh->user_id);
        static::__assert_null($fresh->site_id);
        static::__assert_null($fresh->handler);
        static::__assert_null($fresh->ip);
        static::__assert_equals(401, $fresh->status);
    }

    public static function test_model_is_realtime_silent()
    {
        // Infrastructure churn must never kick the realtime emitter engine.
        static::__assert_true(Api_Request_Log_Model::$realtime_silent);
    }
}
