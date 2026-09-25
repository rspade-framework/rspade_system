<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Errors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Csp\Rsx_Csp;
use App\RSpade\Core\Debug\Rsx_Diagnostics;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Errors\Error_Context;
use App\RSpade\Core\Errors\Error_Pages;
use App\RSpade\Core\Login\Login_Redirect;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Error_Screens - what a terminal request outcome RENDERS (full-page, server side)
 *
 * ONE FUNNEL. Every full-page failure the framework produces is a call to one of
 * the entry points below, each of which builds an Error_Context and hands it to
 * render(). render() asks Error_Pages which application route declares that
 * status for that realm, renders it if there is one, and renders the framework's
 * own standalone page if there is not. So "what does a denial / a missing page /
 * a crash look like" is answered in one place, and an application answers it by
 * declaring a route rather than by overriding a class.
 *
 *     unauthorized()  - a gate denial or any coded unauthorized/auth-required
 *     not_found()     - no route matched (after Main::unhandled_route declined)
 *     fatal()         - an uncaught exception reached the top of the request
 *     expired()       - a CSRF failure on a native form POST
 *     bad_request()   - a coded validation failure on a web GET
 *     http_status()   - any other abort() status on a browsed request
 *     preview()       - a development browse of /error/<code>
 *
 * THE UNAUTHORIZED SPLIT LIVES HERE. A caller with no session cannot fix a denial
 * by being shown a 403 - it is sent to the login route with the originally
 * requested URL threaded through Login_Redirect. An authenticated caller gets a
 * genuine 403: re-authenticating changes nothing. The split is realm-aware, so a
 * portal request consults the PORTAL session and lands on the PORTAL login route.
 *
 * REDACTION BY CALLER. A context carries exception detail (class, message, file,
 * line, trace) only when Rsx_Diagnostics::caller_sees_detail() admits the caller -
 * a developer, outside production - the same predicate every JSON channel asks, so
 * all channels redact together. Everyone else gets error_id, the reference under
 * which the detail was logged: an error page is fully inspectable with curl, and a
 * development-mode site may be public. An application page is handed the same
 * context, so it cannot disclose more than the framework page would.
 *
 * DEPENDENCY-LIGHT BY DESIGN. The framework page is a standalone Blade with inline
 * styles - no bundle, no manifest view lookup, no SPA runtime, no session chrome -
 * so it still renders when the manifest is the thing that broke. An application
 * page is ordinary application code and may use anything it likes; when it fails,
 * the framework page is what the caller gets.
 *
 * The SPA has its own same-named JS twin (Core/SPA/Error_Screens.js) that renders
 * the app-owned theme error components into the live layout.
 *
 * See: php artisan rsx:man error_pages
 */
class Error_Screens
{
    /**
     * The framework's own standalone Blade, rendered when the application
     * declares no page for the status or its page fails.
     *
     * Lives in resources/views (NOT under app/RSpade), so it is a plain Laravel
     * view: no @rsx_id, no manifest lookup, no layout-chain validation - it still
     * renders when the manifest is the thing that broke.
     */
    protected const VIEW = 'errors.rsx_error';

    /**
     * The framework's title and message per status.
     *
     * A status with no row here takes the Symfony status text as its title and
     * the generic sentence below as its message.
     */
    protected const DEFAULTS = [
        400 => ['Bad Request', 'The request could not be completed.'],
        403 => ['Access Denied', 'You do not have permission to view this page.'],
        404 => ['Page Not Found', 'The page you requested does not exist.'],
        419 => ['Page Expired', 'This page has expired. Go back, reload the page, and try again.'],
        500 => ['Something Went Wrong', 'The server could not complete this request.'],
    ];

    /** The message a status with no row of its own carries. */
    protected const DEFAULT_MESSAGE = 'The request could not be completed.';

    /**
     * A denial: login redirect for an unidentified caller, themed 403 otherwise.
     *
     * The realm decides which session is consulted and which login route the
     * unidentified caller lands on. A caller that KNOWS its realm says so
     * (the page dispatcher does); everyone else omits it and the active realm is
     * detected from the request context.
     *
     * @param Request $request The request being denied
     * @param string|null $realm Auth_Gates::REALM_STAFF / REALM_PORTAL, or null to detect
     * @return Response 302 to the realm's login route, or a 403 page
     */
    public static function unauthorized(Request $request, ?string $realm = null): Response
    {
        $is_portal = $realm === null
            ? Rsx_Portal::is_portal_request()
            : $realm === Auth_Gates::REALM_PORTAL;

        $is_authenticated = $is_portal ? Portal_Session::is_logged_in() : Session::is_logged_in();

        if (!$is_authenticated) {
            // Login_Redirect::capture() silently degrades a hostile, non-page or
            // out-of-context target to [], so a captured target only survives when
            // it is a safe in-context page GET.
            $capture = Login_Redirect::capture($request);

            $login_url = $is_portal
                ? Rsx_Portal::Route('Portal_Login_Controller', $capture)
                : Rsx::Route('Login_Controller', $capture);

            return redirect($login_url);
        }

        return static::render(
            $request,
            static::__context(403, $request, $is_portal ? Auth_Gates::REALM_PORTAL : Auth_Gates::REALM_STAFF)
        );
    }

    /**
     * No route matched this URL.
     *
     * @param Request $request The unmatched request
     * @return Response A 404 page
     */
    public static function not_found(Request $request): Response
    {
        return static::render($request, static::__context(404, $request));
    }

    /**
     * An uncaught exception reached the top of the request.
     *
     * @param Request $request The failed request
     * @param Throwable|null $e The exception, when one is available
     * @return Response A 500 page (detail for a developer caller, an error id otherwise)
     */
    public static function fatal(Request $request, ?Throwable $e = null): Response
    {
        $detail = static::__exception_detail($e);

        $error_id = ($e !== null && $detail === null)
            ? Rsx_Diagnostics::report_redacted($e, 'web')
            : null;

        return static::render(
            $request,
            static::__context(500, $request, null, '', $detail, false, $error_id)
        );
    }

    /**
     * A CSRF failure on a native form POST.
     *
     * @param Request $request The rejected request
     * @return Response A 419 page
     */
    public static function expired(Request $request): Response
    {
        return static::render($request, static::__context(419, $request));
    }

    /**
     * A coded validation failure on a browsed GET.
     *
     * @param Request $request The failed request
     * @param string $message The reason the endpoint gave
     * @return Response A 400 page carrying that reason
     */
    public static function bad_request(Request $request, string $message): Response
    {
        return static::render($request, static::__context(400, $request, null, $message));
    }

    /**
     * Any other HTTP status a browsed request ended on.
     *
     * @param Request $request The failed request
     * @param int $status The status to render
     * @param string $message The reason, when the raiser gave one
     * @return Response A page carrying that status
     */
    public static function http_status(Request $request, int $status, string $message = ''): Response
    {
        return static::render($request, static::__context($status, $request, null, $message));
    }

    /**
     * Render a status as it would look, for a developer browsing /error/<code>.
     *
     * 'generic' asks for the catch-all page BY NAME, so it renders as a 500 with
     * the exact lookup skipped - which is how a developer sees what an
     * undeclared status will get. A 500 preview carries a fabricated detail
     * block, so the page's trace region is visible without crashing anything.
     *
     * @param Request $request The browsing request
     * @param string $code_or_generic Three digits, or 'generic'
     * @return Response
     */
    public static function preview(Request $request, string $code_or_generic): Response
    {
        $is_generic = $code_or_generic === 'generic';
        $status = $is_generic ? 500 : (int) $code_or_generic;

        $detail = $status === 500 ? static::__preview_detail() : null;

        return static::render(
            $request,
            static::__context($status, $request, null, '', $detail, true),
            !$is_generic
        );
    }

    /**
     * THE FUNNEL: turn a context into the response the caller gets.
     *
     * @param Request $request The failing request
     * @param Error_Context $error What the page is told about the failure
     * @param bool $allow_exact_page False to render the generic page even when an
     *                               exact one is declared (the /error/generic preview)
     * @return Response
     */
    public static function render(Request $request, Error_Context $error, bool $allow_exact_page = true): Response
    {
        $route = Error_Pages::resolve($error->status, $error->realm, $allow_exact_page);

        $response = null;

        if ($route !== null) {
            // The error page for the error page must not be a third error: an
            // application page that throws is logged and replaced by the
            // framework's own page, which depends on nothing.
            try {
                $response = Dispatcher::render_error_route($route, $request, $error);
            } catch (Throwable $e) {
                Log::error(sprintf(
                    'Error page %s failed while rendering a %d; the framework page was rendered instead: %s',
                    $route['surface'],
                    $error->status,
                    $e->getMessage()
                ));

                $response = null;
            }
        }

        if ($response === null) {
            $response = response()->view(static::VIEW, [
                'status' => $error->status,
                'heading' => $error->title,
                'message' => $error->message,
                'detail' => $error->detail,
                'error_id' => $error->error_id,
                'home_url' => $error->home_url,
            ], $error->status);
        }

        // The status is the framework's to state, not the page's: a page that
        // renders a view answers 200 by default, and an error page that answers
        // 200 is a lie to every crawler and every caller.
        $response->setStatusCode($error->status);

        // Idempotent: apply_to_response returns immediately when the response
        // already carries a policy, so a dispatcher-path error page passing
        // through here and then through the dispatcher's own application keeps
        // the first policy.
        Rsx_Csp::apply_to_response($response, $error->realm);

        return $response;
    }

    /**
     * Build the context one failure hands to its page.
     *
     * @param int $status The HTTP status
     * @param Request $request The failing request
     * @param string|null $realm REALM_STAFF / REALM_PORTAL, or null to detect
     * @param string $message Overrides the status default when non-empty
     * @param array|null $detail Exception detail (500 only, non-production only)
     * @param bool $preview True for a development /error/<code> browse
     * @param string|null $error_id The log reference of a redacted exception
     * @return Error_Context
     */
    protected static function __context(
        int $status,
        Request $request,
        ?string $realm = null,
        string $message = '',
        ?array $detail = null,
        bool $preview = false,
        ?string $error_id = null
    ): Error_Context {
        $realm = $realm ?? (Rsx_Portal::is_portal_request() ? Auth_Gates::REALM_PORTAL : Auth_Gates::REALM_STAFF);

        [$title, $default_message] = static::__defaults_for($status);

        return new Error_Context(
            $status,
            $title,
            $message !== '' ? $message : $default_message,
            $request->getPathInfo(),
            $request->getMethod(),
            $realm,
            static::__home_url($realm),
            $preview,
            $detail,
            $error_id
        );
    }

    /**
     * The framework title and message for a status.
     *
     * @param int $status
     * @return array{0: string, 1: string}
     */
    protected static function __defaults_for(int $status): array
    {
        if (isset(static::DEFAULTS[$status])) {
            return static::DEFAULTS[$status];
        }

        $title = Response::$statusTexts[$status] ?? 'Error';

        return [$title, static::DEFAULT_MESSAGE];
    }

    /**
     * The realm's home.
     *
     * @param string $realm
     * @return string
     */
    protected static function __home_url(string $realm): string
    {
        if ($realm !== Auth_Gates::REALM_PORTAL) {
            return url('/');
        }

        // A portal on its own domain is already at the root; a prefixed portal
        // lives under it, and the staff root is somebody else's application.
        return Rsx_Portal::has_dedicated_domain() ? url('/') : url(Rsx_Portal::get_prefix());
    }

    /**
     * A fabricated detail block for a 500 preview, or null in production.
     *
     * A preview is a browse, not a crash: there is no exception to describe, so
     * the block says so in the place a real message would sit.
     *
     * @return array|null
     */
    protected static function __preview_detail(): ?array
    {
        if (Rsx::is_production()) {
            return null;
        }

        $file = static::__relative_file(__FILE__);

        return [
            'class' => 'RuntimeException',
            'message' => 'Preview of the 500 page: no exception occurred.',
            'file' => $file,
            'line' => __LINE__,
            'frames' => [sprintf('%s:%d  %s::preview()', $file, __LINE__, static::class)],
        ];
    }

    /**
     * Exception detail for the page, or null when it must not be rendered.
     *
     * Only a caller Rsx_Diagnostics::caller_sees_detail() admits is shown anything
     * about the exception; everybody else (and everybody in production) sees not the
     * message, not the file, not one trace frame.
     *
     * @param Throwable|null $e
     * @return array|null
     */
    protected static function __exception_detail(?Throwable $e): ?array
    {
        if ($e === null || !Rsx_Diagnostics::caller_sees_detail()) {
            return null;
        }

        $frames = [];
        foreach ($e->getTrace() as $index => $frame) {
            if ($index >= 10) {
                break;
            }

            $function = $frame['function'] ?? 'unknown';
            if (isset($frame['class'])) {
                $function = $frame['class'] . ($frame['type'] ?? '::') . $function;
            }

            $frames[] = sprintf(
                '%s:%d  %s()',
                static::__relative_file($frame['file'] ?? 'unknown'),
                $frame['line'] ?? 0,
                $function
            );
        }

        return [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => static::__relative_file($e->getFile()),
            'line' => $e->getLine(),
            'frames' => $frames,
        ];
    }

    /**
     * Project-relative path for display (absolute paths leak the deployment layout).
     */
    protected static function __relative_file(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }
}
