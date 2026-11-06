<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\JsonResponse;
use App\RSpade\Core\Api\Api_Dispatcher;

/**
 * Rsx_Api - app-facing facade for building external API responses.
 *
 * External API controllers (extending Rsx_Api_Controller_Abstract) return an associative
 * array or a model directly for a 200; these helpers cover the remaining shapes - 201 Created
 * and the error family. Every helper returns a JsonResponse with a real HTTP status code, and
 * its body is a JSON object like every API body (Api_Dispatcher::build_response() refuses
 * anything else, a 201 included). An endpoint with nothing else to say answers an object such
 * as ['result' => 'success']. Errors are {"error":{"code","message","fields"?}}, built by
 * Api_Dispatcher::_error() - the one implementation of the error shape.
 *
 * Success payloads serialize through the one shared serializer (Api_Dispatcher::serialize),
 * so a model passed to created() is redacted via toArray() exactly as a bare return would be.
 */
class Rsx_Api
{
    /**
     * 201 Created. $data serializes exactly like a bare endpoint return (models redacted).
     */
    public static function created($data): JsonResponse
    {
        return response()->json(Api_Dispatcher::serialize($data), 201);
    }

    /**
     * 404 Not Found.
     */
    public static function not_found(?string $message = null): JsonResponse
    {
        return static::error('not_found', $message ?? 'Resource not found', 404);
    }

    /**
     * 401 Unauthorized.
     */
    public static function unauthorized(?string $message = null): JsonResponse
    {
        return static::error('unauthorized', $message ?? 'Unauthorized', 401);
    }

    /**
     * 403 Forbidden.
     */
    public static function forbidden(?string $message = null): JsonResponse
    {
        return static::error('forbidden', $message ?? 'Forbidden', 403);
    }

    /**
     * 422 Unprocessable Entity with per-field messages.
     */
    public static function validation_error(array $fields, ?string $message = null): JsonResponse
    {
        return static::error('validation', $message ?? 'Validation failed', 422, $fields);
    }

    /**
     * Arbitrary error response: {"error":{"code","message","fields"?}} with $status.
     */
    public static function error(string $code, string $message, int $status, ?array $fields = null): JsonResponse
    {
        return Api_Dispatcher::_error($code, $message, $status, $fields);
    }
}
