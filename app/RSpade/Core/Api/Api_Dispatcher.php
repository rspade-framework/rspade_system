<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Param_Validator;
use App\RSpade\Core\Api\Api_Request_Log_Model;
use App\RSpade\Core\Api\Rsx_Api_Bearer;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Dispatch\RouteResolver;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\User_Model;

/**
 * Api_Dispatcher - request pipeline for external REST API endpoints (/api/vN/...).
 *
 * Self-contained (Portal_Dispatcher-style). The main Dispatcher branches here for any
 * URL matching /api/vN/ BEFORE its asset/FPC/HEAD-rewrite logic, so this dispatcher owns
 * the whole request:
 *
 *   1. verb gate (GET/POST only; HEAD and everything else -> 405);
 *   2. Bearer authentication FIRST (uniform 401 across the namespace, no route probing) -
 *      establishes a headless, cookie-less Session identity via Session::_set_api_identity();
 *   3. exact-match route resolution over the 'api' routes (unknown -> 404);
 *   4. declarative param validation against baked #[Api_Param] specs (422 per field);
 *   5. controller invocation (no auth of its own - the controller trusts this dispatcher);
 *   6. bare-JSON response building (models serialize via toArray(); null -> 204).
 *
 * EVERY request is recorded in _api_request_log (success and every failure path). An
 * uncaught Throwable from the endpoint is logged as a 500 row then rethrown to
 * Api_Exception_Handler, which renders the JSON 500 (never an HTML error page).
 *
 * Responses are bare JSON with real HTTP status codes. Errors are {"error":{"code",
 * "message","fields"?}}. There is no {success,data} envelope.
 */
class Api_Dispatcher
{
    /**
     * True for the duration of an API dispatch. Api_Exception_Handler gates on this so
     * an uncaught endpoint error renders as JSON rather than an HTML error page.
     */
    private static bool $_is_api_dispatch = false;

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
     * Is an API dispatch currently in flight? (Exception-handler gate.)
     */
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

    public static function is_api_dispatch(): bool
    {
        return self::$_is_api_dispatch;
    }

    /**
     * Dispatch an API request. Always returns a Response; never returns null.
     */
    public static function dispatch(string $url, string $method = 'GET', array $extra_params = [], ?Request $request = null): Response
    {
        self::$_is_api_dispatch = true;
        $start = hrtime(true);
        $request = $request ?? request();

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

        // --- Verb gate (HEAD deliberately included -> 405) ---
        if ($method !== 'GET' && $method !== 'POST') {
            self::_log($request, $start, $method, $path, null, 405, $api_key_id, $user_id, $site_id);

            return self::_error('method_not_allowed', 'Only GET and POST are supported', 405);
        }

        // --- Auth FIRST (uniform 401 across the whole namespace) ---
        $auth = self::_authenticate($request);
        if ($auth['error'] !== null) {
            self::_log($request, $start, $method, $path, null, 401, $api_key_id, $user_id, $site_id);

            return self::_error($auth['error'][0], $auth['error'][1], 401);
        }
        $api_key = $auth['key'];
        $user = $auth['user'];
        self::$_current_key = $api_key;
        $api_key_id = (int) $api_key->id;
        $user_id = (int) $user->id;
        $site_id = (int) $user->site_id;

        // --- Route match (exact, api routes only) ---
        $route = self::_match_route($path, $method);
        if ($route === null) {
            self::_log($request, $start, $method, $path, null, 404, $api_key_id, $user_id, $site_id);

            return self::_error('not_found', 'Unknown API endpoint', 404);
        }
        $handler = $route['class'] . '::' . $route['method'];

        // --- Assemble raw input (route > GET > body); reject unparseable JSON ---
        $json_invalid = false;
        $raw = self::_collect_raw_input($request, $route['params'], $json_invalid);
        if ($json_invalid) {
            self::_log($request, $start, $method, $path, $handler, 400, $api_key_id, $user_id, $site_id);

            return self::_error('invalid_json', 'Request body is not valid JSON', 400);
        }

        // --- Param validation ---
        $validation = Api_Param_Validator::validate($route['api_params'], $raw);
        if (!$validation['valid']) {
            self::_log($request, $start, $method, $path, $handler, 422, $api_key_id, $user_id, $site_id);

            return self::_error('validation', 'Validation failed', 422, $validation['fields']);
        }
        $params = $validation['params'];

        // --- Declarative #[Auth] gates ---
        // Runs after bearer identity established the headless session and before the
        // controller. Names resolve in the STAFF realm: an API key belongs to a staff
        // user, so the bearer session IS a staff identity (#[Api_Endpoint] surfaces are
        // indexed staff for the same reason). An endpoint declaring no gates is
        // untouched; closed-by-default is a manifest-build rule, not a runtime one.
        $gates = $route['auth'] ?? [];
        if (!empty($gates)
            && !Auth_Gates::gates_pass_at_seam($gates, Auth_Gates::REALM_STAFF, $handler)) {
            self::_log($request, $start, $method, $path, $handler, 403, $api_key_id, $user_id, $site_id);

            return self::_error('forbidden', 'Insufficient permissions', 403);
        }

        // --- Invoke (controller pre_dispatch + action) + build response ---
        $controller = $route['class'];
        $action = $route['method'];

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
            self::_log($request, $start, $method, $path, $handler, 500, $api_key_id, $user_id, $site_id);

            throw $e;
        }

        $status = $response->getStatusCode();
        self::_log($request, $start, $method, $path, $handler, $status, $api_key_id, $user_id, $site_id);

        return $response;
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
                    'api_params' => $route['api_params'] ?? [],
                    // Declarative gate list baked onto the row by the manifest
                    // (class-level #[Auth] merged with the method's own).
                    'auth' => $route['auth'] ?? [],
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
     * Build a {"error":{"code","message","fields"?}} JSON response with the given status.
     */
    private static function _error(string $code, string $message, int $status, ?array $fields = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        return response()->json(['error' => $error], $status);
    }

    /**
     * Write one _api_request_log row. Deliberately try/catch-free: a write failure here
     * indicates schema drift and must fail loud, not be swallowed.
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
        ?int $site_id
    ): void {
        $duration_ms = (int) round((hrtime(true) - $start_ns) / 1_000_000);

        $log = new Api_Request_Log_Model();
        $log->api_key_id = $api_key_id;
        $log->user_id = $user_id;
        $log->site_id = $site_id;
        $log->verb = $method;
        $log->path = substr($path, 0, 2048);
        $log->handler = $handler;
        $log->status = $status;
        $log->duration_ms = $duration_ms;
        $log->ip = $request->ip();
        $log->save();
    }
}
