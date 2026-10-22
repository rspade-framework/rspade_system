<?php
/**
 * @ROUTE-EXISTS-01-EXCEPTION - This file contains documentation examples with fictional route names
 */

namespace App\RSpade\Core\Portal;

use RuntimeException;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Rsx;

/**
 * Portal utility class
 *
 * Provides static utility methods for the client portal including
 * route generation and portal context detection.
 *
 * Key differences from Rsx:
 * - Route() applies the portal's address (Rsx_Portal_Url: its prefix, and its origin
 *   when the caller is not on the portal's host)
 * - Portal-specific context detection
 * - Simpler (no event system, fewer utilities)
 */
class Rsx_Portal
{
    /**
     * Current portal controller being executed
     * @var string|null
     */
    protected static ?string $current_controller = null;

    /**
     * Current portal action being executed
     * @var string|null
     */
    protected static ?string $current_action = null;

    /**
     * Current portal route type ('spa' or 'standard')
     * @var string|null
     */
    protected static ?string $current_route_type = null;

    // =========================================================================
    // Portal Request Context Detection
    // =========================================================================

    /**
     * Is the current request a portal request?
     *
     * The answer is the request's REALM as Rsx_Request_Channel classified it, once, before
     * anything was dispatched: on the portal's own host every request, on the application
     * host a request under the portal's prefix (Portal_Site_Resolver). Outside a request (CLI, tests) it is false unless set_portal_request()
     * declared otherwise.
     *
     * @return bool True if this is a portal request
     */
    public static function is_portal_request(): bool
    {
        return Rsx_Request_Channel::is_portal();
    }

    /**
     * Declare whether this is a portal request - the CLI/test seam. A real request is
     * classified by the front controller and never needs it.
     *
     * @param bool $is_portal
     * @return void
     */
    public static function set_portal_request(bool $is_portal): void
    {
        Rsx_Request_Channel::set_realm($is_portal ? Rsx_Request_Channel::REALM_PORTAL : Rsx_Request_Channel::REALM_STAFF);
    }

    /**
     * Whether a path (a query string or fragment may follow) is under the portal's prefix:
     * the prefix itself, or the prefix followed by '/', '?' or '#'. An empty prefix (the
     * portal at the root of its own host) contains every path.
     *
     * The prefix is this portal request's base (Rsx_Request_Channel::portal_base() - per
     * site in multi tenancy); outside a classified portal request, the current site's
     * (Rsx_Portal_Url::prefix(), which throws when that site has no portal).
     *
     * @param string $path
     * @return bool
     */
    public static function is_under_prefix(string $path): bool
    {
        return static::is_under_base($path, Rsx_Request_Channel::portal_base());
    }

    /**
     * A URL with the portal's path prefix removed - the path INSIDE the portal ('/_portal'
     * -> '/', '/_portal/x?y' -> '/x?y'). Unchanged when the portal has no prefix or the URL
     * is not under it. The prefix is is_under_prefix()'s.
     *
     * @param string $url
     * @return string
     */
    public static function strip_prefix(string $url): string
    {
        return static::strip_base($url, Rsx_Request_Channel::portal_base());
    }

    /**
     * is_under_prefix() against a given base ('' or '/seg[/seg]') - one tenant's concrete
     * prefix ('/_portal/acme') rather than the configured one.
     *
     * @param string $path
     * @param string $base
     * @return bool
     */
    public static function is_under_base(string $path, string $base): bool
    {
        if ($base === '' || $path === $base) {
            return true;
        }

        if (!str_starts_with($path, $base)) {
            return false;
        }

        return in_array($path[strlen($base)], ['/', '?', '#'], true);
    }

    /**
     * strip_prefix() against a given base: '/' for the base itself, the remainder beneath
     * it, the URL unchanged when the base is empty or the URL is not under it.
     *
     * @param string $url
     * @param string $base
     * @return string
     */
    public static function strip_base(string $url, string $base): string
    {
        if ($base === '' || !static::is_under_base($url, $base)) {
            return $url;
        }

        $remainder = substr($url, strlen($base));

        return ($remainder === '' || $remainder[0] !== '/') ? '/' . $remainder : $remainder;
    }

    /**
     * Whether a URL a site's portal generates can be a host-relative path for the caller.
     *
     * In a same-host layout (the portal under a prefix on APP_URL's host) yes, unless the
     * caller is itself on a portal host of its own (a custom domain): the portal lives on
     * whatever application host the caller is on, and an absolute form, when one is
     * needed, is rsx_absolute_url()'s job. With the portal on its own host, only when the
     * current request's host IS that portal's host; a staff page, CLI and a task (whose
     * request carries APP_URL's host) need the absolute URL. False when the site has no
     * portal.
     *
     * @param int|null $site_id The site (Rsx_Portal_Url::for_site()); null = the current site
     * @return bool
     */
    public static function is_on_portal_host(?int $site_id = null): bool
    {
        $address = Rsx_Portal_Url::for_site($site_id);

        return $address !== false && static::__is_on_host_of($address);
    }

    /**
     * Rebase a framework endpoint path served in BOTH realms (/_upload, /_thumbnail/...,
     * /_inline/..., /_download/..., /_download_zip/..., /_preview/..., /_icon_by_extension/...)
     * onto the realm of the CURRENT request - the PHP twin of Rsx_Portal.internal_url().
     *
     *   staff request (and CLI, tasks, the external API)  -> unchanged
     *   portal request                                    -> under the request's portal base
     *                                                        (per site in multi tenancy)
     *
     * A portal request is always on the portal's host, so the result is a path. Without
     * this a portal page would request the bare path, which on the application host is a
     * STAFF request (authorized against the staff side of the session) and on the portal's
     * own host is outside the prefix.
     *
     * @param string $path An endpoint path beginning with '/'
     * @return string
     */
    public static function internal_url(string $path): string
    {
        if (!static::is_portal_request()) {
            return $path;
        }

        return Rsx_Request_Channel::portal_base() . $path;
    }

    // =========================================================================
    // Route Generation
    // =========================================================================

    /**
     * Generate URL for a portal route
     *
     * Similar to Rsx::Route() but:
     * - Returns URLs under the portal's prefix, and on the portal's origin when the
     *   caller is not on the portal's host (is_on_portal_host())
     * - Only works with routes that have #[Portal_Route] attribute
     *
     * So rsx_absolute_url(Rsx_Portal::Route(...)) is right from any context: a path is
     * made absolute against the caller's host, an absolute URL passes through.
     *
     * Usage examples:
     * ```php
     * // Portal action route
     * $url = Rsx_Portal::Route('Portal_Dashboard_Action');
     * // Default PORTAL_URL: /_portal/dashboard
     * // PORTAL_URL=https://portal.example.com/, from a staff page or a task:
     * //   https://portal.example.com/dashboard  (on the portal host: /dashboard)
     *
     * // Route with integer parameter
     * $url = Rsx_Portal::Route('Portal_Project_View_Action', 123);
     * // Default PORTAL_URL: /_portal/projects/123
     *
     * // Hash state (the fragment Rsx.url_hash_get() reads back)
     * $url = Rsx_Portal::Route('Portal_Project_View_Action', 123, ['tab' => 'files']);
     * // Default PORTAL_URL: /_portal/projects/123#tab=files
     *
     * // Placeholder route
     * $url = Rsx_Portal::Route('Future_Portal_Feature::#index');
     * // Returns: #
     * ```
     *
     * THE SITE. Every site has its own portal address in multi tenancy, so the URL is for
     * one site: the named site_id, or the current one (Rsx_Portal_Url::for_site()) - in a
     * portal request the site its URL resolved to, on a staff page the signed-in site.
     * FALSE when that site has no portal (the feature off, the site unserved or switched
     * off, under 'only' no custom domain): a staff screen that links into the portal
     * handles it rather than send a broken link. A portal request's own site always has
     * one.
     *
     * ```php
     * // Every site's invitation link from a batch task:
     * $url = Rsx_Portal::Route('Portal_Login_Controller', site_id: $site->id);
     * if ($url === false) { ... this site has no client portal ... }
     * ```
     *
     * @param string $action Controller class, SPA action, or "Class::method"
     * @param int|array|\stdClass|null $params Route parameters
     * @param array|null $hash Fragment state, as Rsx::Route() takes it
     * @param int|null $site_id The site whose portal; null = the current site
     * @return string|false A path, or an absolute URL when the caller is not on that portal's
     *                      host; false when the site has no portal
     */
    public static function Route($action, $params = null, ?array $hash = null, ?int $site_id = null): string|false
    {
        // Parse action into class_name and action_name
        if (str_contains($action, '::')) {
            [$class_name, $action_name] = explode('::', $action, 2);
        } else {
            $class_name = $action;
            $action_name = 'index';
        }

        // Normalize params to array
        $params_array = [];
        if (is_int($params)) {
            $params_array = ['id' => $params];
        } elseif (is_array($params)) {
            $params_array = $params;
        } elseif ($params instanceof \stdClass) {
            $params_array = (array) $params;
        } elseif ($params !== null) {
            throw new RuntimeException("Params must be integer, array, stdClass, or null");
        }

        // Placeholder route
        if (str_starts_with($action_name, '#')) {
            return '#';
        }

        // Look up routes in manifest using portal_routes_by_target
        $manifest = Manifest::get_full_manifest();

        // Build target - always include ::method for consistency with manifest keys
        $target = $class_name . '::' . $action_name;

        // First try direct target lookup (Class::method)
        if (!isset($manifest['data']['portal_routes_by_target'][$target])) {
            // Allow shorthand: Route('MyController') implies Route('MyController::index')
            if ($action_name === 'index' && isset($manifest['data']['portal_routes_by_target'][$class_name])) {
                $target = $class_name;
            } else {
                throw new Rsx_Caller_Exception(
                    "Portal route not found for {$action}. " .
                    "Ensure the class has a #[Portal_Route] attribute."
                );
            }
        }

        $routes = $manifest['data']['portal_routes_by_target'][$target];

        // Selection and generation are Rsx's own routines: a portal URL is a staff URL with
        // the portal base applied, so tokens, the query string, the `at` anchor
        // (rsx:man anchors) and the hash state behave identically in both realms.
        $selected_route = Rsx::_select_best_route($routes, $params_array);

        if (!$selected_route) {
            throw new Rsx_Caller_Exception(
                "No suitable portal route found for {$action} with provided parameters. " .
                "Available routes: " . implode(', ', array_column($routes, 'pattern'))
            );
        }

        $path = Rsx::_generate_url_from_pattern($selected_route['pattern'], $params_array, $class_name, $action_name, $hash);

        return self::_apply_portal_base($path, $site_id);
    }

    /**
     * A portal-namespace path as the browser addresses it: under the portal's prefix
     * ('/login' -> '/_portal/login' by default), and absolute on the portal's origin when
     * the caller is not on the portal's host - exactly as Route() builds a route's URL.
     *
     * For a framework path that is not a route target - a ceremony URL, a redirect after a
     * failure. Anything that IS a route target is Route(). The site, and false, are Route()'s.
     *
     * @param string $path A path in the portal's own namespace, starting with '/'.
     * @param int|null $site_id The site whose portal; null = the current site
     * @return string|false
     */
    public static function portal_path(string $path, ?int $site_id = null): string|false
    {
        return self::_apply_portal_base($path, $site_id);
    }

    /**
     * Place a portal-namespace path at a site's portal address: prefix + path, on that
     * portal's origin unless the caller is on its host. A portal request generating a path
     * of its OWN portal (no site named) uses the base its URL resolved to
     * (Rsx_Request_Channel::resolved_portal_base()), on the host it arrived on - which also
     * answers on an address that serves no site (the portal's 404 page links home).
     *
     * @param string $path The path inside the portal (e.g., '/dashboard')
     * @param int|null $site_id The site whose portal; null = the current site
     * @return string|false False when the site has no portal
     */
    protected static function _apply_portal_base(string $path, ?int $site_id = null): string|false
    {
        $resolved_base = ($site_id === null && static::is_portal_request()) ? Rsx_Request_Channel::resolved_portal_base() : null;

        if ($resolved_base !== null) {
            return $resolved_base . $path;
        }

        $address = Rsx_Portal_Url::for_site($site_id);

        if ($address === false) {
            return false;
        }

        $path = $address['prefix'] . $path;

        if (static::__is_on_host_of($address)) {
            return $path;
        }

        return $address['origin'] . $path;
    }

    /**
     * is_on_portal_host() for an address for_site() answered.
     *
     * @param array $address
     * @return bool
     */
    private static function __is_on_host_of(array $address): bool
    {
        if (!$address['separate_host'] && !Rsx_Request_Channel::is_portal_host()) {
            return true;
        }

        return strtolower(request()->getHost()) === $address['host'];
    }

    // =========================================================================
    // Controller/Action Tracking
    // =========================================================================

    /**
     * Set the current portal controller and action being executed
     *
     * @param string $controller_class The controller class name
     * @param string $action_method The action method name
     * @param string|null $route_type Route type ('spa' or 'standard')
     */
    public static function _set_current_controller_action(
        string $controller_class,
        string $action_method,
        ?string $route_type = null
    ): void {
        // Extract just the class name without namespace
        $parts = explode('\\', $controller_class);
        $class_name = end($parts);

        static::$current_controller = $class_name;
        static::$current_action = $action_method;
        static::$current_route_type = $route_type;
    }

    /**
     * Get the current portal controller class name
     *
     * @return string|null
     */
    public static function get_current_controller(): ?string
    {
        return static::$current_controller;
    }

    /**
     * Get the current portal action method name
     *
     * @return string|null
     */
    public static function get_current_action(): ?string
    {
        return static::$current_action;
    }

    /**
     * Check if current portal route is a SPA route
     *
     * @return bool
     */
    public static function is_spa(): bool
    {
        return static::$current_route_type === 'spa' || static::$current_route_type === 'portal_spa';
    }

    /**
     * Clear the current controller and action tracking
     */
    public static function _clear_current_controller_action(): void
    {
        static::$current_controller = null;
        static::$current_action = null;
        static::$current_route_type = null;
    }

    /**
     * Clear all cached state (for testing)
     */
    public static function _clear_cache(): void
    {
        Rsx_Request_Channel::reset();
        static::$current_controller = null;
        static::$current_action = null;
        static::$current_route_type = null;
    }
}
