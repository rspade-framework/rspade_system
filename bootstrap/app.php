<?php

/*
|--------------------------------------------------------------------------
| Block Laravel VSCode Extension Discovery Scripts
|--------------------------------------------------------------------------
|
| The official Laravel VSCode extension creates discovery scripts in
| vendor/_laravel_ide/ which bootstrap the application. We block these
| as they conflict with RSpade framework's initialization.
|
*/

if (php_sapi_name() === 'cli') {
    $script = $_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    if (strpos($script, 'vendor/_laravel_ide/') !== false ||
        strpos($script, 'vendor\\_laravel_ide\\') !== false) {
        exit(0); // Exit silently
    }
}

/*
|--------------------------------------------------------------------------
| Base Path
|--------------------------------------------------------------------------
|
| The container gate is NOT here. Development's "artisan runs inside the
| RSpade container or not at all" rule lives in bootstrap/rsx_container_gate.php
| and is decided by the /.rspade_container MARKER FILE the framework's own
| Dockerfile writes - not by guessing from the base path and the uid.
|
*/

$base_path = realpath($_ENV['APP_BASE_PATH'] ?? dirname(__DIR__));

// App-layer composer autoloader (project root). Downstream applications may
// install their own composer packages at the project root (rsx:composer);
// chained AFTER the framework's own autoloader so framework packages always
// win class-name collisions. Absent file = app has no dependencies of its own.
$app_layer_autoload = dirname($base_path) . '/vendor/autoload.php';
if (file_exists($app_layer_autoload)) {
    require $app_layer_autoload;
}

// Check for spaces in base path (critical issue for any environment)
if (str_contains($base_path, ' ')) {
    $error_message = "CRITICAL ERROR: The application base path contains spaces.\n\n";
    $error_message .= "Current path: {$base_path}\n\n";
    $error_message .= "Spaces in file paths cause numerous issues:\n";
    $error_message .= "- Shell commands break without proper quoting\n";
    $error_message .= "- Git operations may fail\n";
    $error_message .= "- Build tools and npm scripts often malfunction\n";
    $error_message .= "- URLs and file references become problematic\n\n";
    $error_message .= "Solution: Move the project to a path without spaces.\n";
    $error_message .= "Example: /var/www/html or /home/user/projects/rspade\n";

    $sapi = php_sapi_name();
    $is_cli = ($sapi === 'cli' || $sapi === 'cli-server');

    if ($is_cli) {
        fwrite(STDERR, $error_message);
        exit(1);
    }
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: text/plain');
    echo $error_message;
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/
// The pre-boot path resolver. Both entrypoints require it before anything else, but
// a test harness or a tool that boots the application directly may not have, and every
// path below depends on it.
require_once __DIR__ . '/rsx_paths.php';

// Rsx_Application is Laravel's container taught where the build tree is: the five
// cached artifacts (config, routes, events, services, packages) are build OUTPUTS and
// land in build/laravel, not inside the framework checkout. The class is required
// directly because the autoloader's map is not consulted for it this early.
require_once __DIR__ . '/../app/RSpade/Core/Paths/Rsx_Project_Paths.php';
require_once __DIR__ . '/../app/RSpade/Core/Laravel/Rsx_Application.php';

/** @phpstan-ignore-next-line */
$app = new App\RSpade\Core\Laravel\Rsx_Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

// LARAVEL'S STORAGE PATH IS THE TMP TREE, and that is a deliberate reading of what
// Laravel keeps there: framework caches, compiled views, package scratch, session and
// temp files - derived state, all of it regenerable. Pointing useStoragePath() at tmp/
// puts every package and every helper that assumes storage_path() into a disposable
// tree by construction, instead of leaving each one to be discovered and repointed.
//
// The persistent exceptions are named rather than assumed: config/logging.php pins the
// log channels to <storage>/logs, config/filesystems.php pins the local disk to
// <storage>/app, and ensure_tmp_tree() keeps tmp/logs and tmp/app as symlinks onto
// those two directories - so even a lazy storage_path('logs') lands in persistent
// storage. Durable application files go through a Storage disk or through
// Rsx_Project_Paths.
//
// ONE SPELLING. The resolver answers here exactly as it answers the pre-boot guards
// and Rsx_Project_Paths. Two spellings of one path is how the same file ends up under
// two different manifest keys.
$app->useStoragePath(rsx_paths_tmp_root());

// RSpade env: the project-root .env is the ONE authoritative environment file, so
// Laravel is pointed straight at it rather than at base_path('.env'). Same bytes
// either way - the system/.env symlink already resolved there - but now
// environmentPath() is the project root and environmentFilePath() is the root .env,
// which is what env:encrypt (writes beside environmentFilePath()), env:decrypt (see
// App\RSpade\Commands\Rsx\Env_Decrypt_Command) and key:generate all address. Without
// this, those three write inside system/ - a git submodule the framework pull cleans.
//
// The system/.env symlink is NOT retired: bash readers (the container entrypoint, the
// pull script) and Rsx_Env_Symlink::heal() still address that path, and the invariant
// keeps the two spellings one file.
$app->useEnvironmentPath(dirname(__DIR__, 2));

/*
|--------------------------------------------------------------------------
| Resolve APP_URL ($HOSTNAME token)
|--------------------------------------------------------------------------
|
| APP_URL is the single source of the application hostname. afterLoadingEnvironment
| runs after phpdotenv loads .env and BEFORE LoadConfiguration reads env('APP_URL'),
| so substituting the $HOSTNAME token (-> OS hostname) here means every consumer -
| config('app.url'), url(), filesystem disks, OAuth redirects - sees the resolved
| value. https enforcement is NOT here: it is deferred to
| Rsx_Framework_Provider::boot() so the throw renders readably (HandleExceptions
| has not bootstrapped yet at this phase). The composer autoloader is required by
| artisan/index.php before this file, so the class resolves. See
| App\RSpade\Core\Env\Rsx_App_Url.
|
*/

$app->afterLoadingEnvironment(function () {
    App\RSpade\Core\Env\Rsx_App_Url::patch_environment();
});

/*
|--------------------------------------------------------------------------
| Ensure The Volatile Directory Skeletons Exist
|--------------------------------------------------------------------------
|
| storage/ holds user data and process state; tmp/ holds derived caches and
| runtime temp. Both are created on demand because both are regenerable and an
| absent one is simply a fresh checkout.
|
| build/ is NOT created here, and that is the point. It holds build outputs, it
| is read-only to the web user on a correctly configured production box, and a
| missing artifact there must fail loud naming the build command rather than
| quietly growing an empty tree for a request to find nothing in.
|
*/

App\RSpade\Core\Paths\Rsx_Project_Paths::ensure_storage_tree();
App\RSpade\Core\Paths\Rsx_Project_Paths::ensure_tmp_tree();

// The ONE exception, and it is Laravel's: PackageManifest WRITES its packages.php
// during boot whenever that file is missing, and throws "The .../build/laravel
// directory must be present and writable" when the directory is not there. That
// happens before any command runs, so on a fresh development checkout it refuses the
// very build that would create the tree. Development therefore makes this single
// directory on demand, as it makes storage/ and tmp/.
//
// A production-like mode still creates nothing: there the whole build tree - this
// directory included - is the build's to produce, and its absence must stay loud.
require_once __DIR__ . '/rsx_mode.php';

if (rsx_preboot_mode() === 'development') {
    $rsx_laravel_cache_dir = App\RSpade\Core\Paths\Rsx_Project_Paths::laravel_cache_dir();

    if (!is_dir($rsx_laravel_cache_dir)) {
        @mkdir($rsx_laravel_cache_dir, 0775, true);
    }
}

// Laravel's package and service-provider caches (packages.php, services.php) list provider
// CLASSES from the vendor tree they were written against. A framework update that removes
// a package leaves them naming a class that no longer exists, and the very next boot - the
// update's own rebuild included - dies with "Class ... not found" before any command runs.
// Composer rewrites vendor/composer/installed.json on every install, so a cache OLDER than
// it was written against a different vendor tree. Development and the build discard such a
// cache (Laravel rewrites it on this boot); any other process in a production-like mode
// refuses, because there the build tree is the build's to write.
$rsx_installed_json = __DIR__ . '/../vendor/composer/installed.json';
$rsx_laravel_caches = [
    App\RSpade\Core\Paths\Rsx_Project_Paths::laravel_cache_dir() . '/packages.php',
    App\RSpade\Core\Paths\Rsx_Project_Paths::laravel_cache_dir() . '/services.php',
];

foreach ($rsx_laravel_caches as $rsx_laravel_cache) {
    if (!is_file($rsx_laravel_cache) || filemtime($rsx_laravel_cache) >= filemtime($rsx_installed_json)) {
        continue;
    }

    $rsx_may_rewrite = rsx_preboot_mode() === 'development'
        || (PHP_SAPI === 'cli' && !defined('RSX_SCRIPT_MODE') && ($_SERVER['argv'][1] ?? '') === 'rsx:build');

    if (!$rsx_may_rewrite) {
        rsx_paths_fail([
            "Laravel's provider cache predates the installed vendor tree: {$rsx_laravel_cache}",
            '',
            '  It may name a package this framework release no longer ships. Rebuild:',
            '      php artisan rsx:build --force',
        ]);
    }

    foreach ($rsx_laravel_caches as $rsx_stale_cache) {
        if (is_file($rsx_stale_cache) && !unlink($rsx_stale_cache)) {
            rsx_paths_fail(["Could not discard the stale Laravel cache: {$rsx_stale_cache}"]);
        }
    }

    break;
}

// Keep the contents of the three user-data directories out of git without ignoring
// the directories themselves, so a fresh checkout has the shape a running app needs.
$gitignoreContent = "*\n!.gitignore\n";

$rsx_gitignore_dirs = [
    App\RSpade\Core\Paths\Rsx_Project_Paths::app_dir('public'),
    App\RSpade\Core\Paths\Rsx_Project_Paths::app_dir('temp'),
    App\RSpade\Core\Paths\Rsx_Project_Paths::logs_dir(),
];

foreach ($rsx_gitignore_dirs as $rsx_gitignore_dir) {
    $gitignorePath = $rsx_gitignore_dir . '/.gitignore';
    if (!file_exists($gitignorePath)) {
        @file_put_contents($gitignorePath, $gitignoreContent);
    }
}

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    /** @phpstan-ignore-next-line */
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    /** @phpstan-ignore-next-line */
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| RSX Exception Handler (DO NOT MODIFY)
|--------------------------------------------------------------------------
|
| This registers the RSX framework's extensible exception handler system.
| DO NOT modify this registration - it should remain pointed at
| Rsx_ExceptionHandler in all RSX projects.
|
| TO ADD CUSTOM EXCEPTION HANDLERS:
| Register them in config/rsx.php under 'exception_handlers' array.
|
| EXAMPLE:
| 'exception_handlers' => [
|     \App\MyApp\My_Custom_ExceptionHandler::class,  // Your handler
|     \App\RSpade\Core\Exceptions\Cli_ExceptionHandler::class,
|     // ... framework handlers
| ]
|
| FOR MORE INFORMATION:
| - Base class: app/RSpade/Core/Exceptions/Rsx_Exception_Handler_Abstract.php
| - Main handler: app/RSpade/Core/Exceptions/Rsx_Exception_Handler.php
| - Config: config/rsx.php (search for 'exception_handlers')
| - Example handlers:
|   - app/RSpade/Core/Exceptions/Cli_Exception_Handler.php
|   - app/RSpade/Core/Exceptions/Ajax_Exception_Handler.php
|   - app/RSpade/Core/Exceptions/Api_Exception_Handler.php
|   - app/RSpade/Core/Debug/Playwright_Exception_Handler.php
|   - app/RSpade/Core/Exceptions/Web_Exception_Handler.php
|
| A handler FORMATS a failure; none dispatches. RSX dispatch has one entry point,
| Rsx_Front_Controller, which the HTTP kernel calls in place of Laravel's router.
|
*/

$app->singleton(
    /** @phpstan-ignore-next-line */
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\RSpade\Core\Exceptions\Rsx_Exception_Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
