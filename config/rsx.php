<?php

/**
 * RSX Framework Configuration
 *
 * Framework defaults and core operational settings. User customizations belong
 * in /rsx/resource/config/rsx.php which is merged with this file.
 *
 * For extended documentation: php artisan rsx:man config_rsx
 *
 * ---
 *
 * MOVED TO USER CONFIG (/rsx/resource/config/rsx.php):
 *
 * The following configuration keys were moved from this file to user config
 * because they represent user preferences rather than framework requirements:
 *
 * - development.auto_rename_files - IDE preference for file auto-renaming
 *   Reason: VS Code extension user preference
 *
 * - development.ignore_filename_convention - Disable filename checks globally
 *   Reason: Developer choice for convention enforcement
 *
 * - manifest.excluded_dirs - Additional directories to exclude from scanning
 *   Reason: Users may want custom exclusions (framework exclusions are hardcoded)
 *
 * - code_quality.generic_suffix_replacements - Required suffix specificity rules
 *   Reason: Users define their own naming patterns (Handler, Service, etc.)
 *
 * - console_debug.outputs - Debug output destinations (cli, web, ajax, laravel_log)
 *   Reason: User preference for debug visibility
 *
 * - console_debug.filter_mode - Filter mode (all, whitelist, blacklist, specific)
 *   Reason: User debugging workflow preference
 *
 * - console_debug.specific_channel - Specific channel to show
 *   Reason: User debugging focus
 *
 * - console_debug.whitelist - Channels to show in whitelist mode
 *   Reason: Users add custom application debug channels
 *
 * - console_debug.blacklist - Channels to hide in blacklist mode
 *   Reason: User preference for hiding noisy channels
 *
 * - console_debug.include_benchmark - Include timing in debug output
 *   Reason: User preference for performance analysis
 *
 * - console_debug.include_location - Include file/line in debug output
 *   Reason: User debugging preference
 *
 * - console_debug.include_backtrace - Include call stack in debug output
 *   Reason: User debugging preference
 *
 * - log_browser_errors - Log JavaScript errors to Laravel log
 *   Reason: User preference for client-side error tracking
 *
 * - csp.additional_sources - Extra CSP sources for TRANSITIVE externals
 *   Reason: Application-specific; a script whose own further loads only the app knows
 *
 * - csp.enabled - Emit a policy at all
 *   Reason: Application-specific EMERGENCY KILL-SWITCH; the framework default is on
 *
 * - response.default_view - Default view when controller doesn't specify
 *   Reason: Application-specific default
 *
 * - response.error_views - Custom error view templates (404, 500)
 *   Reason: Application-specific error pages
 *
 * - response.cors - CORS settings for API responses
 *   Reason: Application-specific API configuration
 *
 * REMOVED (DEAD CODE):
 *
 * - middleware - RSX middleware configuration (UNUSED - no code references)
 * - response.asset_cache - Asset caching settings (UNUSED - no code references)
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Application Mode
    |--------------------------------------------------------------------------
    |
    | RSX_MODE is the authoritative source of truth for application mode.
    | Valid values: development, debug, production
    |
    | - development: Auto-rebuild, full debugging, sourcemaps
    | - debug: Production optimizations with sourcemaps for debugging
    | - production: Full optimization, minification, merging, CDN bundling
    |
    | See: php artisan rsx:man app_mode
    |
    */

    'mode' => env('RSX_MODE', 'development'),

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | 'tenancy' says whether this install serves one organisation or many.
    | Valid values: single, multi. Anything else throws at boot.
    |
    | It is APPLICATION configuration, not a deployment setting: there is no
    | .env key for it. The framework default is single; a multi-tenant
    | application sets 'tenancy' => 'multi' in its rsx/resource/config/rsx.php.
    |
    | - single: one tenant, always site 1. The client portal is served at
    |   PORTAL_URL exactly as written, which carries no tenant placeholder.
    | - multi: many tenants. PORTAL_URL must carry the tenant placeholder
    |   (:slug or :site_id) that addresses a portal per site.
    |
    | Its effect today is the client portal's tenancy. See rsx:man portal.
    |
    | Site 1 is the tenant a single-tenant install serves, the site the initial
    | user belongs to, and the site a sign-in with no other membership lands on
    | (Rsx_Tenancy::SINGLE_TENANT_SITE_ID - fixed, not configurable). Site 0 is
    | the sessionless infrastructure site and is never a tenant.
    |
    */

    'tenancy' => 'single',

    /*
    |--------------------------------------------------------------------------
    | Theme (dark mode)
    |--------------------------------------------------------------------------
    |
    | The per-identity theme preference (login_users.dark_mode), resolved by
    | Rsx_Dark_Mode and put on <html> before first paint by autotheme.js.
    |
    | - default: mode for a signed-out visitor, an identity that has expressed no
    |   preference, and every portal request (portal accounts have no theme
    |   preference). Login_User_Model::DARK_MODE_LIGHT / _DARK / _AUTO. AUTO follows
    |   the operating system.
    | - dark_class / mode_class_prefix: the CLASS NAMES the framework puts on <html>.
    |   The dark class is present exactly while dark is showing; the mode class always
    |   names the chosen mode (rsx-theme-auto even when the OS is currently light).
    | - attributes: THE APP'S OWN THEME VOCABULARY, keyed 'dark' and 'light', set on
    |   <html> verbatim. RSpade ships no UI toolkit and no colours, so this is
    |   EMPTY here by design - an app declares what its CSS actually reads. A Bootstrap
    |   5.3 app declares ['dark' => ['data-bs-theme' => 'dark'], 'light' => [...]];
    |   something else declares whatever it uses. See rsx:man dark_mode.
    |
    */

    'theme' => [
        'dark_mode' => [
            'default' => 2, // Login_User_Model::DARK_MODE_AUTO - literal: config is read before autoload
            'dark_class' => 'rsx-dark',
            'mode_class_prefix' => 'rsx-theme-',
            'attributes' => [
                'dark' => [],
                'light' => [],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | External API
    |--------------------------------------------------------------------------
    |
    | Settings for the external REST API (#[Api_Endpoint] controllers).
    |
    | - log_retention_days: how many days of _api_request_log rows to keep. Consumed by
    |   Api_Cleanup_Service::cleanup_request_log (daily 3am, #[Exclusive]); older rows pruned.
    |
    */

    'api' => [
        // Leading token on every newly minted API key, giving keys from this product a
        // recognisable shape: {prefix}{environment}_{random} -> rsx_live_xxxxx.
        //
        // Rename it to your own product and new keys carry that instead. EXISTING KEYS ARE
        // UNAFFECTED - lookup is by SHA-256 of the whole key, never by prefix, so changing
        // this cannot invalidate a key already in service. Only key_prefix, the display
        // fragment shown in the UI, records what a key was minted with.
        'key_prefix' => 'rsx_',

        'log_retention_days' => 30,
    ],


    /*
    |--------------------------------------------------------------------------
    | Revision History
    |--------------------------------------------------------------------------
    |
    | Settings for the revision-history subsystem (models declaring
    | public static $revisions = true).
    |
    | - retention_days: how many days of _transactions rows to keep. Consumed by
    |   Revision_Cleanup_Service::cleanup_revisions (daily 3am, #[Exclusive]); the
    |   _revisions FK cascades, so pruning a transaction prunes its revisions.
    |   0 means KEEP FOREVER - the cleanup task does nothing at all.
    |
    | - dictionary_max_age_days: how old the current compression dictionary may get
    |   before the post-migrate step in Maint_Migrate builds a new one. The dictionary
    |   is derived from the live schema's column names and the models' enum labels, so
    |   it drifts as the schema does; rebuilding on a cadence keeps new revisions
    |   compressing well without ever invalidating old ones (every stored payload names
    |   the dictionary it was written with, in its prefix byte).
    |
    */

    'revisions' => [
        'retention_days' => 0,

        'dictionary_max_age_days' => 30,
    ],


    /*
    |--------------------------------------------------------------------------
    | Manifest Modules
    |--------------------------------------------------------------------------
    |
    | These modules process files during manifest scanning. Each module
    | handles specific file types and extracts metadata. Modules are
    | processed in priority order (lower number = higher priority).
    |
    */

    'manifest_modules' => [
        // Core modules (can be copied and modified)
        // PHP extraction is handled directly in Manifest to avoid DRY violations
        // JavaScript is a first-class citizen, handled directly in Manifest.php
        \App\RSpade\Core\Manifest\Modules\Blade_ManifestModule::class,
        \App\RSpade\Integrations\Scss\Scss_ManifestModule::class,

        // Custom modules
        // \App\RSpade\Modules\Custom\MyCustomModule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest Support Modules
    |--------------------------------------------------------------------------
    |
    | These modules run AFTER the primary manifest is built to add
    | supplementary metadata that requires the full manifest to be available.
    | Order matters - modules are processed in the order listed.
    |
    */

    'manifest_support' => [
        \App\RSpade\Core\Dispatch\Route_ManifestSupport::class,
        \App\RSpade\Core\Portal\Portal_Route_ManifestSupport::class,
        \App\RSpade\Core\Portal\Portal_Spa_ManifestSupport::class,
        \App\RSpade\Core\Manifest\Modules\Model_ManifestSupport::class,
        \App\RSpade\Integrations\Jqhtml\Jqhtml_ManifestSupport::class,
        // A stylesheet's id names what it styles, which other files decide: derived from
        // the complete file map, so cold and warm builds agree.
        \App\RSpade\Integrations\Scss\Scss_Id_ManifestSupport::class,
        \App\RSpade\Core\SPA\Spa_ManifestSupport::class,
        \App\RSpade\Core\Api\Api_Endpoint_ManifestSupport::class,
        // After the last producers of `routes` (staff, SPA, API): judges PORTAL_URL and
        // its path-prefix collision with those routes.
        \App\RSpade\Core\Portal\Portal_Url_ManifestSupport::class,
        \App\RSpade\Core\Externals\Externals_ManifestSupport::class,
        \App\RSpade\Core\Task\Task_Command_ManifestSupport::class,
        \App\RSpade\Core\Mail\Email_ManifestSupport::class,
        \App\RSpade\Core\Bundle\Bundle_Alias_ManifestSupport::class,
        // Auth gates run LAST of the INDEX modules: the consolidated #[Auth] index covers
        // every surface kind the modules above register.
        \App\RSpade\Core\Auth\Auth_ManifestSupport::class,

        // THE STUB GENERATORS. Ordinary support modules, at the end of the same ordered
        // list - there is no second module system and no method_exists() dispatch. They run
        // after every index module because the list says so: the controller and model stubs
        // read the sections above, and the auth mirrors read auth.checks.
        \App\RSpade\Core\Controller\Controller_Stub_ManifestSupport::class,
        \App\RSpade\Core\Database\Model_Stub_ManifestSupport::class,
        \App\RSpade\Core\Auth\Auth_Stub_ManifestSupport::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | External integrations that extend the framework's capabilities.
    | Each integration can provide file discovery, processing, and bundling.
    |
    */

    'integrations' => [
        // Register integration service providers here
        // These will be loaded automatically if enabled
        'providers' => [
            \App\RSpade\Integrations\Jqhtml\Jqhtml_Service_Provider::class,
            \App\RSpade\Core\Controller\Controller_Service_Provider::class,
            \App\RSpade\Core\Database\Database_Service_Provider::class,
            \App\RSpade\Core\Auth\Auth_Service_Provider::class,
        ],

        // Integration-specific configuration
        'jqhtml' => [
            'compiler' => [
                'cache' => true,
                'cache_ttl' => 3600,
                'source_maps' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundle Processors
    |--------------------------------------------------------------------------
    |
    | Configure global processors that transform files during bundle compilation.
    | These processors are automatically applied to all bundles based on file
    | extensions. Order matters - processors with lower priority run first.
    |
    */

    'bundle_processors' => [
        // SCSS/Sass processor
        \App\RSpade\Integrations\Scss\Scss_BundleProcessor::class,

        // JQHTML processor
        \App\RSpade\Integrations\Jqhtml\Jqhtml_BundleProcessor::class,

        // Add custom processors here
        // \App\RSpade\Processors\MyCustomProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Bundles
    |--------------------------------------------------------------------------
    |
    | These bundles are automatically prepended to every bundle's include list.
    | They provide core functionality that all bundles need.
    |
    */
    'required_bundles' => [
        'jquery',   // jQuery library - foundation for many components
        'lodash',   // Lodash utility library - common utilities
        'core',     // Core framework JS - Manifest, Rsx, cache, etc.
        'jqhtml',   // Jqhtml library - client side templating library
    ],

    /*
    |--------------------------------------------------------------------------
    | Always-Published Routes
    |--------------------------------------------------------------------------
    |
    | Targets whose route patterns are emitted into EVERY bundle's client route
    | table, so Rsx.Route() answers for them in a bundle that does not - and must
    | not - include the code that declares them. The patterns are resolved from
    | the manifest at compile time, exactly as Rsx::Route() resolves them
    | server-side, so moving the page moves every link to it; a hardcoded URL is
    | never the answer here. A target with no routes in the manifest FAILS THE
    | COMPILE rather than silently vanishing.
    |
    | The spelling is the one Rsx::Route() takes: a bare SPA action class name, or
    | 'Controller::method' for a PHP route. The shipped entries are the system control
    | panel's two sanctioned targets for an application page (see rsx:man sys_panel):
    | its dashboard, the ONE way to link to /_sys, and the route that ends an
    | impersonation the panel started, which an application's impersonation banner
    | links to. Auth grants for these targets are exported alongside them, so
    | Permission.can_access() answers in every bundle too.
    |
    | An application appends its own entries from /rsx/resource/config/rsx.php;
    | the two-tier merge is append-only, so the framework's entry always remains.
    |
    */
    'always_published_routes' => [
        '_Sys_Dashboard_Action',
        '_Sys_Impersonation_Controller::stop',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundle Aliases
    |--------------------------------------------------------------------------
    |
    | Map bundle aliases to their corresponding bundle classes.
    | These aliases can be used in bundle 'include' arrays for convenience.
    |
    */
    'bundle_aliases' => [
        'core' => \App\RSpade\Core\Bundle\Core_Bundle::class,
        'jquery' => \App\RSpade\Bundles\Jquery_Bundle::class,
        'lodash' => \App\RSpade\Bundles\Lodash_Bundle::class,
        'bootstrap5' => \App\RSpade\Bundles\Bootstrap5_Bundle::class,
        'jqhtml' => \App\RSpade\Integrations\Jqhtml\Jqhtml_Bundle::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | RSX Routing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how RSX handles routing, caching, and request dispatch.
    |
    */
    'routing' => [
        // Directories to check for static assets
        'asset_dirs' => ['public', 'assets', 'static', 'dist', 'build'],

        // Handler type priorities (lower number = higher priority)
        'handler_priority' => [
            'controller' => 1,
            'file' => 2,
            'asset' => 3,
            'custom' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Middleware (application additions)
    |--------------------------------------------------------------------------
    |
    | app/Http/Kernel.php is a FRAMEWORK-OWNED file - it is hard-synced by every
    | framework update and a local edit is refused by the tamper gate. This block
    | is the sanctioned seam for an application's own HTTP middleware.
    |
    | Shape:
    |   'global' => [My_Middleware::class]    appended to the global stack
    |
    | 'global' is the only key. RSX requests never pass through Laravel's router
    | (the kernel hands every request to Rsx_Front_Controller), so there are no route
    | middleware groups or aliases; a non-empty 'web', 'api' or 'aliases' key throws
    | at bootstrap, as does any other key.
    |
    | APPEND-ONLY. Declared middleware runs AFTER the framework stack, around every
    | request; nothing here can reorder or remove framework middleware. If you
    | genuinely need that, file a framework change request - do not edit the kernel.
    |
    | Validation is loud: an unknown key and a class that does not exist each throw at
    | bootstrap. Declaring something already present is a silent no-op.
    |
    | The framework itself declares none; the block ships empty for apps to extend.
    |
    | See: php artisan rsx:man config_rsx
    |
    */
    'middleware' => [
        'global' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Redirect (intended-URL threading)
    |--------------------------------------------------------------------------
    |
    | Login_Redirect threads a validated ?redirect= target through a multi-hop
    | login flow so a user who hit a protected deep URL while logged out lands
    | back on it after authenticating. excluded_prefixes are login-flow routes
    | that a redirect target may never point at (loop prevention) - a redirect
    | to any of these is treated as absent. Apps merge their own login-flow
    | route prefixes here (2FA, site selection, etc.).
    |
    | portal_excluded_prefixes is the same loop-prevention list for the client
    | portal's login flow, applied when the request is a portal request. It is
    | expressed in PORTAL-NAMESPACE terms (unprefixed): the portal's prefix
    | (from PORTAL_URL; none at the root of a portal host) is stripped before
    | the comparison. The default covers the framework's shipped portal auth routes;
    | apps add their own portal login-flow routes here.
    |
    | See: php artisan rsx:man login_redirect
    |
    */
    'login_redirect' => [
        'excluded_prefixes' => ['/login', '/logout'],

        'portal_excluded_prefixes' => [
            '/login', '/logout', '/register', '/password/reset', '/request-access', '/impersonate',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ajax
    |--------------------------------------------------------------------------
    |
    | batch_max_calls: the most calls one /_ajax/_batch request may carry. A
    | larger batch is refused WHOLE with a 400 naming this key, and none of its
    | calls runs. The client flushes a batch at 20 pending calls (Ajax.MAX_BATCH_SIZE
    | in Ajax.js), so the cap refuses only a request no RSX page sends - it bounds
    | how much work one request can ask for. See rsx:man ajax_error_handling.
    |
    */
    'ajax' => [
        'batch_max_calls' => 100,

        // Calls a page makes in the same synchronous turn share one request to /_ajax/_batch
        // (up to 20 each). False sends every call as its own request - still through
        // /_ajax/_batch, still answered the same way - which makes each call its own entry
        // in the browser's network panel. Off by default in development, where reading one
        // call per request is what a developer wants; on in debug and production. An
        // application overrides it either way in rsx/resource/config/rsx.php.
        'batching' => !\App\RSpade\Core\Rsx::is_development(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Portal
    |--------------------------------------------------------------------------
    |
    | Framework defaults for the client portal - a second authenticated
    | experience for external users (customers, clients, vendors) served by
    | the portal realm of the page dispatcher and Portal_Session, parallel to
    | the main app.
    |
    | An application merges its own overrides on top of these (see
    | rsx/resource/config/rsx.php) to add app-specific knobs. These defaults
    | let the portal subsystem run sanely on their own.
    |
    | 'enabled' (PORTAL_ENABLED, default true) switches the portal on or off for
    | the whole install. Off, nothing is served as the portal and PORTAL_URL is
    | not validated.
    |
    | 'url' (PORTAL_URL) is WHERE the portal is served: a TEMPLATE, written like
    | APP_URL - scheme, host, optional port, optional path, the $HOSTNAME token -
    | or as a bare path on APP_URL's host ('/clients'). The portal's host and
    | path prefix are derived from it (Rsx_Portal_Url).
    |
    | Single tenancy (rsx.tenancy = single) - no placeholder:
    |
    |   (blank)                      APP_URL's origin + /_portal
    |   /clients                     the application host, under /clients
    |   https://myapp.com/clients    the application host, under /clients
    |   https://portal.myapp.com/    its own host, no prefix
    |   https://portal.myapp.com/x   its own host, under /x
    |
    | Multi tenancy (rsx.tenancy = multi) - exactly one tenant placeholder, :slug
    | (sites.slug) or :site_id (sites.id):
    |
    |   (blank)                      APP_URL's origin + /_portal/:site_id
    |   /_portal/:slug               the application host, one path per site
    |   https://:slug.myapp.com      a subdomain per site (the placeholder is
    |                                the host's first label)
    |
    | A path template ends in the placeholder after at least one fixed segment.
    |
    | Refused at MANIFEST BUILD, every mode: a value equal to APP_URL, http
    | outside development, a query/fragment/credentials, a path segment outside
    | [A-Za-z0-9_-], a first segment the framework owns (api, error, ws, any /_
    | name other than /_portal), a placeholder in single tenancy or a missing
    | one in multi, and a path prefix on the application host that collides
    | with a staff #[Route], SPA @route or API route. A sealed build pins these
    | values: changing one there means php artisan rsx:build --force.
    |
    | 'custom_domains' (PORTAL_CUSTOM_DOMAINS) - whether sites.portal_domain is
    | honoured. MULTI TENANCY ONLY: in single tenancy the portal's domain is
    | PORTAL_URL, and override / only fail the manifest build. Valid values:
    | off, override, only. Anything else throws at boot.
    |
    |   off       sites.portal_domain is ignored
    |   override  a site with a portal_domain is served there; its template
    |             address redirects to it
    |   only      a site is served at its portal_domain and nowhere else
    |
    | Each site also carries sites.is_portal_enabled (default on): a site with
    | it off has no portal. See rsx:man portal.
    |
    */
    'portal' => [
        // The client portal, on or off for the whole install.
        'enabled' => env('PORTAL_ENABLED', true),

        // Where the portal is served (a template - see above); blank = APP_URL +
        // '/_portal' (single) or APP_URL + '/_portal/:site_id' (multi).
        'url' => env('PORTAL_URL', ''),

        // off | override | only - whether sites.portal_domain is honoured.
        'custom_domains' => env('PORTAL_CUSTOM_DOMAINS', 'off'),

        // NOTE: there is deliberately NO portal site key here. The site a portal
        // request serves is resolved from its URL (Portal_Site_Resolver): site 1
        // in single tenancy, the :slug / :site_id / sites.portal_domain the
        // address names in multi tenancy. See rsx:man portal.

        // How long a portal invitation code stays valid, in days.
        'invitation_expiry_days' => 14,

        // Minimum length enforced for portal user passwords.
        'password_min_length' => 8,

        // How long a portal password-reset token stays valid, in hours.
        'password_reset_expiry_hours' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Directory Configuration
    |--------------------------------------------------------------------------
    |
    | Configure security and access control for the /rsx/public/ directory.
    | Files in this directory are served at the root URL of the application.
    |
    */
    'public' => [
        // Global patterns blocked from HTTP access for security
        // These patterns are always blocked regardless of public_ignore.json
        'ignore_patterns' => [
            'public_ignore.json',
            '.git',
            '.gitignore',
            '.gitattributes',
            '*.php',
            '.env',
            '.env.*',
            '*.sh',
            '*.py',
            '*.rb',
            '*.conf',
            '*.ini',
            '*.sql',
            '*.bak',
            '*.tmp',
            '*~',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RSX Manifest Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how RSX discovers and indexes classes, routes, and attributes.
    |
    */
    'manifest' => [
        // Base directories to scan (relative to base_path())
        // Can be directories (will be scanned recursively) or individual files
        'scan_directories' => [
            'rsx',                         // Main RSX application directory (symlinked to ../rsx)
            'app/RSpade/Core',             // Core framework classes (runtime essentials)
            'app/RSpade/Integrations',     // Integration modules (Jqhtml, Scss, etc.)
            'app/RSpade/Bundles',          // Third-party bundles
            'app/RSpade/Breadcrumbs',      // Progressive breadcrumb resolution
            'app/RSpade/CodeQuality',      // Code quality rules and checks
            'app/RSpade/Lib',              // UI features (Flash alerts, etc.)
            'app/RSpade/Sys',             // The framework's own application: the /_sys control panel
            // NOT LISTED, deliberately: app/RSpade/tests, app/RSpade/temp and rsx/tests.
            // A test fixture is real indexed source - a route, a surface, an #[Auth] naming a
            // check - and a served site has no business carrying one. Manifest_Scanner
            // ::_scan_directories() appends those three ONLY while the process is a test run
            // (Rsx_Test_Abstract::suite_is_running()); the manifest's ordinary add/remove
            // handles the transition in both directions.
        ],

        // Specific filenames to exclude from manifest scanning (anywhere in tree)
        'excluded_files' => [
            'CLAUDE.md',      // Documentation for AI assistants
            '.placeholder',   // Empty directory markers
            '.DS_Store',      // macOS folder metadata
            'Thumbs.db',      // Windows thumbnail cache
            'desktop.ini',    // Windows folder settings
            '.gitkeep',       // Git empty directory markers
            '.gitattributes', // Git attributes config
            '._rsx_helper.php', // IDE helper stubs (auto-generated)
        ],

        // Directories to exclude from code quality checks (relative path segments)
        // These are matched via str_contains, so 'Core/Manifest' matches 'app/RSpade/Core/Manifest/'
        'excluded_dirs' => [
            'vendor',
            'node_modules',
            'storage',
            '.git',
            'public',
            'resource',
            'Core/Manifest',  // Manifest builder uses reflection - can't use Manifest API
            'playwright',      // Test type dir: browser tests (.js) - not PHP, never indexed
            '_archive',        // Retired tests parked for reference - not discovered/run
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RSX Development Settings
    |--------------------------------------------------------------------------
    |
    | Developer conveniences. console_debug is configured under 'console_debug'
    | below; a developer can override it for one browser from the /_sys Debug
    | Flags screen (rsx:man console_debug).
    |
    */
    'development' => [
        // Whether the login form arrives with RSPADE_DEFAULT_EMAIL /
        // RSPADE_DEFAULT_PASSWORD already filled in. On or off, nothing else.
        //
        // OFF IS THE SECURE DEFAULT AND THE SHIPPED ONE. The first-run setup
        // wizard turns it on when the developer ticks the box, and clearing this
        // value turns it off again. It is a developer convenience that puts a
        // usable credential on an unauthenticated page, so leave it off anywhere
        // another person can reach the address.
        'login_autofill' => env('RSPADE_LOGIN_AUTOFILL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Strict Production Build
    |--------------------------------------------------------------------------
    |
    | Steps that run only where the build minifies (strict production; never
    | debug or development - Manifest::_should_minify()).
    |
    */
    'production' => [
        // String obfuscation of each bundle's APP JavaScript. The files the
        // manifest scopes it to - ES6 class files, generated model stubs and
        // concrete model classes, compiled jqhtml templates - have, in their
        // source before babel, the strings inside their function bodies that carry an inner space, and every
        // string there that is an object-literal value or array-literal
        // element, split into words and rebuilt at run time from a seeded,
        // deterministic dictionary: the global `_$$SC` array and the printer
        // `_$$(...indexes)` (with `_$$R(index, count)` spreading a run of one
        // repeated index), declared by a generated file concatenated directly
        // before the first such file. Every other file, vendor JavaScript and
        // CSS are never touched. A build fails if any of the three names is
        // already in use.
        //
        // It deters casual reading of the shipped file and nothing more -
        // rendered text is in the DOM and the dictionary is readable - so rules
        // and secrets stay server-side. See rsx:man app_mode (STRING OBFUSCATION).
        'obfuscate_strings' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Credentials
    |--------------------------------------------------------------------------
    |
    | The credentials of the first user, created by the framework migration
    | create_admin_test_user, and the pair the login form may auto-fill.
    |
    | BOTH ARE EMPTY BY DEFAULT AND MUST BE SET IN .env. They are deliberately
    | not given fallback values: a framework with a known default password is a
    | framework where every install shares one. The migration THROWS when either
    | is blank rather than inventing one, so a new project makes a deliberate
    | choice before it has a login at all. See .env.README.
    |
    | Auto-fill additionally requires rsx.development.login_autofill to be on -
    | credentials existing is not enough.
    |
    */

    'default_user' => [
        'email' => env('RSPADE_DEFAULT_EMAIL', ''),
        'password' => env('RSPADE_DEFAULT_PASSWORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Code Quality Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for code quality checks and validation rules.
    |
    */
    'code_quality' => [
        // Framework developer mode - enables additional testing capabilities
        // When true, allows testing rules in app/RSpade/temp directory
        'is_framework_developer' => env('IS_FRAMEWORK_DEVELOPER', false),

        // Whitelisted PHP/JS files allowed in project root directory
        // These are typically build configuration files and IDE helpers
        'root_whitelist' => [
            'vite.config.js',
            'webpack.config.js',
            'webpack.mix.js',
            '_ide_helper.php',          // Laravel IDE Helper
            '._rsx_helper.php',         // RSX IDE Helper
            '.phpstorm.meta.php',       // PhpStorm metadata
            'script.php',               // The external-script boot entry (rsx:man scripting)
        ],

        // Whitelisted test files allowed in rsx/ directory (not subdirectories)
        // Test files should normally be in proper test directories, not loose in rsx/
        'rsx_test_whitelist' => [
            // Currently no test files should exist directly in rsx/
        ],

        // Classes exempt from suffix inheritance rules
        // Children of these classes can use any naming pattern
        'suffix_exempt_classes' => [
            'Component',            // JQHTML components can have flexible naming
            'Rsx_System_Model_Abstract',   // System models (e.g., Session) have special naming
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | First Run
    |--------------------------------------------------------------------------
    |
    | The first-run setup screen (Rsx_First_User_Setup) creates the initial account.
    |
    | 'sample_data_task' - 'Service::method' of a #[Task] that fills the NEW site with
    | sample data. When set, the screen offers a "Add sample data" checkbox and, if it is
    | ticked, dispatches that task for site 1 ({site: 1} in its params) once the account
    | exists. Null (the framework's default) or empty hides the option. The framework
    | ships no seeder: the application names its own (the template's is
    | Sample_Data_Service::seed, rsx/services/sample_data/). Nothing checks that the
    | named task exists - a wrong name surfaces as a failed run in /_sys/tasks.
    |
    */
    'first_run' => [
        'sample_data_task' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sys Control Panel
    |--------------------------------------------------------------------------
    |
    | The framework's own control panel at /_sys, developed like any RSpade app
    | and shipped inside app/RSpade/Sys/ (see rsx:man sys_panel).
    |
    | Linking to it from an application page is Rsx::Route('_Sys_Dashboard_Action')
    | / Rsx.Route('_Sys_Dashboard_Action'), gated on can_access() of the same
    | target - the INDEX ACTION, because a SPA route is registered under its JS
    | action class and never under its bootstrap controller. That target is listed
    | in 'always_published_routes' above, which is what makes both answer from a
    | bundle that does not include app/RSpade/Sys (and must not - CONV-BUNDLE-02).
    |
    */
    'sys_panel' => [
        // The framework's own control panel at /_sys (rsx:man sys_panel). Application
        // behaviour, not deployment: an app switches it off in rsx/resource/config/rsx.php.
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | IDE Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for VS Code extension and IDE bridge communication.
    |
    */
    'ide_integration' => [
        // Path where the IDE bridge local-file grant token + passive guards live.
        // This directory is OUTSIDE the web docroot and must stay that way. The IDE now
        // reads the server URL straight from APP_URL in .env (no domain.txt discovery).
        'bridge_path' => 'storage/rsx-ide-bridge',

        // Master switch for the IDE bridge. DEVELOPMENT ONLY, by construction: there is
        // no env key and no opt-in that turns it on anywhere else, because the bridge's
        // grant is a file on local disk and its consumer is an editor sitting beside the
        // source. Setting this false in a user config turns the bridge off in
        // development too; setting it true elsewhere changes nothing, because the
        // pre-boot gate (Ide/Services/auth.php) refuses any mode but development before
        // it reads a token. Governs whether the grant token is created at all.
        'enabled' => \App\RSpade\Core\Rsx::is_development(),
    ],

    /*
    |--------------------------------------------------------------------------
    | JavaScript Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for JavaScript parsing and transformation.
    |
    */
    'javascript' => [
        'babel' => [
            // Enable Babel transformation for decorators
            'transform_enabled' => env('BABEL_TRANSFORM', true),

            // Target environment for transformation (modern, es6, es5)
            'target' => env('BABEL_TARGET', 'modern'),

            // There is no cache_dir here. Every per-source-file derived cache lives under
            // tmp/derived/<namespace>/ and is addressed through
            // App\RSpade\Core\Cache\File_Content_Cache - one location, not a setting.
        ],

        // Enable decorator support (parsed and optionally transformed)
        'decorators' => true,

        // Note: Private fields (#private) use native browser support, not transpiled

        // How long to wait for a spawned node RPC helper to answer its first ping.
        //
        // This bounds a wait on an EXTERNAL party (a process that may never come up)
        // and degrades to a loud, evidence-carrying error - the one shape of deadline
        // the no-timeout mandate sanctions. It is NOT a cap on the helper's work: once
        // it answers, every RPC call runs to completion with no deadline at all.
        //
        // 20s is generous for a cold node start. It is a key rather than a literal because
        // a loaded box legitimately takes longer - a downstream update failed here
        // mid-backup (2026-08-11) - and the honest fix for a slow machine is a bigger
        // number, not a retry storm.
        'rpc_server_ready_wait_ms' => 20000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Console Debug Configuration
    |--------------------------------------------------------------------------
    |
    | Core debug system settings. Override outputs and filters in user config.
    |
    | Per-mode behavior (RSX_MODE):
    | - development: config block injected into window.rsxapp; console_debug()
    |   works in the browser (JS) and PHP; nothing stripped from bundles.
    | - debug: SAME as development - the config block is injected and
    |   console_debug() works (Manifest::_should_include_debug_info() is true for
    |   both). Debug builds are minified but keep console_debug intact.
    | - production (strict): config block is NOT injected, the PHP gate returns
    |   early, and every console_debug() call site is stripped from the compiled
    |   bundle by Terser pure_funcs (Manifest::_should_strip_console_debug()).
    |
    | Channels are the taxonomy (uppercased tags like DISPATCH, AUTH, DB). Level
    | is inferred from the channel-name substring (ERROR/WARN/INFO). The sane
    | defaults below - enabled, filter_mode 'all' - mean every channel is emitted
    | wherever console_debug is active; narrow via filter_mode + whitelist/blacklist.
    |
    */
    'console_debug' => [
        // Master switch to enable/disable all console debug output
        'enabled' => env('CONSOLE_DEBUG_ENABLED', true),

        // Default filter: emit every channel. Set to whitelist/blacklist/specific
        // (with the matching whitelist/blacklist/specific_channel key) to narrow.
        'filter_mode' => 'all',
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Rotation
    |--------------------------------------------------------------------------
    |
    | RSpade rotates storage/logs itself. NOTHING here assumes an OS logrotate:
    | the containers this framework ships to carry logrotate CONFIGS and no
    | logrotate BINARY, and Laravel's own 'daily' channel only rotates Monolog's
    | own dated files - it never compresses, never prunes anything it did not
    | write, and never touches csp_violations.log or ide-formatter.log.
    |
    | The sweep is Log_Maintenance_Service::rotate(), a #[Schedule('daily at
    | 12:00am')] framework task, and the same rotation is available on demand as
    | `php artisan rsx:logrotate`.
    |
    | Generations are logrotate-style numeric suffixes on each `*.log` at the TOP
    | level of storage/logs: name.log -> name.log.1 -> name.log.2 -> ...
    |
    |   generations 1 .. days_uncompressed            stay PLAIN
    |   generations days_uncompressed+1 .. days_retention   are GZIPPED (.gz)
    |   generations past days_retention               are DELETED
    |
    | With the shipped 3 / 21 that is 3 plain days you can grep and tail without
    | thinking, 18 compressed days behind them, and nothing older than three
    | weeks on disk. Because the sweep runs once a day, a generation number is a
    | day count - hence the key names.
    |
    | .env.dist ships LOGROTATE_ENABLED=true, LOGROTATE_DAYS_UNCOMPRESSED=3 and
    | LOGROTATE_DAYS_RETENTION=21, and the env-heal contract appends any of those
    | keys that an existing .env lacks on the next development boot. The code
    | defaults below are what applies when a key is absent entirely, and they are
    | deliberately CONSERVATIVE: rotation off until an .env says otherwise.
    |
    | See: php artisan rsx:man logrotate
    |
    */
    'logging' => [
        'rotation' => [
            // Run the scheduled sweep at all. `rsx:logrotate` runs regardless -
            // an explicit invocation is its own consent - and says so.
            'enabled' => env('LOGROTATE_ENABLED', false),

            // How many of the most recent generations stay uncompressed.
            'days_uncompressed' => env('LOGROTATE_DAYS_UNCOMPRESSED', 3),

            // The oldest generation kept. Anything numbered higher is deleted.
            // Must be >= days_uncompressed.
            'days_retention' => env('LOGROTATE_DAYS_RETENTION', 21),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception Handlers
    |--------------------------------------------------------------------------
    |
    | Exception handlers are executed in priority order when exceptions occur.
    | Each handler can choose to handle the exception (return a response) or
    | pass it to the next handler (return null). Handlers are sorted by priority
    | with lower numbers running first.
    |
    | Handler Priority Ranges:
    | - 1-50: Critical/environment-specific (CLI, AJAX, API, Playwright)
    | - 51-100: Standard handlers
    | - 101-500: Low priority handlers
    | - 501+: Fallback / catch-all handlers (Web_Exception_Handler, the page channel)
    |
    | A handler FORMATS a failure; none dispatches. A failure inside dispatch reaches the
    | chain once, from Rsx_Front_Controller; the AJAX and API handlers choose by the
    | request's classified channel (Rsx_Request_Channel).
    |
    | Users can add custom handlers or reorder existing ones by modifying this array.
    |
    */
    'exception_handlers' => [
        \App\RSpade\Core\Exceptions\Cli_Exception_Handler::class,              // Priority 10
        \App\RSpade\Core\Exceptions\Ajax_Exception_Handler::class,             // Priority 20
        \App\RSpade\Core\Exceptions\Api_Exception_Handler::class,        // Priority 25
        \App\RSpade\Core\Debug\Playwright_Exception_Handler::class,            // Priority 30
        \App\RSpade\Core\Exceptions\Web_Exception_Handler::class,              // Priority 1100
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the two-tier thumbnail caching system with named presets
    | (developer-defined) and dynamic thumbnails (ad-hoc sizes).
    |
    | Storage Structure:
    | - tmp/thumbnails/preset/  - Named preset thumbnails
    | - tmp/thumbnails/dynamic/ - Dynamic ad-hoc thumbnails
    |
    | Quota Management:
    | - Preset thumbnails: Enforced via scheduled rsx:thumbnails:clean task
    | - Dynamic thumbnails: Enforced synchronously after each new thumbnail
    | - Both use LRU eviction (oldest mtime deleted first)
    |
    | LRU Tracking:
    | - Both preset and dynamic thumbnails have mtime touched on cache hit
    | - Touch only occurs if mtime is older than touch_interval (default 10 min)
    | - Prevents excessive filesystem writes while maintaining LRU accuracy
    |
    | Commands:
    | - php artisan rsx:thumbnails:clean [--preset] [--dynamic]
    | - php artisan rsx:thumbnails:generate [--preset=name]
    | - php artisan rsx:thumbnails:stats
    |
    */

    /*
    |--------------------------------------------------------------------------
    | File Attachments
    |--------------------------------------------------------------------------
    |
    | External byte-residency handlers (WP-A). An attachment may carry a
    | handler_class naming where its bytes live (OneDrive, S3, a URL, ...). The
    | framework materializes bytes on demand via the handler's fetch().
    |
    | SECURITY ALLOWLIST: the framework NEVER instantiates a handler class named
    | in the database unless its simple class name appears in this array. An
    | attachment whose handler_class is non-null but unregistered is a hard error
    | (fail loud, do not serve). Apps register their handlers here (overriding
    | this key in rsx/resource/config/rsx.php).
    |
    | Format: array of simple class-name strings, e.g.
    |   'handlers' => ['Onedrive_Attachment_Handler', 'S3_Attachment_Handler'],
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Temp file store (Rsx_Temp_Files)
    |--------------------------------------------------------------------------
    |
    | Files a pipeline produces from application data and keeps for a while - a CSV
    | export waiting to be downloaded, a file a task attached for whoever started it.
    | They live in uploads/_temp/ under random keys, apart from the content-addressed
    | attachment store, and Temp_File_Cleanup_Service deletes each one hourly once it
    | expires (only the files this database has rows for).
    |
    | retention_days: how long a temp file lives when its caller does not choose -
    | a whole number of days, at least 1. A RETENTION window, not a timeout: it bounds
    | how long data is kept, never how long anything may take.
    |
    | See: php artisan rsx:man temp_files
    */
    'temp_files' => [
        'retention_days' => 7,
    ],

    'attachments' => [
        'handlers' => [],

        // How long a minted multi-file ZIP download request (_zip_download_requests) stays
        // valid, in hours. Expiry is enforced at serve time (Zip_Download_Request_Model::
        // is_expired()) AND by the periodic cleanup task (Zip_Download_Cleanup_Service).
        'zip_request_retention_hours' => 24,

        // How long an UNATTACHED upload stays claimable, in hours. A file uploaded to
        // /_upload is reachable only by its 64-char random key and may be attached to a
        // record exactly once (File_Attachment_Model::can_user_assign_this_file()); this
        // window bounds how long that key remains useful. Past it,
        // File_Disposal_Service::sweep_unclaimed_uploads (every 6 hours) SOFT-deletes the
        // orphan, which enters the normal retention window (still recoverable, blob still
        // pinned) rather than being erased. Handler-backed (external) attachments are never
        // swept - they are created attached-or-soon-attached by trusted app code.
        // 0 or null disables the sweep entirely.
        'unattached_claim_window_hours' => 24,

        // When an uploaded image's BYTES cannot be parsed by ImageMagick (a content-parse
        // failure - NOT a missing binary, which always fatals): false (default) = accept and
        // DEGRADE it to a generic, non-previewable file; true = REJECT the upload outright
        // (process_file() throws Unparseable_Upload_Exception for the endpoint to handle).
        'reject_unparseable_images' => false,

        // Developer-callable upload extension allowlist consulted by
        // File_Attachment_Model::is_allowed_extension() (PROVIDE ONLY - the framework does not
        // auto-enforce it at /_upload). Empty array = allow all extensions. Case-insensitive;
        // leading dots optional (e.g. 'png' or '.png').
        'allowed_extensions' => [],
    ],

    'files' => [

        /*
        | Maximum size of a single uploaded file, IN BYTES. Enforced by
        | File_Attachment_Controller::upload() (the /_upload endpoint every attachment goes
        | through), before the file.upload.authorize gate runs.
        |
        | THE DEFAULT IS DERIVED FROM PHP'S OWN LIMITS, not invented. PHP rejects an
        | oversized upload before userland ever sees it, so a framework ceiling ABOVE the ini
        | cannot be enforced - it only moves the rejection somewhere with a worse message.
        | Deriving it means the shipped default is already correct on a box whose php.ini was
        | tuned, with nothing to keep in sync.
        |
        | The two ini values are read and the SMALLER wins, because that is the one that
        | actually binds: upload_max_filesize caps the file, post_max_size caps the whole
        | request body that carries it, and a file cannot exceed either. Both are read from
        | the RUNNING SAPI, so php-fpm and CLI each see their own php.ini - which is right,
        | since the limit is whatever this process can accept.
        |
        | If neither is set, or either reads as unlimited (both yield 0 - see ini_bytes),
        | fall back to 100 MB: comfortably above the documents, images and media a business
        | app attaches, and far below the point where one multipart request is a
        | denial-of-service by itself.
        |
        | Override in rsx/resource/config/rsx.php to set a deliberate app-level ceiling; 0
        | disables the framework check entirely, leaving only PHP's.
        */
        'max_file_size' => (static function () {
            $upload = ini_bytes(ini_get('upload_max_filesize'));
            $post = ini_bytes(ini_get('post_max_size'));

            $limits = array_filter([$upload, $post]);

            return empty($limits) ? 100 * 1024 * 1024 : min($limits);
        })(),

        /*
        | File disposal & retention lifecycle (File_Disposal_Service). Deleting an
        | attachment SOFT-deletes it into a recoverable retention window; a scheduled task
        | permanently destroys it after the window and releases the blob once no live or
        | retained attachment still pins it. See rsx:man file_disposal.
        |
        | deleted_retention_days: days a deleted attachment stays recoverable before the
        | daily task destroys it. 0 means KEEP FOREVER - the destroy pass never runs, every
        | deleted attachment stays restorable and its blob stays pinned; force_destroy()
        | still erases at once. A negative value is a config error, thrown by the daily task.
        */
        'deleted_retention_days'   => 30,   // recoverable window before permanent destruction; 0 = forever
        'disposal_lookback_days'   => 60,   // blob-release pass window for the daily task
        'disk_orphan_min_age_days' => 14,   // age guard for the monthly disk/unassigned sweep

        /*
        | Pipeline-type map: extension => canonical mime, for the DOCUMENT family.
        |
        | This drives PROCESSING/ROUTING only (viewer, PDF-rendition, text-extractor, and
        | thumbnail registries + the coarse file_type_id bucket), NOT serving. It is consulted
        | by File_Attachment_Model::resolve_pipeline_mime(): for a document extension the mapped
        | mime wins UNCONDITIONALLY over the byte sniff.
        |
        | Rationale: libmagic's OOXML content sniff is heuristic (it inspects early zip member
        | names) and demonstrably per-file flaky - a valid .docx whose second zip member is
        | word/styles.xml can sniff as the generic container application/zip, which silently
        | disables its preview, text extraction, and thumbnail. The file extension is a
        | deliberate authored claim; like a desktop OS, we trust it for documents so two files
        | with the same extension always take the same pipeline path. If the bytes do not match
        | the claim the correct outcome is a loud conversion/parse failure, not a silent misroute.
        |
        | Serving (download/inline Content-Type) intentionally keeps the raw SNIFFED mime as a
        | security boundary - see File_Attachment_Controller::download_file()/inline().
        |
        | Apps may override/extend this map in rsx/resource/config/rsx.php.
        */
        'document_mime_by_extension' => [
            'pdf' => 'application/pdf',

            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',

            // Macro-enabled and template variants of the three OOXML formats. They are zip
            // containers like their siblings, so the sniff cannot tell them from a ZIP, and
            // LibreOffice and PhpSpreadsheet read them exactly as they read the plain forms.
            // (.xlsb, the binary workbook, is deliberately absent: PhpSpreadsheet cannot read
            // it, so it is labelled by extension and previews as an icon.)
            'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'xltm' => 'application/vnd.ms-excel.template.macroEnabled.12',
            'docm' => 'application/vnd.ms-word.document.macroEnabled.12',
            'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'dotm' => 'application/vnd.ms-word.template.macroEnabled.12',
            'pptm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
            'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
            'potm' => 'application/vnd.ms-powerpoint.template.macroEnabled.12',
            'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'ppsm' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',

            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',

            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',

            'rtf' => 'application/rtf',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The Document Worker (preview renditions + text extraction)
    |--------------------------------------------------------------------------
    |
    | Document_Render_Service::render_pending takes the blobs that owe a preview rendition
    | or their extracted text, earliest due first (_file_storage.process_due_at), and
    | processes each in a CHILD PROCESS of its own (rsx:documents:process_one). An attempt
    | is charged to the blob before its child starts; a failed attempt is recorded FAILED
    | and retried later, and a blob that has spent every attempt stays FAILED until an
    | operator re-queues it (rsx:documents:rerender / rsx:search:reindex). One document
    | that cannot be processed therefore costs the queue behind it at most
    | max_attempts * kill_seconds, once.
    |
    | THE TWO TIME LIMITS BELOW ARE SANCTIONED TIMEOUTS (owner ruling 2026-10-11, after a
    | downstream field report: one workbook that never finished was taken first by every
    | pass, and nothing behind it was ever processed). The no-timeout mandate requires a
    | timeout to be justified where it lives:
    |
    | - stage_seconds: the wall-clock bound on ONE STAGE of ONE document inside the child -
    |   the render (soffice to PDF, or a workbook to HTML in-process) and the text
    |   extraction (soffice, pdftotext or an in-process reader) each get this long.
    |     WHAT IT BOUNDS - a parse of one uploaded file, which is input the application
    |     does not control: a converter or a spreadsheet reader does not merely run slowly
    |     on a hostile or damaged file, it can run without end. It never bounds the queue,
    |     the worker's pass, or any work whose size the application chose.
    |     WHAT EXPIRY DOES - the stage is abandoned and the attempt is recorded FAILED with
    |     the reason ("render exceeded 20 seconds"); the document shows the icon-and-title
    |     preview every failed document shows, is retried retry_after_minutes later, and
    |     after max_attempts stays FAILED for an operator. The uploaded file is untouched:
    |     nothing is lost but a preview and a search entry that can be produced again.
    |     WHY 20 - the limit is the price every OTHER document pays for one that cannot be
    |     processed, so it is set by how long the queue may stand still, not by how slow a
    |     conversion may be. A document that needs longer on this hardware is one this
    |     pipeline declines to preview; raise it here, in the application's config, when a
    |     site's ordinary documents need more.
    |
    | - kill_seconds: how long the PARENT waits for the child before killing it and every
    |   process it started (soffice is a grandchild) with SIGKILL.
    |     WHAT IT BOUNDS - the child process as a whole, for the cases the stage bound
    |     cannot reach: the stage bound is a signal handled between PHP statements, so a
    |     child stuck inside one native call, or dead on its feet, never answers it.
    |     WHAT EXPIRY DOES - the same recorded FAILED attempt ("killed after 60 seconds"),
    |     retried on the same schedule.
    |     WHY 60 - two stages of stage_seconds each, plus the margin a PHP process needs to
    |     start on a loaded box. Keep it above 2 * stage_seconds, or the kill pre-empts
    |     the stage bound and its more precise reason.
    |
    | - max_attempts: attempts a blob gets each time it enters the queue. The third failure
    |   is permanent.
    | - retry_after_minutes: how long a failed blob waits before its next attempt. This is
    |   a schedule, not a timeout: it decides when work is next offered, and ends nothing.
    |
    | - soft_retry_max_minutes: the longest wait between two checks of a SOFT-FAILED blob.
    |   A soft failure is the environment, not the document - the tool a pass needs is not
    |   installed or not executable, the sandbox image is absent, the source blob cannot be
    |   read - found by the renderer's or extractor's soft_failure() check BEFORE an attempt.
    |   It is recorded like any failure but charges no attempt, and the blob is checked
    |   again retry_after_minutes later, the wait doubling after every consecutive soft
    |   failure (30 minutes, 1 hour, 2 hours ...) up to this cap, for as long as it takes:
    |   a box repaired next month still renders what was uploaded today. A pass that
    |   actually runs restarts the doubling. This is a schedule, not a timeout: it decides
    |   when a check is next made, and ends nothing. 10080 = one week. Never below
    |   retry_after_minutes.
    |
    | - drain_yield_after_minutes / drain_resume_after_minutes: the PACING of a long drain.
    |   A pass of the worker (Document_Render_Service::render_pending) that has been
    |   draining for drain_yield_after_minutes and still finds work due stops BETWEEN two
    |   documents, sets its own next run drain_resume_after_minutes from now, reports how
    |   many documents are still waiting and returns - a successful run.
    |     THESE ARE NOT TIMEOUTS (owner-set pacing, 2026-10-11). It is a voluntary yield so
    |     the other scheduled work, which shares the scheduled pool with this drain, gets a
    |     turn during a backlog that takes hours. Nothing is cut short - the check is made
    |     only between documents - nothing fails, and nothing is lost: every blob still due
    |     is still in the queue, and the next pass (or the next upload's kick) takes it up.
    |     A pass that empties the queue first never yields.
    |
    | - renderers: fnmatch map of mime pattern (glob) => renderer class (simple, manifest-
    |   resolved name, a Rsx_Document_Renderer_Abstract), resolved for a document's PIPELINE
    |   mime by Rsx_Mime_Map: an application entry beats a framework entry, and inside a layer
    |   the most specific pattern wins (exact mime, then the glob with more literal characters,
    |   then the later line). THIS MAP IS WHAT MAKES A TYPE PREVIEWABLE BY RENDITION: an
    |   attachment whose type matches it queues its blob for the worker, and the rendition
    |   endpoints serve what the worker produced. A type matching nothing gets no rendition.
    |   Apps add a type, or replace the framework's renderer for one, in
    |   rsx/resource/config/rsx.php - a renderer of their own produces a PDF. See rsx:man
    |   documents.
    |
    */
    'documents' => [
        'stage_seconds' => 20,
        'kill_seconds' => 60,
        'max_attempts' => 3,
        'retry_after_minutes' => 30,
        'soft_retry_max_minutes' => 10080,
        'drain_yield_after_minutes' => 20,
        'drain_resume_after_minutes' => 10,

        'renderers' => [
            // SPREADSHEETS - their globs are longer than the two generic Office patterns below,
            // so they win for a workbook (the most specific match wins). A workbook renders to
            // an HTML GRID in-process (PhpSpreadsheet, no soffice), never to a PDF: a
            // spreadsheet has no pages, and a PDF of one is LibreOffice's print view.
            // The ms-excel glob takes the macro-enabled and template variants with the classic .xls.
            'application/vnd.ms-excel*' => 'Spreadsheet_Document_Renderer',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.*' => 'Spreadsheet_Document_Renderer',
            'application/vnd.oasis.opendocument.spreadsheet*' => 'Spreadsheet_Document_Renderer',

            // Everything else Office-shaped converts to a PDF through headless LibreOffice
            // (switched by rsx.libreoffice.enabled).
            'application/msword' => 'Libreoffice_Document_Renderer',
            'application/vnd.ms-word*' => 'Libreoffice_Document_Renderer',
            'application/vnd.ms-powerpoint*' => 'Libreoffice_Document_Renderer',
            'application/vnd.openxmlformats-officedocument.*' => 'Libreoffice_Document_Renderer',
            'application/vnd.oasis.opendocument.*' => 'Libreoffice_Document_Renderer',
            'application/rtf' => 'Libreoffice_Document_Renderer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | LibreOffice Integration (the document render worker)
    |--------------------------------------------------------------------------
    |
    | Document_Render_Service shells out to headless LibreOffice to convert Office
    | documents (docx/xlsx/pptx/odt/...) into the PDF rendition that feeds BOTH the
    | Document_Preview viewer and the document thumbnail, and (for Writer documents)
    | the text that pdftotext then extracts from it. RSpade dev Docker images ship
    | LibreOffice preinstalled.
    |
    | The worker is #[Exclusive], so it is the only caller of soffice and invocations
    | are serialized by construction. There is no concurrency semaphore and no slot
    | wait: nothing is competing for a pool any more.
    |
    | - enabled: master switch for RENDERING. When FALSE, no soffice call is ever made:
    |   documents LibreOffice renders stay PENDING (and render the day it is switched back on),
    |   the rendition endpoint has nothing to serve, and thumbnails use the generic
    |   extension icon. Text extraction of non-LibreOffice formats keeps draining.
    | - binary_path: absolute path to the `soffice` binary. NULL = auto-detect on PATH and
    |   common install locations.
    | - How long a conversion may run is not set here: soffice and pdftotext run inside
    |   one of the document worker's two stages and are bounded with it, by
    |   rsx.documents.stage_seconds.
    |
    | THE DOCUMENT SANDBOX (sandbox, sandbox_image, sandbox_memory, sandbox_pids)
    |
    |   soffice and pdftotext are large C++ parsers of hostile input: their filters are a
    |   long-running source of memory-corruption CVEs, a document can carry event or script
    |   hooks, can name local files for the filter to read and embed, and can reference
    |   resources the converter fetches over the network. On a site that accepts uploads from
    |   outside the organisation those binaries are handed to strangers, and the user the
    |   render worker runs as can read the application tree, its storage and its credentials.
    |
    |   - sandbox: 'none' (default) spawns the binaries on the host, exactly as before.
    |     'docker' spawns ONE throwaway `docker run --rm` PER FILE, sequential, with no
    |     network, a read-only root filesystem, every capability dropped, no-new-privileges,
    |     the memory and pid caps below, a tmpfs /tmp and the worker's own uid:gid. Nothing
    |     survives the file. Any other value throws at first use. The deployment requirement
    |     is that the worker's user can reach the docker socket (or rootless docker).
    |   - sandbox_image: the image the sandbox runs; it must carry soffice, pdftotext and
    |     fonts. Default 'rspade/rspade-docconvert:latest' - the framework's own converter
    |     image, a bare distribution base carrying those three and nothing else, BUILT ON
    |     FIRST USE (or by `php artisan rsx:heal document-sandbox-image`, or by
    |     `bash system/app/RSpade/resource/docker/build.sh docconvert`). Point the env key
    |     LIBREOFFICE_SANDBOX_IMAGE at an image of your own instead and it is yours to
    |     carry the three and yours to `docker pull` - the framework builds only its own.
    |   - sandbox_memory / sandbox_pids: the caps a sandboxed conversion gets. Application
    |     behaviour rather than deployment identity, so they are config and not env keys. A
    |     daemon that refuses the memory cap (a threaded cgroup layout) has it dropped, once
    |     per process, and the health rows say so; the pid cap always applies.
    |
    |   NOT COVERED: PhpSpreadsheet. Workbook renditions (Spreadsheet_Rendition) and workbook
    |   text (Spreadsheet_Text_Extractor) are read IN-PROCESS by a PHP library - no binary is
    |   spawned, so there is no process to contain.
    |
    |   rsx:health reports the posture, and in 'docker' mode proves the whole chain (client,
    |   daemon, image, and one real hardened run). See rsx:man libreoffice.
    |
    */
    'libreoffice' => [
        'enabled' => env('LIBREOFFICE_ENABLED', true),
        'binary_path' => env('LIBREOFFICE_BINARY_PATH', null),
        'sandbox' => env('LIBREOFFICE_SANDBOX', 'none'),
        'sandbox_image' => env('LIBREOFFICE_SANDBOX_IMAGE', 'rspade/rspade-docconvert:latest'),
        'sandbox_memory' => '1g',
        'sandbox_pids' => 256,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Text Extraction / Full-text Search
    |--------------------------------------------------------------------------
    |
    | Text extraction pulls text out of documents (PDF, Office, plain text) into
    | _search_indexes, keyed on the deduplicated _file_storage blob so identical bytes
    | are extracted exactly once. Search_Index_Service::extract_storage() is the unit of
    | work; Document_Render_Service owns the queue and drains _file_storage.is_indexed=0
    | in the same pass that produces PDF renditions.
    |
    | - enabled: master switch for EXTRACTION, and the kick switch for the render worker.
    |   When FALSE, no pipeline kick is fired on upload/materialization and the worker
    |   skips the extraction half of its queue. Existing rows are untouched.
    | - extractor_version: the current pipeline generation. Bump this when an extractor
    |   changes in a way that should re-produce existing text; then
    |   `rsx:search:reindex --below-version=N` re-queues everything older.
    | - extractors: fnmatch map of mime pattern (glob) => extractor class (simple,
    |   manifest-resolved name), resolved by Rsx_Mime_Map: an application entry beats a
    |   framework entry, and inside a layer the most specific pattern wins (exact mime, then
    |   the glob with more literal characters, then the later line). A mime matching nothing
    |   -> UNSUPPORTED (not FAILED). Apps add or replace entries in
    |   rsx/resource/config/rsx.php, or intercept per-file with an
    |   #[OnEvent('document.extract_text')] handler.
    | - pdftotext_binary_path: absolute path to `pdftotext` (poppler-utils). NULL = auto-
    |   detect on PATH and common install locations.
    | - max_text_bytes: extracted text is capped at this many bytes. Over-cap extraction is
    |   TRUNCATED and RECORDED (index-row metadata truncated=true + original_bytes), never
    |   silently dropped and never FAILED - a partial index is discoverable, not a wrong answer.
    |
    */
    'search' => [
        'enabled' => env('SEARCH_ENABLED', true),
        'extractor_version' => 2,

        'extractors' => [
            'application/pdf' => 'Pdftotext_Text_Extractor',

            // SPREADSHEETS - their globs are longer than the two generic Office patterns
            // below, so they win for a workbook (the most specific match wins). PhpSpreadsheet
            // reads a workbook in-process (no soffice spawn, no worker slot) and indexes only
            // the WORDS: numbers, dates and currency are excluded, because a date and a
            // currency amount are numbers wearing a format and a full-text index is served
            // worst by them.
            // The ms-* globs cover the macro-enabled and template variants
            // (vnd.ms-excel.sheet.macroEnabled.12 and friends) beside the classic binary format.
            'application/vnd.ms-excel*' => 'Spreadsheet_Text_Extractor',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.*' => 'Spreadsheet_Text_Extractor',
            'application/vnd.oasis.opendocument.spreadsheet*' => 'Spreadsheet_Text_Extractor',

            'application/msword' => 'Libreoffice_Text_Extractor',
            'application/vnd.ms-word*' => 'Libreoffice_Text_Extractor',
            'application/vnd.ms-powerpoint*' => 'Libreoffice_Text_Extractor',
            'application/vnd.openxmlformats-officedocument.*' => 'Libreoffice_Text_Extractor',
            'application/vnd.oasis.opendocument.*' => 'Libreoffice_Text_Extractor',
            'application/rtf' => 'Libreoffice_Text_Extractor',

            'text/*' => 'Plain_Text_Extractor',
            'application/csv' => 'Plain_Text_Extractor',
            'application/json' => 'Plain_Text_Extractor',
        ],

        'pdftotext_binary_path' => env('PDFTOTEXT_BINARY_PATH', null),
        'max_text_bytes' => 2 * 1024 * 1024,  // 2MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Preview (Document_Preview component + rendition endpoint)
    |--------------------------------------------------------------------------
    |
    | Server-side viewer resolution + the cached PDF rendition pipeline for the
    | Document_Preview component. get_preview_info() resolves a viewer by mime; the
    | /_preview/pdf/:key route serves a PDF rendition of the attachment (the blob itself
    | when it is already a PDF, else the rendition Document_Render_Service produced in the
    | background - a web request never converts anything).
    |
    | - viewers: fnmatch map of mime pattern (glob) => viewer component (simple name),
    |   resolved by Rsx_Mime_Map: an application entry beats a framework entry, and inside
    |   a layer the most specific pattern wins (exact mime, then the glob with more literal
    |   characters, then the later line). The '*' entry is the terminal Icon_Viewer,
    |   consulted only when nothing else matches, so every mime resolves to something. An
    |   application adds a viewer, or replaces the framework's for a type, by listing the
    |   pattern in rsx/resource/config/rsx.php.
    | - Which types get a rendition at all, and how it is produced, is
    |   rsx.documents.renderers.
    | - quota_max_bytes: LRU cap (bytes) on tmp/renditions, enforced by the
    |   File_Rendition_Service scheduled cleanup task (oldest mtime evicted first).
    |
    */
    'preview' => [
        'viewers' => [
            'application/pdf' => 'Pdf_Viewer',

            // SPREADSHEETS - their globs are longer than the two generic Office patterns below,
            // so they win for a workbook (the most specific match wins). A workbook previews as
            // a GRID (Spreadsheet_Viewer over the HTML rendition PhpSpreadsheet produces), never
            // as a PDF: a spreadsheet has no pages, so a PDF of one is LibreOffice's PRINT view -
            // page breaks through the data, no gridlines, no row or column headers - which is
            // faithful to a print-out and unrecognisable as the thing being previewed.
            'application/vnd.ms-excel*' => 'Spreadsheet_Viewer',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.*' => 'Spreadsheet_Viewer',
            'application/vnd.oasis.opendocument.spreadsheet*' => 'Spreadsheet_Viewer',

            'application/msword' => 'Pdf_Viewer',
            'application/vnd.ms-word*' => 'Pdf_Viewer',
            'application/vnd.ms-powerpoint*' => 'Pdf_Viewer',
            'application/vnd.openxmlformats-officedocument.*' => 'Pdf_Viewer',
            'application/vnd.oasis.opendocument.*' => 'Pdf_Viewer',
            'application/rtf' => 'Pdf_Viewer',

            'image/*' => 'Image_Viewer',

            // MARKDOWN - an exact mime, so it beats the text/* glob below. A .md file previews
            // as the DOCUMENT its author wrote (headings, tables, task lists), rendered
            // server-side and sanitised twice; the source characters are still one click away
            // in the file itself.
            'text/markdown' => 'Markdown_Viewer',

            // Plain text renders as ITSELF, in a scrolling text frame - it needs no
            // conversion and gains nothing from one. Any more specific entry (text/markdown
            // above) wins over it; the terminal '*', whose Icon_Viewer would otherwise answer
            // for them, never does.
            'text/*' => 'Text_Viewer',

            '*' => 'Icon_Viewer',
        ],

        // Mimes a BROWSER can display in a tab of its own, given Content-Disposition: inline
        // (fnmatch globs). Read by File_Attachment_Model::can_open_inline, which is what an
        // "Open in Browser" affordance keys off.
        //
        // THE TEST IS THE BROWSER'S NATIVE ABILITY, NOT OURS. A .docx is previewable in this
        // application - the render worker converts it to a PDF - but handing its bytes to a
        // browser tab produces a download prompt, not a document, so it is deliberately absent
        // here. This list and rsx.preview.viewers answer two different questions and are
        // expected to disagree.
        //
        // Nor can it be derived from file_type_id: text/* classifies as DOCUMENT alongside
        // Word and Excel (2026-09-08), and those three disagree about inline display.
        'browser_inline' => [
            'application/pdf',
            'image/*',
            'text/*',
            'video/*',
            'audio/*',
        ],

        // Mimes whose EXTRACTED TEXT is not worth showing beside their preview (fnmatch
        // globs), read by File_Attachment_Model::should_show_text_preview(). Extraction
        // still runs for every one of these - the text remains fully searchable; this list
        // only says "do not put it on screen next to the document", for two distinct
        // reasons:
        //
        //   text/*        REDUNDANT. The preview IS the text (Text_Viewer renders the file
        //                 itself), so a text pane beside it shows the same characters twice.
        //   spreadsheets  NOT LEGIBLE. A sheet extracts to an undelimited run of cell
        //                 values with no rows, columns or headings - genuinely useful to
        //                 full-text search and meaningless to a reader.
        //
        // An app narrows or widens this in rsx/resource/config/rsx.php.
        'text_preview_suppressed' => [
            'text/*',
            'application/vnd.ms-excel*',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.*',
            'application/vnd.oasis.opendocument.spreadsheet',
        ],

        'quota_max_bytes' => 200 * 1024 * 1024,  // 200MB (enforced via scheduled LRU cleanup)
    ],

    'thumbnails' => [
        // Named preset definitions
        // Format: 'name' => ['type' => 'cover'|'fit', 'width' => int, 'height' => int]
        'presets' => [
            'profile' => ['type' => 'cover', 'width' => 200, 'height' => 200],
            'gallery' => ['type' => 'fit', 'width' => 400, 'height' => 300],
            'icon_small' => ['type' => 'cover', 'width' => 32, 'height' => 32],
            'icon_large' => ['type' => 'cover', 'width' => 64, 'height' => 64],
        ],

        // Thumbnail renderer registry: the "produce a raster from bytes" step of the
        // thumbnail pipeline, pluggable by mime type. Map of mime pattern (fnmatch glob) =>
        // renderer class (simple, manifest-resolved name), resolved by Rsx_Mime_Map: an
        // application entry beats a framework entry, and inside a layer the most specific
        // pattern wins (exact mime, then the glob with more literal characters, then the
        // later line). A mime with no registered renderer falls back to a generic extension
        // icon. Apps add or replace entries in rsx/resource/config/rsx.php.
        //
        // Imagick_Thumbnail_Renderer: raster images (byte-identical to the historic path)
        //   and PDFs (first page, requires Imagick + Ghostscript).
        //
        // OFFICE DOCUMENTS ARE NOT LISTED HERE, and that is the design: their pixels come
        // from the PDF rendition Document_Render_Service produced in the background, which
        // the thumbnail pipeline rasterizes with Imagick like any other PDF. A renderer
        // entry would mean converting a document inside a web request, which is exactly
        // what the render worker exists to stop.
        'renderers' => [
            'image/*' => 'Imagick_Thumbnail_Renderer',
            'application/pdf' => 'Imagick_Thumbnail_Renderer',
        ],

        // Skip the renderer (use the extension icon) for source blobs larger than this,
        // to bound render cost. null = no cap.
        'renderer_max_bytes' => 50 * 1024 * 1024,  // 50MB

        // Storage quotas in bytes (both enforced via LRU eviction)
        'quotas' => [
            'preset_max_bytes' => 100 * 1024 * 1024,  // 100MB (enforced via scheduled task)
            'dynamic_max_bytes' => 50 * 1024 * 1024,  // 50MB (enforced synchronously)
        ],

        // Maximum dimension limit for dynamic thumbnails (base resolution before 2x scaling)
        // This value is doubled during generation (800 becomes 1600x1600 after 2x scaling)
        // Preset thumbnails have no enforced maximum (developer-controlled)
        // NOTE: Application configuration - not overridable via environment variable
        'max_dynamic_size' => 800,

        // Touch mtime on cache hit to update LRU tracking (both preset and dynamic)
        'touch_on_read' => env('THUMBNAILS_TOUCH_ON_READ', true),

        // Only touch if mtime is older than this many seconds (prevents excessive filesystem writes)
        'touch_interval' => env('THUMBNAILS_TOUCH_INTERVAL', 600),  // 10 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Task System
    |--------------------------------------------------------------------------
    |
    | Background tasks (rsx:man tasks). Every run is a _tasks row: a dispatched run, a run of
    | a #[Schedule], and an inline run (Task::internal(), rsx:task:run, a #[Command]).
    |
    | Worker pools (rsx-lockd counts the members of each):
    | - on_demand: runs dispatched work (Task::dispatch, framework drains included).
    | - scheduled: runs #[Schedule] work, and takes queued on-demand work FIRST when there is
    |   any - so a cap of 1 in each lets two on-demand runs proceed at once.
    | - kill: the kill workers that carry out force stops and force kills.
    | Each cap is at least 1; a lower value throws.
    |
    | Commands:
    | - php artisan rsx:task:process (run via cron every minute)
    | - php artisan rsx:task:run Service method [params]
    | - php artisan rsx:tasks:list / rsx:tasks:stop / rsx:tasks:cancel
    |
    */
    'tasks' => [
        'pools' => [
            'on_demand' => ['max_workers' => env('RSX_TASK_MAX_WORKERS', 2)],
            'scheduled' => ['max_workers' => 1],
            'kill' => ['max_workers' => 10],
        ],

        // THE SILENCE LIMIT (seconds): how long a worker-run task may go WITHOUT REPORTING
        // before it is force-killed. A task has no limit on how long it RUNS - a run that
        // keeps reporting that it is alive runs for as long as its work takes. The limit is
        // measured from the run's last sign of life: the latest of when it started, its last
        // $task->heartbeat() and its last written report (status, progress, eta, state, the
        // queue calls, message, stdout/stderr and captured echo, an attachment, summary). A
        // task that reports nothing is therefore killed this long after it started.
        //
        // A SANCTIONED TIMEOUT (owner ruling, 2026-10-11), and what it bounds is the SILENCE
        // of a task, never its work: a long task reports on every item (heartbeat/progress),
        // which costs one held write per 0.25 s, and a run that does so is never cut short.
        // What it catches is a task that has stopped doing anything - wedged in a call that
        // never returns - which would otherwise hold its worker slot, and its #[Exclusive]
        // lock, forever. On expiry the rsx:task:process reaper requests a force kill, a kill
        // worker carries it out, and the run settles KILLED with the reason "no report for
        // Ns (cap Ms)". Enforced once per cron tick, so its granularity is a minute.
        //
        // An inline run (Task::internal(), rsx:task:run, a #[Command]) is never killed for
        // time. A run dispatched with an explicit 'timeout' option is under that HARD limit
        // from its start instead, and not under this one. 0 = no silence limit.
        'silent_timeout' => 1800,  // 30 minutes

        // THE MAINTENANCE WINDOW: the hour (0-23, on the RSX_SERVER_TIMEZONE wall clock) at
        // which every #[Schedule('maintenance')] task runs - the framework's retention
        // purges, disposal sweeps and log rotation, and an application's own. They declare
        // #[Exclusive('maintenance')] as well, so they run one after another from this hour.
        // Changing it re-registers those schedules on the next tick. See rsx:man tasks.
        'maintenance_hour' => 2,

        // How long a FORCE STOP waits for the task to stop on its own before its worker is
        // killed (Task_Run_Model::force_stop(), when the caller names no grace). Owner-set: it is
        // the window a cooperative task gets to finish cleanly, not a bound on any operation -
        // a graceful stop (request_stop()) never kills, whatever this says.
        'stop_grace_seconds' => 60,

        // Consecutive failed runs before rsx:health WARNs about a #[Schedule].
        // A failing schedule is never stopped - each run that throws is FAILED and the
        // schedule runs again at its next cadence - so this is the line between "retrying"
        // and "broken every run". A reporting threshold only.
        'failing_schedule_warn_after' => 3,

        // Re-running work a dead or disconnected worker ABANDONED (the rsx:task:process
        // reaper's verdict - never a task that threw, which is FAILED as always). Owner-set
        // pacing, not a timeout: it bounds nothing's run time and cuts nothing short.
        // An abandoned dispatched run goes back to PENDING with scheduled_for =
        // now + base_seconds * 2^(n-1), n being that row's abandonment count; the
        // attempts-th abandonment FAILS it for good. An abandoned scheduled or inline run is
        // FAILED at once: the schedule runs again at its next cadence, and an inline caller
        // is gone.
        'retry' => [
            'base_seconds' => 600,  // 10 minutes, doubling per abandonment
            'attempts' => 5,        // runs allowed before an abandoned run is FAILED
        ],

        // Task history retention, in MINUTES, applied by Task_Retention_Service every 30
        // minutes. A finished task's output is cut to its last output_keep_lines lines, and
        // its attachments are unlinked (their blobs released once nothing else references
        // them), output_truncate_after_minutes after it finished; the whole task - output,
        // reports, messages - is purged purge_after_minutes after it finished.
        'retention' => [
            'output_truncate_after_minutes' => 10080,  // 7 days
            'output_keep_lines' => 150,
            'purge_after_minutes' => 30240,            // 21 days
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime (WebSocket) Configuration
    |--------------------------------------------------------------------------
    |
    | PHP publishes via Redis; system/bin/realtime-server.js (Node) relays to
    | the browser. ws_port is also read directly from .env by that Node script
    | (it has no access to Laravel's config cache) - keep it in sync if you change
    | it here. The client's connect URL is DERIVED (wss://{Rsx::get_hostname()}/ws),
    | not configured. See: php artisan rsx:man realtime
    |
    */
    'realtime' => [
        'enabled' => env('REALTIME_ENABLED', false),
        'ws_port' => env('REALTIME_WS_PORT', 6200),

        // Max time (ms) a component's FIRST load waits for its realtime subscription to
        // establish before proceeding anyway (fail-soft when the relay is slow/down).
        // Application behavior, not deployment-specific -> config, not .env. Surfaced to
        // JS via window.rsxapp.realtime.load_gate_timeout_ms.
        'load_gate_timeout_ms' => 1500,

        // How long (ms) a tab may be disconnected or suspended before a successful
        // reconnect forces a full page reload instead of relying on resync. Resync brings
        // the DATA current; it cannot fix stale CODE (an open tab keeps its bundle forever,
        // so a deploy during the gap leaves it running dead JavaScript). The reload reuses
        // the refresh-push machinery: >=500ms floor, >=5s user-idle, suppressed while the
        // tab is navigating away, plus up to 10s of herd jitter. Set 0 to disable.
        // Surfaced to JS via window.rsxapp.realtime.stale_reload_after_ms.
        'stale_reload_after_ms' => 3600000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Locks (rsx-lockd)
    |--------------------------------------------------------------------------
    |
    | Web-cluster locks are served by rsx-lockd (system/bin/rsx-lockd), the lock
    | daemon whose CONNECTION IS THE LOCK - no lease, no TTL, no renewal. System
    | locks are flock() on this box and are not configured here.
    |
    | server_host/server_port must match the daemon's own lockd.conf: the Node
    | daemon reads that JSON file directly and has no access to Laravel's config,
    | so the two are kept in sync by hand. The shipped daemon defaults are
    | 0.0.0.0:6210 (dialed as 127.0.0.1); change both sides together.
    |
    | See: php artisan rsx:man locks
    |
    */
    'locks' => [
        'server_host' => env('LOCK_SERVER_HOST', '127.0.0.1'),
        'server_port' => env('LOCK_SERVER_PORT', 6210),

        // Connect attempts before a cluster-lock request throws, and the pause
        // between them. The overwhelmingly common failure is a daemon in the
        // middle of a supervisor restart, which is over in about a second; a
        // rejected hello is a wrong key and is never retried.
        // Application behavior, not deployment-specific -> config, not .env.
        'connect_retries' => 3,
        'connect_retry_delay_ms' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Gates
    |--------------------------------------------------------------------------
    |
    | Declarative authorization gates: #[Auth('check')] / @auth('check') on every
    | dispatchable surface, #[Auth_Check] on the realm's Permission methods. At
    | page render the active realm's marked checks are evaluated once each and the
    | GRANTED names ship as window.rsxapp.auth = {name: 1}. Denied checks are
    | omitted, never listed as false. See: php artisan rsx:man auth_gates
    |
    */
    'auth' => [
        // Also ship window.rsxapp.auth_routes = {'Controller::method': 1} - the
        // #[Route]/#[SPA] PHP surfaces this user's grants satisfy - so JS
        // Permission.can_access('Controller::method') can answer for a PHP-routed
        // target. When false (the default) the key is never defined at all and
        // can_access() on a '::' target console.errors naming this setting.
        //
        // PRIVACY DEFAULT. SPA pages link almost exclusively to other SPA actions
        // (whose @auth lists are already in the bundle, so they check for free) or
        // to specialized endpoints (downloads, logout) that need no affordance
        // gating. A manifest of every PHP page a user may reach is more information
        // than a page's source needs to carry, so it stays opt-in. Enable it only
        // if the application genuinely renders conditional links to PHP-routed
        // pages from the SPA.
        //
        // Application behavior, not deployment-specific -> config, not .env.
        'export_php_route_grants' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Fetch
    |--------------------------------------------------------------------------
    |
    | Limits on the generic ORM fetch endpoint (Model.fetch() / lazy relationship
    | accessors in JS). See: php artisan rsx:man model_fetch
    |
    */
    'model_fetch' => [
        // Most records a lazy plural relationship (`await record.things()`) may
        // return. Every record costs its own gated fetch() round trip, so this is a
        // fan-out ceiling, not a page size: exceeding it THROWS rather than
        // truncating, because a caller handed a silent partial set cannot tell.
        // A relation that legitimately grows past this wants its own paginated
        // #[Ajax_Endpoint].
        'max_relationship_records' => 500,

        // Most ids one ORM fetch request may carry. The JS ORM batches every lookup
        // issued in a single turn into one request and SPLITS a larger set across
        // several requests, so this is a request-size ceiling rather than a limit on
        // how much a page may fetch. The server REJECTS an oversized request instead
        // of truncating it: a silently-partial records map is indistinguishable from
        // "those records do not exist", which is precisely what per-id absence means
        // on this endpoint.
        'batch_max_ids' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | See the "Do The Whole Job" section of CLAUDE.md for the result-set rules this
    | supports, and rsx:man database for Rsx_Result_Set.
    |
    */
    'database' => [
        // DEVELOPMENT TRIPWIRE. When a single ->get() returns more than this many rows,
        // log one warning naming the count, the model and the call site. It never
        // throws and never truncates - it just tells you, while you are building the
        // feature, that a query you assumed was small is not.
        //
        // This is the empirical counterpart to the $unbounded declaration: the
        // declaration is a static claim about a table, this fires on what actually
        // happened. Development and debug modes only; production never pays for it.
        //
        // Set to 0 or null to disable.
        'result_set_warn_threshold' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Flash Alerts
    |--------------------------------------------------------------------------
    |
    | Server-to-client messages delivered through the session. See the Flash
    | system docs in app/RSpade/Lib/Flash/CLAUDE.md.
    |
    */
    'flash' => [
        // Most pending alerts one session may hold. Enforced at the WRITER, because
        // the reader hands the whole set to the browser and deletes it - a limit on
        // the READ would silently drop alerts the user was meant to see. When a
        // session exceeds this, the OLDEST overflow is dropped (the newest alerts
        // describe what the user just did). Set to 0 or null to disable.
        'max_alerts_per_session' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP: Trusted Proxies (the client address)
    |--------------------------------------------------------------------------
    |
    | Which peers may tell the application who the CLIENT is. X-Forwarded-For is
    | read ONLY when the immediate peer (REMOTE_ADDR) is a trusted proxy, and then
    | right to left: each trusted hop is skipped and the first untrusted address is
    | the client. Every entry a client wrote itself sits to the LEFT of the address
    | the first trusted proxy appended, so a forged header changes nothing. One
    | resolver, $request->ip() (App\Http\Middleware\TrustProxies), feeds
    | Session::get_client_ip(): the login throttle, the second-factor throttle,
    | login history, session rows, the API request log and revision records.
    |
    | Loopback (127.0.0.1, ::1) is ALWAYS trusted: the framework's own hops are on
    | it (nginx -> the full-page-cache proxy on 127.0.0.1:3200 -> nginx -> PHP).
    | List here the addresses or CIDRs of anything in FRONT of nginx that appends
    | X-Forwarded-For - a load balancer, a TLS terminator, a CDN's published ranges.
    | Until it is listed, every request appears to come from that device, and the
    | per-IP login throttle counts every visitor as one.
    |
    | Only X-Forwarded-For is trusted. X-Forwarded-Host/-Port/-Prefix are never
    | read through a proxy (the host the application answers on is HTTP_HOST,
    | validated against APP_URL / PORTAL_URL).
    |
    */
    'http' => [
        // TRUSTED_PROXIES in .env: addresses or CIDRs separated by commas, e.g.
        // TRUSTED_PROXIES=10.0.0.0/8,192.0.2.10. It is an environment key because the
        // device in front of a box is a fact about that box, never about the application.
        // Empty: loopback only.
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))), 'strlen')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Retention
    |--------------------------------------------------------------------------
    |
    | How long a session row survives without being used. Every session is stamped
    | with a TYPE at creation (Session::TYPE_WEB / TYPE_CLI / TYPE_API) and expires
    | against its own type's window; Session_Cleanup_Service sweeps hourly.
    |
    | These are INACTIVITY windows measured from last_active, not absolute
    | lifetimes: an in-use browser session never expires. See: rsx:man session
    |
    */
    'sessions' => [
        // Every value in this block is a DEFAULT. An application changes any of them in
        // rsx/resource/config/rsx.php under the same keys (the two files are deep-merged,
        // so overriding one key leaves its siblings at these values).
        //
        // HOW LONG A SIGN-IN LASTS, per experience. Enforced when a request arrives
        // (Session::enforce_timeouts() / Portal_Session::enforce_timeouts()), and measured
        // on the USER SESSION - one account's sign-in on one browser - never on the browser
        // row, which outlives every sign-in made on it. The hourly
        // Session_Cleanup_Service::cleanup_sessions sweep signs out the sign-ins whose
        // browser never comes back once they are past a limit plus ajax_grace_minutes, so
        // a "where you're signed in" list stops showing them as signed in.
        //
        // max_length_minutes: from the SIGN-IN. The hard ceiling - a sign-in is ended
        //   this long after it began however active it is, and the person signs in again
        //   (facing their second factor again, if they have one). This is the limit that
        //   bounds a sign-in somebody else got hold of, because a thief is never idle.
        //   Default: 10080 (7 days).
        // max_idle_minutes: from the last PAGE VIEW - a rendered page or an SPA
        //   navigation. Ajax and API calls are not page views, so an open tab that polls
        //   does not keep a sign-in alive. Default: 480 (8 hours - a working day away
        //   from the screen signs the browser out).
        //
        // 0 means no limit. Both are SECURITY WINDOWS on a sign-in, not operation
        // timeouts: nothing is waiting on them, and expiry ends in the sign-in screen
        // with a message saying why. An application handling sensitive records sets both
        // lower; see rsx:man session (HOW SESSIONS TIME OUT).
        'staff' => [
            'max_length_minutes' => 10080,
            'max_idle_minutes' => 480,
        ],

        // The same two limits for a client-portal sign-in. One browser can carry a staff
        // and a portal sign-in at once; each is judged by its own limits on its own
        // requests.
        'portal' => [
            'max_length_minutes' => 10080,
            'max_idle_minutes' => 480,
        ],

        // Days a BROWSER session may go unused before it is retired. A session row is the
        // browser (its cookie, its values, the sign-ins made on it); one whose cookie has
        // not been presented for this long is made INACTIVE - by the daily maintenance task
        // Session_Cleanup_Service::deactivate_unused_sessions, or the next time it is
        // presented, whichever comes first - and never deleted, since its user sessions are
        // the record of where an account signed in; the browser carries on as a stranger,
        // and whoever was still signed in on it is signed out ("Browser unused"). Also how
        // long a signed-out user session stays in Session::get_sessions_for_user(). A
        // RETENTION WINDOW, not an operation timeout: nothing is waiting on it.
        // 0 = never retired. Default: 30.
        'unused_session_days' => 30,

        // How long past its limit a sign-in still answers NON-PAGE requests - Ajax
        // calls, thumbnails, downloads. A page (and an SPA navigation) is refused at the
        // limit itself and signs the person out; this window exists so a page that was
        // already open can still finish what it was doing, such as saving the form on
        // screen. The first such request after the window ends the sign-in itself and
        // answers the session_expired envelope. Default: 2 hours.
        'ajax_grace_minutes' => 120,

        // Minutes a GUEST browser session - one nobody has ever signed in on, in either
        // experience (_sessions.first_signed_in_at is null) - may go unused before the
        // hourly Session_Cleanup_Service::cleanup_sessions sweep DELETES it, with its
        // session values. Guest rows are minted by any visitor something asks a session of,
        // crawlers included. A browser that has ever had a sign-in is never deleted: it is
        // made inactive after unused_session_days instead. A RETENTION WINDOW, not an
        // operation timeout: nothing is waiting on it. Default: 30 days.
        'guest_expiry_minutes' => 43200,

        // Days an IMPERSONATION may run before the daily
        // Session_Cleanup_Service::end_stale_impersonations task ends it. An
        // impersonation has no sign-in of its own to expire - it rides the
        // impersonator's browser session - so without this it lasts as long as that
        // browser stays signed in. A staff impersonation is signed out together with the
        // impersonator's own sign-in behind it (they sign in again as themselves); a
        // portal View as Client is signed out alone and the staff sign-in carries on. A
        // SECURITY WINDOW on a borrowed identity, not an operation timeout. 0 = never ended
        // for age.
        'impersonation_max_days' => 7,

        // How many browsers one account may be signed in on at once, per realm. On every
        // sign-in the account's older signed-in user sessions beyond this count (by last
        // activity) are signed out - only that realm's sign-in on each evicted browser;
        // the browser row and any other sign-in on it are untouched. Limits how many
        // places a stolen-then-abandoned session can persist.
        //
        // Set to 0 or null to DISABLE the feature entirely (no session is ever
        // signed out on login). PLAYWRIGHT and API sessions are never counted or
        // evicted - a harness run must not sign a developer out of their browser.
        'max_web_sessions_per_user' => 25,

        // Brute-force throttle on the authentication surface, enforced by the
        // framework (Login_Throttle, the first statement of every credential check).
        //
        // WHAT IS COUNTED: every sign-in ATTEMPT, right or wrong, against both the
        // client address (an IPv6 address by its /64) and the account the attempt
        // names. A request with no client address at all (CLI, tasks) is never
        // throttled: there is no remote party to throttle.
        //
        // ON BY DEFAULT: an unthrottled login endpoint is an unlimited password
        // and second-factor guessing oracle, so the secure posture is the one you
        // get without doing anything. 'enabled' => false turns it off for an
        // environment that throttles at the edge instead.
        //
        // A throttled attempt throws Auth_Throttled_Exception (HTTP 429 when
        // nothing catches it); a login function catches it and shows its message.
        // Under maintenance mode Redis is stopped and the throttle is inert while
        // the web tier answers 503. Any OTHER cache failure is loud.
        // How long a password confirmation lasts (Rsx_Password_Confirmation): the minutes
        // after a user re-enters their password (or uses a passkey) during which the gated
        // actions - adding or removing a passkey, an authenticator app, recovery codes, a
        // federated sign-in, and whatever an application gates - go through without asking
        // again. A SECURITY WINDOW set by owner ruling (2026-10-29), not an operation
        // timeout: it bounds how long a confirmed browser keeps the right, cancels no work,
        // and its expiry degrades to the password being asked for again.
        'password_confirmation_minutes' => 5,

        'login_throttle' => [
            // Master switch for counting and enforcement.
            'enabled' => true,

            // Sign-in attempts allowed per `seconds`, counted per client address and per
            // account; one more is refused until the rate falls back under it. Loose on
            // purpose: Turnstile and second factors protect accounts, and this only stops a
            // flood of automated attempts. Applies to staff and portal sign-in, second
            // factors, passkeys, federated sign-in and the password confirmation.
            'attempts' => 8,
            'seconds' => 60,
        ],

        // Days a login-history row - `_login_history` (staff) and `_portal_login_history`
        // (client portal) alike; failures are never rows - is kept before the hourly
        // Session_Cleanup_Service prunes it. Default: 1 year. Set to 0 or null to
        // disable the prune entirely (rows are then kept forever).
        'login_history_retention_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | The second factor an identity may add to its password: an authenticator app
    | (TOTP, RFC 6238), a passkey (WebAuthn), and the recovery codes that back
    | both up. Rsx_Two_Factor is the only class application code touches.
    |
    | THERE IS NO 'enabled' SWITCH HERE, deliberately. Whether an identity has a
    | second factor is a property of that identity, answered by
    | Rsx_Two_Factor::is_enabled($login_user) - not a property of the install.
    | A global off switch would be a way to disable everybody's second factor at
    | once by editing one line, which is not a control anybody should have.
    | An application that does not offer 2FA simply never calls the enrollment
    | methods, and no identity ever has a factor to be challenged for.
    |
    | See: php artisan rsx:man two_factor
    |
    */
    'two_factor' => [
        // The issuer label an authenticator app files the account under, shown
        // beside the account name in the user's code list.
        //
        // null DERIVES it from the application hostname (Rsx::get_hostname()),
        // which is the right default because it is what the user typed to get
        // here - recognisable in a list of twenty accounts in a way a product
        // name may not be. Set it to a literal when the product name is the more
        // recognisable of the two.
        //
        // A colon is stripped on the way out: the otpauth:// Key URI Format uses
        // one to separate issuer from account, so an issuer containing one
        // produces a label the app parses wrongly.
        'issuer' => null,

        // A SECURITY WINDOW, NOT AN OPERATION TIMEOUT (see the timeout mandate in
        // CLAUDE.md): how long a passed-password-awaiting-second-factor state
        // stays redeemable.
        //
        // It bounds no work and imposes no deadline on anything - nothing is
        // cancelled when it expires, and expiry degrades to a working outcome the
        // user can act on ("Your verification window has expired. Please sign in
        // again."), never to a failure handed to code that did not expect one.
        //
        // WHY 599 SECONDS IS ACCEPTABLE HERE AND 601 IS NOT is a question about
        // an attacker, not about how long an operation takes. What is parked
        // during this window is a HALF-AUTHENTICATED identity: a password that
        // has already been proven correct, waiting on a screen that may be
        // unattended. The window is how long someone who walks up to that browser
        // can finish the sign-in by supplying only the second factor. Fifteen
        // minutes is long enough to fetch the phone that IS the second factor from
        // another room, or to wait out a slow mail relay for an emailed code; it is
        // not long enough to leave a proven password redeemable for the rest of the
        // afternoon.
        //
        // An ISSUED code (Rsx_Two_Factor::issue_code() - the six digits an
        // application emails or texts) lives exactly as long as its challenge.
        //
        // The same window bounds a parked TOTP enrollment seed. An in-flight
        // WebAuthn ceremony's challenge is NOT bounded here: its window is derived
        // from the ceremony timeout the browser is sent (300s plus a 60s
        // round-trip margin - Passkeys::challenge_window_seconds()), so the two
        // can never disagree.
        //
        // Must be at least 1. Rsx_Two_Factor::challenge_expires_at() calls
        // shouldnt_happen() on anything lower rather than minting a window that is
        // already closed.
        'challenge_window_minutes' => 15,

        // Whether the login challenge screen shows the FULL address being signed in
        // to. false (the default) sends only a masked form (c***e@example.com): the
        // challenge state is a public endpoint answered for whoever holds the session
        // cookie, and on an unattended browser that need not be the person who typed
        // the password. true adds the address itself to the challenge state (`email`,
        // beside `email_masked`) and <Two_Factor_Challenge> prints it, so the person
        // completing the sign-in can see which of their accounts it is for. Applies to
        // both realms.
        'challenge_shows_full_email' => false,

        // Whether this deployment issues RECOVERY CODES. true (the default) mints a
        // sheet of ten alongside an identity's first factor and lets the identity
        // regenerate it. false mints NOTHING, on any path - enrollment, the
        // rsx:users:2fa:setup operator path, regeneration (which then throws) - for
        // a deployment whose way back in is an administrator rather than a sheet of
        // paper. Applies to both realms.
        //
        // It governs MINTING only. A code an identity already holds is not deleted
        // by turning this off and is still accepted at the challenge until it is
        // spent or an operator runs rsx:users:2fa:remove --recovery-codes. Turned
        // on again, the next enrollment mints a sheet; nothing is backfilled for
        // identities enrolled in between. Read it as
        // Rsx_Two_Factor::issues_recovery_codes().
        'recovery_codes' => true,

        // ATTEMPT CAPS. These are counts, not timeouts. A six-digit code with a
        // +/-1 step tolerance has three live answers in a million, so an uncapped
        // challenge is a guessing oracle for anyone who already holds the password.
        // The per-IP login throttle (rsx.sessions.login_throttle) is not enough on
        // its own: an attacker rotates addresses. Every wrong answer to a challenge -
        // a TOTP code, a recovery code, a passkey assertion - counts against BOTH caps.
        //
        // Wrong answers ONE parked challenge survives. The answer that reaches this
        // number destroys the challenge, and the user signs in again (re-entering the
        // password, which spends the per-IP budget). Must be at least 1.
        'challenge_max_failures' => 5,

        // Wrong answers ONE IDENTITY may give, across every challenge and every IP,
        // inside identity_failure_window_minutes. Once reached, verification for that
        // identity is refused - a correct code included - until the window closes or an
        // operator runs rsx:users:2fa:unlock (Rsx_Two_Factor::clear_failures()). A
        // correct answer before the cap is reached clears the count. Must be at least 1.
        'identity_max_failures' => 10,

        // A SECURITY WINDOW, NOT AN OPERATION TIMEOUT (see the timeout mandate): the
        // fixed window, opened by an identity's first wrong answer, inside which
        // identity_max_failures is counted - and therefore how long a locked identity
        // stays locked. It bounds an attacker's guessing RATE, not any operation:
        // nothing is waiting on it and nothing fails when it closes. 60 minutes holds a
        // password-holding attacker to identity_max_failures guesses an hour (about one
        // success in 30,000 hours for a six-digit code), while a real user who fumbled
        // their phone is locked out for at most an hour or until support unlocks them.
        // Must be at least 1.
        'identity_failure_window_minutes' => 60,

        // TRUSTED BROWSERS, per realm, in days; 0 = off. After a challenge is passed, an
        // application's verify endpoint may call trust_this_device($identity) to remember
        // that browser for this long, and its login function may then ask
        // device_is_trusted($identity) before starting the next challenge. Nothing skips a
        // challenge on its own: the application decides who a trusted browser excuses. With
        // a realm at 0, trust_this_device() throws and device_is_trusted() is always false.
        // A browser is its _sessions row, kept across sign-ins and sign-outs (its tokens
        // rotate in place) and lost with its cookie. See rsx:man two_factor, TRUSTED BROWSERS.
        'trusted_device_days' => [
            'staff' => 0,
            'portal' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SSO / Social Login (Rsx_Sso)
    |--------------------------------------------------------------------------
    |
    | "Continue with Google/Microsoft/Facebook/Apple/X" on a login page. The
    | framework owns the OAuth ceremony and hands the application a NORMALIZED
    | IDENTITY; the application owns account policy (auto-provision, verified-email
    | match, invite-only, finish-registration) through the sso.identity.unlinked
    | resolve hook. Rsx_Sso is the only class application code touches.
    |
    | ACTIVITY IS CONFIGURED, NOT DERIVED - exactly like Turnstile. A provider is
    | live because its 'enabled' is true and for no other reason; development mode,
    | hostname and APP_URL have no bearing on it. A provider enabled with a missing
    | credential is a HALF-CONFIGURED INSTALL and THROWS, naming the literal .env
    | keys, rather than rendering a button that leads to a provider error page.
    |
    | Client ids are effectively public (they ride in the authorize URL). Client
    | secrets and the Apple private key are server-only and are never exported to
    | the browser - only {key, label, begin_url, icon_svg} reaches window.rsxapp.sso.
    |
    | The redirect URI to register in each provider's console is
    | <APP_URL>/_sso/<key>/callback - plus the portal's own callback when
    | 'portal_enabled' is on. See: php artisan rsx:man sso
    |
    */
    'sso' => [
        // The five built-in providers. Adding one to a console is configuration;
        // adding a SIXTH is 'custom' below, not a change to this list.
        'providers' => [
            // Google. Console: APIs & Services > Credentials > OAuth client ID
            // (Web application). Asserts a verified email on every sign-in.
            'google' => [
                'enabled' => env('SSO_GOOGLE_ENABLED', false),
                'client_id' => env('SSO_GOOGLE_CLIENT_ID'),
                'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET'),
            ],

            // Microsoft (Entra ID). Console: App registrations.
            //
            // 'tenant' decides WHO may sign in: 'common' (any Microsoft account,
            // work or personal), 'organizations' (work/school only), 'consumers'
            // (personal only), or a tenant GUID to admit exactly one directory.
            // It is a single-tenant application's only real access control here,
            // so leaving it at 'common' on an internal tool is a decision, not a
            // default to ignore.
            'microsoft' => [
                'enabled' => env('SSO_MICROSOFT_ENABLED', false),
                'client_id' => env('SSO_MICROSOFT_CLIENT_ID'),
                'client_secret' => env('SSO_MICROSOFT_CLIENT_SECRET'),
                'tenant' => env('SSO_MICROSOFT_TENANT', 'common'),
            ],

            // Facebook. Console: Meta for Developers > Facebook Login.
            // The email scope may be withheld by the user, so an unlinked identity
            // from Facebook can arrive with a null email.
            'facebook' => [
                'enabled' => env('SSO_FACEBOOK_ENABLED', false),
                'client_id' => env('SSO_FACEBOOK_CLIENT_ID'),
                'client_secret' => env('SSO_FACEBOOK_CLIENT_SECRET'),
            ],

            // Apple. FOUR credentials, not two, because Apple has no static client
            // secret: the secret is a short-lived ES256 JWT the server MINTS on
            // every token exchange, signed with the .p8 key, issued by the team and
            // identified by the key id. All three of team_id/key_id/private_key are
            // therefore required, and client_id is the SERVICES ID (com.example.web
            // shape), never the app's bundle id.
            //
            // private_key accepts either an absolute PATH to the .p8 file or the PEM
            // text itself, so a container with no writable secrets volume can carry
            // it in the environment. Nothing is minted offline-unsafe: the JWT is
            // signed locally and no key ever leaves the box.
            'apple' => [
                'enabled' => env('SSO_APPLE_ENABLED', false),
                'client_id' => env('SSO_APPLE_CLIENT_ID'),
                'team_id' => env('SSO_APPLE_TEAM_ID'),
                'key_id' => env('SSO_APPLE_KEY_ID'),
                'private_key' => env('SSO_APPLE_PRIVATE_KEY'),
            ],

            // X (formerly Twitter), OAuth 2.0 with PKCE.
            //
            // X DOES NOT RETURN AN EMAIL to most applications - the users.email
            // scope requires additional approval - so an identity from X routinely
            // arrives with a null email, and any policy that matches on a verified
            // address will decline it. That is a property of X, not a bug here.
            'x' => [
                'enabled' => env('SSO_X_ENABLED', false),
                'client_id' => env('SSO_X_CLIENT_ID'),
                'client_secret' => env('SSO_X_CLIENT_SECRET'),
            ],
        ],

        // Providers this application adds, beyond the five above. THE EXTENSION
        // SEAM: install an adapter (rsx:composer require socialiteproviders/okta)
        // and name its provider class here - no listener, no service provider, no
        // framework change. Generic OIDC is deliberately not a built-in; a provider
        // package for the identity server in question is this seam's answer.
        //
        //   'okta' => [
        //       'provider' => \SocialiteProviders\Okta\Provider::class,
        //       'label' => 'Okta',
        //       'icon_file' => '/path/to/okta.svg',   // or 'icon_svg' => '<svg .../>'
        //       'enabled' => true,
        //       'client_id' => env('SSO_OKTA_CLIENT_ID'),
        //       'client_secret' => env('SSO_OKTA_CLIENT_SECRET'),
        //       'base_url' => env('SSO_OKTA_BASE_URL'),   // any extra key the
        //   ],                                            // adapter declares
        'custom' => [],

        // Whether an identity that signed in through a provider still faces this
        // application's own second factor.
        //
        // FALSE (the default) means it does, and that is the safe posture: the
        // provider proved who owns the Google account, which is not the same
        // statement as "the person at this keyboard is allowed into this account",
        // and a user who deliberately enrolled a second factor here did not consent
        // to it being skippable by signing in another way.
        //
        // Set it true only where the identity provider IS the organization's
        // authentication authority and already enforces its own MFA - a corporate
        // Entra tenant with conditional access, say - so that a second challenge is
        // duplicated ceremony rather than a second factor.
        'skip_two_factor' => false,

        // Federated sign-in on the CLIENT PORTAL (Rsx_Portal_Sso). OFF by default, and an
        // application decision rather than a deployment one, so it is config and not .env:
        // switching a provider on for staff must not quietly put a button on the portal.
        // When true, every provider enabled above is also offered on the portal login page,
        // through the portal's own ceremony URLs - register the portal callback
        // (Rsx_Portal_Sso::callback_url('<key>'): <portal host>[/_portal]/_sso/<key>/callback)
        // as a second redirect URI in each provider's console - and portal policy is answered
        // by the portal.sso.* hooks, never by the staff sso.* ones.
        'portal_enabled' => false,

        // A SECURITY WINDOW, NOT AN OPERATION TIMEOUT (see the timeout mandate in
        // CLAUDE.md), and the exact analogue of rsx.two_factor.
        // challenge_window_minutes.
        //
        // It bounds no work: no HTTP call, no redirect and no token exchange is
        // cancelled when it expires, and expiry degrades to a working outcome the
        // user can act on ("That sign-in took too long. Please try again."), never
        // to a failure handed to code that did not expect one.
        //
        // WHY 599 SECONDS IS ACCEPTABLE AND 601 IS NOT is a question about an
        // attacker, not about how long anything takes. Two half-authenticated
        // states live in this window. The first is an in-flight ceremony - a state
        // value parked before the browser left for the provider, which must stop
        // being redeemable once the user has plainly abandoned the trip. The second
        // is a PENDING IDENTITY: a provider account whose ownership has been proven
        // but which is connected to no local account yet, sitting on a
        // finish-registration screen that may be unattended. Ten minutes is long
        // enough to read a consent screen, approve a push, and type a name; it is
        // not long enough to leave a proven third-party identity redeemable for the
        // rest of the afternoon.
        //
        // Must be at least 1. Rsx_Sso::pending_expires_at() calls shouldnt_happen()
        // on anything lower rather than minting a window that is already closed.
        'pending_window_minutes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Full Page Cache (FPC) Configuration
    |--------------------------------------------------------------------------
    |
    | The FPC is a Node.js reverse proxy (system/bin/fpc-proxy.js) that caches
    | #[FPC]-marked pages in Redis (DB 2).
    |
    | THERE IS NO MASTER SWITCH, and that is the design: full page caching is an
    | APPLICATION decision a developer makes per route by writing #[FPC] on it,
    | not a deployment setting an administrator flips. A route without the
    | attribute is never cached in any mode, so "off" is what an application
    | that has not asked for it already gets. The per-entry TTL comes from the
    | attribute too - #[FPC(ttl: 5)] - and rides to the proxy on the response
    | marker, so no environment value has to be kept in step with the code.
    |
    | An unreachable or unauthenticated Redis fails loud on a purge (a developer
    | purge must never silently claim success). The proxy reads REDIS_* and
    | FPC_PROXY_PORT / FPC_BACKEND_PORT directly from .env (no Laravel config
    | cache). See: php artisan rsx:man fpc
    |
    */
    'fpc' => [
        // Proxy listen port (advisory - the Node proxy reads FPC_PROXY_PORT
        // from .env itself; this mirrors it for the rsx:health liveness probe).
        'proxy_port' => env('FPC_PROXY_PORT', 3200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail Configuration
    |--------------------------------------------------------------------------
    |
    | Outbound email: the queue (Email_Queue_Model) and the two independent
    | safety layers. See: php artisan rsx:man email
    |
    | HOW a message leaves - the mailer, its host and credentials, the From
    | address - is Laravel's mail configuration (config/mail.php: MAIL_MAILER,
    | MAIL_HOST, MAIL_FROM_ADDRESS, ...), extended in rsx/resource/config/mail.php.
    | WHETHER it leaves is decided here.
    |
    | 'delivery' is the master switch and is CONFIGURED, not derived: 'live'
    | hands each row to the Laravel mailer MAIL_MAILER names, 'suppressed'
    | renders the message, records it, and records STATUS_SUPPRESSED without
    | sending. The shipped default is 'suppressed', so a fresh install records
    | every message - the email history screens render it from the database -
    | and nothing leaves the box, with no catcher daemon to depend on.
    |
    | 'dev_site' is the SECOND, independent layer: on a hostname containing
    | '.dev.' (Rsx::is_dev_site()) every recipient is checked against the
    | whitelists and otherwise redirected to the catchall.
    |
    */
    'mail' => [
        // What this install does with an email. FOUR modes, and nothing else is
        // accepted - an unrecognised value throws (Rsx_Mail_Transport::delivery_mode()).
        //
        //   aiosmtpd   Opt-in. Captured by the development catcher on this box:
        //              SMTP to 127.0.0.1:1025, no encryption, no auth. Laravel's mail
        //              config is IGNORED in this mode, so a stale MAIL_HOST cannot
        //              redirect a development install's mail at a real relay. The
        //              catcher's SMTP greeting must contain 'aiosmtpd' or every
        //              message is refused as a server error - see
        //              system/bin/mail_catcher.py.
        //   live       Real delivery through the Laravel mailer MAIL_MAILER names
        //              (config/mail.php). 'log' and 'array' are refused - they deliver
        //              nothing. The .dev.-hostname recipient whitelist/catchall gate
        //              applies in THIS MODE ONLY.
        //   suppressed THE DEFAULT. Built and recorded (rendered_html/rendered_text and
        //              the inline parts land on the row), never handed to a transport.
        //              Rows end SUPPRESSED - a deliberate outcome, not an error. The
        //              catcher is only needed to inspect the raw MIME a transport saw.
        //   disabled   The queue is FROZEN. The drain logs one line and returns; rows
        //              stay PENDING, untouched, and nothing is marked stale.
        'delivery' => env('MAIL_DELIVERY', 'suppressed'),

        // Server-error policy: the mail host answered with an error for THIS message
        // (an SMTP reply, or an API transport's 4xx refusal), so the message is retried
        // on its own clock. A connection-level failure is NOT this - the runner
        // reconnects once and then dies loud, leaving every row PENDING for the next
        // sweep.
        'retry' => [
            'attempts' => 3,
            'delay_minutes' => 3,
        ],

        // Whole queue rows (and their attachments, by FK cascade) are deleted
        // this many days after they were created.
        'retention_days' => 30,

        // QUEUE HYGIENE, NOT A TIMEOUT. This bounds no wait, no send, no connection
        // and no drain - those take as long as they take. It is the safeguard against
        // ONE system-configuration failure:
        //
        //   The email queue silently stops working - a dead transport, a misconfigured
        //   host, a stopped worker - and nobody notices for a month. The moment it is
        //   repaired, the drain would flood a month of stale notices: out of date,
        //   irrelevant, and impossible to retract.
        //
        // So the drain refuses to send a message that should have gone out more than
        // this many days ago. It marks the row FAILED with an error telling the operator
        // exactly how to resend it (`php artisan rsx:mail:resend <id>`) if they decide
        // that message is still wanted. THE DECISION TO SEND OLD MAIL BELONGS TO A
        // HUMAN, NEVER TO THE DRAIN. Nothing is deleted, and rsx:mail:resend is the
        // deliberate escape hatch.
        //
        // Measured from COALESCE(next_attempt_at, created_at), never created_at alone: a
        // message scheduled with send_at() three weeks out is not late until three weeks
        // from now, and must not be born stale.
        'stale_after_days' => 2,

        // Secret used to sign unsubscribe links (falls back to app.key).
        'unsubscribe_secret' => null,

        // App-tier overrides: see rsx/resource/config/rsx.php.
        'branding' => [
            'logo_url' => null,
            'footer_text' => null,
        ],

        // TEST ADDRESSES - never delivered, in EVERY mode, production included. A message
        // whose recipient's domain matches one of these (shell-style, case-insensitive) is
        // queued and rendered like any other, then recorded as status Test instead of
        // being handed to a transport; a test cc/bcc on a real message is withheld and
        // recorded. Sample data and demos use them so they behave as sent without ever
        // reaching an inbox. The SMS twin is fixed: any (nnn) 555-nnnn number
        // (App\RSpade\Core\Mail\Rsx_Test_Recipients). An application's list ADDS patterns
        // to this one (the two-tier merge combines lists).
        'test_domains' => ['test.*'],

        // Dev-site (.dev. hostname) recipient gating - a second layer, independent
        // of 'delivery'. Empty whitelists + empty catchall = nothing deliverable.
        'dev_site' => [
            'catchall_address' => env('EMAIL_DEV_CATCHALL_ADDRESS', ''),
            'address_whitelist' => env('EMAIL_DEV_EMAIL_ADDRESS_WHITELIST', ''),
            'domain_whitelist' => env('EMAIL_DEV_EMAIL_DOMAIN_WHITELIST', ''),
        ],

        // Where the development SMTP catcher writes its Maildir. Read by
        // rsx:mail:test and pruned by the retention cleanup when it exists.
        'catcher_maildir' => \App\RSpade\Core\Paths\Rsx_Project_Paths::mail_catcher_dir(),

        // Set to the DKIM selector this domain publishes to have rsx:health look
        // up the corresponding TXT record. Null = no DKIM check.
        'dkim_selector' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Configuration
    |--------------------------------------------------------------------------
    |
    | The Rsx_Sms mirror of the mail block. NO PROVIDER IS WIRED: 'delivery'
    | defaults to 'suppressed' and Sms_Queue_Service records every message
    | STATUS_SUPPRESSED. The provider/credentials keys below are the shape a
    | future transport reads; they are inert today.
    |
    */
    'sms' => [
        // suppressed | disabled. The mail modes' vocabulary, minus the two that need a
        // transport: THERE IS NO SMS PROVIDER, so 'live' and 'aiosmtpd' are refused with
        // a message saying so rather than silently doing nothing.
        //
        //   suppressed THE DEFAULT. Every claimed row is recorded SUPPRESSED ('no SMS
        //              provider configured') - honest about a message that is not going.
        //   disabled   The queue is FROZEN. The drain logs one line and returns; rows
        //              stay PENDING, untouched.
        //
        // There is no stale-message window here (the mail side's rsx.mail.stale_after_days
        // has no mirror): nothing can time out against a delivery path that does not exist.
        'delivery' => env('SMS_DELIVERY', 'suppressed'),

        // Sending service that will actually deliver SMS once a transport exists.
        // Common providers:
        //   'twilio'      - Twilio
        //   'aws_sns'     - Amazon SNS (AWS)                 (also Amazon Pinpoint)
        //   'vonage'      - Vonage (formerly Nexmo)
        //   'messagebird' - MessageBird (now Bird)
        //   'plivo'       - Plivo
        //   'sinch'       - Sinch
        //   'telnyx'      - Telnyx
        //   'infobip'     - Infobip
        //   'bandwidth'   - Bandwidth
        'provider' => env('SMS_PROVIDER', null),

        // Sending number / sender id for outbound SMS.
        'from_number' => env('SMS_FROM_NUMBER', null),

        // Provider auth credentials. Provider-specific: set only the ones your
        // SMS_PROVIDER needs. Keep secrets in .env, never here.
        'credentials' => [
            // Twilio
            'twilio_account_sid' => env('SMS_TWILIO_ACCOUNT_SID'),
            'twilio_auth_token' => env('SMS_TWILIO_AUTH_TOKEN'),

            // Amazon SNS / AWS (or reuse the app's shared AWS credentials)
            'aws_key' => env('SMS_AWS_KEY'),
            'aws_secret' => env('SMS_AWS_SECRET'),
            'aws_region' => env('SMS_AWS_REGION', 'us-east-1'),

            // Vonage (Nexmo)
            'vonage_api_key' => env('SMS_VONAGE_API_KEY'),
            'vonage_api_secret' => env('SMS_VONAGE_API_SECRET'),

            // Generic API key/secret + token for the others
            // (MessageBird/Plivo/Sinch/Telnyx/Infobip/Bandwidth).
            'api_key' => env('SMS_API_KEY'),
            'api_secret' => env('SMS_API_SECRET'),
            'auth_token' => env('SMS_AUTH_TOKEN'),
        ],

        // Provider-error policy, mirroring rsx.mail.retry.
        'retry' => [
            'attempts' => 3,
            'delay_minutes' => 3,
        ],

        'retention_days' => 30,

        // Dev-site (.dev. hostname) destination gating, mirroring rsx.mail.dev_site.
        'dev_site' => [
            'catchall_number' => env('SMS_DEV_CATCHALL_NUMBER', ''),
            'number_whitelist' => env('SMS_DEV_NUMBER_WHITELIST', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    |
    | The CAPTCHA replacement: a background browser challenge that mints a
    | single-use token, verified server-side against Cloudflare's siteverify
    | service. ACTIVITY IS CONFIGURED, NOT DERIVED - development mode has no
    | bearing on it; only 'enabled' does.
    |
    | The site key is PUBLIC and ships to the browser as window.rsxapp.turnstile
    | (only when enabled). The secret key is server-only and is never exported.
    | Enabling with either key missing is a half-configured install and throws.
    | See: php artisan rsx:man turnstile
    |
    */
    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),

        // Public - rendered into the widget by the browser.
        'site_key' => env('TURNSTILE_SITE_KEY'),

        // Server-only - the siteverify credential. Never exported to the client.
        'secret_key' => env('TURNSTILE_SECRET_KEY'),

        // siteverify HTTP timeout, in seconds. A SANCTIONED timeout, owner-approved
        // 2026-08-12: expiry degrades to FAIL CLOSED (the verification is rejected
        // and the user retries), never to an accepted-without-verification pass.
        'verify_timeout_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | A Content-Security-Policy tells the browser which origins a page may load
    | code, styles, images, fonts and connections from, and refuses everything
    | else. It is the last line of defense against an injected <script>: even a
    | successful XSS has nowhere to load its payload from.
    |
    | RSpade composes ONE policy PER REALM (staff, portal) in Rsx_Csp and stamps
    | it on every dispatched HTML response. The policy is DERIVED, never
    | hand-written:
    |
    | - Framework inline scripts carry a per-request NONCE. 'unsafe-inline' never
    |   appears in script-src, in any mode.
    | - Every external host comes from a *.externals.php declaration beside the
    |   feature that needs it, so an undeclared resource is a blocked resource and
    |   policy cannot drift from code.
    | - style-src keeps 'unsafe-inline' and carries NO nonce (a nonce present makes
    |   browsers ignore 'unsafe-inline', which would break every style="" in the
    |   tree). This is deliberate, not an oversight.
    |
    | - Mirrored assets are served from this build's own /_vendor/ copy in EVERY
    |   mode, so their origins never appear in the policy at all - they collapse
    |   into 'self'. That covers a bundle's cdn_assets, mirror:true externals and
    |   the remote references inside compiled CSS (a stylesheet's @font-face
    |   files included). Only mirror:false entries (a widget that must run from
    |   its vendor's origin) stay named.
    | - A mirror:false STYLESHEET whose fonts live on another host names that
    |   host in its own declaration, `csp => ['font-src' => [...]]`. Nothing
    |   infers a font host from a stylesheet's origin.
    |
    | Per-mode behavior (RSX_MODE): only ws:// differs - it is permitted alongside
    | wss:// outside strict production, matching the realtime client's plain-http
    | downgrade.
    |
    | Each declaration's own `csp` extras (frames it opens, hosts it calls at
    | runtime) apply in EVERY mode - mirroring an asset does not change what the
    | script does once it runs.
    |
    | See: php artisan rsx:man csp
    | See: php artisan rsx:man external_resources
    |
    */
    'csp' => [
        // Emit a policy at all. False = no header on any response, anywhere.
        //
        // AN EMERGENCY KILL-SWITCH, NOT A QUIET MODE. The policy always ENFORCES:
        // there is one header name and an undeclared resource is a blocked
        // resource. Turning this off deletes the protection outright and says so;
        // it is not a way to keep reporting while blocking nothing. Every blocked
        // resource is still POSTed to /_csp-report and lands in
        // storage/logs/csp_violations.log - that log is the triage tool.
        'enabled' => true,

        // directive => [sources] merged into the composed policy.
        //
        // THIS EXISTS ONLY FOR TRANSITIVE EXTERNALS: a declared script that goes
        // on to load FURTHER scripts, from origins the loader cannot know because
        // the vendor chooses them at runtime (an analytics or tag-manager loader
        // is the classic case). Nothing else belongs here.
        //
        // A resource the PAGE loads directly belongs in a *.externals.php
        // declaration instead, where the CSP whitelist derives from it
        // automatically and the loader can fetch it by identifier.
        //
        // WIDEN-ONLY: sources are APPENDED - they can never remove or replace the
        // framework's own hardening. Downstream config deep-merges ADDITIVELY on
        // top of this, so an app's array widens the policy and never replaces it.
        // object-src is refused outright: it is set to 'none' as hardening, and
        // appending to "nothing" would loosen it while reading like an addition.
        'additional_sources' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor Mirror
    |--------------------------------------------------------------------------
    |
    | Every remote asset the application serves is mirrored to a FILE in
    | rsx/resource/.cdn-cache/ and served from /_vendor/ - the same code path in
    | every mode, so a development page and a sealed build load byte-identical
    | assets and the CSP has no external origin to whitelist.
    |
    | See: php artisan rsx:man external_resources
    |
    */

    'cdn_externals' => [
        // The User-Agent every mirror download sends - curl in Cdn_Cache and node in
        // the CSS localizer alike, so both halves of one fetch look like one client.
        //
        // WHY A MODERN BROWSER UA, and not an "RSpade/1.0" that would be honest about
        // who we are: Google Fonts and CDNs like it CONTENT-NEGOTIATE on this header.
        // A modern browser is served woff2 plus per-script unicode-range subsets; an
        // unrecognized agent is served one fat legacy TTF for the whole family. What we
        // mirror is what the browser will be handed, so we must ask the way it asks.
        //
        // CHANGING THIS CHANGES WHAT A URL MIRRORS. The store is keyed by URL, not by
        // the response, so an existing file is NOT re-fetched when this value changes -
        // run php artisan rsx:cdn_externals:refresh to re-fetch under the new agent.
        'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ],

    /*
    |--------------------------------------------------------------------------
    | Datetime Configuration
    |--------------------------------------------------------------------------
    |
    | Configure date and time handling across the application.
    | See: php artisan rsx:man time
    |
    */
    'datetime' => [
        // The server's timezone (IANA identifier). Every process still runs in UTC - PHP
        // and the MySQL session both - and this zone is consulted in three places only:
        //   - #[Schedule] wall-clock times ("daily at 2am" is 2am in this zone)
        //   - the timezone a new site record gets when none was assigned
        //   - the display tier after the user's explicit zone, the browser's zone (the rsx_tz
        //     cookie) and the site's: login_users.timezone -> rsx_tz -> sites.timezone -> this
        // Blank or absent means UTC; an unknown identifier throws.
        'server_timezone' => env('RSX_SERVER_TIMEZONE') ?: 'UTC',

        // The timezone a NEW login identity starts with when its creator set none. Applied
        // once, when the login_users row is created - never on a later save.
        //   automatic  NULL: the account follows each browser's own zone (the default)
        //   site       the creating site's timezone, COPIED as an identifier
        //   server     server_timezone above, COPIED as an identifier
        //   <IANA id>  that identifier (e.g. America/Chicago)
        // `site` and `server` are snapshots, as a new site's zone is: changing the site's or
        // the server's zone later does not move an account created before. Anything else
        // throws when a login identity is created. Application behaviour, not deployment:
        // an application changes it in rsx/resource/config/rsx.php, never in .env.
        'default_user_timezone' => 'automatic',

        // Time dropdown interval in minutes (for Schedule_Input component)
        'time_interval' => 15,

        // Default duration for new events in minutes (for Schedule_Input component)
        'default_duration' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dependency Layer (rsx:composer / rsx:npm)
    |--------------------------------------------------------------------------
    |
    | Downstream applications layer their own packages at the project root
    | (composer.json -> /vendor, package.json -> /node_modules); the framework
    | wins all overlaps. The lists below name framework-installed packages that
    | are formally exposed to application code: requiring one via rsx:composer/
    | rsx:npm records it as provided-by-framework instead of installing a
    | duplicate. Exposure is a standing commitment - breaking changes to an
    | exposed package ship with a breaking_changes document (Category 2).
    |
    | See: php artisan rsx:man dependencies
    |
    */

    'dependencies' => [
        // Framework packages formally exposed to application code. Requiring one
        // via rsx:composer/rsx:npm records it as provided-by-framework instead of
        // installing a duplicate. Exposure is a commitment: breaking changes to
        // these ship with a breaking_changes document (Category 2).
        'exposed_composer' => [
            'laravel/framework',
            'guzzlehttp/guzzle',
            'giggsey/libphonenumber-for-php',
            'ezyang/htmlpurifier',
            // Markdown -> HTML. Markdown_Rendition::render() is the framework's own
            // sanitised call (CommonMark + GFM, then HTMLPurifier); exposed because an
            // application that renders markdown of its own must use this parser rather
            // than installing a second one beside it.
            'league/commonmark',
            'sokil/php-isocodes',
            'nikic/php-parser',
            'phpoffice/phpspreadsheet',
            'league/csv',
            'brick/money',
            // The SSO engine. Rsx_Sso wraps both entirely, so an application never
            // calls Socialite - they are exposed for the EXTENSION SEAM: a downstream
            // provider package (socialiteproviders/okta and friends) depends on
            // socialiteproviders/manager and would otherwise install a second copy of
            // it and of laravel/socialite alongside the framework's.
            'laravel/socialite',
            'socialiteproviders/manager',
        ],
        'exposed_npm' => [
            'dompurify',
            'google-libphonenumber',
        ],
    ],
];
