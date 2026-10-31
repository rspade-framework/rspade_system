<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Ajax;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use App\RSpade\Core\Ajax\Exceptions\AjaxAuthRequiredException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFatalErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxNotFoundException;
use App\RSpade\Core\Ajax\Exceptions\AjaxQuestionException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Debug\Rsx_Diagnostics;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Metrics\Rsx_Metrics;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Response\Rsx_Response_Abstract;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;
use App\RSpade\Lib\Flash\Flash_Alert;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Ajax - the internal endpoint channel: #[Ajax_Endpoint] methods called from JavaScript.
 *
 * ONE CORE, THREE CALLERS. execute() is the whole of calling an endpoint - resolve the
 * name, check the realm and the #[Auth] gates, rehydrate text envelopes, give the call its
 * own Turnstile latch and revision unit of work, run the controller's pre_dispatch and the
 * action, fire rsx.post_dispatch - and it answers the endpoint's raw result or throws. Its
 * callers only differ in what they do with that answer:
 *
 *   /_ajax/_batch                 handle_batch_request(): N calls, N envelopes, one
 *                                 response - THE browser transport (Ajax.js sends every
 *                                 call here, a lone call as a batch of one)
 *   /_ajax/<Controller>/<action>  handle_browser_request(): one call answered with its bare
 *                                 envelope, for a hand-made request (curl, a test). It is a
 *                                 batch of one through the same handle_calls().
 *   Ajax::internal()              server-side PHP, rsx:ajax, tests: the value, or the coded
 *                                 exception (AjaxUnauthorizedException etc.)
 *
 * ONE PER-CALL PATH. handle_calls() runs every browser call the same way, alone or in a
 * batch: the session-lifetime and site-membership checks, the realm's Main::pre_dispatch
 * with the call's own controller and action, then call_envelope(). So the identity a call
 * runs under is checked for THAT call, even when an earlier call in the same request
 * signed somebody out or switched site.
 *
 * ONE ENVELOPE. call_envelope() turns one call into the {_success, ...} array. An exception
 * raised by the endpoint is mapped by error_envelope(); a failure that refuses the whole
 * REQUEST (CSRF, a fault outside any call) is answered by request_refusal(), which gives a
 * batch one envelope per call_id - so every call a client sent is always answered.
 *
 * The browser transport is the AJAX channel of Rsx_Request_Channel: a POST to /_ajax/...
 * in its realm. Dispatcher routes it here after the realm preamble (CSRF, dev-auth, the
 * portal's init); there is no route row for it. See rsx:man ajax_error_handling.
 */
class Ajax
{
    // Error code constants
    const ERROR_VALIDATION = 'validation';
    const ERROR_NOT_FOUND = 'not_found';
    const ERROR_UNAUTHORIZED = 'unauthorized';
    const ERROR_AUTH_REQUIRED = 'auth_required';
    const ERROR_SESSION_EXPIRED = 'session_expired';  // The sign-in outlived rsx.sessions.* and was just ended
    const ERROR_TOO_MANY_REQUESTS = 'too_many_requests';  // A rate limit refused the call (HTTP 429 elsewhere)
    const ERROR_FATAL = 'fatal';
    const ERROR_GENERIC = 'generic';
    const ERROR_QUESTION = 'question';        // Not a failure: the endpoint is asking the user something
    const ERROR_SERVER = 'server_error';      // Client-generated (HTTP 5xx, or an answer missing a call)
    const ERROR_NETWORK = 'network_error';    // Client-generated (connection failed)

    /**
     * The default for config('rsx.ajax.batch_max_calls'): the most calls one /_ajax/_batch
     * request may carry. The client flushes a batch at Ajax.MAX_BATCH_SIZE (20) pending
     * calls, so the cap refuses only a request no RSX page sends.
     */
    public const DEFAULT_BATCH_MAX_CALLS = 100;

    /**
     * The refusal a portal call gets during "View as Client" when its endpoint is not
     * marked #[Portal_Impersonation_Readable].
     */
    public const IMPERSONATION_READ_ONLY_MESSAGE = 'This is a read-only session; changes are disabled.';

    /**
     * True while an endpoint call (execute()) is on the stack - any transport, any depth.
     * Nesting-safe: each call saves and restores it.
     */
    private static bool $__in_endpoint_call = false;

    /**
     * Is an Ajax endpoint call (Ajax::execute()) on the stack?
     *
     * Framework-internal. It exists so a check that must STOP an endpoint call can shape
     * its failure for the caller it actually has: inside a call the answer is a coded
     * exception, which every caller of execute() maps (the envelope, or the exception
     * Ajax::internal() contracts for). Rsx_Turnstile reads it.
     */
    public static function _is_internal_call(): bool
    {
        return static::$__in_endpoint_call;
    }

    /**
     * The upload size ceiling as a string you can put in front of a user: "100 MB".
     *
     * The PHP mirror of Ajax.max_file_size_human(), for Blade and any server-rendered
     * label. Identical rounding, identical output - a notice rendered server-side and the
     * same notice rendered by a component must not disagree by a decimal place.
     *
     * Every hardcoded "Max size 25MB" in a template is a number that will be wrong the day
     * the limit changes, and nobody will notice, because nothing breaks - the label just
     * quietly lies. This reads config('rsx.files.max_file_size'), the same value /_upload
     * enforces, so the notice and the enforcement cannot drift apart.
     *
     *     <div class="form-text">Max size {{ Ajax::max_file_size_human() }}.</div>
     *
     * @return string e.g. "100 MB", "1.2 GB", "12 GB", or "Unlimited" when uncapped
     */
    public static function max_file_size_human(): string
    {
        return static::bytes_to_size_label((int) config('rsx.files.max_file_size', 0));
    }

    /**
     * The label formatter behind max_file_size_human(), exposed for an app that caps
     * tighter than the framework and wants its own number rendered the same way.
     *
     * Rounding is chosen for a LABEL, not for accuracy: whole MB up to 1 GB, one decimal
     * from 1 GB to 10 GB (so 1.2 GB does not read as "1 GB"), whole GB above that. Below
     * 1 MB it falls back to whole KB rather than rounding to a meaningless "0 MB".
     *
     * Distinct from bytes_to_human(), deliberately: that one is a faithful 2-decimal
     * rendering of an ACTUAL size ("1.22 GB"), which is what you want when reporting the
     * file someone just uploaded. This one is a round number for a limit.
     *
     * @param int $bytes 0 (or anything non-positive) means no ceiling
     * @return string
     */
    public static function bytes_to_size_label(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'Unlimited';
        }

        $kb = 1024;
        $mb = $kb * 1024;
        $gb = $mb * 1024;

        if ($bytes < $mb) {
            return round($bytes / $kb) . ' KB';
        }
        if ($bytes < $gb) {
            return round($bytes / $mb) . ' MB';
        }
        if ($bytes < 10 * $gb) {
            // One decimal, trailing .0 dropped: "1.2 GB", but "2 GB" not "2.0 GB".
            return (float) number_format($bytes / $gb, 1, '.', '') . ' GB';
        }

        return round($bytes / $gb) . ' GB';
    }

    /**
     * Get default message for error code
     *
     * @param string $error_code One of the ERROR_* constants
     * @return string Default user-friendly message
     */
    public static function get_default_message(string $error_code): string
    {
        return match ($error_code) {
            self::ERROR_VALIDATION => 'Please correct the errors below',
            self::ERROR_NOT_FOUND => 'The requested record was not found',
            self::ERROR_UNAUTHORIZED => 'You do not have permission to perform this action',
            self::ERROR_AUTH_REQUIRED => 'Please log in to continue',
            self::ERROR_SESSION_EXPIRED => 'Your session has expired. Please sign in again.',
            self::ERROR_TOO_MANY_REQUESTS => "You're doing that too fast",
            self::ERROR_FATAL => 'A fatal error has occurred',
            self::ERROR_SERVER => 'A server error occurred. Please try again.',
            self::ERROR_NETWORK => 'Could not connect to server. Please check your connection.',
            self::ERROR_GENERIC => 'An error has occurred',
            self::ERROR_QUESTION => 'A question is pending',
            default => 'An error has occurred',
        };
    }

    /**
     * Call one Ajax endpoint: THE core every caller shares.
     *
     * In order: resolve Controller::action to an #[Ajax_Endpoint] (one uniform refusal for
     * a name that is not one); the surface's realm and #[Auth] gates, in the request's
     * realm (a denial answers response_unauthorized(), and the endpoint never runs); text
     * envelopes rehydrated into their value objects; then, as ONE UNIT - its own Turnstile
     * latch, its own revision transaction, both handed back to the calling scope afterwards
     * - the controller's pre_dispatch, the action, and the rsx.post_dispatch event.
     *
     * $request is what the endpoint receives. The browser transport passes the real
     * request; a batch passes one call_request() per call - the real request (its IP, its
     * headers, its cookies, its session) carrying that call's parameters as its input.
     *
     * @param string $controller_name The controller's SIMPLE class name
     * @param string $action_name The endpoint method
     * @param array $params The call's parameters, as sent (envelopes not yet rehydrated)
     * @param Request $request The request the endpoint receives
     * @return mixed The endpoint's raw result - a value, or an Rsx_Response_Abstract
     * @throws Throwable Whatever the endpoint raised
     */
    public static function execute(string $controller_name, string $action_name, array $params, Request $request)
    {
        $controller_class = static::_resolve_endpoint_class($controller_name, $action_name);
        Rsx_Metrics::name_ajax_call($controller_name . '::' . $action_name);

        if (!Auth_Gates::surface_permits($controller_name . '::' . $action_name)) {
            // The page channel's split: nobody signed in in this realm is asked to sign in;
            // a signed-in caller the gates refuse is unauthorized. So a page whose sign-in
            // ended elsewhere gets the session-ended handling, not a permission error.
            return static::__realm_has_identity() ? response_unauthorized() : response_auth_required();
        }

        if (static::__refused_while_impersonating($controller_name, $action_name)) {
            return response_unauthorized(self::IMPERSONATION_READ_ONLY_MESSAGE);
        }

        $params = Rsx_Text_Abstract::wrap_request_envelopes($params);

        // The call is its own unit, whatever called it. A fresh Turnstile latch, so a batch
        // cannot launder one call's verification across its siblings; a fresh revision
        // transaction, so a batch cannot file unrelated endpoints' writes under one action.
        // The calling scope's latch and transaction are restored afterwards: a handler that
        // validated and then made a nested call has not lost its own validation.
        $previous_in_call = static::$__in_endpoint_call;
        $previous_turnstile_checked = Rsx_Turnstile::_was_checked();
        $previous_revision_state = Revision::_snapshot_request_state();

        static::$__in_endpoint_call = true;
        Rsx_Turnstile::_reset_request_state();
        Revision::_reset_request_state('ajax', $controller_name . '::' . $action_name);

        // The call's own request is THE request while it runs: request(), the Request
        // facade and a helper that reads either see this call's parameters, never the
        // batch that carried it.
        $previous_request = app('request');
        static::__bind_request($request);

        // A call that leaves a database transaction open would carry its siblings in the
        // same request into it: their realtime frames wait for level 0, and their writes
        // are rolled back when the request ends after they reported success.
        $transaction_level = DB::transactionLevel();
        $leaked_transactions = 0;

        try {
            $result = $controller_class::pre_dispatch($request, $params);

            if ($result === null) {
                $result = $controller_class::$action_name($request, $params);
            }

            static::__refuse_http_response($controller_name, $action_name, $result);

            // rsx.post_dispatch - fired immediately after the handler returns and before
            // any response shaping, on the SUCCESS path only (never after an exception).
            // Handlers run INLINE and may throw; that is the point (the Turnstile
            // completeness guard is one). Handlers must not mutate 'result'.
            // See: php artisan rsx:man event_hooks
            Rsx::trigger_action('rsx.post_dispatch', [
                'request' => $request,
                'params' => $params,
                'result' => $result,
            ]);
        } finally {
            static::$__in_endpoint_call = $previous_in_call;
            Rsx_Turnstile::_set_request_checked($previous_turnstile_checked);
            Revision::_restore_request_state($previous_revision_state);
            static::__bind_request($previous_request);

            while (DB::transactionLevel() > $transaction_level) {
                DB::rollBack();
                $leaked_transactions++;
            }
        }

        if ($leaked_transactions > 0) {
            throw new \RuntimeException(
                "{$controller_name}::{$action_name} returned with {$leaked_transactions} database transaction(s) "
                . 'still open. They have been rolled back, so whatever the call wrote inside them is gone. '
                . 'Commit or roll back every transaction an endpoint begins - DB::transaction(fn) does both.'
            );
        }

        return $result;
    }

    /**
     * Make $request the container's request, so request() and the Request facade answer it.
     *
     * @param Request $request
     * @return void
     */
    private static function __bind_request(Request $request): void
    {
        app()->instance('request', $request);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
    }

    /**
     * Refuse a pre_dispatch or action result that is an HTTP response: a redirect, a view,
     * a download. An Ajax call answers a value or a coded result; JSON-encoding a response
     * object would report SUCCESS with a meaningless value while nothing the caller asked
     * for happened.
     *
     * @param string $controller_name
     * @param string $action_name
     * @param mixed $result
     * @return void
     * @throws \RuntimeException
     */
    private static function __refuse_http_response(string $controller_name, string $action_name, $result): void
    {
        if (!($result instanceof Response
            || $result instanceof \Illuminate\Contracts\View\View
            || $result instanceof \Illuminate\Contracts\Support\Responsable
            || $result instanceof \Illuminate\Contracts\Support\Renderable)) {
            return;
        }

        throw new \RuntimeException(
            "{$controller_name}::{$action_name} (or its controller's pre_dispatch) returned " . get_class($result)
            . ', an HTTP response, to an Ajax call. An Ajax endpoint answers a value, or a coded result '
            . '(response_unauthorized(), response_not_found(), response_form_error(), ...). '
            . 'A controller pre_dispatch that redirects its pages must return null for an Ajax call '
            . '(Rsx_Request_Channel::current() === Rsx_Request_Channel::AJAX) or answer a coded result.'
        );
    }

    /**
     * Does the request's realm have a signed-in identity?
     *
     * @return bool
     */
    private static function __realm_has_identity(): bool
    {
        return Auth_Gates::active_realm() === Auth_Gates::REALM_PORTAL
            ? Portal_Session::is_logged_in()
            : Session::is_logged_in();
    }

    /**
     * Is $e a CODED failure - a refusal with an error code (AjaxUnauthorizedException,
     * AjaxNotFoundException, an HttpException 401/403/404 ...) - as opposed to a fault?
     *
     * @param Throwable $e
     * @return bool
     */
    public static function is_coded_failure(Throwable $e): bool
    {
        return static::__coded_failure($e) !== null;
    }

    /**
     * Call an Ajax endpoint from PHP - server-side code, rsx:ajax, a test.
     *
     * The same core as the browser (execute()), on the current request carrying $params as
     * its input. A coded result becomes the exception this entry point contracts for
     * (AjaxUnauthorizedException, AjaxFormErrorException, AjaxNotFoundException,
     * AjaxQuestionException, AjaxAuthRequiredException, AjaxFatalErrorException); a value
     * is returned as plain data (JSON-encoded and decoded, so no object reaches the caller).
     *
     * @param string $rsx_controller Controller name (e.g., 'User_Controller')
     * @param string $rsx_action Action/method name (e.g., 'get_profile')
     * @param array $params Parameters to pass to the method
     * @return mixed The endpoint's value as plain data
     * @throws AjaxAuthRequiredException
     * @throws AjaxUnauthorizedException
     * @throws AjaxFormErrorException
     * @throws AjaxNotFoundException
     * @throws AjaxQuestionException
     * @throws AjaxFatalErrorException
     * @throws Exception
     */
    public static function internal($rsx_controller, $rsx_action, $params = [])
    {
        $params = (array) $params;
        $request = static::call_request(request(), $params);

        $result = static::execute((string) $rsx_controller, (string) $rsx_action, $params, $request);

        if ($result instanceof Rsx_Response_Abstract) {
            return static::_handle_special_response($result);
        }

        return json_decode(json_encode($result), true);
    }

    /**
     * The request one call receives: $base - its client address, headers, cookies, files
     * and session - with $params as its input (form and JSON bodies alike), no query
     * string, and the method POST. How a batched call sees the real caller while reading only its own
     * parameters off $request->input().
     *
     * @param Request $base
     * @param array $params
     * @return Request
     */
    public static function call_request(Request $base, array $params): Request
    {
        $request = $base->duplicate([], $params);
        $request->setJson(new InputBag($params));

        // Every Ajax call is a POST, whatever carried it (the console's own request is a
        // GET): a seam asking the verb - the Turnstile completeness guard - sees a POST.
        $request->setMethod('POST');

        return $request;
    }

    /**
     * One call, answered as the envelope both transports send.
     *
     * @param string $controller_name
     * @param string $action_name
     * @param array $params
     * @param Request $request The request the endpoint receives
     * @return array The envelope
     */
    public static function call_envelope(string $controller_name, string $action_name, array $params, Request $request): array
    {
        $console_mark = count(Debugger::_get_console_messages());

        try {
            $result = static::execute($controller_name, $action_name, $params, $request);
        } catch (Throwable $e) {
            // A fault is reported exactly as the front controller reports one that escapes
            // dispatch; a coded failure is an answer, not a fault.
            if (static::__coded_failure($e) === null) {
                app(ExceptionHandler::class)->report($e);
            }

            return static::error_envelope($e, $console_mark);
        }

        if ($result instanceof Rsx_Response_Abstract) {
            return static::coded_envelope($result, $console_mark);
        }

        return static::format_ajax_response($result, $console_mark);
    }

    /**
     * The envelope for a coded result (response_unauthorized(), response_form_error() ...).
     * A FATAL one is a server fault and answers the fatal envelope.
     *
     * @param Rsx_Response_Abstract $response
     * @param int $console_mark Console messages before this index belong to someone else
     * @return array
     */
    public static function coded_envelope(Rsx_Response_Abstract $response, int $console_mark = 0): array
    {
        if ($response->get_type() === self::ERROR_FATAL) {
            $message = $response->get_reason();
            if (!empty($response->get_details())) {
                $message .= ' - ' . json_encode($response->get_details());
            }

            $fault = new AjaxFatalErrorException($message);
            app(ExceptionHandler::class)->report($fault);

            return static::error_envelope($fault, $console_mark);
        }

        return static::__side_channels([
            '_success' => false,
            'error_code' => $response->get_type(),
            'reason' => $response->get_reason(),
            'metadata' => $response->get_details(),
        ], $console_mark);
    }

    /**
     * The envelope for anything a call threw. The one mapping both transports use, and the
     * AJAX channel's error policy for a failure outside any call:
     *
     *   AjaxAuthRequiredException    auth_required
     *   AjaxUnauthorizedException    unauthorized   (Permission::require_*, terminate_*)
     *   AjaxNotFoundException        not_found      metadata = its details
     *   AjaxFormErrorException       validation     metadata = its details
     *   AjaxQuestionException        question       metadata = {key, question}
     *   HttpException 401 / 403 / 404  auth_required / unauthorized / not_found
     *   HttpException, other status  generic, its message
     *   anything else                fatal - the detail only for a caller
     *                                Rsx_Diagnostics admits, an error_id (its detail
     *                                logged under it) for everybody else
     *
     * It FORMATS; reporting a fault is the caller's (call_envelope(), the front controller).
     *
     * @param Throwable $e
     * @param int $console_mark Console messages before this index belong to someone else
     * @return array
     */
    public static function error_envelope(Throwable $e, int $console_mark = 0): array
    {
        $coded = static::__coded_failure($e);

        if ($coded !== null) {
            return static::__side_channels(['_success' => false] + $coded, $console_mark);
        }

        if (Rsx_Diagnostics::caller_sees_detail()) {
            $error = [
                'file' => str_replace(base_path() . '/', '', $e->getFile()),
                'line' => $e->getLine(),
                'error' => $e->getMessage(),
                'backtrace' => [],
            ];

            foreach (array_slice($e->getTrace(), 0, 10) as $frame) {
                $error['backtrace'][] = [
                    'file' => isset($frame['file']) ? str_replace(base_path() . '/', '', $frame['file']) : 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'function' => $frame['function'] ?? 'unknown',
                    'class' => $frame['class'] ?? null,
                    'type' => $frame['type'] ?? null,
                ];
            }

            $reason = $e->getMessage();
        } else {
            $error = [
                'error' => 'An unexpected error occurred. Please try again.',
                'error_id' => Rsx_Diagnostics::report_redacted($e, 'ajax'),
            ];

            $reason = $error['error'];
        }

        return static::__side_channels([
            '_success' => false,
            'error_code' => self::ERROR_FATAL,
            'reason' => $reason,
            'metadata' => [],
            'error' => $error,
        ], $console_mark);
    }

    /**
     * The error code, reason and metadata a coded failure carries, or null for a fault.
     *
     * @param Throwable $e
     * @return array|null ['error_code', 'reason', 'metadata']
     */
    private static function __coded_failure(Throwable $e): ?array
    {
        $code = null;
        $metadata = [];

        if ($e instanceof AjaxAuthRequiredException) {
            $code = self::ERROR_AUTH_REQUIRED;
        } elseif ($e instanceof AjaxUnauthorizedException) {
            $code = self::ERROR_UNAUTHORIZED;
        } elseif ($e instanceof AjaxNotFoundException) {
            // Before AjaxFormErrorException: it is a subclass, and a not-found must keep
            // its own code (the ORM fetch path branches on it).
            $code = self::ERROR_NOT_FOUND;
            $metadata = $e->get_details();
        } elseif ($e instanceof AjaxFormErrorException) {
            $code = self::ERROR_VALIDATION;
            $metadata = $e->get_details();
        } elseif ($e instanceof AjaxQuestionException) {
            $code = self::ERROR_QUESTION;
            $metadata = ['key' => $e->get_key(), 'question' => $e->get_question()];
        } elseif ($e instanceof HttpExceptionInterface) {
            $code = match ($e->getStatusCode()) {
                401 => self::ERROR_AUTH_REQUIRED,
                403 => self::ERROR_UNAUTHORIZED,
                404 => self::ERROR_NOT_FOUND,
                429 => self::ERROR_TOO_MANY_REQUESTS,
                default => self::ERROR_GENERIC,
            };
        }

        if ($code === null) {
            return null;
        }

        $reason = $e->getMessage();
        if ($reason === '') {
            $reason = ($e instanceof HttpExceptionInterface && $code === self::ERROR_GENERIC)
                ? (Response::$statusTexts[$e->getStatusCode()] ?? static::get_default_message($code))
                : static::get_default_message($code);
        }

        return ['error_code' => $code, 'reason' => $reason, 'metadata' => $metadata];
    }

    /**
     * The fields every envelope carries beside its outcome: the server clock and the
     * user's zone (the client syncs from them), the console_debug messages produced since
     * $console_mark, and any pending flash alerts (read once, so the first envelope built
     * after a flash carries it).
     *
     * @param array $envelope
     * @param int $console_mark
     * @param bool $with_flash False when the caller attaches the flash itself (a batch)
     * @return array
     */
    private static function __side_channels(array $envelope, int $console_mark, bool $with_flash = true): array
    {
        $envelope['_server_time'] = Rsx_Time::now_iso();
        $envelope['_user_timezone'] = Rsx_Time::get_user_timezone();

        // A sign-in or sign-out made by this request rotated the browser's tokens; the page
        // that asked still holds the old CSRF token.
        $rotated_csrf_token = Session::_rotated_csrf_token();
        if ($rotated_csrf_token !== null) {
            $envelope['_csrf'] = $rotated_csrf_token;
        }

        $console_messages = array_slice(Debugger::_get_console_messages(), $console_mark);
        if (!empty($console_messages)) {
            // Each message is [channel, [arguments]]
            $envelope['console_debug'] = array_values($console_messages);
        }

        if ($with_flash) {
            $flash_messages = Flash_Alert::get_pending_messages();
            if (!empty($flash_messages)) {
                $envelope['flash_alerts'] = $flash_messages;
            }
        }

        return $envelope;
    }

    /**
     * /_ajax/_batch - THE browser transport: one or more calls in one request, answered as
     * {"C_<call_id>": envelope}. Ajax.js sends every call here, a lone call as a batch of one.
     *
     * Reached from Dispatcher on the AJAX channel, after the realm preamble (CSRF, dev-auth,
     * the portal's init). A batch is refused WHOLE (400, {"error": ...}) when it is malformed
     * or larger than config('rsx.ajax.batch_max_calls'): every call must be an object with a
     * non-negative integer call_id (unique within the batch), a controller and an action, and
     * params that are an object when present. The client never sends such a batch, so a
     * refusal is a caller defect and nothing in it runs. Otherwise every call is answered.
     *
     * @param Request $request
     * @return Response
     */
    public static function handle_batch_request(Request $request): Response
    {
        $batch_calls = $request->input('batch_calls');
        $problem = static::__batch_problem($batch_calls);

        if ($problem !== null) {
            return response()->json(['error' => $problem], 400);
        }

        return response()->json(static::handle_calls($request, $batch_calls));
    }

    /**
     * /_ajax/<Controller>/<action> - one call answered with its bare envelope.
     *
     * For a request made by hand (curl, a shell test); the browser never sends one. It is a
     * batch of one through handle_calls(), so it is checked and answered exactly as a batched
     * call is. The call's parameters are the request body as sent: the JSON body's object, or
     * the form fields - nothing added, nothing removed, no query string.
     *
     * @param Request $request
     * @param string $controller_name
     * @param string $action_name
     * @return Response
     */
    public static function handle_browser_request(Request $request, string $controller_name, string $action_name): Response
    {
        $params = $request->isJson() ? $request->json()->all() : $request->request->all();

        $envelopes = static::handle_calls($request, [[
            'call_id' => 0,
            'controller' => $controller_name,
            'action' => $action_name,
            'params' => $params,
        ]]);

        return response()->json($envelopes['C_0']);
    }

    /**
     * Run browser calls and answer each one: THE per-call path both browser transports share.
     *
     * Every call, in order, on call_request() - the real request (its address, headers,
     * cookies, session) carrying that call's parameters:
     *
     *   1. the session lifetime (rsx.sessions.*) - once a call ends the sign-in, every later
     *      call in the request answers the same session_expired envelope;
     *   2. the staff site membership (users.is_enabled);
     *   3. the realm's Main::pre_dispatch, with the call's own controller and action - a
     *      non-null answer refuses THAT call (main_pre_dispatch_refusal());
     *   4. call_envelope().
     *
     * The checks are per call because a call can change who is signed in - a sign-in, a
     * logout, a site switch - and the next call must be judged as the identity it runs under.
     * Pending flash alerts are read once, after the last call, and ride on the LAST call's
     * envelope; console messages ride on the call that produced them.
     *
     * @param Request $request
     * @param array $calls Validated calls: [{call_id, controller, action, params?}, ...]
     * @return array {"C_<call_id>": envelope, ...}
     */
    public static function handle_calls(Request $request, array $calls): array
    {
        Debugger::disable_console_html_output();

        $responses = [];
        $expired = null;

        foreach ($calls as $call) {
            $params = $call['params'] ?? [];
            $call_request = static::call_request($request, $params);
            $console_mark = count(Debugger::_get_console_messages());

            $expired = $expired ?? static::__timeout_refusal($console_mark);
            $envelope = $expired
                ?? static::__membership_refusal($console_mark)
                ?? static::__main_pre_dispatch_refusal($call_request, $call['controller'], $call['action'], $console_mark)
                ?? static::call_envelope($call['controller'], $call['action'], $params, $call_request);

            $responses['C_' . $call['call_id']] = $envelope;
        }

        $flash_messages = Flash_Alert::get_pending_messages();
        if (!empty($flash_messages)) {
            $responses[array_key_last($responses)]['flash_alerts'] = $flash_messages;
        }

        return $responses;
    }

    /**
     * The response that refuses a whole Ajax REQUEST - a CSRF failure, a fault outside any
     * call (Ajax_Exception_Handler) - shaped so every call the client sent is answered.
     *
     * A batch is answered {"C_<call_id>": $envelope} for every call_id its body names; the
     * client settles each call from its own key and would otherwise wait forever. A direct
     * call, and a batch whose body names no call, get the bare envelope. The side channels
     * (server clock, user's zone) are added here, so a refusal carries them like any envelope.
     *
     * @param Request $request
     * @param array $envelope {_success: false, error_code, reason, metadata?}
     * @return \Illuminate\Http\JsonResponse HTTP 200
     */
    public static function request_refusal(Request $request, array $envelope): \Illuminate\Http\JsonResponse
    {
        $envelope = static::__side_channels($envelope + ['metadata' => []], 0, false);

        $call_ids = [];
        if (str_ends_with('/' . ltrim($request->getPathInfo(), '/'), '/_ajax/_batch')) {
            $batch_calls = json_decode((string) $request->getContent(), true)['batch_calls'] ?? null;

            foreach (is_array($batch_calls) ? $batch_calls : [] as $call) {
                if (is_array($call) && is_int($call['call_id'] ?? null)) {
                    $call_ids[] = $call['call_id'];
                }
            }
        }

        if ($call_ids === []) {
            return response()->json($envelope);
        }

        $responses = [];
        foreach ($call_ids as $call_id) {
            $responses['C_' . $call_id] = $envelope;
        }

        return response()->json($responses);
    }

    /**
     * What is wrong with a batch, or null when nothing is.
     *
     * @param mixed $batch_calls
     * @return string|null
     */
    private static function __batch_problem($batch_calls): ?string
    {
        if (!is_array($batch_calls) || $batch_calls === [] || !array_is_list($batch_calls)) {
            return 'batch_calls must be a non-empty array of calls';
        }

        $max_calls = (int) config('rsx.ajax.batch_max_calls', self::DEFAULT_BATCH_MAX_CALLS);

        if (count($batch_calls) > $max_calls) {
            return 'A batch of ' . count($batch_calls) . " calls exceeds rsx.ajax.batch_max_calls ({$max_calls})";
        }

        $seen = [];

        foreach ($batch_calls as $index => $call) {
            if (!is_array($call)) {
                return "Call {$index} is not an object";
            }

            $call_id = $call['call_id'] ?? null;

            if (!is_int($call_id) || $call_id < 0) {
                return "Call {$index} has no call_id, or one that is not a non-negative integer";
            }

            if (isset($seen[$call_id])) {
                return "Call {$index} repeats call_id {$call_id}";
            }
            $seen[$call_id] = true;

            // The rule the direct path's URL pattern applies: a PHP identifier.
            foreach (['controller', 'action'] as $key) {
                if (!is_string($call[$key] ?? null) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $call[$key])) {
                    return "Call {$index} has no {$key}, or one that is not an identifier";
                }
            }

            if (array_key_exists('params', $call) && !is_array($call['params'])) {
                return "Call {$index} has params that are not an object";
            }
        }

        return null;
    }

    /**
     * Session lifetime (rsx.sessions.staff / rsx.sessions.portal), for the request's own
     * experience. An Ajax call is allowed rsx.sessions.ajax_grace_minutes past the limit -
     * the page that sent it may have been open when the limit passed, and should still be
     * able to save what is on screen. The first call after that window ends the sign-in and
     * answers the session_expired envelope, which the client turns into its "you have been
     * signed out" handling (Rsx.set_session_expired_handler()). A call on a rotated token is
     * answered the same envelope, cause 'rotated'.
     *
     * @param int $console_mark
     * @return array|null The session_expired envelope, or null to proceed
     */
    private static function __timeout_refusal(int $console_mark): ?array
    {
        // A call carrying the token the browser's latest sign-in or sign-out replaced: it was
        // in flight when the tokens rotated. Refused exactly as an expired one is, and its
        // response carries no session cookie (Session::_arrived_on_rotated_token()).
        if (Session::_arrived_on_rotated_token()) {
            return static::__side_channels([
                '_success' => false,
                'error_code' => self::ERROR_SESSION_EXPIRED,
                'reason' => 'This session has changed. Reload the page to continue.',
                'metadata' => ['cause' => 'rotated'],
            ], $console_mark, false);
        }

        $reason = Rsx_Portal::is_portal_request()
            ? Portal_Session::enforce_timeouts(false)
            : Session::enforce_timeouts(false);

        if ($reason === null) {
            return null;
        }

        return static::__side_channels([
            '_success' => false,
            'error_code' => self::ERROR_SESSION_EXPIRED,
            'reason' => Session::timeout_message($reason),
            'metadata' => ['cause' => $reason],
        ], $console_mark, false);
    }

    /**
     * Site membership (users.is_enabled): the request-time half of the framework's
     * is_enabled contract. A session whose membership was disabled or deleted since it was
     * established is ended here, and the call answers the ordinary auth_required envelope -
     * which the client's generic handlers render as a sign-in prompt.
     *
     * STAFF ONLY. Session is the staff facade, so the row it asks about is the staff
     * membership; a portal request shares the browser's one session row and would have its
     * staff identity judged for it. The portal's own per-client rules live in the record
     * layer (portal_can_read).
     *
     * @param int $console_mark
     * @return array|null The auth_required envelope, or null to proceed
     */
    private static function __membership_refusal(int $console_mark): ?array
    {
        if (Rsx_Portal::is_portal_request() || Session::enforce_enabled_membership()) {
            return null;
        }

        return static::coded_envelope(response_auth_required(), $console_mark);
    }

    /**
     * The realm's Main::pre_dispatch for one call (Dispatcher::ajax_main_pre_dispatch()),
     * answered as that call's envelope when it halts.
     *
     * A coded result (response_unauthorized('Account suspended'), response_auth_required()
     * ...) is the call's coded envelope, its reason intact. Any other non-null answer - a
     * redirect, a page, an array, which a hook written for pages returns - refuses the call
     * as unauthorized: the hook halted the request, and an Ajax caller can act on a code but
     * never on a page.
     *
     * @param Request $call_request
     * @param string $controller_name
     * @param string $action_name
     * @param int $console_mark
     * @return array|null The refusal envelope, or null to proceed
     */
    private static function __main_pre_dispatch_refusal(Request $call_request, string $controller_name, string $action_name, int $console_mark): ?array
    {
        $previous_request = app('request');
        static::__bind_request($call_request);

        try {
            $result = Dispatcher::ajax_main_pre_dispatch($call_request, $controller_name, $action_name);
        } finally {
            static::__bind_request($previous_request);
        }

        if ($result === null) {
            return null;
        }

        if ($result instanceof Rsx_Response_Abstract) {
            return static::coded_envelope($result, $console_mark);
        }

        return static::coded_envelope(
            response_unauthorized('This request was refused.'),
            $console_mark
        );
    }

    /**
     * Resolve Controller::action to the controller's FQCN, or throw because it is not an
     * Ajax endpoint.
     *
     * ONE LOOKUP in the class map, then the SURFACE INDEX: the manifest records every
     * #[Ajax_Endpoint] there with its kind and realm, so the endpoint's method map is
     * never read.
     *
     * ONE ANSWER for every way a name can fail - no such class, not a controller, not an
     * endpoint - unless the caller may see diagnostics (Rsx_Diagnostics). Distinct messages
     * would let an anonymous caller probe which class names exist.
     *
     * @param string $controller_name The controller's SIMPLE class name
     * @param string $action_name The endpoint method
     * @return string The controller FQCN
     * @throws Exception
     */
    protected static function _resolve_endpoint_class(string $controller_name, string $action_name): string
    {
        $class_record = Manifest::php_class_metadata($controller_name);
        $controller_class = $class_record['fqcn'] ?? null;

        if (!$controller_class) {
            static::_not_an_endpoint($controller_name, $action_name, "Controller class not found: {$controller_name}");
        }

        if (!Manifest::php_is_subclass_of($controller_class, \App\RSpade\Core\Controller\Rsx_Controller_Abstract::class)) {
            static::_not_an_endpoint($controller_name, $action_name, "Controller {$controller_class} must extend Rsx_Controller_Abstract");
        }

        $surface = Auth_Gates::get_surfaces()[$controller_name . '::' . $action_name] ?? null;

        if ($surface === null || !in_array('ajax', $surface['kinds'] ?? [], true)) {
            static::_not_an_endpoint(
                $controller_name,
                $action_name,
                "Method {$action_name} in {$controller_class} is not an Ajax endpoint. "
                . 'An Ajax endpoint is a public static method carrying #[Ajax_Endpoint] and its '
                . 'mandatory #[Auth].'
            );
        }

        return $controller_class;
    }

    /**
     * Throw the not-an-endpoint failure: the specific reason for a caller that may see
     * diagnostics, one uniform sentence for everybody else.
     *
     * @param string $controller_name
     * @param string $action_name
     * @param string $developer_detail
     * @return never
     * @throws AjaxNotFoundException
     */
    protected static function _not_an_endpoint(string $controller_name, string $action_name, string $developer_detail): never
    {
        // A CODED not-found, not a fault: a name that is not an endpoint is the caller's
        // mistake (or a probe), so it is answered, never reported and logged as a server error.
        if (\App\RSpade\Core\Debug\Rsx_Diagnostics::caller_sees_detail()) {
            throw new AjaxNotFoundException($developer_detail);
        }

        throw new AjaxNotFoundException("{$controller_name}::{$action_name} is not an Ajax endpoint");
    }

    /**
     * Is this call refused because the portal session is a staff member's read-only
     * "View as Client"?
     *
     * DENY BY DEFAULT. Every Ajax call is a POST, reads included, so a write cannot be told
     * from a read by its verb. While Portal_Session::is_impersonating(), a portal-realm call
     * runs only when its endpoint declares #[Portal_Impersonation_Readable] - an endpoint that
     * forgot the mark is refused, never left writable. Staff-realm calls are untouched.
     *
     * @param string $controller_name
     * @param string $action_name
     * @return bool
     */
    private static function __refused_while_impersonating(string $controller_name, string $action_name): bool
    {
        if (Auth_Gates::active_realm() !== Auth_Gates::REALM_PORTAL) {
            return false;
        }

        if (!\App\RSpade\Core\Portal\Portal_Session::is_impersonating()) {
            return false;
        }

        $surface = Auth_Gates::get_surfaces()[$controller_name . '::' . $action_name] ?? [];

        return empty($surface['impersonation_readable']);
    }

    /**
     * Handle special response types by throwing appropriate exceptions
     *
     * @param Rsx_Response_Abstract $response
     * @throws AjaxAuthRequiredException
     * @throws AjaxUnauthorizedException
     * @throws AjaxFormErrorException
     * @throws AjaxNotFoundException
     * @throws AjaxFatalErrorException
     */
    protected static function _handle_special_response(Rsx_Response_Abstract $response)
    {
        $type = $response->get_type();
        $reason = $response->get_reason();
        $details = $response->get_details();

        switch ($type) {
            // An ended sign-in asks for a new one, exactly as a missing one does.
            case self::ERROR_AUTH_REQUIRED:
            case self::ERROR_SESSION_EXPIRED:
                throw new AjaxAuthRequiredException($reason);

            case self::ERROR_UNAUTHORIZED:
                throw new AjaxUnauthorizedException($reason);

            case self::ERROR_VALIDATION:
                throw new AjaxFormErrorException($reason, $details);

            // NOT_FOUND raises the narrower subclass so a caller that re-encodes the
            // exception into a client error code (error_envelope()) can keep the code it
            // came in with. Every existing `catch (AjaxFormErrorException)`
            // still catches it - the subclass IS one.
            case self::ERROR_NOT_FOUND:
                throw new AjaxNotFoundException($reason, $details);

            case self::ERROR_FATAL:
                $message = $reason;
                if (!empty($details)) {
                    $message .= ' - ' . json_encode($details);
                }
                throw new AjaxFatalErrorException($message);

            // Not a failure. The endpoint validated, found a decision only the user can
            // make, and returned it instead of writing. An in-process caller (a PHP test,
            // rsx:ajax, one leg of a batch) sees the question as this exception and either
            // answers it - by calling again with $params['_answers'][$key] set - or gives up.
            case self::ERROR_QUESTION:
                throw new AjaxQuestionException(
                    $reason,
                    (string) ($details['key'] ?? ''),
                    (array) ($details['question'] ?? [])
                );

            case self::ERROR_GENERIC:
                throw new Exception($reason);

            default:
                throw new Exception("Unknown RSX response type: {$type}");
        }
    }

    /**
     * Validate Ajax endpoint return value
     *
     * Ensures developers don't manually add 'success' field to their responses.
     * The framework automatically wraps responses with success/failure metadata.
     *
     * @param mixed $response The Ajax method return value
     * @throws Exception If response contains manual success field
     */
    protected static function _validate_ajax_response($response): void
    {
        // Only validate if response is an array
        if (!is_array($response)) {
            return;
        }

        // Check if response contains '_success' or 'success' key with boolean value
        if ((array_key_exists('_success', $response) && is_bool($response['_success'])) ||
            (array_key_exists('success', $response) && is_bool($response['success']))) {
            $wrong_way = "return ['_success' => false, 'message' => 'Error'];";
            $right_way_validation = "return response_error(Ajax::ERROR_VALIDATION, ['email' => 'Invalid email']);";
            $right_way_success = "return ['user_id' => 123, 'data' => [...]];\n// Framework wraps: {_success: true, _ajax_return_value: {...}}";
            $right_way_exception = "// Let exceptions bubble - framework handles them\n\$user->save(); // Don't wrap in try/catch";

            throw new Exception(
                "YOU'RE DOING IT WRONG: Ajax endpoints must not return arrays with '_success' or 'success' boolean key.\n\n" .
                "The '_success' field is reserved for framework-level Ajax response wrapping.\n\n" .
                "WRONG WAY:\n" .
                "  {$wrong_way}\n\n" .
                "RIGHT WAY (validation errors):\n" .
                "  {$right_way_validation}\n\n" .
                "RIGHT WAY (success):\n" .
                "  {$right_way_success}\n\n" .
                "RIGHT WAY (exceptions):\n" .
                "  {$right_way_exception}\n\n" .
                "See: php artisan rsx:man ajax_error_handling"
            );
        }
    }

    /**
     * The success envelope: {_success: true, _ajax_return_value, _server_time,
     * _user_timezone[, console_debug][, flash_alerts]}. The browser transports and
     * rsx:ajax --debug print exactly this.
     *
     * @param mixed $response The endpoint's value
     * @param int $console_mark Console messages before this index belong to someone else
     * @return array
     */
    public static function format_ajax_response($response, int $console_mark = 0): array
    {
        static::_validate_ajax_response($response);

        return static::__side_channels([
            '_success' => true,
            '_ajax_return_value' => $response,
        ], $console_mark);
    }
}
