<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT PATH RESOLVER
 *
 * RSpade keeps three volatile trees at the project root, each with one job:
 *
 *   <project>/build     build OUTPUTS - the manifest index, compiled bundles, the
 *                       Laravel route/event/package caches, compiled Blade, the seal.
 *                       On a correctly configured production box this tree is
 *                       read-only to the web user.
 *   <project>/tmp       DERIVED caches and runtime temp - per-file transform caches,
 *                       JS stubs consumed while compiling, sockets, scratch files,
 *                       thumbnails and document renditions. Deletable at any moment;
 *                       regenerated on demand. It is also what Laravel calls its
 *                       storage path, and what TMPDIR points at.
 *   <project>/storage   USER data - uploads, logs, storage/app - plus storage/state,
 *                       the small tree holding process-lifetime state (the maintenance
 *                       flag, flock files, the updater's ledger).
 *
 * build/ is FIXED at <project>/build and is not relocatable: the seal, the manifest
 * index and the build key live inside it, and it has to sit beside system/ and rsx/
 * to be made read-only with them on a production box.
 *
 * tmp/ and storage/ are overridable in the environment with RSX_TMP_PATH and
 * RSX_STORAGE_PATH, each taking an absolute path or one relative to the PROJECT ROOT.
 * storage/state is NOT independently overridable - it follows the storage root,
 * because the locks inside it must be the same files a parent process and its
 * children both open.
 *
 * WHY PRE-BOOT. Both entrypoints, the storage/build/tmp link guard, the env heal and
 * the container gate all need these answers before Laravel exists, and the answer a
 * booted process gets must be byte-identical to the answer a pre-boot guard got -
 * otherwise the same file lands under two spellings. So the resolution lives here,
 * in plain functions with no autoloader and no config, and the booted-world owner
 * (App\RSpade\Core\Paths\Rsx_Project_Paths) delegates to it.
 *
 * Reading order for an override matches phpdotenv's immutable behaviour: a non-empty
 * real environment variable wins, otherwise the FIRST matching line of the project
 * root .env. That is what the booted framework will see once phpdotenv has run, so
 * pre-boot and booted code cannot disagree.
 *
 * REQUIRED WITH require_once, ALWAYS. Six entrypoints and guards reach this file and
 * any of them may be first; defining the functions conditionally instead would be a
 * second answer to "are these loaded" that could disagree with PHP's own.
 */

/**
 * The project root - the directory holding system/, rsx/, build/, tmp/, storage/.
 *
 * RSX_PATHS_PROJECT_ROOT is a test seam: a cli test points it at a synthetic tree
 * to exercise resolution without touching the real one.
 */
function rsx_paths_project_root(): string
{
    static $root = null;

    if ($root !== null) {
        return $root;
    }

    $override = getenv('RSX_PATHS_PROJECT_ROOT');

    if (is_string($override) && trim($override) !== '') {
        return $root = rtrim(trim($override), '/');
    }

    return $root = dirname(__DIR__, 2);
}

/**
 * The framework tree - Laravel's base path.
 */
function rsx_paths_system_dir(): string
{
    return rsx_paths_project_root() . '/system';
}

/**
 * Read one key from the environment, falling back to the project-root .env.
 *
 * Deliberately minimal: the FIRST occurrence wins, matching the parser that reads
 * this file later, and only a matched pair of surrounding quotes is unwrapped. It
 * is not a general .env parser and must not grow into one.
 */
function rsx_paths_env_value(string $key): string
{
    $live = getenv($key);

    if (is_string($live) && trim($live) !== '') {
        return trim($live);
    }

    $path = rsx_paths_project_root() . '/.env';

    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $lines = preg_split('/\R/', (string) @file_get_contents($path));

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $equals = strpos($line, '=');

        if ($equals === false || trim(substr($line, 0, $equals)) !== $key) {
            continue;
        }

        $value = trim(substr($line, $equals + 1));

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return $value;
    }

    return '';
}

/**
 * Resolve one root: the override when set, otherwise <project>/<default>.
 *
 * A relative override is resolved against the PROJECT ROOT rather than against an
 * unknowable working directory - a child process spawned from anywhere must land on
 * the same tree as its parent.
 */
function rsx_paths_resolve_root(string $key, string $default_name): string
{
    static $resolved = [];

    if (isset($resolved[$key])) {
        return $resolved[$key];
    }

    $value = rsx_paths_env_value($key);

    if ($value === '') {
        return $resolved[$key] = rsx_paths_project_root() . '/' . $default_name;
    }

    // A relative value is relative to the PROJECT ROOT - never to the working directory,
    // which a spawned subprocess does not choose, and never to Laravel's base path one
    // level down. './build' and 'build' both mean <project>/build.
    if ($value[0] !== '/') {
        $value = rsx_paths_project_root() . '/' . preg_replace('#^\./#', '', $value);
    }

    return $resolved[$key] = rtrim($value, '/');
}

/**
 * Build OUTPUTS. Never created by boot - the build and rsx:clean create it.
 *
 * FIXED, with no environment key. Everything the seal pins lives here, and the tree
 * is read-only beside system/ and rsx/ on a production box; a relocation would move
 * the artifact away from the two trees it is deployed with.
 */
function rsx_paths_build_root(): string
{
    return rsx_paths_project_root() . '/build';
}

/**
 * DERIVED caches and runtime temp.
 */
function rsx_paths_tmp_root(): string
{
    return rsx_paths_resolve_root('RSX_TMP_PATH', 'tmp');
}

/**
 * USER data.
 */
function rsx_paths_storage_root(): string
{
    return rsx_paths_resolve_root('RSX_STORAGE_PATH', 'storage');
}

/**
 * Process-lifetime state, inside storage and deliberately not separately
 * overridable: a lock file only excludes when every process opens the same one.
 */
function rsx_paths_state_root(): string
{
    return rsx_paths_storage_root() . '/state';
}

/**
 * The relocatable roots, keyed by their environment variable name.
 *
 * build/ is absent because it has no key: it is derived from the project root and a
 * child derives it identically.
 */
function rsx_paths_all(): array
{
    return [
        'RSX_TMP_PATH'     => rsx_paths_tmp_root(),
        'RSX_STORAGE_PATH' => rsx_paths_storage_root(),
    ];
}

/**
 * Publish the RESOLVED roots into the process environment.
 *
 * Every spawned child - artisan subprocess, bash script, node daemon - then reads
 * the same absolute paths its parent resolved, instead of re-deriving them from a
 * working directory or a .env it may not be able to see.
 *
 * TMPDIR IS PART OF THE ANSWER. A plain composer library writes its scratch files
 * through PHP's sys_get_temp_dir(), which reads the sys_temp_dir ini setting, then
 * TMPDIR, then falls back to /tmp. Pointing TMPDIR at the project's own tmp tree puts
 * that scratch where rsx:clean can reach it and where a container's disposable layer
 * is not. PHP caches sys_get_temp_dir() for the life of the process, so this must run
 * before anything asks - which is why both entrypoints call this on their first lines.
 */
function rsx_paths_export(): void
{
    $exports = rsx_paths_all();
    $exports['TMPDIR'] = rsx_paths_tmp_root();

    foreach ($exports as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

/**
 * The trees this process must be able to write, checked before anything uses them.
 *
 * storage/ and tmp/ are required in EVERY mode: a box that cannot write them cannot
 * accept an upload, compile a template or take a lock, and every symptom of that
 * appears somewhere far from the cause. Both are created when simply absent, because
 * a fresh checkout has neither and both are the framework's to make.
 *
 * build/, system/ and the project root are checked only where something is expected
 * to WRITE them: in development, where the build is rebuilt on demand, and in a
 * production mode when this process IS the build. A sealed production runtime wants
 * exactly the opposite posture - those three read-only to the web user - so checking
 * them there would report the correct deployment as broken.
 *
 * Top-level directories only. This is a boot-time sanity check, not a permissions
 * audit, and a per-file walk of a source tree on every request is not one either.
 */
function rsx_paths_assert_trees_writable(): void
{
    $user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
        ? ((posix_getpwuid(posix_geteuid())['name'] ?? '') ?: ('uid ' . posix_geteuid()))
        : 'the current user';

    foreach (['storage' => rsx_paths_storage_root(), 'tmp' => rsx_paths_tmp_root()] as $name => $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir)) {
            rsx_paths_fail([
                "The {$name} directory does not exist and could not be created: {$dir}",
                '',
                "  RSpade writes there in every mode, as {$user}.",
                '',
                '      mkdir -p ' . escapeshellarg($dir),
                '      chown -R ' . escapeshellarg($user) . ' ' . escapeshellarg($dir),
                '',
                '  A relocated root is the other possibility: RSX_' . strtoupper($name) . '_PATH in .env',
                '  names this directory, and an absolute or project-root-relative value is expected.',
                '',
            ]);
        }

        if (!is_writable($dir)) {
            rsx_paths_fail([
                "The {$name} directory is not writable: {$dir}",
                '',
                "  RSpade writes there in every mode, as {$user}.",
                '',
                '      chown -R ' . escapeshellarg($user) . ' ' . escapeshellarg($dir),
                '      chmod -R u+rwX ' . escapeshellarg($dir),
                '',
            ]);
        }
    }

    $mode = rsx_paths_env_value('RSX_MODE');
    $is_development = ($mode === '' || $mode === 'development');
    // RSX_SCRIPT_MODE (system/script.php) says argv[1] is the script's own argument and
    // not a command name. Read as the raw constant: this runs pre-boot, with no
    // autoloader for App\RSpade\Core\Console\Rsx_Script.
    $is_build = PHP_SAPI === 'cli'
        && !defined('RSX_SCRIPT_MODE')
        && isset($_SERVER['argv'][1])
        && $_SERVER['argv'][1] === 'rsx:build';

    if (!$is_development && !$is_build) {
        return;
    }

    $writers = [
        'the project root' => rsx_paths_project_root(),
        'the framework tree' => rsx_paths_system_dir(),
        'the build tree' => rsx_paths_build_root(),
    ];

    foreach ($writers as $label => $dir) {
        // An absent build tree is a normal state - the build makes it, and the project
        // root check above is what says whether it can.
        if (!is_dir($dir) || is_writable($dir)) {
            continue;
        }

        rsx_paths_fail([
            ucfirst($label) . " is not writable: {$dir}",
            '',
            '  ' . ($is_build
                ? 'A build writes the manifest, the bundles and the seal, so it needs this tree writable.'
                : 'Development rebuilds the manifest and the bundles on demand, so it needs this tree writable.'),
            "  The current user is {$user}.",
            '',
            '      chown -R ' . escapeshellarg($user) . ' ' . escapeshellarg($dir),
            '      chmod -R u+rwX ' . escapeshellarg($dir),
            '',
            '  A production RUNTIME is meant to have these read-only; only a build and',
            '  development mode write them. See: php artisan rsx:man prod',
            '',
        ]);
    }
}

/**
 * Refuse, on whichever channel is listening.
 *
 * @return never
 */
function rsx_paths_fail(array $lines)
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "\n[ERROR] RSpade path configuration is wrong.\n\n");
        foreach ($lines as $line) {
            fwrite(STDERR, $line . "\n");
        }
        exit(1);
    }

    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 60');
    }

    echo "503 - RSpade path configuration is wrong\n\n";
    foreach ($lines as $line) {
        echo $line . "\n";
    }
    exit(1);
}
