<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Indexer;
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
            Manifest_Indexer::_check_unique_base_class_names();
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
    private static function __entry(string $file, string $class, ?string $extends = null): array
    {
        $entry = ['file' => $file, 'extension' => 'php', 'class' => $class];

        if ($extends !== null) {
            $entry['extends'] = $extends;
        }

        return [$file => $entry];
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

    // =====================================================================
    // The split-model refusal
    // =====================================================================

    /**
     * A SPLIT framework class - `class X extends X_Abstract`, every member on the base - may
     * only be overridden by a class that extends the SAME base. A copy of the framework file
     * is a second implementation of a class the framework keeps developing: every member
     * added to the base after the copy was taken is missing from it, and framework code calls
     * those members on this class regardless. That is the exact failure the split exists to
     * end, so it is refused rather than reported.
     */
    public static function test_an_override_of_a_split_class_that_does_not_extend_the_base_is_refused()
    {
        self::__write_probe_files(true);

        try {
            $files = array_merge(
                self::__entry(self::FRAMEWORK_FILE, self::PROBE_CLASS, self::PROBE_CLASS . '_Abstract'),
                self::__entry(self::RSX_FILE, self::PROBE_CLASS, 'Rsx_Site_Model_Abstract')
            );

            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__run_pass_with($files),
                'Invalid override of the split framework class'
            );

            static::__assert_contains(
                self::PROBE_CLASS . '_Abstract',
                $error->getMessage(),
                'the refusal names the abstract the override must extend'
            );
            static::__assert_contains(
                self::RSX_FILE,
                $error->getMessage(),
                'and the override file it is about'
            );
            static::__assert_contains(
                'rsx:man class_override',
                $error->getMessage(),
                'and where the contract is written down'
            );

            static::__assert_true(
                file_exists(base_path(self::FRAMEWORK_FILE)),
                'and nothing was archived - the build stopped instead'
            );
        } finally {
            self::__remove_probe_files();
        }
    }

    /** An override of a split class that DOES extend the base is the supported shape. */
    public static function test_an_override_of_a_split_class_that_extends_the_base_is_archived()
    {
        self::__write_probe_files(true);

        try {
            $files = array_merge(
                self::__entry(self::FRAMEWORK_FILE, self::PROBE_CLASS, self::PROBE_CLASS . '_Abstract'),
                self::__entry(self::RSX_FILE, self::PROBE_CLASS, self::PROBE_CLASS . '_Abstract')
            );

            $log = self::__run_pass_with($files);

            static::__assert_true(
                file_exists(base_path(self::FRAMEWORK_FILE) . '.upstream'),
                'the shell moved aside for the override'
            );
            static::__assert_contains(self::RSX_FILE, $log, 'and the notice names the override');
        } finally {
            self::__remove_probe_files();
        }
    }

    /**
     * The same-name-extends refusal now tells a developer of a SPLIT class the right answer -
     * extend the base - instead of telling them to clone the file.
     */
    public static function test_extending_the_shell_itself_is_refused_and_points_at_the_base()
    {
        self::__write_probe_files(true);

        try {
            $files = array_merge(
                self::__entry(self::FRAMEWORK_FILE, self::PROBE_CLASS, self::PROBE_CLASS . '_Abstract'),
                self::__entry(self::RSX_FILE, self::PROBE_CLASS, self::PROBE_CLASS)
            );

            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__run_pass_with($files),
                'Invalid class override pattern'
            );

            static::__assert_contains(
                'CORRECT OVERRIDE PATTERN FOR A SPLIT FRAMEWORK CLASS',
                $error->getMessage(),
                'the split answer, not the clone recipe'
            );
            static::__assert_contains(
                'extends ' . self::PROBE_CLASS . '_Abstract',
                $error->getMessage(),
                'and it spells out the declaration to write'
            );
        } finally {
            self::__remove_probe_files();
        }
    }

    /** A NON-split framework class still gets the clone recipe - that path is unchanged. */
    public static function test_extending_a_non_split_class_still_gets_the_clone_recipe()
    {
        self::__write_probe_files(true);

        try {
            $files = array_merge(
                self::__entry(self::FRAMEWORK_FILE, self::PROBE_CLASS, 'Rsx_Controller_Abstract'),
                self::__entry(self::RSX_FILE, self::PROBE_CLASS, self::PROBE_CLASS)
            );

            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__run_pass_with($files),
                'Invalid class override pattern'
            );

            static::__assert_contains(
                'Copy the framework file to your rsx/ directory',
                $error->getMessage(),
                'copy-and-replace is still the answer for a class with no base to extend'
            );
        } finally {
            self::__remove_probe_files();
        }
    }
}
