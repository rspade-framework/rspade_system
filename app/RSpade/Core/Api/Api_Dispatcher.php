<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Param_Validator;
use App\RSpade\Core\Api\Api_Request_Log_Model;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Api\Rsx_Api_Bearer;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Dispatch\RouteResolver;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Session\Session;

/**
 * Api_Dispatcher - request pipeline for external REST API endpoints (/api/vN/...).
 *
 * Self-contained. Rsx_Front_Controller hands the API channel here (any URL matching
 * /api/vN/, classified before assets, Ajax and pages), so this dispatcher owns the whole
 * request:
 *
 *   0. the portal's dedicated domain has no API - every path there is 404 not_found;
 *   1. verb gate (GET/POST only; HEAD and everything else -> 405);
 *   2. Bearer authentication FIRST (uniform 401 across the namespace, no route probing) -
 *      establishes a headless, cookie-less Session identity via Session::_set_api_identity();
 *   3. the KEY's read_only flag - a read-only key may execute GET requests only, and any
 *      other verb is refused with read_only_key 403 before the route is even resolved;
 *   4. exact-match route resolution over the 'api' routes (unknown -> 404);
 *   5. the KEY's scopes (Api_Scopes) - a scoped key whose path patterns do not reach this
 *      endpoint is refused with insufficient_scope 403 BEFORE its params are read, so it
 *      learns nothing about an endpoint it may not call;
 *   6. declarative param validation against baked #[Api_Param] specs (422 per field);
 *   7. declarative #[Auth] gates (403 forbidden);
 *   8. controller invocation (no auth of its own - the controller trusts this dispatcher);
 *   9. bare-JSON response building (models serialize via toArray(); null -> 204).
 *
 * EVERY request is recorded in _api_request_log (success and every failure path). A
 * CODED failure from the endpoint - abort(404), a thrown AjaxUnauthorizedException - is
 * answered as its JSON error and logged with its status (coded_error_response()). Any other
 * Throwable is logged as a 500 row then rethrown to Rsx_Front_Controller, whose API channel
 * policy (Api_Exception_Handler) renders the JSON 500 - never an HTML error page.
 *
 * Responses are bare JSON with real HTTP status codes. Errors are {"error":{"code",
 * "message","fields"?}}. There is no {success,data} envelope.
 */
class Api_Dispatcher
{
    /**
     * Whole-payload ceiling for the stored request body. The log is an activity record,
     * not a copy of the traffic: a body larger than this is truncated with a marker.
     */
    private const LOG_BODY_MAX_BYTES = 25000;

    /**
     * Per-value ceiling inside that payload, so one enormous field cannot consume the
     * whole budget and push every other field out of the record.
     */
    private const LOG_VALUE_MAX_BYTES = 4000;

    /**
     * Appended wherever a cap bit, so a truncated value is never mistaken for the value.
     */
    private const LOG_TRUNCATION_MARKER = '...[truncated]';

    /**
     * Keys whose VALUE is replaced with [redacted] before the body is stored. Credentials
     * only - see _redact() for why a bare 'key' is deliberately absent.
     */
    private const LOG_REDACT_KEYS = '/pass(word|wd)?|secret|token|authorization|api[_-]?key|credential|private[_-]?key/i';

    /**
     * The key that authenticated this request; see current_key().
     */
    private static ?Api_Key_Model $_current_key = null;

    /**
     * Does this URL address the external API namespace (/api/vN/...)?
     */
    public static function is_api_request(string $url): bool
    {
        $path = strtok($url, '?');
        if ($path === false) {
            $path = $url;
        }

        return (bool) preg_match('#^/api/v[0-9]+/#', $path);
    }

    /**
     * The Api_Key_Model that authenticated THIS request, or null outside an API dispatch.
     *
     * Exposed because the identity Session carries is a user, not a key - two keys belonging
     * to one user are indistinguishable through Session, and an endpoint that reports on the
     * credential itself (its expiry, its name) needs the credential. Set once per dispatch,
     * after authentication succeeds.
     */
    public static function current_key(): ?Api_Key_Model
    {
        return self::$_current_key;
    }

    /**
     * The id of the last _api_request_log row this process wrote. See last_request_log_id().
     */
    private static ?int $_last_request_log_id = null;

    /**
     * Dispatch an API request. Always returns a Response; never returns null.
     *
     * EXTERNAL API REQUESTS RESOLVE ONLY AGAINST DECLARED ROUTE PATTERNS. The one and only
     * way to reach a handler here is a URL that matches an #[Api_Endpoint] pattern baked into
     * the manifest, exact-match, by path - see _match_route(). There is deliberately NO
     * by-name channel: nothing resolves a request by controller class plus action name the way
     * Rsx::Route('Class::method') addresses a web route or /_ajax/Class/method addresses an
     * internal Ajax endpoint.
     *
     * THAT ABSENCE IS LOAD-BEARING AND MUST NEVER BE FILLED IN. A key's scope is a PATH
     * PATTERN. A second way to address the same handler would be a URL no scope was written
     * against, so every scoped key in existence would silently reach every endpoint through
     * it - a scope bypass, delivered as a convenience feature. If a handler needs to be
     * reachable, it gets a route.
     */
    public static function dispatch(string $url, string $method = 'GET', array $extra_params = [], ?Request $request = null): Response
    {
        // A previous in-process dispatch may have left its identity standing: a direct
        // caller (a test harness, a CLI tool) has no front controller to tear it down.
        // _set_api_identity() throws on a second call, so a new dispatch starts clean.
        if (Session::is_api_request()) {
            Session::_reset_api_identity();
        }
        self::$_current_key = null;

        return self::__dispatch($url, $method, $extra_params, $request);
    }

    /**
     * End this request's API identity. Called by Rsx_Front_Controller AFTER the API
     * channel's response exists - including a 500 rendered by the channel's error policy -
     * so a failure is rendered under the identity that caused it, never under whatever the
     * request's cookie would resolve to.
     */
    public static function end_request(): void
    {
        if (Session::is_api_request()) {
            Session::_reset_api_identity();
        }
        self::$_current_key = null;
    }

    /**
     * The dispatch body. Split out only so dispatch() can scope the API identity around it
     * without indenting nine return paths.
     */
    private static function __dispatch(string $url, string $method, array $extra_params, ?Request $request): Response
    {
        $start = hrtime(true);
        $request = $request ?? request();

        // One API request is one unit of work for revision history. The _api_request_log row
        // does not exist yet (it is written after the endpoint answers), so its id is
        // back-filled onto the transaction by _log().
        \App\RSpade\Core\Revisions\Revision::_reset_request_state('api', $method . ' ' . $url);

        Manifest::init();

        $path = strtok($url, '?');
        if ($path === false) {
            $path = $url;
        }
        $method = strtoupper($method);

        // Identity is unknown until auth succeeds; failure paths log with nulls.
        $api_key_id = null;
        $user_id = null;
        $site_id = null;

        // --- The portal's dedicated domain has no API ---
        // The API is the staff application's. When the portal runs on a host of its own,
        // /api/... on that host is not the API: every path under it answers the ordinary
        // unknown-endpoint 404, before any credential is looked at, so the portal host
        // says nothing about which endpoints or keys exist.
        if (Rsx_Request_Channel::is_portal_host()) {
            $response = self::_error('not_found', 'Unknown API endpoint', 404);
            self::_log($request, $start, $method, $path, null, 404, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }

        // --- Verb gate (HEAD deliberately included -> 405) ---
        if ($method !== 'GET' && $method !== 'POST') {
            $response = self::_error('method_not_allowed', 'Only GET and POST are supported', 405);
            self::_log($request, $start, $method, $path, null, 405, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }

        // --- Auth FIRST (uniform 401 across the whole namespace) ---
        $auth = self::_authenticate($request);
        if ($auth['error'] !== null) {
            $response = self::_error($auth['error'][0], $auth['error'][1], 401);
            self::_log($request, $start, $method, $path, null, 401, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }
        $api_key = $auth['key'];
        $user = $auth['user'];
        self::$_current_key = $api_key;
        $api_key_id = (int) $api_key->id;
        $user_id = (int) $user->id;
        $site_id = (int) $user->site_id;

        // --- Read-only key gate ---
        // A read-only key may execute GET requests and nothing else. This sits BEFORE the
        // route match deliberately: the answer does not depend on which endpoint was asked
        // for, so a read-only key learns nothing about which write endpoints exist - a
        // non-GET request is refused identically whether the path resolves or not.
        //
        // It also sits before the SCOPES, and the two answer different questions. A scope
        // says WHICH PATHS this credential may reach and carries no method; read_only says
        // WHICH VERBS it may use and names no path. So a read-only key with no scopes still
        // GETs everything its holder may, and a read-only key never reaches a POST handler
        // even when a scope names its path.
        //
        // What makes the guarantee real is API-GET-PURE-01, the manifest-scan rule that
        // forbids a GET handler from writing: the runtime half is here, the build-time half
        // is in Api_Endpoint_ManifestSupport, and neither is worth anything alone.
        if ($api_key->read_only && $method !== 'GET') {
            $response = self::_error(
                'read_only_key',
                'This API key is read-only: GET requests only.',
                403
            );
            self::_log($request, $start, $method, $path, null, 403, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }

        // --- Route match (exact, api routes only) ---
        $route = self::_match_route($path, $method);
        if ($route === null) {
            $response = self::_error('not_found', 'Unknown API endpoint', 404);
            self::_log($request, $start, $method, $path, null, 404, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }
        $handler = $route['class'] . '::' . $route['method'];

        // --- Key scope check ---
        // Runs before ANY input is read, and before the gates. A scope denial is a KEY
        // problem ("mint a wider key"); the gate denial below is a PERMISSION problem
        // ("ask your administrator"), and an integrator cannot act on the two the same
        // way. Scopes only ever subtract from the user's live permissions - the gates
        // still run for a key that clears its scope.
        //
        // The KEY ID is handed in so a malformed stored scope logs a warning naming the key
        // it belongs to. Such a scope grants nothing and still counts, so the key denies
        // everything: it fails closed, loudly enough to be diagnosed.
        //
        // 'required' is the matched ROUTE PATTERN, not the request path: it is the thing a
        // scope would have to reach, and its ':id' tokens say which segments are the caller's
        // to fill in. A concrete path would read as though only that one URL were grantable.
        // An @api-hidden endpoint's pattern is never named: the catalogue does not publish
        // it, and the refusal must not either.
        if (!Api_Scopes::decide($api_key->scopes, $path, (int) $api_key->id)) {
            $response = self::_error(
                'insufficient_scope',
                'This API key is not scoped for this endpoint',
                403,
                null,
                Api_Catalog::is_hidden_pattern($route['pattern'])
                    ? []
                    : ['required' => rtrim($route['pattern'], '/')]
            );
            self::_log($request, $start, $method, $path, $handler, 403, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }

        // --- Assemble raw input (route > GET > body); reject unparseable JSON ---
        $json_invalid = false;
        $raw = self::_collect_raw_input($request, $route['params'], $json_invalid);
        if ($json_invalid) {
            $response = self::_error('invalid_json', 'Request body is not valid JSON', 400);
            self::_log($request, $start, $method, $path, $handler, 400, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }

        // --- Param validation ---
        $validation = Api_Param_Validator::validate($route['api_params'], $raw);
        if (!$validation['valid']) {
            $response = self::_error('validation', 'Validation failed', 422, $validation['fields']);
            self::_log($request, $start, $method, $path, $handler, 422, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }
        $params = $validation['params'];

        // --- Declarative #[Auth] gates ---
        // Runs after bearer identity established the headless session and before the
        // controller. This answers "may this USER do this at all" - distinct from the
        // scope check above, which answered "may this KEY reach this endpoint". Names
        // resolve in the STAFF realm: an API key belongs to a staff
        // user, so the bearer session IS a staff identity (#[Api_Endpoint] surfaces are
        // indexed staff for the same reason). The list was resolved through
        // Auth_Gates::surface_gates() when the row matched, which refuses an unindexed or
        // gateless surface: closed-by-default holds at run time as well as at build time.
        if (!Auth_Gates::gates_pass_at_seam($route['auth'], Auth_Gates::REALM_STAFF, $handler)) {
            $response = self::_error('forbidden', 'Insufficient permissions', 403);
            self::_log($request, $start, $method, $path, $handler, 403, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }

        // --- Invoke (controller pre_dispatch + action) + build response ---
        $controller = $route['class'];
        $action = $route['method'];

        // --- Application account policy (Main::pre_dispatch) ---
        // The application's per-request hook runs here exactly as it does for a page: after
        // the identity is established and the gates have passed, before the controller. It
        // is where an app enforces ITS OWN account states mid-session (suspended, unpaid,
        // terms not accepted) for "every surface", and a bearer key is a surface - skipping
        // it let a suspended account keep working through its keys. Any non-null return
        // refuses the call: an API client cannot follow an interstitial, so whatever the
        // hook returned is answered as a uniform 403 account_refused.
        //
        // params carries _handler and _method, as the page dispatcher's does, so a hook that
        // scopes itself by handler namespace behaves identically here.
        $main_refusal = self::_main_pre_dispatch($request, $params, $controller, $method);
        if ($main_refusal !== null) {
            $response = self::_error(
                'account_refused',
                'This account may not use the API at this time.',
                403
            );
            self::_log($request, $start, $method, $path, $handler, 403, $api_key_id, $user_id, $site_id, $response);

            return $response;
        }

        try {
            $pre = method_exists($controller, 'pre_dispatch')
                ? $controller::pre_dispatch($request, $params)
                : null;

            $result = $pre !== null ? $pre : $controller::$action($request, $params);

            // rsx.post_dispatch - fired immediately after the handler returns and before
            // response shaping, on the SUCCESS path only (never after an exception).
            // Handlers run INLINE and may throw; that is the point (the Turnstile
            // completeness guard is one). Handlers must not mutate 'result'. 'params' are
            // the VALIDATED params; 'raw_input' is what arrived before validation.
            // See: php artisan rsx:man event_hooks
            \App\RSpade\Core\Rsx::trigger_action('rsx.post_dispatch', [
                'request' => $request,
                'params' => $params,
                'raw_input' => $raw,
                'result' => $result,
            ]);

            $response = self::build_response($result);
        } catch (Throwable $e) {
            // A CODED outcome (abort(404), a thrown AjaxUnauthorizedException) is an answer:
            // the JSON error for its status, logged with that status.
            $coded = self::coded_error_response($e);

            if ($coded !== null) {
                self::_log($request, $start, $method, $path, $handler, $coded->getStatusCode(), $api_key_id, $user_id, $site_id, $coded);

                return $coded;
            }

            self::_log($request, $start, $method, $path, $handler, 500, $api_key_id, $user_id, $site_id, null);

            throw $e;
        }

        $status = $response->getStatusCode();
        self::_log($request, $start, $method, $path, $handler, $status, $api_key_id, $user_id, $site_id, $response);

        return $response;
    }

    /**
     * Run the application's Main_Abstract::pre_dispatch() hooks, returning the first non-null
     * answer (a refusal) or null to continue.
     *
     * @param Request $request
     * @param array $params The validated params
     * @param string $controller The endpoint's controller class
     * @param string $method The HTTP method
     * @return mixed
     */
    private static function _main_pre_dispatch(Request $request, array $params, string $controller, string $method)
    {
        $main_params = array_merge($params, [
            '_handler' => $controller,
            '_method' => $method,
        ]);

        foreach (Manifest::php_class_records_extending('Main_Abstract') as $main_class) {
            $main_class_name = $main_class['fqcn'] ?? null;
            if (!$main_class_name) {
                continue;
            }

            $result = $main_class_name::pre_dispatch($request, $main_params);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Authenticate the Bearer key for this dispatch.
     *
     * The bearer rules themselves live in Rsx_Api_Bearer, which the file-serving web routes
     * share - there is exactly one implementation of what a key must satisfy. This wrapper
     * exists so the dispatch pipeline above reads in one vocabulary.
     *
     * @return array{error: ?array, key: ?Api_Key_Model, user: ?User_Model}
     */
    private static function _authenticate(Request $request): array
    {
        return Rsx_Api_Bearer::authenticate($request);
    }

    /**
     * Exact-match the path against the 'api' routes, reusing RouteResolver's priority
     * sort and matcher. Returns the resolved route plus its baked param specs, or null.
     */
    private static function _match_route(string $path, string $method): ?array
    {
        $routes = Manifest::get_routes();
        if (empty($routes)) {
            return null;
        }

        $api_routes = [];
        foreach ($routes as $pattern => $route) {
            if (($route['type'] ?? null) === 'api') {
                $api_routes[$pattern] = $route;
            }
        }
        if (empty($api_routes)) {
            return null;
        }

        $patterns = RouteResolver::sort_by_priority(array_keys($api_routes));
        foreach ($patterns as $pattern) {
            $route = $api_routes[$pattern];
            if (!in_array($method, $route['methods'], true)) {
                continue;
            }

            $params = RouteResolver::match($path, $pattern);
            if ($params !== false) {
                return [
                    'pattern' => $pattern,
                    'class' => $route['class'],
                    'method' => $route['method'],
                    'params' => $params,
                    // The param declarations live ONCE, on the api_endpoints row (which is
                    // in the hot index, exactly like routes) rather than on both.
                    'api_params' => Api_Catalog::params_for_pattern($pattern),
                    // The gate list lives ONCE, in auth.surfaces; the row names its surface.
                    'auth' => Auth_Gates::surface_gates($route['surface'] ?? ''),
                ];
            }
        }

        return null;
    }

    /**
     * Assemble the raw input map with precedence route params > GET query > uploaded files >
     * request body. The body is a JSON object when Content-Type is application/json (an
     * unparseable body sets $json_invalid), otherwise the posted form fields.
     *
     * A multipart/form-data body lands in the else branch: its TEXT parts arrive through
     * $request->post() like any form post, and its FILE parts are merged in from
     * $request->allFiles() so a declared type:'file' param resolves to the UploadedFile.
     * Files sit above the body and below the query deliberately - a file part and a text
     * part of the same name is a client bug, and the file is the one the declaration meant.
     */
    private static function _collect_raw_input(Request $request, array $route_params, bool &$json_invalid): array
    {
        $json_invalid = false;
        $content_type = (string) $request->header('Content-Type', '');

        if (stripos($content_type, 'application/json') !== false) {
            $body_raw = $request->getContent();
            if ($body_raw === '' || $body_raw === null) {
                $body = [];
            } else {
                $decoded = json_decode($body_raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $json_invalid = true;

                    return [];
                }
                $body = $decoded;
            }
        } else {
            $body = $request->post();
        }

        $get = $request->query->all();
        $files = $request->allFiles();

        return array_merge($body, $files, $get, $route_params);
    }

    /**
     * Build the bare-JSON response from an endpoint's return value.
     *
     * A JsonResponse / Symfony Response (including Rsx_Api helpers) passes through. A model
     * serializes via toArray(); a collection or array is recursed for nested models; null
     * becomes 204 No Content. Anything else (a scalar) is a programming error and fails loud.
     */
    public static function build_response($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result === null) {
            return response('', 204);
        }

        if ($result instanceof Rsx_Model_Abstract
            || $result instanceof Collection
            || is_array($result)) {
            return response()->json(self::serialize($result));
        }

        shouldnt_happen(
            'API endpoint returned an unsupported type (' . gettype($result) . '). '
            . 'Return an array, a model, an Eloquent collection, null, or an Rsx_Api helper response.'
        );
    }

    /**
     * Recursively serialize models to arrays. A model becomes its redacting toArray(); a
     * collection becomes an ordered array; nested arrays are recursed; scalars pass through.
     */
    public static function serialize($value)
    {
        if ($value instanceof Rsx_Model_Abstract) {
            return $value->toArray();
        }

        if ($value instanceof Collection) {
            return array_map(fn ($item) => self::serialize($item), $value->all());
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::serialize($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * The API answer for a CODED failure, or null for a fault (a 500).
     *
     *   HttpException 404 / 403 / 401   not_found / forbidden / unauthenticated, its status
     *   HttpException, other status     http_<status>, its status
     *   AjaxNotFoundException           not_found 404
     *   AjaxUnauthorizedException       forbidden 403   (Permission::require_permission())
     *   AjaxAuthRequiredException       unauthenticated 401
     *
     * The message is the raiser's, or the status text when it gave none. Used by the
     * dispatcher for an endpoint's failure and by the API channel's error policy
     * (Api_Exception_Handler) for one raised outside it.
     *
     * @param Throwable $e
     * @return JsonResponse|null
     */
    public static function coded_error_response(Throwable $e): ?JsonResponse
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                default => 'http_' . $status,
            };
        } elseif ($e instanceof \App\RSpade\Core\Ajax\Exceptions\AjaxNotFoundException) {
            [$code, $status] = ['not_found', 404];
        } elseif ($e instanceof \App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException) {
            [$code, $status] = ['forbidden', 403];
        } elseif ($e instanceof \App\RSpade\Core\Ajax\Exceptions\AjaxAuthRequiredException) {
            [$code, $status] = ['unauthenticated', 401];
        } else {
            return null;
        }

        $message = $e->getMessage();
        if ($message === '') {
            $message = Response::$statusTexts[$status] ?? 'Error';
        }

        $response = self::_error($code, $message, $status);

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Build a {"error":{"code","message","fields"?}} JSON response with the given status.
     *
     * $extra merges additional keys INTO the error object - insufficient_scope carries
     * "required": "/api/v1/x/:id", the route pattern the caller would need a scope for.
     */
    private static function _error(
        string $code,
        string $message,
        int $status,
        ?array $fields = null,
        array $extra = []
    ): JsonResponse {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }
        foreach ($extra as $key => $value) {
            $error[$key] = $value;
        }

        return response()->json(['error' => $error], $status);
    }

    /**
     * Write one _api_request_log row. Deliberately try/catch-free: a write failure here
     * indicates schema drift and must fail loud, not be swallowed.
     *
     * $response is the response this request is ABOUT to be answered with - every call
     * site builds it first and hands it in, so the error code, error message and byte
     * size are all read from the ONE thing that actually goes over the wire rather than
     * being restated per call site.
     */
    private static function _log(
        Request $request,
        int $start_ns,
        string $method,
        string $path,
        ?string $handler,
        int $status,
        ?int $api_key_id,
        ?int $user_id,
        ?int $site_id,
        ?Response $response = null
    ): void {
        $duration_ms = (int) round((hrtime(true) - $start_ns) / 1_000_000);

        $log = new Api_Request_Log_Model();
        $log->api_key_id = $api_key_id;
        $log->user_id = $user_id;
        $log->site_id = $site_id;
        $log->verb = self::_loggable_verb($method);
        $log->path = substr($path, 0, 2048);
        $log->handler = $handler;
        $log->status = $status;
        $log->duration_ms = $duration_ms;
        $log->ip = $request->ip();
        // An unauthenticated request's body is not stored: the row still records that the
        // call happened, but an anonymous caller cannot make the log hold its payload.
        $log->request_body = $api_key_id === null ? null : self::_capture_request_body($request);

        $facts = self::_response_facts($response);
        $log->response_error_code = $facts['error_code'];
        $log->response_error_message = $facts['error_message'];
        $log->response_bytes = $facts['bytes'];

        $log->save();

        self::$_last_request_log_id = (int) $log->id;

        // A revision transaction minted during this request can now name the log row that
        // recorded it - the API's answer to "which call did this".
        \App\RSpade\Core\Revisions\Revision::_set_api_request_log_id((int) $log->id);
    }

    /**
     * The verb as the request log stores it: a standard HTTP method verbatim, anything
     * else as the fixed token UNKNOWN.
     *
     * The verb is caller-supplied text of any length, and the column is varchar(8): an
     * arbitrary verb stored verbatim made the log insert itself the 500 - one that
     * answered an anonymous caller with the SQL error.
     */
    private static function _loggable_verb(string $method): string
    {
        $standard = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'];

        return in_array($method, $standard, true) ? $method : 'UNKNOWN';
    }

    /**
     * The _api_request_log row id this process last wrote, or null before the first one.
     *
     * Exposed because the log row is written AFTER the endpoint has run: anything that
     * wants to reference the call it belongs to (revision transactions do) can only learn
     * the id from here.
     */
    public static function last_request_log_id(): ?int
    {
        return self::$_last_request_log_id;
    }

    /**
     * The request body as it will be stored: redacted, per-value capped, whole-payload
     * capped - or NULL when there is nothing to store or it must not be stored.
     *
     * NEVER FOR AN UPLOAD. A multipart request carries file bytes, and the log is not a
     * copy of the blob store: the test is the REQUEST (files present, or a multipart
     * content type), not the endpoint name, so it holds for POST /api/v1/files and for
     * any future upload endpoint without either knowing about the other.
     */
    private static function _capture_request_body(Request $request): ?string
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

            // An unparseable body cannot be redacted - there are no keys to find a password
            // under - so it is never stored as the text it was. Its size is the evidence
            // kept; the row's invalid_json error code says why the request failed.
            if (!is_array($decoded)) {
                return $raw === '' ? null : '[unparseable JSON body, ' . strlen($raw) . ' bytes, not stored]';
            }

            $body = $decoded;
        } else {
            $body = $request->post();
        }

        if (empty($body)) {
            return null;
        }

        $encoded = json_encode(self::_redact($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }

        return self::_cap($encoded, self::LOG_BODY_MAX_BYTES);
    }

    /**
     * Redact secret-looking keys and cap every scalar, at any depth.
     *
     * WHAT IS NOT REDACTED MATTERS AS MUCH AS WHAT IS: a bare 'key' is left alone,
     * because in this API that is the attachment key an upload hands back - the thing you
     * most need to see when tracing an attach - and it is single-use, tenant-scoped and
     * already spent by the time anyone reads the log. The list names credentials.
     */
    private static function _redact(array $body): array
    {
        $out = [];

        foreach ($body as $key => $value) {
            if (is_string($key) && preg_match(self::LOG_REDACT_KEYS, $key)) {
                $out[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::_redact($value);
                continue;
            }

            $out[$key] = is_string($value) ? self::_redact_value($value) : $value;
        }

        return $out;
    }

    /**
     * Cap one scalar. A value over the per-value ceiling is truncated with a marker, so a
     * reader can tell a truncated value from one that was genuinely that long.
     */
    private static function _redact_value(string $value): string
    {
        return self::_cap($value, self::LOG_VALUE_MAX_BYTES);
    }

    /**
     * Truncate to $max, marking it when truncation happened.
     */
    private static function _cap(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . self::LOG_TRUNCATION_MARKER;
    }

    /**
     * Pull the loggable facts out of the response actually being sent.
     *
     * The error code and message come from this API's error envelope
     * ({"error":{"code","message"}}); a success carries neither, which is what makes
     * "response_error_code IS NULL" the success predicate.
     */
    private static function _response_facts(?Response $response): array
    {
        $facts = ['error_code' => null, 'error_message' => null, 'bytes' => 0];

        if ($response === null) {
            return $facts;
        }

        // A streamed or file response has no in-memory content; its declared length is
        // the only honest answer, and 0 when it declares none.
        $content = null;
        if (!($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse)
            && !($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse)) {
            $raw = $response->getContent();
            $content = is_string($raw) ? $raw : null;
        }

        if ($content !== null) {
            $facts['bytes'] = strlen($content);
        } else {
            $facts['bytes'] = (int) $response->headers->get('Content-Length', 0);
        }

        if ($response->getStatusCode() < 400 || $content === null || $content === '') {
            return $facts;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return $facts;
        }

        $error = $decoded['error'];
        $facts['error_code'] = isset($error['code']) ? substr((string) $error['code'], 0, 64) : null;
        $facts['error_message'] = isset($error['message'])
            ? self::_cap((string) $error['message'], self::LOG_VALUE_MAX_BYTES)
            : null;

        return $facts;
    }
}
