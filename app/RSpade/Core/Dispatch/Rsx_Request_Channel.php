<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Dispatch;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;

/**
 * Rsx_Request_Channel - which kind of request this is, decided ONCE per request.
 *
 * Rsx_Front_Controller classifies every request before anything is dispatched, and every
 * later question about the request's kind (which pipeline runs it, which error policy
 * renders its failure, whether it is a portal request) reads the stored answer. Nothing
 * re-derives it from $_SERVER or from a path somebody rewrote on the way.
 *
 * THE CHANNELS, tested in this order:
 *
 *   ASSET  /_compiled/<bundle file> or /_vendor/<mirrored file>, strict filename patterns
 *          (AssetHandler::is_build_artifact_request). A build artifact: no session, no
 *          CSRF, no route scan; a miss is a plain-text 404, never a route.
 *   API    ^/api/v<N>/ - the external bearer API. It is a STAFF identity on any host,
 *          EXCEPT the portal's own host: the API does not exist there, and the API
 *          pipeline answers every path under it with its own 404 (is_portal_host()).
 *          When the portal lives under a prefix on the application host, /api/... on
 *          that host is the API as usual.
 *   AJAX   /_ajax/... POST, in its realm (staff, or the portal: the same path under the
 *          portal's prefix).
 *   PAGE   everything else, in its realm: public files, /error/* previews, routes, SPA
 *          bootstraps and the framework file routes.
 *
 * THE REALM (staff | portal) is an attribute of AJAX and PAGE, not a channel. Where the
 * portal lives is Rsx_Portal_Url's derivation of PORTAL_URL:
 *
 *   portal on the application host (under a prefix): a path under the prefix is the
 *     portal, any other path is staff - on every host the application answers on.
 *   portal on its own host: EVERY request on that host is the portal realm. A path
 *     outside the prefix there is a portal path no route matches (Dispatcher answers the
 *     portal's 404), never a staff page. On any other host the request is staff, the
 *     prefix included.
 *
 * Build artifacts (/_compiled, /_vendor) are realm-agnostic on every host. realm_path is
 * the path with the prefix removed when the request is under it. Rsx_Portal::
 * is_portal_request() is exactly "realm() === REALM_PORTAL".
 *
 * OUTSIDE A REQUEST (CLI, a test that never classified one) the answer is a staff PAGE,
 * which is what the portal predicate has always answered in CLI. A web process that asks
 * before the front controller ran (a service provider, a global middleware) classifies the
 * current request lazily - the same function, the same input. set_realm() is the CLI/test
 * seam Rsx_Portal::set_portal_request() stands on.
 */
class Rsx_Request_Channel
{
    public const ASSET = 'asset';
    public const API = 'api';
    public const AJAX = 'ajax';
    public const PAGE = 'page';

    public const REALM_STAFF = 'staff';
    public const REALM_PORTAL = 'portal';

    /**
     * The stored classification: ['channel', 'realm', 'portal_host', 'realm_path'], or
     * null when this process has classified nothing yet.
     */
    private static ?array $__state = null;

    /**
     * Classify a request, store the answer for the rest of the request, and return the
     * channel. Called once per request, by Rsx_Front_Controller::handle().
     *
     * @param Request $request
     * @return string One of the channel constants
     */
    public static function classify(Request $request): string
    {
        static::$__state = static::__compute($request);

        return static::$__state['channel'];
    }

    /**
     * The channel of the current request.
     */
    public static function current(): string
    {
        return static::__state()['channel'];
    }

    /**
     * The realm of the current request: REALM_STAFF or REALM_PORTAL.
     */
    public static function realm(): string
    {
        return static::__state()['realm'];
    }

    /**
     * True when the current request is a portal request (its realm is the portal).
     */
    public static function is_portal(): bool
    {
        return static::realm() === self::REALM_PORTAL;
    }

    /**
     * True when the current request arrived on the portal's OWN host (PORTAL_URL names a
     * host other than APP_URL's). Never true when the portal is under a prefix on the
     * application host. The API reads this to refuse its whole namespace there.
     */
    public static function is_portal_host(): bool
    {
        return static::__state()['portal_host'];
    }

    /**
     * The request path inside its realm: the dispatch path with the portal's path prefix
     * removed (a build artifact under the prefix is the same file as without it).
     */
    public static function realm_path(): string
    {
        return static::__state()['realm_path'];
    }

    /**
     * Declare the realm without classifying a request - the CLI/test seam behind
     * Rsx_Portal::set_portal_request(). The channel is kept when one is stored.
     *
     * @param string $realm REALM_STAFF or REALM_PORTAL
     */
    public static function set_realm(string $realm): void
    {
        if ($realm !== self::REALM_STAFF && $realm !== self::REALM_PORTAL) {
            shouldnt_happen("Rsx_Request_Channel::set_realm(): unknown realm '{$realm}'");
        }

        $state = static::$__state ?? static::__outside_request_state();
        $state['realm'] = $realm;
        static::$__state = $state;
    }

    /**
     * Forget the stored classification (tests; Rsx_Portal::_clear_cache()).
     */
    public static function reset(): void
    {
        static::$__state = null;
    }

    /**
     * The path dispatch works with: the request path with a leading slash and no
     * trailing one. It is the spelling every dispatcher has always been handed.
     *
     * @param Request $request
     * @return string
     */
    public static function dispatch_path(Request $request): string
    {
        return '/' . ltrim($request->path(), '/');
    }

    /**
     * The stored classification, classifying lazily inside a web request.
     */
    private static function __state(): array
    {
        if (static::$__state !== null) {
            return static::$__state;
        }

        if (php_sapi_name() === 'cli') {
            return static::__outside_request_state();
        }

        static::$__state = static::__compute(request());

        return static::$__state;
    }

    /**
     * The answer for a process that is not serving a request.
     */
    private static function __outside_request_state(): array
    {
        return ['channel' => self::PAGE, 'realm' => self::REALM_STAFF, 'portal_host' => false, 'realm_path' => '/'];
    }

    /**
     * The classification itself. One input: the request's path and host.
     *
     * @param Request $request
     * @return array ['channel', 'realm', 'portal_host', 'realm_path']
     */
    private static function __compute(Request $request): array
    {
        $path = static::dispatch_path($request);

        $portal_host = Rsx_Portal_Url::is_separate_host()
            && strtolower($request->getHost()) === Rsx_Portal_Url::host();

        $in_portal_prefix = Rsx_Portal::is_under_prefix($path);

        // The portal's own host is the portal realm throughout; on the application host the
        // prefix decides. A separate-host portal's prefix means nothing on the staff host.
        $is_portal = Rsx_Portal_Url::is_separate_host() ? $portal_host : $in_portal_prefix;
        $realm = $is_portal ? self::REALM_PORTAL : self::REALM_STAFF;

        // The path inside the realm: the portal prefix is not part of any portal path.
        $under_prefix = $is_portal && $in_portal_prefix;
        $realm_path = $under_prefix ? Rsx_Portal::strip_prefix($path) : $path;

        if (AssetHandler::is_build_artifact_request($realm_path)) {
            return ['channel' => self::ASSET, 'realm' => $realm, 'portal_host' => $portal_host, 'realm_path' => $realm_path];
        }

        // The API is a staff identity wherever it answers, so its realm is staff even on
        // the portal host - where the API pipeline refuses it (is_portal_host()). /api/
        // under a non-empty portal prefix is a portal path, not the API.
        $prefixed_portal_path = $under_prefix && Rsx_Portal_Url::prefix() !== '';

        if (!$prefixed_portal_path && Api_Dispatcher::is_api_request($path)) {
            return ['channel' => self::API, 'realm' => self::REALM_STAFF, 'portal_host' => $portal_host, 'realm_path' => $path];
        }

        if (str_starts_with($realm_path, '/_ajax/') && $request->getRealMethod() === 'POST') {
            return ['channel' => self::AJAX, 'realm' => $realm, 'portal_host' => $portal_host, 'realm_path' => $realm_path];
        }

        return ['channel' => self::PAGE, 'realm' => $realm, 'portal_host' => $portal_host, 'realm_path' => $realm_path];
    }
}
