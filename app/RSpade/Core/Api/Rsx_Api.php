<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
 *
 * document() is the one answer the dispatcher sends exactly as written: a JSON object
 * whose shape a standard fixes (an OpenAPI description, a JSON Schema), which the timing
 * fields would make invalid.
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
     * 200 with a DOCUMENT: a JSON object answered exactly as given - no _duration_ms,
     * _completed_at or echoed _request_ref (Api_Dispatcher::stamp_response() stamps a
     * JsonResponse only, and this is not one). For a body a standard defines down to its top
     * level, where an extra field is invalid - an OpenAPI document's root admits only its own
     * fields and x- extensions:
     *
     *     return Rsx_Api::document(Api_Openapi::document(null, $version));   // Openapi_Api_Controller
     *
     * Still a JSON object like every API body ($data must be an associative array; a list is
     * refused), and like every API body it carries plain values: a money or text-type value
     * in it is its plain string (Api_Dispatcher::plain_body()). Not for ordinary answers,
     * which carry the timing fields every client reads.
     *
     * @param array $data An associative array
     */
    public static function document(array $data): Response
    {
        if ($data !== [] && array_is_list($data)) {
            shouldnt_happen('Rsx_Api::document() answers a JSON object; it was given a list.');
        }

        $data = Api_Dispatcher::plain_body($data);

        return new Response(
            json_encode($data === [] ? new \stdClass() : $data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
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
