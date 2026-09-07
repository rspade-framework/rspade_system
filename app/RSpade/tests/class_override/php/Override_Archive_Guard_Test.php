<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\_Manifest_Quality_Helper;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The class-override pass archives a framework file - renames it to .php.upstream, which
 * un-registers the class - the moment it believes an rsx/ file is standing in for it. That
 * decision is destructive and it is made from the manifest's FILE LIST.
 *
 * A field report on 2026-08-25 is what these tests pin down. A brand-new framework-core JS
 * class tripped a scan rule on its first scan, while the index still carried the app copies
 * of those same class names that had just been deleted from rsx/. The failure poisoned the
 * manifest; the next build discarded it, ran this pass against the surviving stale list,
 * concluded the new core files were framework twins of app classes, and renamed them away.
 * Nothing was printed, so a set of classes simply ceased to exist.
 *
 * Three properties are asserted here:
 *   1. A twin named by the index but absent from DISK archives nothing.
 *   2. A build that already marked its own manifest bad archives nothing at all.
 *   3. A real twin IS archived, and the archiving says so by name.
 *
 * The pass reads and writes Manifest::$data directly, so every test swaps in a synthetic
 * file list and restores the real one in a finally.
 *
 * Pure filesystem + static state, no DB.
 */
class Override_Archive_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FRAMEWORK_FILE = 'app/RSpade/temp/Override_Guard_Probe_Temp.php';
    private const RSX_FILE = 'rsx/resource/override_guard_probe_temp.php';
    private const PROBE_CLASS = 'Override_Guard_Probe_Temp';

    /**
     * Run the override pass against a synthetic file list, with the real manifest state
     * saved and restored around it. Returns whatever the pass wrote to the error log.
     */
    private static function __run_pass_with(array $files, bool $manifest_is_bad = false): string
    {
        $saved_data = Manifest::$data;
        $saved_restart = Manifest::$_needs_manifest_restart;
        $saved_is_bad = Manifest::$_manifest_is_bad;
        $saved_error_log = ini_get('error_log');

        $log_path = storage_path('rsx-tmp/override_guard_log_' . uniqid() . '.txt');

        Manifest::$data = ['data' => ['files' => $files]];
        Manifest::$_needs_manifest_restart = false;
        Manifest::$_manifest_is_bad = $manifest_is_bad;
        ini_set('error_log', $log_path);

        try {
            _Manifest_Quality_Helper::_check_unique_base_class_names();
        } finally {
            ini_set('error_log', $saved_error_log === false ? '' : $saved_error_log);
            Manifest::$data = $saved_data;
            Manifest::$_needs_manifest_restart = $saved_restart;
            Manifest::$_manifest_is_bad = $saved_is_bad;
        }

        $log = file_exists($log_path) ? file_get_contents($log_path) : '';
        @unlink($log_path);

        return $log;
    }

    /** A manifest entry as the pass reads it. */
    private static function __entry(string $file, string $class): array
    {
        return [$file => ['file' => $file, 'extension' => 'php', 'class' => $class]];
    }

    private static function __write_probe_files(bool $write_rsx_twin): void
    {
        $framework_path = base_path(self::FRAMEWORK_FILE);
        ensure_directory(dirname($framework_path));
        file_put_contents($framework_path, "<?php\n\nclass " . self::PROBE_CLASS . " {}\n");

        if ($write_rsx_twin) {
            $rsx_path = base_path(self::RSX_FILE);
            ensure_directory(dirname($rsx_path));
            file_put_contents($rsx_path, "<?php\n\nclass " . self::PROBE_CLASS . " {}\n");
        }
    }

    private static function __remove_probe_files(): void
    {
        foreach ([
            base_path(self::FRAMEWORK_FILE),
            base_path(self::FRAMEWORK_FILE) . '.upstream',
            base_path(self::RSX_FILE),
        ] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    // =====================================================================
    // The stale-list condition
    // =====================================================================

    public static function test_a_twin_that_is_not_on_disk_archives_nothing()
    {
        // THE FIELD REPRO. The index names an rsx/ override; the file was deleted before
        // this build started. Under the old code that entry alone was enough to rename the
        // framework class out of existence.
        self::__write_probe_files(false);

        try {
            $files = array_merge(
                self::__entry(self::FRAMEWORK_FILE, self::PROBE_CLASS),
                self::__entry(self::RSX_FILE, self::PROBE_CLASS)
            );

            $log = self::__run_pass_with($files);

            static::__assert_true(
                file_exists(base_path(self::FRAMEWORK_FILE)),
                'the framework file is still there'
            );

            static::__assert_false(
                file_exists(base_path(self::FRAMEWORK_FILE) . '.upstream'),
                'and nothing was archived on the strength of a file that does not exist'
            );

            static::__assert_contains(
                'stale index entry',
                $log,
                'the stale entry is reported rather than acted on'
            );
        } finally {
            self::__remove_probe_files();
        }
    }

    public static function test_a_poisoned_build_archives_nothing()
    {
        // Both files exist, so this WOULD be a real override - but the build has already
        // declared its own index untrustworthy, and archiving is the one act it cannot
        // take back. The rebuild that follows decides instead.
        self::__write_probe_files(true);

        try {
            $files = array_merge(
                self::__entry(self::FRAMEWORK_FILE, self::PROBE_CLASS),
                self::__entry(self::RSX_FILE, self::PROBE_CLASS)
            );

            $log = self::__run_pass_with($files, true);

            static::__assert_true(
                file_exists(base_path(self::FRAMEWORK_FILE)),
                'a build with a bad manifest leaves the framework file alone'
            );

            static::__assert_false(
                file_exists(base_path(self::FRAMEWORK_FILE) . '.upstream'),
                'nothing was archived'
            );

            static::__assert_contains('NOT archiving', $log, 'and it says why');
        } finally {
            self::__remove_probe_files();
        }
    }

    // =====================================================================
    // Positive control - the override itself still works, and is announced
    // =====================================================================

    public static function test_a_real_twin_is_archived_and_named()
    {
        self::__write_probe_files(true);

        try {
            $files = array_merge(
                self::__entry(self::FRAMEWORK_FILE, self::PROBE_CLASS),
                self::__entry(self::RSX_FILE, self::PROBE_CLASS)
            );

            $log = self::__run_pass_with($files);

            static::__assert_false(
                file_exists(base_path(self::FRAMEWORK_FILE)),
                'the framework file moved aside for the override'
            );

            static::__assert_true(
                file_exists(base_path(self::FRAMEWORK_FILE) . '.upstream'),
                'it is archived, not deleted'
            );

            static::__assert_contains(self::FRAMEWORK_FILE, $log, 'the notice names the archived file');
            static::__assert_contains(self::RSX_FILE, $log, 'and the rsx/ twin that caused it');
        } finally {
            self::__remove_probe_files();
        }
    }
}
