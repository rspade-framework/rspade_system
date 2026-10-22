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
use App\RSpade\Core\Portal\Portal_Site_Resolver;
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
 * THE REALM (staff | portal) is an attribute of AJAX and PAGE, not a channel. Which
 * requests are the portal's, and which site each serves, is Portal_Site_Resolver's reading
 * of the URL against PORTAL_URL (a template), PORTAL_CUSTOM_DOMAINS and sites.portal_domain:
 *
 *   portal on the application host (under a prefix, per site in multi tenancy): a path
 *     under the prefix is the portal, any other path is staff - on every host the
 *     application answers on.
 *   portal on a host of its own (a separate PORTAL_URL host, a tenant subdomain, a custom
 *     domain): EVERY request on that host is the portal realm. A path outside the prefix
 *     there is a portal path no route matches (Dispatcher answers the portal's 404), never
 *     a staff page. On any other host the request is staff, the prefix included.
 *
 * Build artifacts (/_compiled, /_vendor) are realm-agnostic on every host. realm_path is
 * the path with the request's portal base removed when the request is under it. For AJAX
 * and PAGE the resolver also SETTLES the site the address names - portal_site_id(),
 * portal_redirect_to() (a template address of a site served at its custom domain) or
 * portal_not_served() (no servable portal there) - which the front controller answers
 * before dispatch. Rsx_Portal::is_portal_request() is exactly "realm() === REALM_PORTAL".
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
     * The stored classification: ['channel', 'realm', 'portal_host', 'realm_path',
     * 'portal_base', 'portal_site_id', 'portal_redirect_to', 'portal_not_served'], or null
     * when this process has classified nothing yet.
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
     * True when the current request arrived on a host that belongs to the portal - a
     * PORTAL_URL host other than APP_URL's, a tenant subdomain, a custom portal domain.
     * Never true when the portal is under a prefix on the application host. The API reads
     * this to refuse its whole namespace there.
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
     * The concrete path prefix of this request's portal ('/_portal', '/_portal/acme', ''
     * on a portal host's root). Outside a classified portal request - the CLI/test seam
     * set_realm() - the current site's prefix (Rsx_Portal_Url::prefix(), which throws when
     * that site has no portal).
     */
    public static function portal_base(): string
    {
        return static::resolved_portal_base() ?? Rsx_Portal_Url::prefix();
    }

    /**
     * The concrete path prefix the classifier resolved for this portal request, or null when
     * none was resolved (not a portal request, or the CLI/test seam set_realm()).
     */
    public static function resolved_portal_base(): ?string
    {
        return static::__state()['portal_base'];
    }

    /**
     * The site this portal request's URL resolved to, or null (not a portal request, a
     * build artifact, an address no servable portal answers, or the CLI/test seam).
     */
    public static function portal_site_id(): ?int
    {
        return static::__state()['portal_site_id'];
    }

    /**
     * Where a template address of a site served at its custom domain is permanently
     * redirected (PORTAL_CUSTOM_DOMAINS=override), or null.
     */
    public static function portal_redirect_to(): ?string
    {
        return static::__state()['portal_redirect_to'];
    }

    /**
     * True when this portal request's address names no servable portal - an unknown slug
     * or id, a disabled or deleted site, a site whose portal is switched off, site 0. The
     * front controller answers the portal's 404 before dispatch.
     */
    public static function portal_not_served(): bool
    {
        return static::__state()['portal_not_served'];
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
     * The stored classification as a value (null = none stored), for _restore().
     * FRAMEWORK INTERNAL - Session::_snapshot_context().
     *
     * @return array|null
     */
    public static function _snapshot(): ?array
    {
        return static::$__state;
    }

    /**
     * Put back a classification _snapshot() took. FRAMEWORK INTERNAL -
     * Session::_restore_context().
     *
     * @param array|null $state
     * @return void
     */
    public static function _restore(?array $state): void
    {
        static::$__state = $state;
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
        return static::__state_of(self::PAGE, self::REALM_STAFF, false, '/');
    }

    /**
     * One stored classification.
     *
     * @param string $channel
     * @param string $realm
     * @param bool $portal_host
     * @param string $realm_path
     * @param array|null $portal The resolver's answer (match() or settle()) for a portal request
     * @return array
     */
    private static function __state_of(string $channel, string $realm, bool $portal_host, string $realm_path, ?array $portal = null): array
    {
        return [
            'channel' => $channel,
            'realm' => $realm,
            'portal_host' => $portal_host,
            'realm_path' => $realm_path,
            'portal_base' => $portal['base_path'] ?? null,
            'portal_site_id' => $portal['site_id'] ?? null,
            'portal_redirect_to' => $portal['redirect_to'] ?? null,
            'portal_not_served' => $portal['not_served'] ?? false,
        ];
    }

    /**
     * The classification itself. One input: the request's host, path and query string.
     *
     * @param Request $request
     * @return array See $__state
     */
    private static function __compute(Request $request): array
    {
        $path = static::dispatch_path($request);

        // The Host header is read (and validated) only where the answer can depend on it.
        $host = Portal_Site_Resolver::needs_host() ? strtolower($request->getHost()) : '';
        $match = Portal_Site_Resolver::match($host, $path);

        $realm = $match === null ? self::REALM_STAFF : self::REALM_PORTAL;
        $portal_host = $match !== null && $match['portal_host'];
        $realm_path = $match === null ? $path : $match['realm_path'];

        // A build artifact never asks which site: no lookup, no verdict.
        if (AssetHandler::is_build_artifact_request($realm_path)) {
            return static::__state_of(self::ASSET, $realm, $portal_host, $realm_path, $match);
        }

        // The API is a staff identity wherever it answers, so its realm is staff even on
        // a portal host - where the API pipeline refuses it (is_portal_host()). /api/
        // under a non-empty portal prefix is a portal path, not the API.
        $prefixed_portal_path = $match !== null && $match['under_base'] && $match['base_path'] !== '';

        if (!$prefixed_portal_path && Api_Dispatcher::is_api_request($path)) {
            return static::__state_of(self::API, self::REALM_STAFF, $portal_host, $path);
        }

        $portal = $match === null ? null : Portal_Site_Resolver::settle($match, (string) $request->server->get('QUERY_STRING', ''));
        $channel = str_starts_with($realm_path, '/_ajax/') && $request->getRealMethod() === 'POST' ? self::AJAX : self::PAGE;

        return static::__state_of($channel, $realm, $portal_host, $realm_path, $portal);
    }
}
