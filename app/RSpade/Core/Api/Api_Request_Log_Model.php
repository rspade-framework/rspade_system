<?php

namespace App\RSpade\Core\Api;

use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;

/**
 * Api_Request_Log_Model - one row per external API request (_api_request_log).
 *
 * Every request that reaches Api_Dispatcher is recorded here: successes and every
 * failure path (401/404/405/422/400/500). Rows carry whatever identity is known
 * (api_key_id/user_id/site_id are nullable - a failed auth has none) plus the verb,
 * path, resolved handler, HTTP status, wall-clock duration, and client IP.
 *
 * WHAT WAS EXCHANGED, not just what was called:
 *
 *   request_body            The payload as sent, REDACTED and CAPPED (25000 bytes
 *                           overall, 4000 per value, each truncation marked). NULL for
 *                           an upload - the test is the request (files present, or a
 *                           multipart content type), never the endpoint name, so it
 *                           holds for any upload endpoint. Also NULL when there was no
 *                           body at all, which is every GET.
 *   response_error_code     This API's error-envelope code, or NULL on a success -
 *                           "response_error_code IS NULL" IS the success predicate.
 *   response_error_message  The envelope's message, capped the same way.
 *   response_bytes          Size of the body actually sent. A streamed or file response
 *                           reports its declared Content-Length, and 0 when it declares
 *                           none - never the cost of buffering it to measure.
 *
 * The redaction list names CREDENTIALS (password, secret, token, authorization,
 * api_key, credential, private_key). A bare 'key' is deliberately not on it: in this
 * API that is the single-use, tenant-scoped attachment key an upload hands back, and it
 * is the thing you most need to see when tracing an attach.
 *
 * api_key_id carries ON DELETE CASCADE, so PURGING a key destroys its request history
 * with it. Revoking does not - that sets _api_keys.is_revoked and keeps the row, which
 * is the difference between the two operations.
 *
 * Infrastructure table: writes are observability, never user-facing data anyone
 * subscribes to, so realtime emission is suppressed. Pruned by Api_Cleanup_Service
 * per config('rsx.api.log_retention_days').
 *
 * @property int $id
 * @property int|null $api_key_id
 * @property int|null $user_id
 * @property int|null $site_id
 * @property string $verb
 * @property string $path
 * @property string|null $handler
 * @property int $status
 * @property int $duration_ms
 * @property string|null $ip
 * @property string|null $request_body
 * @property string|null $response_error_code
 * @property string|null $response_error_message
 * @property int $response_bytes
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _api_request_log
 *
 * @property int $id
 * @property int $api_key_id
 * @property int $user_id
 * @property int $site_id
 * @property string $verb
 * @property string $path
 * @property string $handler
 * @property int $status
 * @property int $duration_ms
 * @property string $ip
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $request_body
 * @property string $response_error_code
 * @property string $response_error_message
 * @property int $response_bytes
 *
 * @mixin \Eloquent
 */
class Api_Request_Log_Model extends Rsx_System_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per API request - the fastest-growing table in the framework.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_api_request_log';

    public static $enums = [];

    /**
     * Per-request infrastructure churn - never kicks the realtime emitter engine.
     * @var bool
     */
    public static $realtime_silent = true;
}
