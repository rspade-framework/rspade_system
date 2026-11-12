<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Api;

use Illuminate\Http\Request;

/**
 * Request_Payload_Redactor - THE rules for storing what a request was sent.
 *
 * Two records keep a copy of a request's input for later reading: the external API's
 * request log (_api_request_log.request_body) and the writer request history
 * (_transactions.request_payload, Core/RequestHistory/Request_History). Both store it through
 * here, so "what is redacted, what is capped, what is never stored" has one answer:
 *
 *   - CREDENTIALS are redacted: the value under any key matching REDACT_KEYS, at any depth,
 *     is replaced with [redacted];
 *   - every scalar is capped at VALUE_MAX_BYTES, the whole payload at BODY_MAX_BYTES, each
 *     cut marked with TRUNCATION_MARKER so a truncated value is never mistaken for the value;
 *   - an UPLOAD is never stored (a multipart request, or one carrying files): the record is
 *     an activity record, not a copy of the blob store;
 *   - an unparseable JSON body is never stored as the text it was (it cannot be redacted:
 *     there are no keys to find a password under) - only its size.
 *
 * It lives beside the API log that first defined these rules; the request history reuses
 * them so a payload is redacted identically wherever it is kept.
 */
class Request_Payload_Redactor
{
    /**
     * Whole-payload ceiling for a stored payload. A record of the traffic, not a copy of
     * it: a payload larger than this is truncated with a marker.
     */
    public const BODY_MAX_BYTES = 25000;

    /**
     * Per-value ceiling inside that payload, so one enormous field cannot consume the
     * whole budget and push every other field out of the record.
     */
    public const VALUE_MAX_BYTES = 4000;

    /**
     * Appended wherever a cap bit, so a truncated value is never mistaken for the value.
     */
    public const TRUNCATION_MARKER = '...[truncated]';

    /**
     * Keys whose VALUE is replaced with [redacted] before the payload is stored. Credentials
     * only - see redact() for why a bare 'key' is deliberately absent.
     */
    public const REDACT_KEYS = '/pass(word|wd)?|secret|token|authorization|api[_-]?key|credential|private[_-]?key/i';

    /**
     * A request's body as it will be stored: redacted, per-value capped, whole-payload
     * capped - or NULL when there is nothing to store or it must not be stored.
     *
     * NEVER FOR AN UPLOAD. A multipart request carries file bytes: the test is the REQUEST
     * (files present, or a multipart content type), not the endpoint name, so it holds for
     * POST /api/v1/files and for any future upload endpoint without either knowing about
     * the other.
     *
     * @param Request $request
     * @return string|null
     */
    public static function capture_request(Request $request): ?string
    {
        if (!empty($request->allFiles())) {
            return null;
        }

        $content_type = (string) $request->header('Content-Type', '');
        if (stripos($content_type, 'multipart/form-data') !== false) {
            return null;
        }

        if (stripos($content_type, 'application/json') !== false) {
            $raw = (string) $request->getContent();
            $decoded = $raw === '' ? null : json_decode($raw, true);

            // An unparseable body cannot be redacted, so it is never stored as the text it
            // was. Its size is the evidence kept.
            if (!is_array($decoded)) {
                return $raw === '' ? null : '[unparseable JSON body, ' . strlen($raw) . ' bytes, not stored]';
            }

            $body = $decoded;
        } else {
            $body = $request->post();
        }

        return static::encode($body);
    }

    /**
     * An already-decoded payload (an Ajax call's params) as it will be stored, or NULL when
     * it is empty. Redacted and capped exactly as capture_request() stores a body.
     *
     * @param array $payload
     * @return string|null
     */
    public static function encode(array $payload): ?string
    {
        if (empty($payload)) {
            return null;
        }

        $encoded = json_encode(static::redact($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }

        return static::cap($encoded, self::BODY_MAX_BYTES);
    }

    /**
     * Redact secret-looking keys and cap every scalar, at any depth.
     *
     * WHAT IS NOT REDACTED MATTERS AS MUCH AS WHAT IS: a bare 'key' is left alone, because
     * in the external API that is the attachment key an upload hands back - the thing you
     * most need to see when tracing an attach - and it is single-use, tenant-scoped and
     * already spent by the time anyone reads the record. The list names credentials.
     *
     * An object value (an uploaded file reaching here through an already-assembled params
     * map) is recorded as its class name only, never its contents.
     *
     * @param array $body
     * @return array
     */
    public static function redact(array $body): array
    {
        $out = [];

        foreach ($body as $key => $value) {
            if (is_string($key) && preg_match(self::REDACT_KEYS, $key)) {
                $out[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = static::redact($value);
                continue;
            }

            if (is_object($value)) {
                $out[$key] = '[' . class_basename($value) . ']';
                continue;
            }

            $out[$key] = is_string($value) ? static::cap($value, self::VALUE_MAX_BYTES) : $value;
        }

        return $out;
    }

    /**
     * Truncate to $max bytes, marking it when truncation happened.
     *
     * @param string $value
     * @param int $max
     * @return string
     */
    public static function cap(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . self::TRUNCATION_MARKER;
    }
}
