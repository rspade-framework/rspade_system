// @ROUTE-EXISTS-01-EXCEPTION - This file contains documentation examples with fictional route names

/**
 * Rsx_Portal - Client Portal JavaScript Runtime Utilities
 *
 * Provides static utility methods for client portal JavaScript code including
 * route generation and portal context detection.
 *
 * Key differences from Rsx:
 * - Route() applies the portal's address: its prefix, and its origin when this page is
 *   not on the portal's host
 * - Portal-specific context detection
 * - Simpler (no event system, fewer utilities)
 *
 * The portal's address is the server's derivation of PORTAL_URL (PHP Rsx_Portal_Url),
 * shipped in every bundle as window.rsxapp.portal {origin, prefix, separate_host} and read
 * through origin() / host() / prefix() / is_separate_host().
 *
 * Usage Examples:
 * ```javascript
 * // Check if in portal context
 * if (Rsx_Portal.is_portal()) { ... }
 *
 * // Route generation (applies the portal's prefix, and origin when needed)
 * const url = Rsx_Portal.Route('Portal_Dashboard_Action');
 * // Default PORTAL_URL: /_portal/dashboard
 * // PORTAL_URL=https://portal.example.com/ from a staff page:
 * //   https://portal.example.com/dashboard  (on the portal host: /dashboard)
 *
 * // Route with parameters
 * const url = Rsx_Portal.Route('Portal_Project_View_Action', 123);
 * // Default PORTAL_URL: /_portal/projects/123
 * ```
 *
 * @static
 * @global
 */
class Rsx_Portal {
    /**
     * Storage for portal route definitions loaded from bundles
     */
    static _routes = {};

    /**
     * Cached portal context detection result
     */
    static _is_portal = null;

    // =========================================================================
    // Configuration Methods
    // =========================================================================

    /**
     * scheme://host[:port] the portal is served on ('' before APP_URL is configured).
     *
     * @returns {string}
     */
    static origin() {
        return window.rsxapp.portal.origin;
    }

    /**
     * The portal's host, lowercased, without a port ('' before APP_URL is configured).
     *
     * @returns {string}
     */
    static host() {
        const origin = Rsx_Portal.origin();

        return origin === '' ? '' : new URL(origin).hostname.toLowerCase();
    }

    /**
     * The path the portal lives under on its host: '' or '/seg[/seg]', no trailing slash.
     *
     * @returns {string}
     */
    static prefix() {
        return window.rsxapp.portal.prefix;
    }

    /**
     * True when the portal is served on a host other than the application's.
     *
     * @returns {boolean}
     */
    static is_separate_host() {
        return window.rsxapp.portal.separate_host === true;
    }

    /**
     * Whether a path (a query string or fragment may follow) is under the portal's prefix.
     * An empty prefix contains every path. Mirror of PHP Rsx_Portal::is_under_prefix().
     *
     * @param {string} path
     * @returns {boolean}
     */
    static is_under_prefix(path) {
        const prefix = Rsx_Portal.prefix();

        if (prefix === '' || path === prefix) {
            return true;
        }

        if (!path.startsWith(prefix)) {
            return false;
        }

        return ['/', '?', '#'].includes(path.charAt(prefix.length));
    }

    /**
     * A path with the portal's prefix removed - the path INSIDE the portal. Unchanged when
     * the portal has no prefix or the path is not under it. Mirror of PHP
     * Rsx_Portal::strip_prefix().
     *
     * @param {string} path
     * @returns {string}
     */
    static strip_prefix(path) {
        const prefix = Rsx_Portal.prefix();

        if (prefix === '' || !Rsx_Portal.is_under_prefix(path)) {
            return path;
        }

        const remainder = path.slice(prefix.length);

        return remainder === '' || remainder.charAt(0) !== '/' ? '/' + remainder : remainder;
    }

    /**
     * Whether a portal URL built here can be a host-relative path: always when the portal
     * shares the application's host, otherwise only on a page served from the portal's
     * host. Mirror of PHP Rsx_Portal::is_on_portal_host().
     *
     * @returns {boolean}
     */
    static is_on_portal_host() {
        if (!Rsx_Portal.is_separate_host()) {
            return true;
        }

        return window.location.hostname.toLowerCase() === Rsx_Portal.host();
    }

    // =========================================================================
    // Portal Context Detection
    // =========================================================================

    /**
     * Check if currently in portal context
     *
     * @returns {boolean} True if this is a portal page
     */
    static is_portal() {
        if (Rsx_Portal._is_portal !== null) {
            return Rsx_Portal._is_portal;
        }

        // Check rsxapp flag (set by portal bootstrap)
        Rsx_Portal._is_portal = !!window.rsxapp?.is_portal;

        return Rsx_Portal._is_portal;
    }

    /**
     * Get the current portal user
     *
     * @returns {Object|null} Portal user data or null if not logged in
     */
    static user() {
        if (!Rsx_Portal.is_portal()) {
            return null;
        }
        return window.rsxapp?.user || null;
    }

    /**
     * Whether the current portal session is a staff impersonation session.
     * UI affordance only - the server (Portal_Session::is_impersonating()) is the
     * enforcement boundary, and the APPLICATION is responsible for the read-only
     * experience. See: php artisan rsx:man portal.
     *
     * @returns {boolean}
     */
    static is_impersonating() {
        return !!window.rsxapp?.portal?.is_impersonating;
    }

    /**
     * The staff user id impersonating in the current session, or null.
     *
     * @returns {number|null}
     */
    static impersonator_user_id() {
        return window.rsxapp?.portal?.impersonator_user_id || null;
    }

    // =========================================================================
    // Internal Endpoints
    // =========================================================================

    /**
     * Rebase a framework INTERNAL endpoint path (/_ajax/..., /_ajax/_batch, /_upload,
     * and the file routes /_thumbnail/..., /_inline/..., /_download/..., /_preview/...,
     * /_icon_by_extension/...) onto the base the current page is served under. The PHP
     * twin is Rsx_Portal::internal_url().
     *
     * The framework serves each internal endpoint on BOTH channels: the staff
     * dispatcher answers the bare path in the staff realm, and under the portal's own
     * prefix in the portal realm. Which one a page must call is decided by the page:
     *
     *   staff page   -> unchanged                (/_ajax/Foo/bar)
     *   portal page  -> under the portal prefix  (/_portal/_ajax/Foo/bar by default;
     *                                             unchanged when the portal is at the
     *                                             root of its own host)
     *
     * A portal page is always on the portal's host, so the result is a path.
     *
     * Calling the bare path from a portal page is what made portal Ajax a STAFF
     * request: the portal session's realm, CSRF token and portal_fetch() contract
     * were all resolved wrongly. Every internal-endpoint URL the client builds goes
     * through here.
     *
     * @param {string} path An internal endpoint path beginning with '/'
     * @returns {string} The path to request from this page
     */
    static internal_url(path) {
        if (!Rsx_Portal.is_portal()) {
            return path;
        }

        return Rsx_Portal.prefix() + path;
    }

    // =========================================================================
    // Route Generation
    // =========================================================================

    /**
     * Generate URL for a portal route
     *
     * Similar to Rsx.Route() but:
     * - Returns URLs under the portal's prefix, and on the portal's origin when this page
     *   is not on the portal's host (is_on_portal_host())
     * - Only works with portal routes
     *
     * Usage examples:
     * ```javascript
     * // Portal action route
     * const url = Rsx_Portal.Route('Portal_Dashboard_Action');
     * // Default PORTAL_URL: /_portal/dashboard
     *
     * // Route with integer parameter (sets 'id')
     * const url = Rsx_Portal.Route('Portal_Project_View_Action', 123);
     * // Default PORTAL_URL: /_portal/projects/123
     *
     * // Route with named parameters
     * const url = Rsx_Portal.Route('Portal_Project_View_Action', {id: 123, tab: 'files'});
     * // Default PORTAL_URL: /_portal/projects/123?tab=files
     *
     * // Hash state (the fragment Rsx.url_hash_get() reads back)
     * const url = Rsx_Portal.Route('Portal_Project_View_Action', 123, {tab: 'files'});
     * // Default PORTAL_URL: /_portal/projects/123#tab=files
     *
     * // Placeholder route
     * const url = Rsx_Portal.Route('Future_Portal_Feature::#index');
     * // Returns: #
     * ```
     *
     * @param {string} action Controller class, SPA action, or "Class::method"
     * @param {number|Object} [params=null] Route parameters
     * @param {Object} [hash=null] Fragment state, as Rsx.Route() takes it
     * @returns {string} A path, or an absolute URL when this page is not on the portal host
     */
    static Route(action, params = null, hash = null) {
        if (typeof action !== 'string') {
            throw new Error('Rsx_Portal.Route: action must be a string, got ' + typeof action);
        }

        // Parse action into class_name and action_name
        let class_name, action_name;
        if (action.includes('::')) {
            [class_name, action_name] = action.split('::', 2);
        } else {
            class_name = action;
            action_name = 'index';
        }

        // Normalize params to object
        let params_obj = {};
        if (typeof params === 'number') {
            params_obj = { id: params };
        } else if (typeof params === 'string' && /^\d+$/.test(params)) {
            params_obj = { id: parseInt(params, 10) };
        } else if (params && typeof params === 'object') {
            params_obj = params;
        } else if (params !== null && params !== undefined) {
            throw new Error('Params must be number, object, or null');
        }

        // Placeholder route: action starts with # means unimplemented/scaffolding
        if (action_name.startsWith('#')) {
            return '#';
        }

        // Check if route exists in portal route definitions
        let pattern = null;

        if (Rsx_Portal._routes[class_name] && Rsx_Portal._routes[class_name][action_name]) {
            const route_patterns = Rsx_Portal._routes[class_name][action_name];
            pattern = Rsx._select_best_route_pattern(route_patterns, params_obj);

            if (!pattern) {
                const route_list = route_patterns.join(', ');
                throw new Error(
                    `No suitable portal route found for ${class_name}::${action_name} with provided parameters. ` +
                    `Available routes: ${route_list}`
                );
            }
        } else {
            // Not found in portal routes - try SPA action route
            pattern = Rsx_Portal._try_spa_action_route(class_name, params_obj);

            if (!pattern) {
                throw new Error(
                    `Portal route not found for ${action}. ` +
                    `Ensure the class has a @portal_route decorator.`
                );
            }
        }

        // Selection and generation are Rsx's own routines: a portal URL is a staff URL with
        // the portal base applied, so tokens, the query string, the `at` anchor
        // (rsx:man anchors) and the hash state behave identically in both realms.
        const path = Rsx._generate_url_from_pattern(pattern, params_obj, hash);

        return Rsx_Portal._apply_portal_base(path);
    }

    /**
     * Place a portal-namespace path at the portal's address: prefix + path, on the portal's
     * origin unless is_on_portal_host(). Mirror of PHP Rsx_Portal::_apply_portal_base().
     *
     * @param {string} path The path inside the portal (e.g., '/dashboard')
     * @returns {string}
     * @private
     */
    static _apply_portal_base(path) {
        const prefixed = Rsx_Portal.prefix() + path;

        if (Rsx_Portal.is_on_portal_host()) {
            return prefixed;
        }

        return Rsx_Portal.origin() + prefixed;
    }

    /**
     * Try to find a route pattern for a portal SPA action class
     *
     * @param {string} class_name The action class name
     * @param {Object} params_obj The parameters for route selection
     * @returns {string|null} The route pattern or null
     * @private
     */
    static _try_spa_action_route(class_name, params_obj) {
        const class_object = Manifest.get_class_by_name(class_name);

        if (!class_object || typeof Spa_Action === 'undefined' || !(class_object.prototype instanceof Spa_Action)) {
            return null;
        }

        // Portal SPA actions carry @route() patterns (_spa_routes) and the @portal_spa()
        // mark (_is_portal_spa); a staff action is never a portal route target.
        const routes = class_object._spa_routes || [];

        if (routes.length === 0 || !class_object._is_portal_spa) {
            return null;
        }

        const selected = Rsx._select_best_route_pattern(routes, params_obj);

        if (!selected) {
            throw new Error(
                `No suitable portal route found for SPA action ${class_name} with provided parameters. ` +
                `Available routes: ${routes.join(', ')}`
            );
        }

        return selected;
    }

    /**
     * Define portal routes from bundled data
     * Called by generated JavaScript in portal bundles
     *
     * @param {Object} routes Route definitions object
     */
    static _define_routes(routes) {
        for (const class_name in routes) {
            if (!Rsx_Portal._routes[class_name]) {
                Rsx_Portal._routes[class_name] = {};
            }
            for (const method_name in routes[class_name]) {
                Rsx_Portal._routes[class_name][method_name] = routes[class_name][method_name];
            }
        }
    }

    /**
     * Clear cached state (for testing)
     */
    static _clear_cache() {
        Rsx_Portal._is_portal = null;
        Rsx_Portal._routes = {};
    }
}
