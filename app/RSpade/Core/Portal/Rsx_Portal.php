<?php
/**
 * @ROUTE-EXISTS-01-EXCEPTION - This file contains documentation examples with fictional route names
 */

namespace App\RSpade\Core\Portal;

use RuntimeException;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;

/**
 * Portal utility class
 *
 * Provides static utility methods for the client portal including
 * route generation and portal context detection.
 *
 * Key differences from Rsx:
 * - Route() prepends portal domain/prefix
 * - Portal-specific context detection
 * - Simpler (no event system, fewer utilities)
 */
class Rsx_Portal
{
    /**
     * URL prefix for portal when no dedicated domain configured
     */
    public const URL_PREFIX = '/_portal';

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
    // Configuration Methods
    // =========================================================================

    /**
     * Get the configured portal domain
     *
     * @return string|null Domain if configured, null otherwise
     */
    public static function get_domain(): ?string
    {
        return config('rsx.portal.domain');
    }

    /**
     * Get the portal URL prefix (used when no domain configured)
     *
     * @return string The prefix, defaults to '/_portal'
     */
    public static function get_prefix(): string
    {
        return config('rsx.portal.prefix', self::URL_PREFIX);
    }

    /**
     * Check if portal is using a dedicated domain (vs URL prefix)
     *
     * @return bool True if dedicated domain is configured
     */
    public static function has_dedicated_domain(): bool
    {
        return !empty(self::get_domain());
    }

    // =========================================================================
    // Portal Request Context Detection
    // =========================================================================

    /**
     * Is the current request a portal request?
     *
     * The answer is the request's REALM as Rsx_Request_Channel classified it, once, before
     * anything was dispatched: the portal's dedicated domain, or its path prefix on the
     * staff host. Outside a request (CLI, tests) it is false unless set_portal_request()
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
     * A URL with the portal's path prefix removed - the path INSIDE the portal. Unchanged
     * when the portal has a dedicated domain (there is no prefix) or the URL is not under
     * the prefix.
     *
     * @param string $url
     * @return string
     */
    public static function strip_prefix(string $url): string
    {
        if (!self::has_dedicated_domain()) {
            $prefix = self::get_prefix();

            if (str_starts_with($url, $prefix)) {
                $url = substr($url, strlen($prefix)) ?: '/';
            }
        }

        return $url;
    }

    /**
     * Get the current request URL with portal prefix stripped (if applicable)
     *
     * @return string The normalized path
     */
    public static function get_normalized_path(): string
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query string
        $path = parse_url($request_uri, PHP_URL_PATH) ?? '/';

        // If using prefix mode and path starts with prefix, strip it
        if (!self::has_dedicated_domain()) {
            $prefix = self::get_prefix();
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
        }

        return $path;
    }

    // =========================================================================
    // Route Generation
    // =========================================================================

    /**
     * Generate URL for a portal route
     *
     * Similar to Rsx::Route() but:
     * - Returns URLs with portal domain or prefix
     * - Only works with routes that have #[Portal_Route] attribute
     *
     * Usage examples:
     * ```php
     * // Portal action route
     * $url = Rsx_Portal::Route('Portal_Dashboard_Action');
     * // Development: /_portal/dashboard
     * // Production: https://portal.example.com/dashboard
     *
     * // Route with integer parameter
     * $url = Rsx_Portal::Route('Portal_Project_View_Action', 123);
     * // Development: /_portal/projects/123
     *
     * // Hash state (the fragment Rsx.url_hash_get() reads back)
     * $url = Rsx_Portal::Route('Portal_Project_View_Action', 123, ['tab' => 'files']);
     * // Development: /_portal/projects/123#tab=files
     *
     * // Placeholder route
     * $url = Rsx_Portal::Route('Future_Portal_Feature::#index');
     * // Returns: #
     * ```
     *
     * @param string $action Controller class, SPA action, or "Class::method"
     * @param int|array|\stdClass|null $params Route parameters
     * @param array|null $hash Fragment state, as Rsx::Route() takes it
     * @return string The generated URL (may include portal domain/prefix)
     */
    public static function Route($action, $params = null, ?array $hash = null): string
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

        // Apply portal prefix/domain
        return self::_apply_portal_base($path);
    }

    /**
     * Generate absolute URL for a portal route
     *
     * @param string $action Controller class, SPA action, or "Class::method"
     * @param int|array|\stdClass|null $params Route parameters
     * @param array|null $hash Fragment state, as Rsx::Route() takes it
     * @return string Full URL including protocol and domain
     */
    public static function url($action, $params = null, ?array $hash = null): string
    {
        $path = self::Route($action, $params, $hash);

        // If already has domain, return as-is
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Build absolute URL
        $portal_domain = self::get_domain();

        if (!empty($portal_domain)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

            return $protocol . '://' . $portal_domain . $path;
        }

        // No portal domain, use current host with path
        return url($path);
    }

    /**
     * A portal-namespace path as the browser addresses it: prefixed in prefix mode
     * ('/login' -> '/_portal/login'), unchanged on a dedicated portal domain.
     *
     * For a framework path that is not a route target - a ceremony URL, a redirect after a
     * failure. Anything that IS a route target is Route().
     *
     * @param string $path A path in the portal's own namespace, starting with '/'.
     * @return string
     */
    public static function portal_path(string $path): string
    {
        return self::_apply_portal_base($path);
    }

    /**
     * Apply portal domain or prefix to a path
     *
     * @param string $path The route path (e.g., '/dashboard')
     * @return string Path with portal prefix, or full URL if domain configured
     */
    protected static function _apply_portal_base(string $path): string
    {
        // If using dedicated domain in production, keep path as-is
        // The domain will be handled at routing level
        if (self::has_dedicated_domain()) {
            // In production, the path is relative to portal domain
            // The caller should use url() if they need full URL
            return $path;
        }

        // Development mode: prepend prefix
        $prefix = self::get_prefix();

        return $prefix . $path;
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
