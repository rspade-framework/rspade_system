<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Paths;

use App\RSpade\Core\Console\Rsx_Internal_Flags;

/**
 * THE owner of every volatile on-disk location the framework writes.
 *
 * RSpade keeps three trees at the project root, and which tree a file belongs in is a
 * statement about its lifetime and its production posture:
 *
 *   build/     Build OUTPUTS. Produced by one command, consumed by the served site,
 *              read-only to the web user on a correctly configured production box:
 *              the manifest index, compiled bundles, the Laravel route/event/package
 *              caches, compiled Blade, the seal.
 *   tmp/       DERIVED caches and runtime temp. Deletable at any moment with no loss:
 *              per-file transform caches, the JS stubs a bundle compile reads, node
 *              sockets, scratch files, thumbnails and document renditions, the test
 *              runner's working directories. It is also Laravel's storage path and
 *              what TMPDIR points at, so every framework cache and every plain
 *              library's scratch file lands in it by construction.
 *   storage/   USER data - uploads, logs, storage/app - plus storage/state, the small
 *              tree holding process-lifetime state (the maintenance flag, flock files,
 *              the framework updater's ledger).
 *
 * Every framework path under those three trees is spelled here, once, as a named
 * method. A literal directory name anywhere else is a PATH-OWNER-01 violation: tmp/
 * and storage/ are relocatable (RSX_TMP_PATH / RSX_STORAGE_PATH), and a literal that a
 * relocation leaves behind does not error - it silently addresses a directory nothing
 * else uses. build/ is fixed at <project>/build, so that it is deployed and made
 * read-only together with system/ and rsx/.
 *
 * PRE-BOOT USABLE. Resolution lives in the plain functions of
 * system/bootstrap/rsx_paths.php, which this class requires and delegates to, so a
 * guard running before Laravel and a booted consumer get byte-identical answers.
 * Nothing here reads config().
 *
 * MANIFEST KEYS ARE LOGICAL. key_for() reduces an absolute path under any resolved
 * root to the spelling `build/...`, `tmp/...` or `storage/...`, and absolute_for()
 * inverts it. Two boxes with different overrides therefore produce identical manifest
 * indexes and identical build keys, which is the determinism the seal rests on.
 */
class Rsx_Project_Paths
{
    /** Stub kinds - the three generated-JS directories a bundle compile reads. */
    public const STUBS_CONTROLLER = 'js-stubs';
    public const STUBS_MODEL = 'js-model-stubs';
    public const STUBS_AUTH = 'js-auth-stubs';

    /**
     * In-process root overrides, by kind ('build' | 'tmp' | 'files').
     *
     * @var array<string,string>
     */
    private static array $overrides = [];

    // -----------------------------------------------------------------------
    // Roots
    // -----------------------------------------------------------------------

    /**
     * Build OUTPUTS. Never created by boot - only ensure_build_tree() makes it.
     *
     * FIXED at <project>/build, with no environment key: everything the seal pins
     * lives here, and the tree is deployed read-only beside system/ and rsx/. The
     * internal --_rsx-build-root flag still redirects it for a FIXTURE build, which
     * is a test of the build itself and not a deployment posture.
     */
    public static function build_root(): string
    {
        return self::__root('build', '--_rsx-build-root', 'rsx_paths_build_root');
    }

    /**
     * DERIVED caches and runtime temp.
     */
    public static function tmp_root(): string
    {
        return self::__root('tmp', '--_rsx-tmp-root', 'rsx_paths_tmp_root');
    }

    /**
     * USER data.
     *
     * Deliberately NOT overridable per process: a subprocess must share storage/state
     * with its parent, or the flock files they open stop excluding each other. A test
     * that needs the FILE subsystem redirected redirects files_root() instead.
     */
    public static function storage_root(): string
    {
        self::__require_resolver();

        return rsx_paths_storage_root();
    }

    /**
     * Process-lifetime state, inside storage.
     */
    public static function state_root(): string
    {
        self::__require_resolver();

        return rsx_paths_state_root();
    }

    /**
     * Root of the FILE subsystem (blobs, thumbnails, renditions).
     *
     * Follows the storage root unless a test redirects it, which is the one isolation
     * a test run needs: a test-database attachment delete must never unlink a blob the
     * developer's database shares.
     */
    public static function files_root(): string
    {
        return self::__root('files', '--_rsx-files-root', 'rsx_paths_storage_root');
    }

    // -----------------------------------------------------------------------
    // Joiners
    // -----------------------------------------------------------------------

    public static function build_path(string $relative = ''): string
    {
        return self::__join(self::build_root(), $relative);
    }

    public static function tmp_path(string $relative = ''): string
    {
        return self::__join(self::tmp_root(), $relative);
    }

    public static function storage_path(string $relative = ''): string
    {
        return self::__join(self::storage_root(), $relative);
    }

    public static function state_path(string $relative = ''): string
    {
        return self::__join(self::state_root(), $relative);
    }

    // -----------------------------------------------------------------------
    // build/
    // -----------------------------------------------------------------------

    /** The HOT manifest index - class map, routes, attributes. */
    public static function manifest_index_file(): string
    {
        return self::build_path('manifest_index.php');
    }

    /** The COLD manifest half - per-file records. */
    public static function manifest_files_file(): string
    {
        return self::build_path('manifest_files.php');
    }

    /** The build key every cache consumer compares against. */
    public static function build_key_file(): string
    {
        return self::build_path('build_key');
    }

    /** Marker written by a build that failed, so the next process does not retry blindly. */
    public static function manifest_bad_flag_file(): string
    {
        return self::build_path('manifest_is_bad');
    }

    /** Compiled bundle output (.js and .css). */
    public static function bundles_dir(): string
    {
        return self::build_path('bundles');
    }

    /** The production seal. */
    public static function seal_file(): string
    {
        return self::build_path('prod_seal.json');
    }

    /**
     * One of Laravel's five cached-artifact files.
     *
     * @param string $name 'config' | 'routes-v7' | 'events' | 'services' | 'packages'
     */
    public static function laravel_cache_file(string $name): string
    {
        return self::build_path('laravel/' . $name . '.php');
    }

    /** Directory holding the five Laravel cache files. */
    public static function laravel_cache_dir(): string
    {
        return self::build_path('laravel');
    }

    /** Compiled Blade. The production build precompiles into it; development compiles JIT. */
    public static function views_compiled_dir(): string
    {
        return self::build_path('views');
    }

    // -----------------------------------------------------------------------
    // tmp/
    // -----------------------------------------------------------------------

    /**
     * A generated-JS stub directory - a compile-time INPUT, never served.
     *
     * @param string $kind One of the STUBS_* constants.
     */
    public static function stubs_dir(string $kind): string
    {
        return self::tmp_path($kind);
    }

    /** Per-file derived caches, one subdirectory per namespace. */
    public static function derived_dir(?string $namespace = null): string
    {
        return self::tmp_path('derived' . ($namespace === null ? '' : '/' . $namespace));
    }

    /** The code-quality pass's persisted findings ledger. */
    public static function validation_ledger_file(): string
    {
        return self::tmp_path('persistent/validation_ledger.php');
    }

    /**
     * The PHP fixer's structural memory.
     *
     * Keyed by the build root so a fixture build run under an override cannot poison
     * the real build's memory. It lives beside the derived tree, not inside it: every
     * derived/ namespace is keyed by a source file's build hash and swept against the
     * live set of source files, and this file is keyed by nothing a sweep can recognise.
     */
    public static function php_fixer_structure_file(): string
    {
        return self::tmp_path('php-fixer') . '/' . sha1(self::build_root()) . '.php';
    }

    /** npm's cache directory for framework-driven installs. */
    public static function npm_cache_dir(): string
    {
        return self::tmp_path('npm-cache');
    }

    /** Working directory for npm-driven compiles. */
    public static function npm_compile_dir(): string
    {
        return self::tmp_path('npm-compile');
    }

    /** Where node RPC daemons bind their unix sockets. */
    public static function sockets_dir(): string
    {
        return self::tmp_root();
    }

    /**
     * One of the SSR server's runtime files.
     *
     * @param string $name 'ssr-server.sock' | 'ssr-server.pid' | 'ssr-server.build_key'
     */
    public static function ssr_file(string $name): string
    {
        return self::tmp_path($name);
    }

    /** The pre-boot env heal's "nothing changed" stamp. */
    public static function env_heal_stamp_file(): string
    {
        return self::tmp_path('env_heal.stamp');
    }

    /** Staging directory for every atomic write the framework performs. */
    public static function staging_dir(): string
    {
        return self::tmp_path('staging');
    }

    /** HTMLPurifier's serializer cache. */
    public static function htmlpurifier_dir(): string
    {
        return self::tmp_path('htmlpurifier');
    }

    /** Per-task working directories. */
    public static function tasks_dir(): string
    {
        return self::tmp_path('tasks');
    }

    /** A uniquely-named scratch file. */
    public static function scratch_file(string $prefix, string $extension): string
    {
        return self::tmp_path($prefix . '_' . uniqid() . '.' . ltrim($extension, '.'));
    }

    /** The test runner's isolated FILE subsystem root. */
    public static function test_storage_dir(): string
    {
        return self::tmp_path('test-storage');
    }

    /** Cached test results, keyed by build. */
    public static function test_results_dir(): string
    {
        return self::tmp_path('test-results');
    }

    /** One test run's working directory. */
    public static function test_run_dir(string $run_id): string
    {
        return self::tmp_path('test-run-' . $run_id);
    }

    /** Persisted per-class timing hints. */
    public static function test_timings_file(): string
    {
        return self::tmp_path('test-timings.json');
    }

    /** Working directory for database dump/restore caches. */
    public static function db_cache_dir(): string
    {
        return self::tmp_path('db_cache');
    }

    /** Regenerable database dump cache (the test baseline lives here). */
    public static function db_backups_dir(): string
    {
        return self::tmp_path('db_backups');
    }

    /**
     * One of Build_Manager's artifact directories.
     *
     * @param string $type 'build' | 'cache' | 'temp'
     */
    public static function build_manager_dir(string $type = 'build'): string
    {
        return self::tmp_path('build-manager' . ($type === 'build' ? '' : '/' . $type));
    }

    /** The source formatter's memoized results. */
    public static function formatter_cache_file(): string
    {
        return self::tmp_path('rsx-formatter-cache.json');
    }

    /** Where the development mail catcher writes captured messages. */
    public static function mail_catcher_dir(): string
    {
        return self::tmp_path('mail-catcher');
    }

    /** Laravel's file cache driver store. */
    public static function laravel_file_cache_dir(): string
    {
        return self::tmp_path('laravel-cache');
    }

    /**
     * The thumbnail cache (preset/ and dynamic/ live under it).
     *
     * A REGENERABLE CACHE, not user data: every thumbnail is derived from a blob that
     * is still in the store, so losing the tree costs one re-render and nothing else.
     * It is deliberately outside the files-root override a test run installs - a run
     * has nothing to protect here, while a blob it deletes must never be the
     * developer's.
     */
    public static function thumbnails_dir(): string
    {
        return self::tmp_path('thumbnails');
    }

    /** The document-rendition cache (PDF and spreadsheet HTML), regenerable like thumbnails. */
    public static function renditions_dir(): string
    {
        return self::tmp_path('renditions');
    }

    // -----------------------------------------------------------------------
    // storage/
    // -----------------------------------------------------------------------

    /** Application logs. */
    public static function logs_dir(): string
    {
        return self::storage_path('logs');
    }

    /** The IDE bridge's local-file grant tokens. */
    public static function ide_bridge_dir(): string
    {
        return self::storage_path('rsx-ide-bridge');
    }

    /** A subdirectory of Laravel's storage/app tree. */
    public static function app_dir(string $sub = ''): string
    {
        return self::storage_path('app' . ($sub === '' ? '' : '/' . ltrim($sub, '/')));
    }

    // -----------------------------------------------------------------------
    // storage/state/
    // -----------------------------------------------------------------------

    /** The maintenance flag. Its CONTENT is the operator's reason. */
    public static function maintenance_flag_file(): string
    {
        return self::state_path('.maintenance.mode.framework.update');
    }

    /** Fingerprint of the environment-update scripts last applied. */
    public static function env_updates_fingerprint_file(): string
    {
        return self::state_path('environment_updates_fingerprint');
    }

    /** flock() files backing the system (this-box-only) locks. */
    public static function flock_dir(): string
    {
        return self::state_path('flock');
    }

    /** Stamp recording the last successful submodule sync. */
    public static function submodule_sync_stamp_file(): string
    {
        return self::state_path('.submodule_sync_ok');
    }

    /** The framework updater's ledger. */
    public static function updater_ledger_dir(): string
    {
        return self::state_path('updater');
    }

    // -----------------------------------------------------------------------
    // Manifest keys
    // -----------------------------------------------------------------------

    /**
     * The LOGICAL key of an absolute path under one of the three roots.
     *
     * `build/manifest_index.php`, `tmp/js-stubs/X.js`, `storage/uploads/...`. A path
     * under none of them is returned unchanged.
     */
    public static function key_for(string $absolute): string
    {
        foreach (self::__root_map() as $prefix => $root) {
            if ($absolute === $root) {
                return $prefix;
            }

            if (str_starts_with($absolute, $root . '/')) {
                return $prefix . '/' . substr($absolute, strlen($root) + 1);
            }
        }

        return $absolute;
    }

    /**
     * The absolute path of a logical key. The inverse of key_for().
     *
     * A key naming none of the three trees is not ours; the caller resolves it against
     * base_path() itself.
     */
    public static function absolute_for(string $key): ?string
    {
        foreach (self::__root_map() as $prefix => $root) {
            if ($key === $prefix) {
                return $root;
            }

            if (str_starts_with($key, $prefix . '/')) {
                return $root . '/' . substr($key, strlen($prefix) + 1);
            }
        }

        return null;
    }

    /**
     * The manifest key of one generated stub file.
     *
     * @param string $kind One of the STUBS_* constants.
     * @param string $filename Basename of the stub.
     */
    public static function stub_key(string $kind, string $filename): string
    {
        return 'tmp/' . $kind . '/' . $filename;
    }

    /** Is this key one the build GENERATES (rather than one it indexes from source)? */
    public static function is_generated_key(string $key): bool
    {
        return self::is_under_build($key) || self::is_under_tmp($key);
    }

    /** Absolute or key spelling, is this path inside the build tree? */
    public static function is_under_build(string $path): bool
    {
        return self::__is_under($path, 'build', self::build_root());
    }

    /** Absolute or key spelling, is this path inside the tmp tree? */
    public static function is_under_tmp(string $path): bool
    {
        return self::__is_under($path, 'tmp', self::tmp_root());
    }

    /** Absolute or key spelling, is this path a generated JS stub? */
    public static function is_stub_file(string $path): bool
    {
        foreach ([self::STUBS_CONTROLLER, self::STUBS_MODEL, self::STUBS_AUTH] as $kind) {
            if (self::__is_under($path, 'tmp/' . $kind, self::stubs_dir($kind))) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // The build-tree write guard
    // -----------------------------------------------------------------------

    /**
     * Refuse a write into the build tree from anything but a build.
     *
     * In a production-like mode the build tree is a deployment artifact: one command
     * produces it and everything else reads it. So the guard keys on the MODE, not on
     * whether a seal happens to be on disk - an unsealed production box is a broken
     * deployment, and letting an arbitrary command write into it is how it stays
     * broken. In development the whole tree is rebuilt on demand and this is a no-op.
     *
     * A guardrail, not a security boundary: a raw `rm` still does what it says, and
     * rsx:prod:verify is what detects the drift afterwards.
     *
     * @param string $path Absolute filesystem path about to be written or deleted
     * @param string $context Short description of the operation, for the message
     * @throws \RuntimeException when the write would mutate a build artifact
     */
    public static function assert_build_writable(string $path, string $context): void
    {
        if (!\App\RSpade\Core\Rsx::is_production()) {
            return;
        }

        if (\App\RSpade\Core\Prod\Rsx_Build_Context::is_active()) {
            return;
        }

        if (!self::is_under_build($path)) {
            return;
        }

        throw new \RuntimeException(
            "{$context}: '{$path}' is a build artifact, and only a build may write it.\n"
            . "  Rebuild:  php artisan rsx:build --force\n"
            . '  See:      php artisan rsx:man prod'
        );
    }

    // -----------------------------------------------------------------------
    // Trees
    // -----------------------------------------------------------------------

    /**
     * Create the build tree. THE ONLY place the build root is made.
     *
     * Boot never calls this: a production box whose build tree is missing must fail
     * loud naming the build command, not quietly grow an empty one.
     *
     * The one directory boot does make is laravel_cache_dir(), in DEVELOPMENT only, in
     * bootstrap/app.php - Laravel's PackageManifest writes into it during boot and
     * throws when it is absent, so a fresh development checkout could not otherwise run
     * the build that creates it. The reason is written out there.
     */
    public static function ensure_build_tree(): void
    {
        self::assert_build_writable(self::build_root(), 'ensure_build_tree');

        foreach ([self::build_root(), self::bundles_dir(), self::laravel_cache_dir(), self::views_compiled_dir()] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    /** Create the tmp tree. Boot may call this - everything in it is regenerable. */
    public static function ensure_tmp_tree(): void
    {
        foreach ([self::tmp_root(), self::npm_cache_dir(), self::staging_dir()] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        self::__ensure_storage_link('logs', self::logs_dir());
        self::__ensure_storage_link('app', self::app_dir());
    }

    /**
     * One of the two persistent-storage links inside tmp/.
     *
     * Laravel's storage path IS the tmp root, so anything resolving storage_path('logs')
     * or storage_path('app') - a package, an old call site, a copied recipe - lands in a
     * tree rsx:clean wipes. These two links send exactly those two names back into the
     * persistent storage tree, so the lazy spelling and the owner's spelling reach one
     * directory.
     *
     * A link pointing somewhere else is replaced: it is machine-made and its target
     * moves with the storage root. A REAL directory or file is fatal, because it holds
     * something a person put there and only they know whether it matters.
     */
    private static function __ensure_storage_link(string $name, string $target): void
    {
        $link = self::tmp_path($name);

        if (!is_dir($target)) {
            @mkdir($target, 0775, true);
        }

        if (is_link($link)) {
            if (readlink($link) === $target) {
                return;
            }

            @unlink($link);
            @symlink($target, $link);

            return;
        }

        if (file_exists($link)) {
            throw new \RuntimeException(
                "tmp/{$name} is a real " . (is_dir($link) ? 'directory' : 'file')
                . ", and it has to be a symlink onto {$target}.\n"
                . "  Laravel's storage path is the tmp tree, so storage_path('{$name}') resolves here"
                . " and this link is what keeps it persistent.\n"
                . "  Move anything that matters out of '{$link}', then remove it - the next boot"
                . " recreates the link.\n"
                . '  See:      php artisan rsx:man prod'
            );
        }

        @symlink($target, $link);
    }

    /** Create the storage tree. Boot may call this. */
    public static function ensure_storage_tree(): void
    {
        $dirs = [
            self::storage_root(),
            self::app_dir(),
            self::app_dir('public'),
            self::app_dir('temp'),
            self::logs_dir(),
            self::state_root(),
            self::flock_dir(),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Children
    // -----------------------------------------------------------------------

    /**
     * The three resolved roots as environment assignments, for a spawned child.
     *
     * @return array<string,string>
     */
    public static function child_env(): array
    {
        self::__require_resolver();

        $env = rsx_paths_all();

        // An in-process override is part of this process's identity and must travel,
        // or a subprocess would read a different tree than its parent. A build-root
        // override has no environment key and travels as an argv flag instead
        // (child_flags()), because the root itself is fixed.
        if (isset(self::$overrides['tmp'])) {
            $env['RSX_TMP_PATH'] = self::$overrides['tmp'];
        }

        $env['TMPDIR'] = $env['RSX_TMP_PATH'];

        return $env;
    }

    /**
     * The same overrides as internal argv flags, for a child spawned through artisan.
     *
     * @return array<int,string>
     */
    public static function child_flags(): array
    {
        $flags = [];

        foreach (['build' => '--_rsx-build-root', 'tmp' => '--_rsx-tmp-root', 'files' => '--_rsx-files-root'] as $kind => $flag) {
            if (isset(self::$overrides[$kind])) {
                $flags[] = $flag . '=' . self::$overrides[$kind];
            }
        }

        return $flags;
    }

    // -----------------------------------------------------------------------
    // Test seams
    // -----------------------------------------------------------------------

    /**
     * Redirect one or more roots for THIS process.
     *
     * @param array<string,string> $roots Keys 'build', 'tmp', 'files'.
     */
    public static function _override(array $roots): void
    {
        foreach ($roots as $kind => $dir) {
            if (!in_array($kind, ['build', 'tmp', 'files'], true)) {
                throw new \InvalidArgumentException("Rsx_Project_Paths::_override(): unknown root '{$kind}'.");
            }

            self::$overrides[$kind] = rtrim((string) $dir, '/');
        }
    }

    /** Drop every in-process override. */
    public static function _clear_overrides(): void
    {
        self::$overrides = [];
    }

    /**
     * The in-process override set, for a caller that must put it back exactly.
     *
     * The suite's file-subsystem isolation is one of these overrides and belongs to the
     * RUN, not to whichever test last touched it - so the harness snapshots the set at the
     * class boundary and restores it there (Rsx_Test_Abstract::run()).
     *
     * @return array<string,string>
     */
    public static function _overrides(): array
    {
        return self::$overrides;
    }

    /**
     * Replace the override set wholesale, validating every key the way _override() does.
     *
     * @param array<string,string> $overrides
     */
    public static function _restore_overrides(array $overrides): void
    {
        self::$overrides = [];
        self::_override($overrides);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * One root, in precedence order: in-process override, argv flag, resolver.
     */
    private static function __root(string $kind, string $flag, string $resolver): string
    {
        if (isset(self::$overrides[$kind])) {
            return self::$overrides[$kind];
        }

        self::__require_resolver();

        $argv = Rsx_Internal_Flags::get($flag);

        if ($argv !== null && $argv !== '') {
            return rtrim($argv, '/');
        }

        return $resolver();
    }

    /**
     * The logical prefix of each tree, longest-first so `storage/state` never wins
     * over a tree relocated inside storage.
     *
     * @return array<string,string>
     */
    private static function __root_map(): array
    {
        $map = [
            'build'   => self::build_root(),
            'tmp'     => self::tmp_root(),
            'storage' => self::storage_root(),
        ];

        uasort($map, static fn ($a, $b) => strlen($b) <=> strlen($a));

        return $map;
    }

    private static function __is_under(string $path, string $key_prefix, string $root): bool
    {
        if ($path === $key_prefix || str_starts_with($path, $key_prefix . '/')) {
            return true;
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function __join(string $root, string $relative): string
    {
        $relative = trim($relative, '/');

        return $relative === '' ? $root : $root . '/' . $relative;
    }

    /**
     * Load the two files this class needs before the autoloader exists.
     *
     * The pre-boot guards require this class DIRECTLY, so neither the resolver's
     * functions nor Rsx_Internal_Flags can be assumed loaded. require_once is
     * idempotent by path and costs one stat on the booted path.
     */
    private static function __require_resolver(): void
    {
        require_once dirname(__DIR__, 4) . '/bootstrap/rsx_paths.php';
        require_once __DIR__ . '/../Console/Rsx_Internal_Flags.php';
    }
}
