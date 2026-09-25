<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Environment Symlink Guard
|--------------------------------------------------------------------------
|
| system/.env is framework space and is always a symlink to the project-root
| .env. A real file there SHADOWS the root one, and the application then serves
| every request against configuration nobody wrote - silently, because it boots
| and answers perfectly well while ignoring the operator's settings.
|
| Runs first, before the IDE endpoints and before Laravel: everything after this
| point may read configuration. The healthy path is two syscalls and no writes.
|
*/

/*
|--------------------------------------------------------------------------
| Path Resolver
|--------------------------------------------------------------------------
|
| The three volatile roots - build/, tmp/, storage/ - and the state directory
| inside storage. Required FIRST, before any guard, because every guard below
| addresses one of them, and exported into the environment so every spawned
| child resolves the same absolutes instead of re-deriving them. The export also
| sets TMPDIR, which PHP answers sys_get_temp_dir() with and caches for the life
| of the process, so nothing may run before it.
|
| Then the writability check: storage/ and tmp/ in every mode, plus build/,
| system/ and the project root wherever something is expected to write them.
|
*/

require_once __DIR__ . '/../bootstrap/rsx_paths.php';
require_once __DIR__ . '/../bootstrap/rsx_preboot_page.php';
rsx_paths_export();
rsx_paths_assert_trees_writable();

require __DIR__ . '/../bootstrap/rsx_env_link.php';

/*
|--------------------------------------------------------------------------
| Build Link Guard
|--------------------------------------------------------------------------
|
| system/build must be a symlink to ../build - the project-root tree holding the
| build outputs. system/ is a submodule and is replaced wholesale on update, so
| nothing volatile can live inside it. The link is repaired silently; the target
| is never created here, because a missing build must fail loud naming the build
| command. tmp/ and storage/ have no link: they are relocatable, and code reaches
| them through the path owner.
|
*/

require __DIR__ . '/../bootstrap/rsx_storage_link.php';

/*
|--------------------------------------------------------------------------
| Submodule Sync Guard
|--------------------------------------------------------------------------
|
| system/ is a git submodule, and a plain `git pull` moves the RECORDED revision
| without checking the submodule out - leaving the application serving a
| framework version the project never recorded, with nothing to indicate it.
| Refuse with a 503 before any of that framework code loads.
|
| Placed BEFORE the first-run screen deliberately: a wrong-version framework must
| not get as far as offering to configure the application.
|
| Silent when system/ is not a submodule, and silent when it cannot tell.
|
*/

require __DIR__ . '/../bootstrap/rsx_submodule_sync.php';

/*
|--------------------------------------------------------------------------
| Environment Heal
|--------------------------------------------------------------------------
|
| Creates the root .env from the TRACKED project-root .env.dist when it is
| absent, and in development keeps it in step with the keys .env.dist declares.
| ONE implementation, shared with `php artisan rsx:env:heal` and the container
| entrypoint: App\RSpade\Core\Prod\Rsx_Env_Symlink::full_heal().
|
| Before the first-run screen, which reads APP_URL out of the .env this may have
| just created. In development it runs on EVERY request and costs one stat when
| nothing has changed (see bootstrap/rsx_env_heal.php).
|
*/

require __DIR__ . '/../bootstrap/rsx_env_heal.php';

/*
|--------------------------------------------------------------------------
| First-Run Setup
|--------------------------------------------------------------------------
|
| With APP_URL still empty, offer to set it from the address this request
| arrived on - the one thing a container cannot work out for itself, and the one
| thing the browser knows for certain. Development mode only, and unreachable
| the moment APP_URL has a value.
|
*/

require __DIR__ . '/../bootstrap/rsx_first_run.php';

/*
|--------------------------------------------------------------------------
| IDE Service Endpoints (Must be before maintenance check)
|--------------------------------------------------------------------------
|
| Handle IDE service requests that bypass Laravel for performance.
| These provide fast responses for IDE integration features.
|
*/

$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Handle IDE service endpoints
if (str_starts_with($request_path, '/_ide/service')) {
    // SECURITY-CRITICAL: Authenticate FIRST before any service logic
    // This checks session auth OR localhost bypass before proceeding
    require_once __DIR__ . '/../app/RSpade/Ide/Services/auth.php';

    // If we reach here, authentication passed (auth.php exits on failure)

    // SECURITY: Explicit whitelist only - handlers must be explicitly defined here.
    // User input (service name) determines WHICH handler, but cannot inject arbitrary paths.
    // TODO: Improve the design of this subsystem invocation later.

    // Extract service name
    $service_name = str_replace('/_ide/service', '', $request_path);
    $service_name = trim($service_name, '/');

    // Whitelist of allowed handlers
    $allowed_handlers = [
        'format' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'definition' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'complete' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'resolve_class' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'git' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'git/diff' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        'refactor' => __DIR__ . '/../app/RSpade/Ide/Services/handler.php',
        // All other services use the Laravel handler
        'default' => __DIR__ . '/../app/RSpade/Ide/Services/laravel_handler.php',
    ];

    // Determine which handler to use
    if (isset($allowed_handlers[$service_name])) {
        $handler_path = $allowed_handlers[$service_name];
    } else {
        // Services not explicitly listed use the Laravel handler
        $handler_path = $allowed_handlers['default'];
    }

    // Execute the whitelisted handler
    if (file_exists($handler_path)) {
        require_once $handler_path;
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| State Root (pre-boot)
|--------------------------------------------------------------------------
|
| storage/state holds the small facts about the environment's own lifetime -
| the maintenance flag, the flock files, the updater's ledger. Everything below
| runs BEFORE Laravel boots, so the answer comes from the pre-boot resolver
| required at the top of this file, which is the same answer
| App\RSpade\Core\Paths\Rsx_Project_Paths gives a booted process.
|
*/

$__rsx_state = rsx_paths_state_root();


/*
|--------------------------------------------------------------------------
| Maintenance Gate
|--------------------------------------------------------------------------
|
| While maintenance mode is up (rsx:maintenance:enable, or the window
| rsx:framework:pull holds for an update) every web request returns 503 - the
| IDE bridge above is intentionally exempt. The flag's CONTENT is the operator
| reason and is printed with the 503. RSPADE_MAINT_MODE is the per-process
| snapshot every consumer reads (see system/artisan for the full contract);
| both entrypoints define it unconditionally. Raw + autoload-free so a
| half-synced vendor/ still answers cleanly. Path mirrors
| App\RSpade\Core\Framework\Framework_Maintenance::FLAG_RELATIVE.
|
*/

$__rsx_maint_flag = $__rsx_state . '/.maintenance.mode.framework.update';
define('RSPADE_MAINT_MODE', file_exists($__rsx_maint_flag));

if (RSPADE_MAINT_MODE) {
    // The flag is TWO LINES: line 1 = the operator reason, line 2 = "mode=<app mode>"
    // stamped by the writer. See App\RSpade\Core\Framework\Framework_Maintenance - this
    // runs pre-autoload and cannot call it, so the format is replicated literally.
    $__rsx_maint_lines = preg_split('/\R/', (string) @file_get_contents($__rsx_maint_flag));
    $__rsx_maint_reason = trim($__rsx_maint_lines[0] ?? '');
    if ($__rsx_maint_reason === '') {
        $__rsx_maint_reason = 'maintenance mode';
    }

    $__rsx_maint_stamp = trim($__rsx_maint_lines[1] ?? '');
    $__rsx_maint_mode = str_starts_with($__rsx_maint_stamp, 'mode=')
        ? trim(substr($__rsx_maint_stamp, 5))
        : '';

    $__rsx_maint_page_lines = [
        "RSpade is in maintenance mode ({$__rsx_maint_reason}). Please retry shortly.",
    ];

    // "Retry shortly" is WRONG ADVICE for a stale flag - an interrupted update, a killed
    // maintenance script - where no retry will ever succeed. On a non-production box, name
    // the one command that fixes it. Production says nothing extra, and a flag carrying no
    // stamp at all is treated as production: absence resolves to disclosing nothing. The
    // stamp itself is machinery and never reaches the page.
    if ($__rsx_maint_mode !== '' && $__rsx_maint_mode !== 'production') {
        $__rsx_maint_page_lines[] = 'If this persists, an interrupted update may have left the flag behind. Clear it with:';
        $__rsx_maint_page_lines[] = 'php artisan rsx:maintenance:disable';
    }

    rsx_preboot_page_render(
        503,
        'Maintenance in progress',
        $__rsx_maint_page_lines,
        ['Retry-After' => '120']
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Migration Gate
|--------------------------------------------------------------------------
|
| While `migrate` runs against a datadir snapshot it raises storage/state/.migrating
| (App\RSpade\Core\Paths\Rsx_Project_Paths::migrating_flag_file(); its CONTENT is
| JSON carrying started_at). Every web request answers 503 until the run clears it,
| so no request reads or writes a database that may be rolled back underneath it. The
| same pre-boot shape as the maintenance gate: one stat, autoload-free, and the IDE
| bridge above is exempt. A flag left behind by an interrupted run is cleared by
| `php artisan migrate:restore` (or by the next migrate).
|
*/

$__rsx_migrating_flag = $__rsx_state . '/.migrating';

if (file_exists($__rsx_migrating_flag)) {
    $__rsx_migrating_info = json_decode((string) @file_get_contents($__rsx_migrating_flag), true);
    $__rsx_migrating_started = is_array($__rsx_migrating_info) ? (string) ($__rsx_migrating_info['started_at'] ?? '') : '';

    rsx_preboot_page_render(
        503,
        'Database migration in progress',
        [
            'A database migration is running. The application is unavailable until it completes.'
                . ($__rsx_migrating_started !== '' ? " Started at {$__rsx_migrating_started}." : ''),
            'If this persists, the migration may have been interrupted: see the terminal that ran php artisan migrate.',
        ],
        ['Retry-After' => '30']
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Initialize RSpade Framework
|--------------------------------------------------------------------------
|
| Acquire the global application read lock before anything else happens.
| This ensures proper coordination between processes for operations like
| manifest rebuilding. This MUST happen before the manifest loads.
|
*/

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

// HTTP method override is OFF: a request's method is the verb on the wire. Symfony
// otherwise lets a POST call itself PUT/DELETE through a _method field or an
// X-HTTP-Method-Override header, and RSpade routes only GET and POST - an override
// buys nothing and turned a cross-site POST into a "PUT" that skipped the CSRF check.
// An empty allow-list disables the header and the field alike.
Request::setAllowedHttpMethodOverride([]);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
