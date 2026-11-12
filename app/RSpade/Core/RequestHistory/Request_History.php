<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\RequestHistory;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use JsonSerializable;

/**
 * Request_History - the writer request history on _transactions, and replay by reference.
 *
 * WHAT IS RECORDED. Every writer request that commits (an automatic #[Writer] endpoint, or a
 * #[Manual_Lock] endpoint that took a site write lock) has its _transactions row - the
 * revision unit of work, minted eagerly at the site lock boundary (Site_Lock::_run_request()).
 * An Ajax or external API writer also records on that row, inside the request's database
 * transaction:
 *
 *   request_fingerprint  sha256 of the canonical JSON of the validated params, `_`-prefixed
 *                        system params excluded (_request_ref, an Ajax call's _answers)
 *   request_payload      what was sent, through Request_Payload_Redactor (credentials
 *                        redacted, capped, uploads omitted). API: the request body; Ajax:
 *                        the call's params
 *   duration_ms          from the start of the call to the moment it was recorded
 *   completed_at         that moment (UTC)
 *
 * and, when the client sent a _request_ref and the request SUCCEEDED:
 *
 *   request_ref          the reference
 *   response_status      the HTTP status it was answered with. An Ajax call is always 200 -
 *                        every Ajax response is - and its outcome is the envelope's _success,
 *                        which is in the body
 *   response_body        the body as sent, JSON: the API response object, or for Ajax the
 *                        envelope's outcome {_success: true, _ajax_return_value} (the side
 *                        channels - server clock, CSRF rotation, console, flash - belong to
 *                        the response that carries them and are never replayed)
 *
 * SUCCESS ONLY. A request that threw (a validation or question exception, a 403, a fault)
 * rolled back and recorded nothing, so the corrected request may reuse its reference. A
 * request that RETURNED a coded failure (response_form_error(), response_form_question(),
 * an API error response with a non-2xx status) commits whatever it wrote and records its
 * row - fingerprint, payload, timing - but never its reference: a failed answer is not one a
 * retry should be handed back, and the corrected request reuses the reference. Readers record
 * nothing. page routes and companion endpoints have their writer row (the eager mint) and
 * carry no references. A request whose transaction committed is a success even when an
 * after-commit callback then throws (reported, never answered: Rsx_DatabaseTransactionsManager), so
 * what was recorded and what was answered agree.
 *
 * BROWSER CALLERS. Rsx_Form sends a reference on every submission, bound to the browser history
 * entry and the form's identity; Ajax.action() keeps one per user action and endpoint
 * (rsx:man form_conventions, RESUBMITTING). Plain Ajax.call() sends none.
 *
 * THE REFERENCE. `_request_ref` (REF_PARAM) is a system parameter on every Ajax and external
 * API endpoint: a string of 1 to REF_MAX_LENGTH characters, anything else refused as a
 * validation error before the call runs. The dispatchers take it out of the params an
 * endpoint receives. It is ALWAYS echoed, unchanged, whenever it was sent - reader or
 * writer, success or error, fresh or replayed: a top-level `_request_ref` in an API response
 * object, `_request_ref` in an Ajax call's envelope beside `_success`. Identity is site +
 * actor + reference: the site the request's lock names, the actor its _transactions row
 * records (a staff user, a portal user, a cross-site identity). A request with no actor
 * (nobody signed in) has no identity to keep a reference under: it is echoed, never honoured.
 *
 * REPLAY (writer requests carrying a reference, under the site write lock; a #[Manual_Lock]
 * request also holds a named lock on site + actor + reference for its whole run):
 *
 *   found, same endpoint, same fingerprint, response stored -> nothing runs; the stored
 *       status and body with _replayed: true and the ORIGINAL _duration_ms / _completed_at;
 *   found, same endpoint, same fingerprint, response pruned  -> 410 request_response_expired
 *       naming the original completed_at;
 *   found on the same endpoint with a different fingerprint -> 409 request_ref_reused naming
 *       the original completed_at and, while it is kept, the original answer (`original`);
 *   found on a different endpoint -> 409 request_ref_reused, nothing more (a client defect);
 *   not found -> the request runs, and is recorded at commit as above.
 *
 * A refusal and a replay are thrown as a Request_History_Answer from inside the boundary, so
 * the writer's transaction - and the row its eager mint wrote - rolls back: answering from
 * the history writes nothing. The dispatcher renders it (Ajax: an envelope; API: a response).
 *
 * PRUNING. Revision_Cleanup_Service::prune_request_payloads() nulls request_payload,
 * response_status and response_body past rsx.request_history.payload_retention_days. The
 * reference, fingerprint and timing are kept with the row.
 *
 * See Request_History_Call (one request's history) and Request_History_Answer.
 */
class Request_History
{
    /** The system parameter a client names a request with. */
    public const REF_PARAM = '_request_ref';

    /** The longest reference accepted, in characters (_transactions.request_ref). */
    public const REF_MAX_LENGTH = 128;

    /** Ajax error code / API error code: the reference names a different request. */
    public const CODE_REF_REUSED = 'request_ref_reused';

    /** Ajax error code / API error code: the request ran, but its stored response was pruned. */
    public const CODE_RESPONSE_EXPIRED = 'request_response_expired';

    /** The named lock a #[Manual_Lock] request holds on site + actor + reference. */
    public const NAMED_LOCK_PREFIX = 'REQUEST_REF_';

    /**
     * What is wrong with a sent reference, or null when it is valid.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function ref_problem(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || mb_strlen($value) > self::REF_MAX_LENGTH) {
            return 'must be a string of 1 to ' . self::REF_MAX_LENGTH . ' characters';
        }

        return null;
    }

    /**
     * Take the reference out of an Ajax call's params: [sent (bool), value (as sent)].
     *
     * @param array $params The call's params; REF_PARAM is removed
     * @return array{0: bool, 1: mixed}
     */
    public static function take_ref(array &$params): array
    {
        if (!array_key_exists(self::REF_PARAM, $params)) {
            return [false, null];
        }

        $value = $params[self::REF_PARAM];
        unset($params[self::REF_PARAM]);

        return [true, $value];
    }

    /**
     * The reference an external API request carries, read off the request itself so every
     * answer - a 401 before the input is assembled included - can echo it: the query string
     * first (it wins in the dispatcher's input precedence), then the JSON or form body.
     * [sent (bool), value (as sent)]. An unparseable JSON body carries none.
     *
     * @param Request $request
     * @return array{0: bool, 1: mixed}
     */
    public static function api_ref(Request $request): array
    {
        if ($request->query->has(self::REF_PARAM)) {
            return [true, $request->query->all()[self::REF_PARAM]];
        }

        $content_type = (string) $request->header('Content-Type', '');

        if (stripos($content_type, 'application/json') !== false) {
            $body = json_decode((string) $request->getContent(), true);
        } else {
            $body = $request->post();
        }

        if (is_array($body) && array_key_exists(self::REF_PARAM, $body)) {
            return [true, $body[self::REF_PARAM]];
        }

        return [false, null];
    }

    /**
     * The fingerprint of a request's params: sha256 hex of their canonical JSON (object keys
     * sorted at every depth), top-level `_`-prefixed system params excluded. An uploaded
     * file is fingerprinted by its name and the sha256 of its bytes.
     *
     * @param array $params The validated params (API), or the call's params (Ajax)
     * @return string
     */
    public static function fingerprint(array $params): string
    {
        $subject = [];

        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }

            $subject[$key] = $value;
        }

        $json = json_encode(
            static::__canonical($subject),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $json);
    }

    /**
     * The canonical form of a value: object keys sorted, value objects serialized.
     */
    private static function __canonical(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            $path = $value->getRealPath();

            return [
                '__file' => $value->getClientOriginalName(),
                'sha256' => $path === false ? null : hash_file('sha256', $path),
            ];
        }

        if ($value instanceof JsonSerializable) {
            return static::__canonical($value->jsonSerialize());
        }

        if (is_object($value)) {
            shouldnt_happen('Request_History::fingerprint(): a param holds a ' . get_class($value) . ', which has no canonical form');
        }

        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = static::__canonical($item);
        }

        // An empty map and an empty list encode alike ([]), as PHP cannot tell them apart.
        return $value;
    }
}
