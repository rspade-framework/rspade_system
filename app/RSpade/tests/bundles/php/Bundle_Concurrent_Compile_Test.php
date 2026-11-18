<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use ReflectionMethod;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\PHP\Rsx_Namespace;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Support\Process\Process_Identity;

/**
 * TWO COMPILES OF ONE BUNDLE AT ONCE LEAVE ONE CONSISTENT ARTIFACT (BUNDLE-CONCURRENT-01).
 *
 * A cold deploy under load sends several first requests for the same bundle at the same
 * moment. BundleCompiler serializes the compile on the box-wide system lock
 * LOCK_BUNDLE_BUILD and checks the cache again once it holds it, so the second compiler
 * finds the first one's output instead of compiling (or half-overwriting) it again.
 *
 * The race is FORCED, not hoped for: the test holds LOCK_BUNDLE_BUILD itself, starts two
 * child compiles of a fixture bundle against a scratch build root, waits until /proc/locks
 * shows BOTH children blocked on that lock's file, and only then releases it. After both
 * exit, the build root holds exactly one app script and one vendor script for the bundle,
 * and a third compile serves them unchanged from the cache.
 *
 * The fixture tree sits under a `resource/` directory, scanned only by the children that
 * name it as an extra root.
 */
class Bundle_Concurrent_Compile_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const BUNDLE = 'Bcc_Probe_Bundle';

    private const MARKER = 'BCC_CONCURRENT_COMPILE_MARKER';

    private static string $root = '';

    private static string $build_root = '';

    private static function __flags(): array
    {
        return [
            '--_rsx-build-root=' . static::$build_root,
            '--_rsx-tmp-root=' . static::$build_root . '-tmp',
            '--_manifest-extra-scan-roots=' . static::$root,
        ];
    }

    private static function __write_fixture(): void
    {
        $script = static::$root . '/bcc_probe.js';
        $bundle = static::$root . '/bcc_probe_bundle.php';
        $namespace = Rsx_Namespace::from_path($bundle);
        $class = self::BUNDLE;

        $files = [
            $script => "class Bcc_Probe {\n    static marker() {\n        return '" . self::MARKER . "';\n    }\n}\n",
            $bundle => "<?php\n\nnamespace {$namespace};\n\n"
                . "use App\\RSpade\\Core\\Bundle\\Rsx_Module_Bundle_Abstract;\n\n"
                . "class {$class} extends Rsx_Module_Bundle_Abstract\n{\n"
                . "    public static function define(): array\n    {\n"
                . "        return ['include' => ['{$script}']];\n    }\n}\n",
        ];

        foreach ($files as $path => $content) {
            ensure_directory(dirname(base_path($path)));
            file_put_contents(base_path($path), $content);
        }

        ensure_directory(static::$build_root);
        ensure_directory(static::$build_root . '-tmp');
    }

    private static function __remove(): void
    {
        if (static::$root !== '' && is_dir(base_path(static::$root))) {
            rmdir_recursive(base_path(static::$root));
        }

        if (static::$build_root !== '' && is_dir(dirname(static::$build_root))) {
            rmdir_recursive(dirname(static::$build_root));
        }
    }

    /** The flock file behind LOCK_BUNDLE_BUILD, exactly as BundleCompiler takes it (not db-scoped). */
    private static function __lock_file(): string
    {
        $method = new ReflectionMethod(RsxLocks::class, '__flock_path');

        return $method->invoke(null, RsxLocks::SYSTEM_LOCK, RsxLocks::LOCK_BUNDLE_BUILD, false);
    }

    /**
     * The pids /proc/locks shows WAITING (a `->` line) on the file with inode $inode.
     *
     * @return int[]
     */
    private static function __waiters_on(int $inode): array
    {
        $waiters = [];

        foreach (explode("\n", (string) @file_get_contents('/proc/locks')) as $line) {
            // "12: -> FLOCK  ADVISORY  WRITE 4242 00:1c:498914946 0 EOF"
            if (preg_match('/->\s+FLOCK\s+\S+\s+\S+\s+(\d+)\s+[0-9a-f]+:[0-9a-f]+:(\d+)\s/i', $line, $m) && (int) $m[2] === $inode) {
                $waiters[] = (int) $m[1];
            }
        }

        return $waiters;
    }

    /**
     * Poll $condition every 50 ms. The bound is the single sanctioned test carve-out from the
     * no-timeout mandate: 100 times the event's idle duration, and reaching it FAILS naming
     * the condition never reached.
     */
    private static function __poll_until(callable $condition, \Closure $describe, float $idle_seconds): void
    {
        $bound_seconds = 100 * $idle_seconds;
        $deadline = microtime(true) + $bound_seconds;

        do {
            if ($condition()) {
                return;
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        static::__fail($describe() . " (never reached in {$bound_seconds} s, 100x its idle time)");
    }

    /** name => [mtime, sha1] of every compiled file of the probe bundle. */
    private static function __artifacts(): array
    {
        $artifacts = [];

        foreach (glob(static::$build_root . '/bundles/' . self::BUNDLE . '__*') ?: [] as $file) {
            clearstatcache(true, $file);
            $artifacts[basename($file)] = [filemtime($file), sha1_file($file)];
        }

        ksort($artifacts);

        return $artifacts;
    }

    private static function __of_kind(array $artifacts, string $kind): array
    {
        return array_values(array_filter(
            array_keys($artifacts),
            static fn ($name) => (bool) preg_match('/^' . self::BUNDLE . '__' . $kind . '\.[0-9a-f]+\.js$/', $name)
        ));
    }

    public static function test_two_concurrent_compiles_leave_one_consistent_artifact()
    {
        static::$root = 'app/RSpade/temp/resource/bcc_' . getmypid();
        static::$build_root = Rsx_Project_Paths::tmp_path('bundle-concurrent-compile/' . getmypid() . '/build');
        static::__remove();

        $lock = null;

        try {
            static::__write_fixture();

            $output = [];
            static::__assert_equals(0, Rsx_Artisan::run('rsx:manifest:build', static::__flags(), $output), "the fixture build failed:\n" . implode("\n", $output));
            static::__assert_equals([], static::__artifacts(), 'nothing is compiled before the race');

            $lock_file = static::__lock_file();
            $lock = RsxLocks::system_lock(RsxLocks::LOCK_BUNDLE_BUILD, db_scoped: false);
            clearstatcache(true, $lock_file);
            $inode = (int) fileinode($lock_file);

            $children = [];

            foreach ([1, 2] as $n) {
                $pid = Rsx_Artisan::dispatch_detached('rsx:bundle:compile', array_merge([self::BUNDLE], static::__flags()));
                $children[$pid] = Process_Identity::start_time($pid);
            }

            // Both children boot, resolve the bundle, find no cache and block on the lock. A
            // child boot takes a few seconds on an idle box.
            static::__poll_until(
                static fn () => count(array_intersect(array_keys($children), static::__waiters_on($inode))) === 2,
                static fn () => 'both child compiles (' . implode(', ', array_keys($children)) . ") blocked on {$lock_file}; waiting there: ["
                    . implode(', ', static::__waiters_on($inode)) . ']',
                10.0
            );

            RsxLocks::release_lock($lock);
            $lock = null;

            // One compile of a one-file bundle (plus the framework's required bundles) takes
            // well under a minute on an idle box.
            static::__poll_until(
                static function () use ($children) {
                    foreach ($children as $pid => $start) {
                        if (Process_Identity::is_running($pid, $start)) {
                            return false;
                        }
                    }

                    return true;
                },
                static fn () => 'both child compiles exited',
                60.0
            );

            $artifacts = static::__artifacts();
            $names = implode(', ', array_keys($artifacts));

            static::__assert_count(1, static::__of_kind($artifacts, 'app'), "exactly one app script: {$names}");
            static::__assert_count(1, static::__of_kind($artifacts, 'vendor'), "exactly one vendor script: {$names}");

            foreach (array_keys($artifacts) as $name) {
                static::__assert_true(
                    (bool) preg_match('/^' . self::BUNDLE . '__(app|vendor)\.[0-9a-f]+\.(js|css)$/', $name),
                    "no partial or temporary artifact is left beside the output: {$name}"
                );
            }

            $app = file_get_contents(static::$build_root . '/bundles/' . static::__of_kind($artifacts, 'app')[0]);
            static::__assert_contains(self::MARKER, $app, 'the app script carries the fixture source');

            // A third compile, alone, finds the artifacts complete and current: it returns the
            // same files and rewrites none of them.
            $output = [];
            static::__assert_equals(0, Rsx_Artisan::run('rsx:bundle:compile', array_merge([self::BUNDLE], static::__flags()), $output), "the follow-up compile failed:\n" . implode("\n", $output));
            static::__assert_equals($artifacts, static::__artifacts(), 'the follow-up compile served the same artifacts unchanged');
        } finally {
            if ($lock !== null) {
                RsxLocks::release_lock($lock);
            }

            static::__remove();
        }
    }
}
