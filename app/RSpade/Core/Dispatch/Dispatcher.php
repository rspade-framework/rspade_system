<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Dispatch;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxAuthRequiredException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxNotFoundException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Csp\Rsx_Csp;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Debug\Dev_Auth_Token;
use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Dispatch\RouteResolver;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Response\Rsx_Response_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Rsx_Csrf;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Flash\Flash_Alert;

/**
 * Dispatcher - the ONE pipeline for the AJAX and PAGE channels, in both realms.
 *
 * CALLED BY: Rsx_Front_Controller::handle(), after the request was classified
 * (Rsx_Request_Channel) and the shared preamble ran. The external API goes to
 * Api_Dispatcher and build artifacts to AssetHandler - never here.
 *
 * STAFF AND PORTAL ARE ONE PIPELINE. The realm the request was classified in picks a
 * descriptor (__realm()), and everything that differs between the two is a key in it -
 * which route table, which application Main class, which dev-auth header, whether the
 * site-membership check, the full-page cache and the /_/Controller/action default route
 * apply. Nothing else in this class asks which realm it is in.
 *
 * WHAT IT DOES, IN ORDER:
 * 1. The realm preamble: the portal's Portal_Main::init() (once per process - it declares
 *    the portal's site, which everything after it may ask for), CSRF on a POST, and the
 *    rsx:debug dev-auth identity.
 * 2. AJAX channel: /_ajax/_batch and /_ajax/<Controller>/<action>, to the Ajax core.
 * 3. PAGE channel: a public file when one exists at the path; the /error/* preview
 *    namespace; route matching (and the staff default route); site membership (staff);
 *    the #[Auth] gates; the realm's Main::pre_dispatch; the controller's pre_dispatch and
 *    the action; rsx.post_dispatch; the response (coded RSX responses, views, typed
 *    arrays, JSON), the FPC marker (staff), HEAD stripping and the realm's CSP.
 *
 * A CODED failure the application raises on a page - an abort(), or the coded exception
 * family (AjaxUnauthorizedException from Permission::require_permission() and
 * Session::terminate_*, AjaxNotFoundException ...) - answers the page its code deserves,
 * the same page the equivalent coded return value gets (page_failure_response(), which the
 * page channel's error policy also calls for a failure raised anywhere else). Any other
 * failure propagates to the front controller, whose channel error policy renders it.
 */
class Dispatcher
{
    /**
     * Has the application's Portal_Main::init() run in this process?
     */
    private static bool $__portal_init_called = false;

    /**
     * What differs between the realms. The whole list - a difference that is not a key
     * here is a defect.
     *
     * @param string $realm Auth_Gates::REALM_STAFF or REALM_PORTAL
     * @return array
     */
    private static function __realm(string $realm): array
    {
        if ($realm === Auth_Gates::REALM_PORTAL) {
            return [
                'realm' => Auth_Gates::REALM_PORTAL,
                // Manifest data key of the realm's route table
                'route_table' => 'portal_routes',
                // The application's hook class: pre_dispatch() and unhandled_route()
                'main_abstract' => 'Portal_Main_Abstract',
                // users.is_enabled is a STAFF membership; the portal's per-client rules
                // live in the record layer (portal_can_read)
                'enforce_membership' => false,
                // The full-page cache serves the staff host only
                'fpc' => false,
                // /_/Controller/action addresses staff routes only
                'default_route' => false,
                // The current-page registry the realm's helpers read
                'set_current' => static fn (string $class, string $method, array $params, string $type) =>
                    Rsx_Portal::_set_current_controller_action($class, $method, $type),
                'default_route_type' => 'portal',
            ];
        }

        return [
            'realm' => Auth_Gates::REALM_STAFF,
            'route_table' => 'routes',
            'main_abstract' => 'Main_Abstract',
            'enforce_membership' => true,
            'fpc' => true,
            'default_route' => true,
            'set_current' => static fn (string $class, string $method, array $params, string $type) =>
                Rsx::_set_current_controller_action($class, $method, $params, $type),
            'default_route_type' => 'standard',
        ];
    }

    /**
     * Dispatch an AJAX or PAGE request in the realm it was classified in.
     *
     * @param string $url The dispatch path (a portal path may carry the portal's prefix)
     * @param string $method HTTP method (GET, POST, HEAD)
     * @param array $extra_params Additional parameters, lowest precedence
     * @param Request|null $request The request (default: the current one)
     * @return mixed The response
     * @throws Exception A failure, rendered by Rsx_Front_Controller's channel error policy
     */
    public static function dispatch($url, $method = 'GET', $extra_params = [], ?Request $request = null)
    {
        console_debug('BENCHMARK', "Dispatch started for: {$method} {$url}");

        // Idempotent; the front controller has already done it for a real request.
        Manifest::init();

        $request = $request ?? request();
        $realm = static::__realm(Rsx_Request_Channel::realm());

        // The path inside the realm: a portal path under the portal's prefix is matched
        // without it.
        $path = $realm['realm'] === Auth_Gates::REALM_PORTAL ? Rsx_Portal::strip_prefix($url) : $url;

        // Portal_Main::init() - the FIRST application code to run in a portal request, and
        // the documented place to declare the portal's site (Portal_Session::set_site_id).
        // Everything after it may ask for a site: CSRF, dev auth, the gates, Flash_Alert,
        // the rsxapp payload. Staff Main::init() runs at boot, in the framework provider.
        if ($realm['realm'] === Auth_Gates::REALM_PORTAL) {
            static::__call_portal_init();
        }

        // CSRF, POST-only, asked of the REAL method (the verb on the wire): one session per
        // browser, one token, one seam for Ajax, batches, uploads and native POST routes in
        // both realms. Allowed when there is no session to forge against. The cookie-less
        // external API is a channel of its own and never reaches here.
        if ($method === 'POST' || $request->getRealMethod() === 'POST') {
            Rsx_Csrf::enforce($request);
        }

        // The rsx:debug / Playwright development identity. BEFORE route dispatch, because
        // the #[Auth] gates run before any application code and must see the identity the
        // harness asserts; AFTER CSRF, so a harness POST keeps its session-less CSRF
        // treatment.
        if ($realm['realm'] === Auth_Gates::REALM_PORTAL) {
            static::__handle_portal_dev_auth($request, $path);
        } else {
            static::__handle_dev_auth($request);
        }

        if (Rsx_Request_Channel::current() === Rsx_Request_Channel::AJAX) {
            return static::__dispatch_ajax($request, $path);
        }

        return static::__dispatch_page($realm, $path, $method, $extra_params, $request);
    }

    /**
     * The AJAX channel: /_ajax/_batch, or /_ajax/<Controller>/<action>. Any other path
     * under /_ajax/ is not found, which the AJAX policy answers with the not_found envelope.
     *
     * @param Request $request
     * @param string $path The path inside the realm
     * @return \Illuminate\Http\JsonResponse
     */
    private static function __dispatch_ajax(Request $request, string $path)
    {
        if ($path === '/_ajax/_batch') {
            return Ajax::handle_batch_request($request);
        }

        if (preg_match('#^/_ajax/([A-Za-z_][A-Za-z0-9_]*)/([A-Za-z_][A-Za-z0-9_]*)$#', $path, $matches)) {
            return Ajax::handle_browser_request($request, $matches[1], $matches[2]);
        }

        throw new NotFoundHttpException();
    }

    /**
     * The application's per-request hook for an AJAX request: the realm's
     * Main::pre_dispatch, once per HTTP request - before any endpoint runs, after the
     * transport's site-membership check - exactly as it runs once for every page request.
     * A non-null answer halts the request and becomes its response.
     *
     * $params are the query string plus what the transport adds ('controller' and
     * 'action' for a direct call), and the synthetic keys: _method 'POST', _route the
     * transport pattern ('/_ajax/:controller/:action' or '/_ajax/_batch'), and _handler
     * the transport's class (App\RSpade\Core\Ajax\Ajax) - the ENDPOINT is named by
     * 'controller'/'action', so a hook keyed on page handlers is not applied to Ajax.
     *
     * @param Request $request
     * @param array $params What the transport adds, including '_route'
     * @return \Symfony\Component\HttpFoundation\Response|null
     */
    public static function ajax_main_pre_dispatch(Request $request, array $params)
    {
        $realm = static::__realm(Rsx_Request_Channel::realm());

        $params = array_merge($request->query->all(), $params, [
            '_method' => 'POST',
            '_handler' => Ajax::class,
        ]);

        $result = static::__call_main_pre_dispatch($realm, $request, $params);

        if ($result === null) {
            return null;
        }

        return static::__transform_response($realm, static::__build_response($realm, $result), 'POST', $request);
    }

    /**
     * The PAGE channel.
     *
     * @param array $realm The realm descriptor
     * @param string $path The path inside the realm
     * @param string $method
     * @param array $extra_params
     * @param Request $request
     * @return mixed
     */
    private static function __dispatch_page(array $realm, string $path, string $method, array $extra_params, Request $request)
    {
        // A public file? (Build artifacts - /_compiled/, /_vendor/ - are the ASSET channel.)
        // A real file wins. When there is no such file this returns null and dispatch
        // CONTINUES to the route table, so a route may serve a generated document at a
        // natural filename (/apidocs/openapi.json). Files still take precedence, so no
        // asset that resolves today can be shadowed by a route pattern added tomorrow.
        if (AssetHandler::is_asset_request($path)) {
            $asset_response = AssetHandler::try_serve($path, $request);

            if ($asset_response !== null) {
                return $asset_response;
            }
        }

        // HEAD is matched and handled as GET; the original method is kept for the response.
        $original_method = $method;
        $route_method = ($method === 'HEAD') ? 'GET' : $method;

        if ($method === 'HEAD') {
            $request->setMethod('GET');
        }

        // THE ERROR-PAGE NAMESPACE. /error/<code> and /error/generic are where an
        // application DECLARES its error pages (per realm), and they are never served by
        // ordinary route matching in any mode - so an error page can never answer 200 at
        // its own URL. Development renders the page as a preview of the real failure; a
        // sealed build answers the 404 any unknown URL gets.
        if (preg_match('#^/error/(\d{3}|generic)$#', $path, $error_preview_match)) {
            $error_preview_response = Rsx::is_production()
                ? Error_Screens::not_found($request)
                : Error_Screens::preview($request, $error_preview_match[1]);

            return static::__transform_response($realm, $error_preview_response, $original_method, $request);
        }

        console_debug('DISPATCH', 'Looking for route:', $path, 'method:', $route_method, 'realm:', $realm['realm']);
        $route_match = static::__find_route($path, $route_method, $realm['route_table']);

        if (!$route_match && $realm['default_route']
            && preg_match('#^/_/([A-Za-z_][A-Za-z0-9_]*)/([A-Za-z_][A-Za-z0-9_]*)/?$#', $path, $matches)) {
            // The default route: /_/{Controller}/{action} addresses a #[Route] method (or a
            // JS SPA action) by name. A GET redirects to the real URL; a POST runs a
            // #[Route] method, but only where that route itself accepts POST.
            $default_route = static::__resolve_default_route($matches[1], $matches[2], $route_method, $request, $extra_params);

            if ($default_route === null) {
                return static::__transform_response($realm, Error_Screens::not_found($request), $original_method, $request);
            }

            if (isset($default_route['redirect'])) {
                return redirect($default_route['redirect'], 302);
            }

            $route_match = $default_route['route_match'];
        }

        if (!$route_match) {
            console_debug('DISPATCH', 'No route found for:', $path);

            return static::__transform_response($realm, static::__unmatched($realm, $request, $extra_params), $original_method, $request);
        }

        $handler_class = $route_match['class'];
        $handler_method = $route_match['method'];

        // $params: extra parameters (lowest), the query string, then the URL's route
        // parameters (highest). The POST body is never merged in - a handler reads
        // submitted fields off the Request.
        $params = array_merge($extra_params, $request->query->all(), $route_match['params'] ?? []);
        $params['_method'] = $method;
        $params['_route'] = $route_match['pattern'] ?? $path;
        $params['_handler'] = $handler_class;

        Debugger::console_debug('DISPATCH', 'Matched route to ' . $handler_class . '::' . $handler_method . ' params: ' . json_encode($params));

        // --- FPC detection (staff) ---
        // #[FPC] is baked onto the route row by the manifest. Active only for an anonymous
        // GET with no POST or FILE data.
        $has_fpc = false;
        $fpc_ttl_mins = 0;

        if ($realm['fpc'] && !empty($route_match['fpc']) && $route_method === 'GET' && empty($_POST) && empty($_FILES)) {
            Session::init();

            if (!Session::is_logged_in()) {
                $has_fpc = true;
                $fpc_ttl_mins = (int) ($route_match['fpc_ttl_mins'] ?? 0);

                // Blank all cookies except session to prevent tainted output
                foreach ($_COOKIE as $key => $value) {
                    if ($key !== 'rsx') {
                        unset($_COOKIE[$key]);
                    }
                }
            }
        }

        ($realm['set_current'])($handler_class, $handler_method, $params, $route_match['type'] ?? $realm['default_route_type']);

        if (Manifest::php_class_metadata(Manifest::_normalize_class_name($handler_class)) === null) {
            throw new Exception("Handler class not found in manifest: {$handler_class}");
        }

        // --- Site membership (users.is_enabled, staff) ---
        // The request-time half of the framework's is_enabled contract, asked ONCE per
        // request and ahead of the gates: a session whose membership was disabled or
        // deleted since it was established is ended here, and the caller gets the ordinary
        // unauthorized channel - which, having just been logged out, is the login redirect
        // with the intended URL captured. See: php artisan rsx:man session
        if ($realm['enforce_membership'] && !Session::enforce_enabled_membership()) {
            return static::__transform_response($realm, static::__build_response($realm, response_auth_required()), $original_method, $request);
        }

        // --- Declarative #[Auth] gates ---
        // Every gate the matched surface declares must pass BEFORE any application code
        // runs - the realm's Main::pre_dispatch, the controller's pre_dispatch and the
        // action all come after. Denial: no session -> the realm's login route with the
        // intended URL captured; authenticated but denied -> 403 (Error_Screens owns that
        // split). The gate list was resolved through Auth_Gates::surface_gates() when the
        // row matched, which refuses a surface the index does not know or knows without a
        // gate - closed by default holds at run time. See: php artisan rsx:man auth_gates
        $gate_surface = $handler_class . '::' . $handler_method;

        if (!Auth_Gates::gates_pass_at_seam($route_match['auth'], $realm['realm'], $gate_surface)) {
            console_debug('DISPATCH', 'Auth gates denied:', $gate_surface);

            return static::__transform_response($realm, static::__build_response($realm, response_unauthorized()), $original_method, $request);
        }

        // A CODED failure raised by the application from here on - abort(404), a thrown
        // AjaxUnauthorizedException - is answered at this seam with the page its code
        // deserves (page_failure_response()); a fault propagates to the front controller.
        try {
            $response = static::__run_matched($realm, $handler_class, $handler_method, $params, $request);
        } catch (Throwable $e) {
            $response = static::page_failure_response($e, $request);

            if ($response === null) {
                throw $e;
            }

            return static::__transform_response($realm, $response, $original_method, $request);
        }

        // The FPC marker signals the proxy to cache this response, and its VALUE is the
        // lifetime the route's own #[FPC(ttl: N)] declared: a number of SECONDS, or 'none'
        // for an entry that lives until something clears it.
        if ($has_fpc && $response instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->headers->set(
                \App\RSpade\Core\FPC\Rsx_FPC::MARKER_HEADER,
                \App\RSpade\Core\FPC\Rsx_FPC::marker_value($fpc_ttl_mins)
            );
            $response->headers->remove('Set-Cookie');
        }

        return static::__transform_response($realm, $response, $original_method, $request);
    }

    /**
     * The application's part of a matched page request: the realm's Main::pre_dispatch,
     * the controller's pre_dispatch and the action, the rsx.post_dispatch event, and the
     * response built from the result.
     *
     * @param array $realm
     * @param string $handler_class
     * @param string $handler_method
     * @param array $params
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private static function __run_matched(array $realm, string $handler_class, string $handler_method, array $params, Request $request)
    {
        $pre_dispatch_result = static::__call_main_pre_dispatch($realm, $request, $params);
        if ($pre_dispatch_result !== null) {
            return static::__build_response($realm, $pre_dispatch_result);
        }

        $result = static::__call_action($handler_class, $handler_method, $params, $request);

        // rsx.post_dispatch - fired immediately after the handler returns and before
        // response shaping, on the SUCCESS path only (never after an exception). Handlers
        // run INLINE and may throw; that is the point (the Turnstile completeness guard is
        // one). Handlers must not mutate 'result'. NOTE: $params here is GET + route params
        // only - the POST body is never merged into it, so a handler reading submitted
        // fields must read them off the Request.
        // See: php artisan rsx:man event_hooks
        Rsx::trigger_action('rsx.post_dispatch', [
            'request' => $request,
            'params' => $params,
            'result' => $result,
        ]);

        return static::__build_response($realm, $result);
    }

    /**
     * No route matched. The realm's Main::pre_dispatch and Main::unhandled_route have first
     * refusal, in that order, with the query string as $params; the terminal outcome is the
     * realm's 404 page.
     *
     * @param array $realm
     * @param Request $request
     * @param array $extra_params
     * @return mixed
     */
    private static function __unmatched(array $realm, Request $request, array $extra_params)
    {
        $params = array_merge($extra_params, $request->query->all());

        $result = static::__call_main_pre_dispatch($realm, $request, $params);
        if ($result !== null) {
            return static::__build_response($realm, $result);
        }

        foreach (Manifest::php_class_records_extending($realm['main_abstract']) as $main_class) {
            if (!empty($main_class['fqcn'])) {
                $result = $main_class['fqcn']::unhandled_route($request, $params);

                if ($result !== null) {
                    return static::__build_response($realm, $result);
                }
            }
        }

        return Error_Screens::not_found($request);
    }

    /**
     * The realm's Main::pre_dispatch: the first non-null answer halts dispatch with it.
     *
     * @param array $realm
     * @param Request $request
     * @param array $params
     * @return mixed|null
     */
    private static function __call_main_pre_dispatch(array $realm, Request $request, array $params)
    {
        foreach (Manifest::php_class_records_extending($realm['main_abstract']) as $main_class) {
            if (!empty($main_class['fqcn'])) {
                $result = $main_class['fqcn']::pre_dispatch($request, $params);

                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Call the application's Portal_Main::init(), once per process.
     *
     * The portal twin of the staff Main::init() the framework provider runs at boot. It
     * cannot ride that seam: the provider boots for EVERY request, and Portal_Main is
     * portal-only application code. Once per process, not once per dispatch: init() is
     * bootstrap.
     *
     * @return void
     */
    private static function __call_portal_init(): void
    {
        if (static::$__portal_init_called) {
            return;
        }
        static::$__portal_init_called = true;

        foreach (Manifest::php_class_records_extending('Portal_Main_Abstract') as $portal_main_class) {
            if (!empty($portal_main_class['fqcn'])) {
                $portal_main_class['fqcn']::init();
            }
        }
    }

    /**
     * Resolve the default route /_/{Name}/{action} (staff realm).
     *
     * Two kinds of target qualify, and anything else is a plain 404 (null) - the same
     * answer an unknown URL gets, so the default route is not a probe for which classes
     * and methods exist:
     *
     * A #[Route] METHOD, when all of these hold:
     *   - the controller is a manifest class extending Rsx_Controller_Abstract;
     *   - the action answers a staff #[Route] row in the route table, and is no Ajax,
     *     model-fetch or API surface. A routed method the auth index does not know, or
     *     knows without a gate, is refused LOUDLY (Auth_Gates::surface_gates), as a
     *     matched route row is - never a quiet 404. An #[SPA] bootstrap does not qualify:
     *     its URLs belong to its JS actions;
     *   - none of its routes is an error page (/error/...);
     *   - every query-string value is a scalar (a route parameter is a string).
     * GET (and HEAD) redirect to the method's real URL, built by Rsx::Route() from the
     * query string. POST RUNS the method, and only when one of its own routes accepts POST.
     *
     * A JS SPA ACTION (the name is an indexed js_action surface with @route URLs, and the
     * action segment is 'index'): GET redirects to the action's @route URL, built by
     * Rsx::Route() from the query string. This is the address Rsx.Route() falls back to for
     * an action whose routes are not in the current page's bundle - how one module links
     * to another module's SPA page. POST never qualifies.
     *
     * @param string $name Simple class name (controller or JS action) from the URL
     * @param string $action_name Method name from the URL
     * @param string $route_method GET or POST (HEAD already folded to GET)
     * @param Request $request
     * @param array $extra_params
     * @return array|null ['redirect' => url] | ['route_match' => [...]] | null for a 404
     */
    protected static function __resolve_default_route(string $name, string $action_name, string $route_method, Request $request, array $extra_params): ?array
    {
        $query = $request->query->all();

        foreach ($query as $value) {
            if (!is_scalar($value)) {
                return null;
            }
        }

        $params = array_merge($extra_params, $query);
        $class_record = Manifest::php_class_metadata($name);

        if ($class_record === null) {
            return static::__resolve_default_spa_action($name, $action_name, $route_method, $params);
        }

        if (!Manifest::php_is_subclass_of($name, 'Rsx_Controller_Abstract')) {
            return null;
        }

        // The method's own staff #[Route] rows, read from the route table, so "is this a
        // routed method" is the build's answer, not the index's.
        $rows = [];
        foreach (Manifest::get_routes() as $pattern => $row) {
            if (($row['method'] ?? null) === $action_name
                && ($row['type'] ?? null) === 'standard'
                && class_basename($row['class'] ?? '') === $name) {
                $rows[] = $row + ['pattern' => $pattern];
            }
        }

        if (empty($rows)) {
            return null;
        }

        $target = $name . '::' . $action_name;
        $gates = Auth_Gates::surface_gates($target);

        $kinds = Auth_Gates::get_surfaces()[$target]['kinds'] ?? [];
        if (array_intersect($kinds, ['ajax', 'fetch', 'api']) !== []) {
            return null;
        }

        foreach ($rows as $row) {
            if (str_starts_with($row['pattern'], '/error/')) {
                return null;
            }
        }

        if ($route_method !== 'POST') {
            // Only a URL whose :tokens the query string can fill.
            foreach ($rows as $row) {
                preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $row['pattern'], $tokens);

                if (array_diff($tokens[1], array_keys($params)) === []) {
                    return ['redirect' => Rsx::Route($target, $params)];
                }
            }

            return null;
        }

        $accepts_post = false;
        foreach ($rows as $row) {
            if (in_array('POST', $row['methods'] ?? [], true)) {
                $accepts_post = true;
                break;
            }
        }

        if (!$accepts_post) {
            return null;
        }

        // Parameters come from the query string only, never the POST body - the same rule
        // every matched route follows.
        return [
            'route_match' => [
                'type' => 'standard',
                'class' => $class_record['fqcn'],
                'method' => $action_name,
                'params' => $params,
                'pattern' => "/_/{$name}/{$action_name}",
                'auth' => $gates,
            ],
        ];
    }

    /**
     * The JS SPA action half of the default route: GET only, action 'index' only, a staff
     * js_action surface with gates, and an @route URL the query string can fill.
     *
     * @param string $name
     * @param string $action_name
     * @param string $route_method
     * @param array $params
     * @return array|null ['redirect' => url] or null for a 404
     */
    private static function __resolve_default_spa_action(string $name, string $action_name, string $route_method, array $params): ?array
    {
        if ($route_method === 'POST' || $action_name !== 'index') {
            return null;
        }

        $surface = Auth_Gates::get_surfaces()[$name] ?? null;

        if ($surface === null || !in_array('js_action', $surface['kinds'] ?? [], true)
            || ($surface['realm'] ?? Auth_Gates::REALM_STAFF) !== Auth_Gates::REALM_STAFF) {
            return null;
        }

        // An indexed action with no gate is refused loudly, as every surface is.
        Auth_Gates::surface_gates($name);

        $routes = Manifest::get_full_manifest()['data']['routes_by_target'][$name] ?? [];

        foreach ($routes as $route) {
            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $route['pattern'], $tokens);

            if (array_diff($tokens[1], array_keys($params)) === []) {
                return ['redirect' => Rsx::Route($name, $params)];
            }
        }

        return null;
    }

    /**
     * Establish the staff development identity from signed dev-auth headers.
     *
     * rsx:debug (and the standalone Playwright scripts) send X-Dev-Auth-User-Id +
     * X-Dev-Auth-Exp + X-Dev-Auth-Token, the last an HMAC over the request URI, user id,
     * realm and expiry keyed on the local development GRANT SECRET (never APP_KEY). The wire
     * format and the threat model are documented byte for byte on Dev_Auth_Token, the
     * single verifier both realms call.
     *
     * DEVELOPMENT ONLY, asked as Rsx::is_development(). An absent token grants nothing; a
     * token that is PRESENT and rejected names its failure through console_debug('AUTH'),
     * because a rejected token is always a bug and never a legitimate anonymous request.
     *
     * @param Request $request
     * @return void
     */
    protected static function __handle_dev_auth(Request $request): void
    {
        if (!Rsx::is_development()) {
            return;
        }

        $dev_auth_user_id = $request->header('X-Dev-Auth-User-Id');
        $dev_auth_token = $request->header('X-Dev-Auth-Token');

        if (!$dev_auth_user_id || !$dev_auth_token) {
            return;
        }

        $rejection = Dev_Auth_Token::verify(
            $request->getRequestUri(),
            (int) $dev_auth_user_id,
            false,
            $request->header('X-Dev-Auth-Exp'),
            $dev_auth_token
        );

        if ($rejection !== null) {
            console_debug('AUTH', 'DEV AUTH REJECTED: ' . $rejection . ' - rendering anonymous');
            return;
        }

        $user = \Login_User_Model::find((int) $dev_auth_user_id);
        if (!$user) {
            console_debug('AUTH', 'DEV AUTH REJECTED: login user not found: ' . (int) $dev_auth_user_id
                . ' - rendering anonymous');
            return;
        }

        // A harness login is not a real login, so it must not stamp last_login. login()
        // refuses an identity with no ENABLED site membership, and the harness is not an
        // exemption from that: a disabled account renders anonymous here, named, because a
        // harness run against a disabled account is a fixture problem.
        if (!\App\RSpade\Core\Auth\RsxAuth::login($user, touch_last_login: false)) {
            console_debug('AUTH', 'DEV AUTH REJECTED: login user ' . (int) $user->id
                . ' holds no enabled site membership - rendering anonymous');
            return;
        }

        console_debug('AUTH', "DEV AUTH: Authenticated as user {$user->id} via signed X-Dev-Auth-Token");
    }

    /**
     * Establish the PORTAL development identity from signed dev-auth headers.
     *
     * rsx:debug --portal sends X-Dev-Auth-Portal-User-Id + X-Dev-Auth-Exp +
     * X-Dev-Auth-Token; the token signs the path INSIDE the portal (the prefix removed),
     * which is the URL the minting side signed. Same verifier, same development-only rule.
     *
     * The harness does not declare a site of its own: it browses the portal the application
     * serves, so a portal user of another tenant is refused loudly.
     *
     * @param Request $request
     * @param string $path The path inside the portal
     * @return void
     */
    protected static function __handle_portal_dev_auth(Request $request, string $path): void
    {
        if (!Rsx::is_development()) {
            return;
        }

        $portal_user_id = $request->header('X-Dev-Auth-Portal-User-Id');
        if (!$portal_user_id) {
            return;
        }

        $token = $request->header('X-Dev-Auth-Token');
        if (!$token) {
            console_debug('PORTAL', 'Dev auth REJECTED: no X-Dev-Auth-Token header');
            return;
        }

        $rejection = Dev_Auth_Token::verify(
            $path,
            (int) $portal_user_id,
            true,
            $request->header('X-Dev-Auth-Exp'),
            $token
        );

        if ($rejection !== null) {
            console_debug('PORTAL', 'Dev auth REJECTED: ' . $rejection);
            return;
        }

        $portal_user = Portal_User_Model::find((int) $portal_user_id);
        if (!$portal_user) {
            console_debug('PORTAL', "Dev auth: Portal user not found: {$portal_user_id}");
            return;
        }

        $declared_site_id = Portal_Session::get_site_id();

        if ((int) $portal_user->site_id !== $declared_site_id) {
            throw new RuntimeException(
                "Dev auth: portal user {$portal_user_id} belongs to site {$portal_user->site_id}, but this portal "
                . "request serves site {$declared_site_id}. Pick a portal user on the served site, or point the "
                . 'application\'s Portal_Session::set_site_id() declaration at the other tenant.'
            );
        }

        Portal_Session::set_portal_user_id((int) $portal_user_id);

        console_debug('PORTAL', "Dev auth: Logged in as portal user {$portal_user_id}");
    }

    /**
     * Resolve a URL to its route in one realm's route table - what a request for it would
     * match. A portal URL may carry the portal's prefix.
     *
     * Used by Login_Redirect's routability gate and the code quality rules.
     *
     * @param string $url The URL (a query string is ignored for matching)
     * @param string $method HTTP method (default GET)
     * @param string $realm Auth_Gates::REALM_STAFF (default) or REALM_PORTAL
     * @return array|null ['type', 'pattern', 'class', 'method', 'params', ...] or null
     */
    public static function resolve_url_to_route($url, $method = 'GET', string $realm = Auth_Gates::REALM_STAFF)
    {
        Manifest::init();

        $descriptor = static::__realm($realm);

        if ($realm === Auth_Gates::REALM_PORTAL) {
            $url = Rsx_Portal::strip_prefix($url);
        }

        return static::__find_route($url, $method, $descriptor['route_table']);
    }

    /**
     * Find the route for a URL and method in one route table, most specific pattern first
     * (RouteResolver::sort_by_priority). A matched row's gate list is resolved from the
     * surface it names - it lives once, in auth.surfaces - and refused loudly when the
     * index does not know the surface or knows it without a gate.
     *
     * @param string $url
     * @param string $method
     * @param string $route_table 'routes' or 'portal_routes'
     * @return array|null
     */
    protected static function __find_route($url, $method, string $route_table = 'routes')
    {
        $routes = $route_table === 'routes'
            ? Manifest::get_routes()
            : (Manifest::get_full_manifest()['data'][$route_table] ?? []);

        if (empty($routes)) {
            return null;
        }

        foreach (RouteResolver::sort_by_priority(array_keys($routes)) as $pattern) {
            $route = $routes[$pattern];

            // An #[Api_Endpoint] row shares the staff route table and belongs to
            // Api_Dispatcher alone: served here it would run with the cookie session, no
            // bearer check, no scopes and no param validation.
            if (($route['type'] ?? null) === 'api') {
                continue;
            }

            if (!in_array($method, $route['methods'] ?? ['GET'], true)) {
                continue;
            }

            $params = RouteResolver::match_with_query($url, $pattern);

            if ($params !== false) {
                return [
                    'type' => $route['type'] ?? null,
                    'pattern' => $pattern,
                    'class' => $route['class'],
                    'method' => $route['method'],
                    'params' => $params,
                    'file' => $route['file'] ?? null,
                    'surface' => $route['surface'] ?? null,
                    'fpc' => $route['fpc'] ?? false,
                    'fpc_ttl_mins' => $route['fpc_ttl_mins'] ?? 0,
                    'auth' => Auth_Gates::surface_gates($route['surface'] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * Call the action: the controller's pre_dispatch (a non-null answer halts with it),
     * then the action itself.
     *
     * @param string $class_name
     * @param string $method_name
     * @param array $params
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    protected static function __call_action($class_name, $method_name, $params, Request $request)
    {
        $reflection = new ReflectionClass($class_name);

        if (!$reflection->hasMethod($method_name)) {
            throw new Exception("Method not found: {$class_name}::{$method_name}");
        }

        $method = $reflection->getMethod($method_name);

        if (!$method->isPublic()) {
            throw new Exception("Method not public: {$class_name}::{$method_name}");
        }

        // NO try/catch around either call: a pre_dispatch that THROWS is denying access (or
        // failing for a real reason), and swallowing it would dispatch the request AS IF
        // AUTHORIZED. The exception reaches the channel's error policy.
        if (static::__is_controller($class_name)) {
            // Rsx_Controller_Abstract declares pre_dispatch, so every controller has it
            $result = $class_name::pre_dispatch($request, $params);

            if ($result !== null) {
                return $result;
            }

            return $class_name::$method_name($request, $params);
        }

        if ($reflection->hasMethod('pre_dispatch')) {
            $pre_dispatch = $reflection->getMethod('pre_dispatch');

            if ($pre_dispatch->isStatic() && $pre_dispatch->isPublic()) {
                $result = $pre_dispatch->invoke(null, $request, $params);

                if ($result !== null) {
                    return $result;
                }
            }
        }

        if (!$method->isStatic()) {
            return $method->invoke(app()->make($class_name), $request, $params);
        }

        return $method->invoke(null, $request, $params);
    }

    /**
     * Check if class is a controller
     *
     * @param string $class_name
     * @return bool
     */
    protected static function __is_controller($class_name)
    {
        return Manifest::php_is_subclass_of($class_name, 'App\\RSpade\\Core\\Controller\\Rsx_Controller_Abstract');
    }

    /**
     * Render one application error page, for Error_Screens.
     *
     * The narrow facade the error funnel uses, and the only entry point that invokes a
     * controller method WITHOUT __call_action: the failing request is already over, so a
     * pre_dispatch that redirected or a gate that denied would take the error page away
     * from the person who needs to read it. The page is called directly with the context,
     * in the realm the context names, and its result is built the ordinary way.
     *
     * A coded response (response_unauthorized(), response_not_found()) is a page FAILURE,
     * not a second outcome to route: handing it to __handle_special_response would call
     * back into Error_Screens and recurse. It throws, and the funnel's catch renders the
     * framework page instead.
     *
     * @param array $route_match ['class', 'method', ...] from Error_Pages::resolve
     * @param Request $request The failing request
     * @param \App\RSpade\Core\Errors\Error_Context $error What the page is told
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function render_error_route(array $route_match, Request $request, \App\RSpade\Core\Errors\Error_Context $error)
    {
        $class = $route_match['class'];
        $action = $route_match['method'];
        $realm = static::__realm($error->realm);

        // The error page is the current page from here on. The bundle coverage check
        // (Rsx_Bundle_Abstract) asks whether the bundle being rendered includes the CURRENT
        // controller, which would still be the failing request's. The staff registry is
        // what that check reads, so it is set in both realms; the portal's own registry is
        // what portal helpers read.
        if ($realm['realm'] === Auth_Gates::REALM_PORTAL) {
            Rsx_Portal::_set_current_controller_action($class, $action, $route_match['type'] ?? 'portal');
        }
        Rsx::_set_current_controller_action($class, $action, ['error' => $error], $route_match['type'] ?? $realm['default_route_type']);

        $result = $class::$action($request, ['error' => $error]);

        if ($result === null || $result instanceof Rsx_Response_Abstract) {
            throw new RuntimeException(
                "Error page {$class}::{$action} returned " . ($result === null ? 'nothing' : 'a coded response') . ' instead of a page.'
            );
        }

        return static::__build_response($realm, $result);
    }

    /**
     * The page answer for a CODED failure raised anywhere in a page request, or null when
     * $e is a fault (which the page policy renders as the 500 page).
     *
     * ONE ANSWER for the dispatcher's own seam (a failure in pre_dispatch, the action or the
     * response) and the page channel's error policy (Web_Exception_Handler, for a failure
     * anywhere else), so a page never depends on which layer decided:
     *
     *   - an HttpException (abort()): a browsed request gets the Error_Screens page for its
     *     status (404, 403 and 419 have their own; every other status carries the raiser's
     *     message); a request whose Accept does not prefer HTML (an <img>, a fetch()) gets
     *     the bare status and one line of text;
     *   - the coded exception family, which Permission::require_permission(),
     *     Session::terminate_*() and Ajax::internal() raise: the answer the equivalent coded
     *     RETURN VALUE gets - AjaxUnauthorizedException and AjaxAuthRequiredException are
     *     the unauthorized screen (the realm's login redirect for an anonymous caller, a 403
     *     page otherwise), AjaxNotFoundException the 404 page, any other
     *     AjaxFormErrorException a validation failure (a POST flashes the reason and returns
     *     to the form; a GET is the 400 page).
     *
     * The Ajax channel maps the same family to its envelope codes (Ajax::error_envelope).
     *
     * @param Throwable $e
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response|null
     */
    public static function page_failure_response(Throwable $e, Request $request)
    {
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            if ($request->acceptsHtml()) {
                return match ($status) {
                    404 => Error_Screens::not_found($request),
                    403 => Error_Screens::unauthorized($request),
                    419 => Error_Screens::expired($request),
                    default => Error_Screens::http_status($request, $status, $e->getMessage()),
                };
            }

            $headers = $e->getHeaders();
            if (!isset($headers['Content-Type']) && !isset($headers['content-type'])) {
                $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            }

            $body = $e->getMessage();
            if ($body === '') {
                $body = \Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? '';
            }

            return new Response($body, $status, $headers);
        }

        $coded = static::coded_failure($e);

        if ($coded === null) {
            return null;
        }

        return static::__handle_special_response(static::__realm(Rsx_Request_Channel::realm()), $coded);
    }

    /**
     * The coded response a coded exception stands for, or null when it is not one.
     *
     * @param Throwable $e
     * @return Rsx_Response_Abstract|null
     */
    public static function coded_failure(Throwable $e): ?Rsx_Response_Abstract
    {
        $message = $e->getMessage();

        if ($e instanceof AjaxAuthRequiredException) {
            return response_auth_required($message !== '' ? $message : null);
        }

        if ($e instanceof AjaxUnauthorizedException) {
            return response_unauthorized($message !== '' ? $message : null);
        }

        if ($e instanceof AjaxFormErrorException) {
            $metadata = $e->get_details();
            if ($message !== '') {
                $metadata = ['_message' => $message] + $metadata;
            }

            // AjaxNotFoundException is the not-found subclass of the form error
            return response_error($e instanceof AjaxNotFoundException ? Ajax::ERROR_NOT_FOUND : Ajax::ERROR_VALIDATION, $metadata);
        }

        return null;
    }

    /**
     * Build response from handler result
     *
     * @param array $realm
     * @param mixed $result
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static function __build_response(array $realm, $result)
    {
        if ($result instanceof Rsx_Response_Abstract) {
            return static::__handle_special_response($realm, $result);
        }

        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        if ($result instanceof \Illuminate\View\View || $result instanceof \Illuminate\Contracts\View\View) {
            return response($result);
        }

        if (is_array($result) && isset($result['type'])) {
            return static::__build_typed_response($result);
        }

        // A string is an HTML body
        if (is_string($result)) {
            return response($result);
        }

        return response()->json($result);
    }

    /**
     * Build response from typed result array
     *
     * @param array $result
     * @return Response
     */
    protected static function __build_typed_response($result)
    {
        $type = $result['type'];
        $status = $result['status'] ?? 200;
        $headers = $result['headers'] ?? [];

        switch ($type) {
            case 'view':
                return response()
                    ->view($result['view'], $result['data'] ?? [], $status)
                    ->withHeaders($headers);

            case 'json':
                return response()->json($result['data'] ?? $result, $status, $headers);

            case 'redirect':
                $status = $result['status'] ?? 302;

                return response('', $status)->header('Location', $result['url']);

            case 'file':
                $response = response()->file($result['path'], $headers);

                if (isset($result['name'])) {
                    $disposition = $result['disposition'] ?? 'attachment';
                    $response->header('Content-Disposition', "{$disposition}; filename=\"" . $result['name'] . '"');
                }

                return $response;

            case 'error':
                abort($result['code'] ?? 500, $result['message'] ?? 'Server Error');

                // no break
            case 'empty':
                return response('', $status, $headers);

            case 'stream':
                return response()->stream($result['callback'], $status, $headers);

            default:
                // Unknown type, return as JSON
                return response()->json($result, $status, $headers);
        }
    }

    /**
     * The page answer for a coded RSX response (response_unauthorized(),
     * response_not_found(), response_form_error() ...), in the request's realm.
     *
     * Every coded outcome ends as an Error_Screens page, the same page the equivalent
     * abort() would produce - except a POST, which is a form submission and is answered by
     * flashing the reason and re-issuing the same URL as a GET, so the user is returned to
     * their form.
     *
     * @param array $realm
     * @param Rsx_Response_Abstract $response
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static function __handle_special_response(array $realm, Rsx_Response_Abstract $response)
    {
        $type = $response->get_type();
        $reason = $response->get_reason();
        $details = $response->get_details();
        $request = request();

        if ($type === Ajax::ERROR_FATAL) {
            $message = $reason;
            if (!empty($details)) {
                $message .= ' - ' . json_encode($details);
            }

            throw new Exception($message);
        }

        // Authentication required and unauthorized both route to Error_Screens::
        // unauthorized(), which OWNS the split for the realm: no session -> the realm's
        // login route with the intended URL threaded through Login_Redirect; authenticated
        // -> a themed 403. The reason is flashed here because the flash is only ever read by
        // the login page the unidentified caller is about to land on.
        if ($type === Ajax::ERROR_AUTH_REQUIRED || $type === Ajax::ERROR_UNAUTHORIZED) {
            $is_logged_in = $realm['realm'] === Auth_Gates::REALM_PORTAL
                ? Portal_Session::is_logged_in()
                : Session::is_logged_in();

            if ($reason && !$is_logged_in) {
                Flash_Alert::error($reason);
            }

            return Error_Screens::unauthorized($request, $realm['realm']);
        }

        // Validation and not found. A POST is a form submission: the reason is flashed and
        // the same URL re-requested as a GET. A GET IS the page: a missing record renders
        // the 404 page, a rejected request the 400 page carrying the reason.
        if ($type === Ajax::ERROR_VALIDATION || $type === Ajax::ERROR_NOT_FOUND) {
            if ($request->isMethod('POST')) {
                Flash_Alert::error($reason);

                return redirect($request->url());
            }

            if ($type === Ajax::ERROR_NOT_FOUND) {
                return Error_Screens::not_found($request);
            }

            return Error_Screens::bad_request($request, (string) $reason);
        }

        throw new Exception("Unknown RSX response type: {$type}");
    }

    /**
     * Shape the response: strip the body of a HEAD request, and apply the realm's
     * Content-Security-Policy. Every dispatched page response passes through here, so one
     * call covers routes, SPA bootstraps, error screens and pre_dispatch redirects alike.
     *
     * @param array $realm
     * @param mixed $response
     * @param string $original_method
     * @param Request $request
     * @return mixed
     */
    protected static function __transform_response(array $realm, $response, $original_method, Request $request)
    {
        if ($original_method === 'HEAD' && $response instanceof \Symfony\Component\HttpFoundation\Response) {
            // A BinaryFileResponse / StreamedResponse FORBIDS setContent() (the body is a
            // file handle / callback). These omit the body themselves when the request
            // method is HEAD, so restore HEAD on the request - rewritten to GET so handlers
            // saw GET - and let the response strip its own body while keeping the headers.
            if ($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
                || $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                $request->setMethod('HEAD');
            } else {
                $response->setContent('');
            }
        }

        Rsx_Csp::apply_to_response($response, $realm['realm']);

        return $response;
    }
}
