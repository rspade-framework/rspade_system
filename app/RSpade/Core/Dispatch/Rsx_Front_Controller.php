<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Dispatch;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Debug\Rsx_Diagnostics;
use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Env\Rsx_Env_Hostname_Guard;
use App\RSpade\Core\Env\Rsx_First_User_Setup;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Revisions\Revision;

/**
 * Rsx_Front_Controller - the one entry point of every HTTP request into RSX.
 *
 * App\Http\Kernel::dispatchToRouter() hands every request here, inside the global
 * middleware stack (so config('rsx.middleware.global') still wraps it). Laravel's router
 * is never consulted: no Laravel route, a vendor package's included, is reachable over
 * HTTP, and there is no route table for an application route to outrank.
 *
 * ONE PASS, IN THIS ORDER:
 *
 *   1. The re-entrancy guard. RSX dispatch runs once per request; a second entry while
 *      the first is in flight is shouldnt_happen(). Nothing calls a dispatcher from inside
 *      another, so this is unreachable by construction and exists to make any future
 *      violation loud.
 *   2. Rsx_Request_Channel::classify() - ASSET, API, AJAX or PAGE, plus the realm. Decided
 *      once, read by everything after it.
 *   3. The fixed preamble: the revision unit of work, Manifest::init(), the development
 *      hostname tripwire, and (AJAX and PAGE only) the first-run setup screen and a
 *      developer's per-browser console_debug override (Debugger::apply_session_override()).
 *   4. The channel's pipeline: AssetHandler (ASSET), Api_Dispatcher (API), Dispatcher
 *      (AJAX and PAGE, in the realm the request was classified in).
 *   5. Any Throwable becomes a response through the channel's error policy, inside the
 *      same try - never by dispatching again. HttpResponseException carries its response
 *      verbatim; ASSET answers plain text; every other channel is rendered by the
 *      exception handler chain (config('rsx.exception_handlers')), whose handlers choose
 *      by the stored channel: the Ajax envelope, the API's JSON error, Error_Screens for a
 *      page. Nothing in any of them dispatches.
 *
 * The Laravel exception handler still formats failures that happen OUTSIDE this class
 * (provider boot, a global middleware), through the same chain.
 */
class Rsx_Front_Controller
{
    /**
     * True while a request is being dispatched.
     */
    private static bool $__in_flight = false;

    /**
     * Dispatch one request and return its response. Never throws for a failure inside
     * dispatch: the failure is the response.
     *
     * @param Request $request
     * @return Response
     */
    public static function handle(Request $request): Response
    {
        if (self::$__in_flight) {
            shouldnt_happen('RSX dispatch re-entered while a dispatch was in flight: ' . $request->getPathInfo());
        }

        self::$__in_flight = true;
        $channel = null;

        try {
            $channel = Rsx_Request_Channel::classify($request);
            $response = static::__as_response(static::__dispatch_channel($channel, $request));
        } catch (Throwable $e) {
            $response = static::__render_failure($channel, $request, $e);
        } finally {
            self::$__in_flight = false;

            // The API identity ends with the response, after any failure was rendered.
            if ($channel === Rsx_Request_Channel::API) {
                Api_Dispatcher::end_request();
            }
        }

        // ?__trace=1 (config rsx.console_debug.enable_get_trace): the trace dump is
        // printed by a shutdown handler, in place of the response.
        if (Debugger::is_trace_output_request()) {
            die();
        }

        return $response;
    }

    /**
     * Is a request being dispatched right now? The CLI exception handler reads this: a
     * failure inside an in-process dispatch (a test driving handle()) is rendered by the
     * channel's policy, never printed to the terminal.
     */
    public static function is_handling(): bool
    {
        return self::$__in_flight;
    }

    /**
     * Run the preamble and the channel's pipeline.
     *
     * @param string $channel
     * @param Request $request
     * @return mixed What the pipeline answered
     */
    private static function __dispatch_channel(string $channel, Request $request)
    {
        $url = Rsx_Request_Channel::dispatch_path($request);
        $method = $request->method();

        // One request is one unit of work for revision history. The API declares its own.
        if ($channel !== Rsx_Request_Channel::API) {
            Revision::_reset_request_state('web', $method . ' ' . $url);
        }

        Manifest::init();

        // Development tripwire: a pasted/mismatched .env whose APP_URL host differs from
        // the host this request is browsed under fails loud. Loopback is exempt.
        Rsx_Env_Hostname_Guard::check();

        if ($channel === Rsx_Request_Channel::ASSET) {
            return AssetHandler::serve_build_artifact(Rsx_Request_Channel::realm_path(), $request);
        }

        if ($channel === Rsx_Request_Channel::API) {
            return Api_Dispatcher::dispatch($url, $method, [], $request);
        }

        // First-run setup: with no credential records at all, a page or Ajax request is
        // offered the first account. Development mode only; an asset or an API call never
        // probes for it.
        Rsx_First_User_Setup::check();

        // A developer's per-browser console_debug override (the /_sys Debug Flags
        // screen), from here to the end of the request. Session readers only.
        Debugger::apply_session_override();

        return Dispatcher::dispatch($url, $method, [], $request);
    }

    /**
     * Every pipeline answers a Response. A null answer is "nothing here" and renders as a
     * 404 through the channel's policy; anything else that is not a response is a
     * dispatcher defect and fails loud.
     *
     * @param mixed $result
     * @return Response
     */
    private static function __as_response($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result instanceof Responsable) {
            return $result->toResponse(request());
        }

        if ($result === null) {
            throw new NotFoundHttpException();
        }

        throw new RuntimeException(
            'RSX dispatch answered ' . get_debug_type($result) . ' instead of a response.'
        );
    }

    /**
     * The channel's error policy. Never dispatches.
     *
     * @param string|null $channel Null only when classification itself failed
     * @param Request $request
     * @param Throwable $e
     * @return Response
     */
    private static function __render_failure(?string $channel, Request $request, Throwable $e): Response
    {
        // Not an error: a response the thrower already built (the CSRF rejection, an
        // abort-with-response). It reaches the client verbatim, on every channel.
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        $handler = app(ExceptionHandler::class);
        $handler->report($e);

        if ($channel === Rsx_Request_Channel::ASSET) {
            return static::__asset_failure($e);
        }

        $response = static::__as_response($handler->render($request, $e));

        // A HEAD request gets the failure's status and headers, never its body. A file or
        // stream response strips its own body for HEAD.
        if ($request->getRealMethod() === 'HEAD'
            && !$response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            && !$response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            $response->setContent('');
        }

        return $response;
    }

    /**
     * ASSET: one line of plain text. A coded status keeps its status; anything else is a
     * 500 whose detail only a developer caller sees.
     *
     * @param Throwable $e
     * @return Response
     */
    private static function __asset_failure(Throwable $e): Response
    {
        $headers = [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return new Response((Response::$statusTexts[$status] ?? 'Error') . "\n", $status, $headers);
        }

        if (Rsx_Diagnostics::caller_sees_detail()) {
            $body = get_class($e) . ': ' . $e->getMessage();
        } else {
            $body = 'Internal Server Error (error id ' . Rsx_Diagnostics::report_redacted($e, 'asset') . ')';
        }

        return new Response($body . "\n", 500, $headers);
    }
}
