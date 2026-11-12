<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Companion\Companion_Actor_Model;
use App\RSpade\Core\Companion\Companion_Request_Log;
use App\RSpade\Core\Companion\Companion_Service_Abstract;
use App\RSpade\Core\Companion\Companion_Verifier;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Env\Rsx_Install_State;
use App\RSpade\Core\Env\Rsx_Tenancy;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Response\Rollback_Response;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Operations\Metrics\Rsx_Metrics;
use App\RSpade\Lib\Revisions\Revision;

/**
 * Companion_Dispatcher - the request pipeline of the companion realm (/_companion/...).
 *
 * Rsx_Front_Controller hands the COMPANION channel here. A companion is a trusted process
 * from the application's own code tree, calling it as a peer: no user, no session, no browser
 * and no versioning. The request is headless (Session::_begin_headless_request()), so a sent
 * cookie is ignored and nothing mints a session; the Dispatcher's CSRF, gates, Main hooks and
 * first-run screen are never reached.
 *
 *   POST /_companion/<name>/<endpoint>        an instance calling one of its endpoints
 *   POST /_companion/_daemon/ready/<name>     rsx-companiond asking whether to start one
 *   POST /_companion/_daemon/event            rsx-companiond reporting companion.error
 *
 * A name cannot begin with an underscore, so _daemon cannot collide with a companion.
 *
 * THE PIPELINE: the portal host's 404; POST only; a URI that is exactly the path (no query
 * string, nothing percent-encoded, because the signature covers the raw URI and the body
 * carries everything); the verifier (Companion_Verifier); the endpoint lookup, AFTER
 * authentication so an unsigned caller cannot probe which endpoints exist; strict JSON; the
 * site; the method. Responses are bare JSON with real status codes and errors are the API's
 * {"error":{"code","message"}}.
 *
 * THE SITE IS DECLARED, NEVER INHERITED. A companion is per install and the site is per
 * request, carried signed in X-Rsx-Companion-Site. On a single tenant install an absent site
 * is site 1 and any other is refused; on a multi tenant install an absent site is refused.
 * Site 0 and a disabled or deleted site are refused before the method runs. A task the
 * endpoint dispatches runs as nobody on no site, so the endpoint passes site_id in its params.
 *
 * THE WRITER IS THE COMPANION. current_actor_id() is what the audit stamp and the revision
 * transaction read, resolved on the first write so a read-only call touches no actor row.
 *
 * Refusals are logged by Companion_Request_Log. An endpoint's unexpected Throwable is
 * rethrown to the front controller, whose policy for this channel is
 * Companion_Exception_Handler.
 */
class Companion_Dispatcher
{
    /** The path of the realm, and the head of every path in it. */
    private const PATH_PREFIX = '/_companion';

    /** The companion this request is, set once the signature is accepted. */
    private static ?string $__name = null;

    /** The actor row of $__name, read on the first write. */
    private static ?int $__actor_id = null;

    /**
     * Does this path address the companion realm? The whole namespace does, so nothing under
     * it can ever fall through to a page.
     */
    public static function is_companion_request(string $path): bool
    {
        return $path === self::PATH_PREFIX || str_starts_with($path, self::PATH_PREFIX . '/');
    }

    /**
     * The companion making this request, or null outside one.
     */
    public static function current_name(): ?string
    {
        return self::$__name;
    }

    /**
     * The Companion_Actor_Model id this request writes as, or null outside one.
     *
     * Not memoized inside a database transaction: a row created there can be rolled back.
     */
    public static function current_actor_id(): ?int
    {
        if (self::$__name === null) {
            return null;
        }

        if (self::$__actor_id !== null) {
            return self::$__actor_id;
        }

        $actor_id = Companion_Actor_Model::id_for_name(self::$__name);

        if (\Illuminate\Support\Facades\DB::transactionLevel() === 0) {
            self::$__actor_id = $actor_id;
        }

        return $actor_id;
    }

    /**
     * Dispatch a request of the channel. Always answers a Response, or rethrows an endpoint's
     * unexpected failure for the channel's error policy.
     */
    public static function dispatch(Request $request): Response
    {
        $start = hrtime(true);

        Session::_begin_headless_request();

        $outcome = ['name' => null, 'endpoint' => null, 'instance' => null, 'site_id' => null, 'authenticated' => false];

        try {
            $response = static::__route($request, $outcome);
        } catch (Throwable $e) {
            static::__log($outcome, 500, 'internal_error', $start);

            throw $e;
        }

        static::__log($outcome, $response->getStatusCode(), static::__error_code($response), $start);

        return $response;
    }

    /**
     * End the request. Called by Rsx_Front_Controller after the response exists, a failure
     * rendered by the channel's error policy included.
     */
    public static function end_request(): void
    {
        self::$__name = null;
        self::$__actor_id = null;

        Session::_end_headless_request();
    }

    /**
     * The checks every path shares, then the path's own handler.
     *
     * @param array $outcome What the request log records, filled in as it becomes known
     */
    private static function __route(Request $request, array &$outcome): Response
    {
        // The portal's own host has no companion realm: the same answer as any unknown path,
        // before anything is looked at.
        if (Rsx_Request_Channel::is_portal_host()) {
            return Api_Dispatcher::_error('not_found', 'Not found', 404);
        }

        if ($request->getRealMethod() !== 'POST') {
            return Api_Dispatcher::_error('method_not_allowed', 'Only POST is supported', 405);
        }

        $path = Rsx_Request_Channel::dispatch_path($request);

        if ($request->getRequestUri() !== $path) {
            return Api_Dispatcher::_error('invalid_uri', 'The URI must be the bare path', 400);
        }

        if (preg_match('#^/_companion/_daemon/ready/(' . Companion_Service_Abstract::NAME_PATTERN . ')$#', $path, $match)) {
            $outcome['endpoint'] = '_daemon/ready';

            return static::__daemon_ready($request, $match[1], $outcome);
        }

        if ($path === '/_companion/_daemon/event') {
            $outcome['endpoint'] = '_daemon/event';

            return static::__daemon_event($request, $outcome);
        }

        if (preg_match('#^/_companion/(' . Companion_Service_Abstract::NAME_PATTERN . ')/(' . Companion_Service_Abstract::NAME_PATTERN . ')$#', $path, $match)) {
            $outcome['name'] = $match[1];
            $outcome['endpoint'] = $match[2];

            return static::__endpoint($request, $match[1], $match[2], $outcome);
        }

        return Api_Dispatcher::_error('not_found', 'Not found', 404);
    }

    /**
     * An instance calling one of its endpoints.
     */
    private static function __endpoint(Request $request, string $name, string $endpoint, array &$outcome): Response
    {
        $refusal = Companion_Verifier::verify_instance($request, $name);

        if ($refusal !== null) {
            return Api_Dispatcher::_error($refusal['code'], $refusal['message'], $refusal['status']);
        }

        $outcome['authenticated'] = true;
        $outcome['instance'] = (string) $request->header(Companion_Verifier::HEADER_INSTANCE);

        // The allow-list: a companion reaches its own endpoints and no others, because the
        // name in the URL is the name that signed and each companion has its own map.
        $row = Manifest::get_companions()[$name]['endpoints'][$endpoint] ?? null;

        if ($row === null) {
            return Api_Dispatcher::_error('not_found', 'This companion has no such endpoint', 404);
        }

        // Closed by default at run time too: the signature was the gate, and the surface must
        // be the companion kind the build recorded. The sentinel gate is never evaluated.
        $target = $row['class'] . '::' . $row['method'];

        if (!in_array('companion', Auth_Gates::require_surface($target)['kinds'], true)) {
            shouldnt_happen("Companion endpoint {$target} is not a companion surface in the auth index");
        }

        $params = static::__decode_body($request);

        if ($params === null) {
            return Api_Dispatcher::_error('invalid_json', 'The request body is not a JSON object', 400);
        }

        $site = static::__resolve_site((string) $request->header(Companion_Verifier::HEADER_SITE, ''));

        if (is_array($site)) {
            return Api_Dispatcher::_error($site['code'], $site['message'], $site['status']);
        }

        $outcome['site_id'] = $site;

        Session::set_temporary_site_id($site);
        self::$__name = $name;
        self::$__actor_id = null;

        Revision::_reset_request_state('companion', "Companion:{$name}/{$endpoint}");
        Rsx_Metrics::name_request("Companion:{$name}/{$endpoint}");

        $fqcn = $row['fqcn'];
        $method = $row['method'];

        // THE SITE LOCK BOUNDARY (Site_Lock::_run_request(), shared with Ajax, the API and page
        // routes): a companion endpoint is a READER unless it declares #[Writer] or
        // #[Manual_Lock]. The site is the signed header's, declared above before any lock.
        // The enforcement window is exactly the endpoint; post_dispatch and the response are
        // built under the lock, so a throw there rolls a writer back.
        $label = 'Companion ' . $target;

        try {
            return Site_Lock::_run_request(Site_Lock::_surface_mode($target), $label, function () use ($fqcn, $method, $request, $params, $label) {
                $result = Site_Lock::_enforce($label, fn () => $fqcn::$method($request, $params));

                Rsx::trigger_action('rsx.post_dispatch', [
                    'request' => $request,
                    'params' => $params,
                    'raw_input' => $params,
                    'result' => $result,
                ]);

                return Api_Dispatcher::build_response($result);
            }, $site);
        } catch (Rollback_Response $rollback) {
            // Rolled back by the boundary; answered as the returned response would have been.
            return Api_Dispatcher::build_response($rollback->response);
        } catch (Throwable $e) {
            $coded = Api_Dispatcher::coded_error_response($e);

            if ($coded !== null) {
                return $coded;
            }

            throw $e;
        }
    }

    /**
     * Whether rsx-companiond should start a companion: the install, then the companion's own
     * ready_to_run(), in one read-only round trip.
     *
     * Every failure here is an answer of "not ready", never a refusal the daemon would count
     * as a crash. ready_to_run() runs with revision recording suppressed. It has no request
     * site: a single tenant install declares site 1; a multi tenant one declares none, so a
     * site-scoped read in it throws and the method must use without_site_scope().
     */
    private static function __daemon_ready(Request $request, string $name, array &$outcome): Response
    {
        $refusal = Companion_Verifier::verify_daemon($request);

        if ($refusal !== null) {
            return Api_Dispatcher::_error($refusal['code'], $refusal['message'], $refusal['status']);
        }

        $outcome['authenticated'] = true;
        $outcome['name'] = $name;

        $companion = Manifest::get_companions()[$name] ?? null;

        if ($companion === null) {
            return response()->json(['ready' => false, 'scope' => 'companion', 'reason' => 'This build declares no such companion']);
        }

        $install_reason = Rsx_Install_State::not_ready_reason();

        if ($install_reason !== null) {
            return response()->json(['ready' => false, 'scope' => 'install', 'reason' => $install_reason]);
        }

        static::__declare_install_site();

        $fqcn = $companion['fqcn'];

        try {
            $reason = Revision::without(fn () => $fqcn::ready_to_run());
        } catch (Throwable $e) {
            Log::error("Companion {$name}: ready_to_run() threw: " . $e->getMessage());

            return response()->json(['ready' => false, 'scope' => 'companion', 'reason' => 'ready_to_run() threw; see the application log']);
        }

        return response()->json(['ready' => $reason === null, 'scope' => 'companion', 'reason' => $reason]);
    }

    /**
     * rsx-companiond reporting a companion's error: fires companion.error, which is the
     * application's to route. Handlers run as the companion, with the install's site declared
     * on a single tenant install.
     */
    private static function __daemon_event(Request $request, array &$outcome): Response
    {
        $refusal = Companion_Verifier::verify_daemon($request);

        if ($refusal !== null) {
            return Api_Dispatcher::_error($refusal['code'], $refusal['message'], $refusal['status']);
        }

        $outcome['authenticated'] = true;

        $body = static::__decode_body($request);

        if ($body === null || !is_string($body['name'] ?? null) || !preg_match('/^' . Companion_Service_Abstract::NAME_PATTERN . '$/', $body['name'])) {
            return Api_Dispatcher::_error('invalid_event', 'The event names no companion', 400);
        }

        $outcome['name'] = $body['name'];

        self::$__name = $body['name'];
        static::__declare_install_site();

        Revision::_reset_request_state('companion', 'Companion:' . $body['name'] . '/_daemon/event');

        Rsx::trigger_action('companion.error', [
            'name' => $body['name'],
            'instance_id' => $body['instance_id'] ?? null,
            'code' => $body['code'] ?? null,
            'message' => $body['message'] ?? null,
            'severity' => $body['severity'] ?? 'error',
            'context' => $body['context'] ?? [],
        ]);

        return response('', 204);
    }

    /**
     * Decode the body the signature covered: the raw bytes, never a parsed form. Empty is
     * no params; anything but a JSON object or array is null.
     */
    private static function __decode_body(Request $request): ?array
    {
        $body = (string) $request->getContent();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The site this request acts in, or a refusal.
     *
     * @param string $header The signed site header, '' when absent
     * @return int|array{code: string, message: string, status: int}
     */
    private static function __resolve_site(string $header)
    {
        $is_multi = Rsx_Tenancy::is_multi();

        if ($header === '') {
            if ($is_multi) {
                return ['code' => 'site_required', 'message' => 'A multi tenant install requires a site on every request', 'status' => 422];
            }

            $site_id = Rsx_Tenancy::SINGLE_TENANT_SITE_ID;
        } else {
            $site_id = (int) $header;
        }

        if ($site_id <= 0 || (!$is_multi && $site_id !== Rsx_Tenancy::SINGLE_TENANT_SITE_ID)) {
            return ['code' => 'site_not_allowed', 'message' => 'This request may not act in that site', 'status' => 403];
        }

        $site = Site_Model::find($site_id);

        if ($site === null || !$site->is_active()) {
            return ['code' => 'site_not_allowed', 'message' => 'This request may not act in that site', 'status' => 403];
        }

        return $site_id;
    }

    /**
     * A daemon call has no request site. A single tenant install has only the one.
     */
    private static function __declare_install_site(): void
    {
        if (!Rsx_Tenancy::is_multi()) {
            Session::set_temporary_site_id(Rsx_Tenancy::SINGLE_TENANT_SITE_ID);
        }
    }

    private static function __error_code(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        return json_decode((string) $response->getContent(), true)['error']['code'] ?? null;
    }

    private static function __log(array $outcome, int $status, ?string $code, int $start): void
    {
        Companion_Request_Log::record(
            $outcome['name'],
            $outcome['endpoint'],
            $outcome['instance'],
            $outcome['site_id'],
            $status,
            $code,
            (int) round((hrtime(true) - $start) / 1_000_000),
            $outcome['authenticated']
        );
    }
}
