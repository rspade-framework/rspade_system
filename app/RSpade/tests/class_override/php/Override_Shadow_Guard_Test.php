<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Overrides;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The override map takes a framework class out of the live index - SHADOWS it - the moment it
 * believes an rsx/ file is standing in for it. The file stays on disk; the class leaves every
 * class map, every support module and every rule. That decision is made from the manifest's
 * FILE LIST.
 *
 * A field report on 2026-08-25 is what the first two tests pin down. A brand-new framework-core
 * JS class tripped a scan rule on its first scan, while the index still carried the app copies
 * of those same class names that had just been deleted from rsx/. The failure poisoned the
 * manifest; the next build ran the override pass against the surviving stale list, concluded
 * the new core files were framework twins of app classes, and took them out of the tree.
 * Nothing was printed, so a set of classes simply ceased to exist.
 *
 * Asserted here:
 *   1. A twin named by the index but absent from DISK shadows nothing, and is dropped.
 *   2. A build that already marked its own manifest bad shadows nothing at all.
 *   3. A real twin IS shadowed - recorded in the map, its record moved out of `files` - and
 *      the shadowing says so by name. Nothing on disk changes.
 *   4-7. The refusals: a split class's override must extend the base, and an override may not
 *      extend the class it overrides.
 *
 * Manifest_Overrides::derive() reads and writes Manifest::$data directly, so every test swaps
 * in a synthetic file list and restores the real one in a finally. Only the APPLICATION file
 * is written to disk (the derivation checks that it exists); it lives under rsx/resource/,
 * which the manifest never indexes.
 *
 * Pure filesystem + static state, no DB.
 */
class Override_Shadow_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FRAMEWORK_FILE = 'app/RSpade/temp/Override_Guard_Probe_Temp.php';
    private const RSX_FILE = 'rsx/resource/override_guard_probe_temp.php';
    private const PROBE_CLASS = 'Override_Guard_Probe_Temp';

    /**
     * Derive the override map over a synthetic file list, with the real manifest state saved
     * and restored around it.
     *
     * @return array{log: string, files: array, overrides: array, shadowed_files: array, result: array}
     */
    private static function __derive_with(array $files, bool $manifest_is_bad = false): array
    {
        $saved_data = Manifest::$data;
        $saved_is_bad = Manifest::$_manifest_is_bad;
        $saved_error_log = ini_get('error_log');

        $log_path = Rsx_Project_Paths::tmp_path('override_guard_log_' . uniqid() . '.txt');

        Manifest::$data = ['data' => ['files' => $files]];
        Manifest::$_manifest_is_bad = $manifest_is_bad;
        ini_set('error_log', $log_path);

        try {
            $result = Manifest_Overrides::derive([], []);
            $outcome = [
                'files' => Manifest::$data['data']['files'],
                'overrides' => Manifest::$data['data']['overrides'],
                'shadowed_files' => Manifest::$data['data']['shadowed_files'],
                'result' => $result,
            ];
        } finally {
            ini_set('error_log', $saved_error_log === false ? '' : $saved_error_log);
            Manifest::$data = $saved_data;
            Manifest::$_manifest_is_bad = $saved_is_bad;
        }

        $outcome['log'] = file_exists($log_path) ? file_get_contents($log_path) : '';
        @unlink($log_path);

        return $outcome;
    }

    /** A manifest entry as the derivation reads it. */
    private static function __entry(string $file, string $class, ?string $namespace, ?string $extends = null): array
    {
        $entry = ['extension' => 'php', 'class' => $class, 'hash' => sha1($file)];

        if ($namespace !== null) {
            $entry['namespace'] = $namespace;
            $entry['fqcn'] = $namespace . '\\' . $class;
        }

        if ($extends !== null) {
            $entry['extends'] = $extends;
        }

        return [$file => $entry];
    }

    private static function __files(?string $framework_extends = null, ?string $rsx_extends = null): array
    {
        return array_merge(
            self::__entry(self::FRAMEWORK_FILE, self::PROBE_CLASS, 'App\\RSpade\\Temp', $framework_extends),
            self::__entry(self::RSX_FILE, self::PROBE_CLASS, 'Rsx\\Resource', $rsx_extends)
        );
    }

    private static function __write_rsx_twin(): void
    {
        $rsx_path = base_path(self::RSX_FILE);
        ensure_directory(dirname($rsx_path));
        file_put_contents($rsx_path, "<?php\n\nnamespace Rsx\\Resource;\n\nclass " . self::PROBE_CLASS . " {}\n");
    }

    private static function __remove_rsx_twin(): void
    {
        if (file_exists(base_path(self::RSX_FILE))) {
            unlink(base_path(self::RSX_FILE));
        }
    }

    // =====================================================================
    // The stale-list condition
    // =====================================================================

    public static function test_a_twin_that_is_not_on_disk_shadows_nothing()
    {
        // THE FIELD REPRO. The index names an rsx/ override; the file was deleted before this
        // build started. That entry alone must never take the framework class out of the tree.
        self::__remove_rsx_twin();

        $outcome = self::__derive_with(self::__files());

        static::__assert_equals([], $outcome['overrides'], 'nothing is shadowed on the strength of a file that does not exist');
        static::__assert_true(isset($outcome['files'][self::FRAMEWORK_FILE]), 'the framework class stays in the live tree');
        static::__assert_false(isset($outcome['files'][self::RSX_FILE]), 'the stale entry is dropped');
        static::__assert_equals([self::RSX_FILE], $outcome['result']['dropped'], 'and reported as dropped, so the build reports it removed');
        static::__assert_contains('stale index entry', $outcome['log'], 'the stale entry is reported rather than acted on');
    }

    public static function test_a_poisoned_build_shadows_nothing()
    {
        // Both files exist, so this WOULD be a real override - but the build has already
        // declared its own index untrustworthy. The next build decides instead.
        self::__write_rsx_twin();

        try {
            $outcome = self::__derive_with(self::__files(), true);

            static::__assert_equals([], $outcome['overrides'], 'a build with a bad manifest shadows nothing');
            static::__assert_true(isset($outcome['files'][self::FRAMEWORK_FILE]), 'both declarations stay in the tree');
            static::__assert_true(isset($outcome['files'][self::RSX_FILE]));
            static::__assert_contains('NOT shadowing', $outcome['log'], 'and it says why');
        } finally {
            self::__remove_rsx_twin();
        }
    }

    // =====================================================================
    // Positive control - the override itself works, and is announced
    // =====================================================================

    public static function test_a_real_twin_is_shadowed_and_named()
    {
        self::__write_rsx_twin();

        try {
            $outcome = self::__derive_with(self::__files());

            static::__assert_equals(
                [self::FRAMEWORK_FILE => [
                    'kind' => 'php',
                    'name' => self::PROBE_CLASS,
                    'by' => self::RSX_FILE,
                    'fqcn' => 'App\\RSpade\\Temp\\' . self::PROBE_CLASS,
                    'by_fqcn' => 'Rsx\\Resource\\' . self::PROBE_CLASS,
                ]],
                $outcome['overrides'],
                'the map records the framework file, the class and the override'
            );

            static::__assert_false(isset($outcome['files'][self::FRAMEWORK_FILE]), 'the framework record left the live tree');
            static::__assert_true(isset($outcome['shadowed_files'][self::FRAMEWORK_FILE]), 'into the shadowed records, intact');
            static::__assert_true(isset($outcome['files'][self::RSX_FILE]), 'the override is the live declaration');
            static::__assert_equals([self::FRAMEWORK_FILE], $outcome['result']['entering'], 'the framework file entered the shadow');

            static::__assert_contains(self::FRAMEWORK_FILE, $outcome['log'], 'the notice names the shadowed file');
            static::__assert_contains(self::RSX_FILE, $outcome['log'], 'and the rsx/ twin that shadows it');

            static::__assert_equals(
                [
                    'App\\RSpade\\Temp\\' . self::PROBE_CLASS => [
                        'by_fqcn' => 'Rsx\\Resource\\' . self::PROBE_CLASS,
                        'by_file' => self::RSX_FILE,
                        'file' => self::FRAMEWORK_FILE,
                    ],
                ],
                Manifest_Overrides::loader_map($outcome['overrides']),
                'and the class override loader is handed exactly that pair'
            );
        } finally {
            self::__remove_rsx_twin();
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
        self::__write_rsx_twin();

        try {
            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__derive_with(self::__files(self::PROBE_CLASS . '_Abstract', 'Rsx_Site_Model_Abstract')),
                'Invalid override of the split framework class'
            );

            static::__assert_contains(
                self::PROBE_CLASS . '_Abstract',
                $error->getMessage(),
                'the refusal names the abstract the override must extend'
            );
            static::__assert_contains(self::RSX_FILE, $error->getMessage(), 'and the override file it is about');
            static::__assert_contains('rsx:man class_override', $error->getMessage(), 'and where the contract is written down');
        } finally {
            self::__remove_rsx_twin();
        }
    }

    /** An override of a split class that DOES extend the base is the supported shape. */
    public static function test_an_override_of_a_split_class_that_extends_the_base_is_shadowed()
    {
        self::__write_rsx_twin();

        try {
            $outcome = self::__derive_with(self::__files(self::PROBE_CLASS . '_Abstract', self::PROBE_CLASS . '_Abstract'));

            static::__assert_true(isset($outcome['overrides'][self::FRAMEWORK_FILE]), 'the shell is shadowed by the override');
            static::__assert_contains(self::RSX_FILE, $outcome['log'], 'and the notice names the override');
        } finally {
            self::__remove_rsx_twin();
        }
    }

    /**
     * The same-name-extends refusal tells a developer of a SPLIT class the right answer -
     * extend the base - instead of telling them to clone the file.
     */
    public static function test_extending_the_shell_itself_is_refused_and_points_at_the_base()
    {
        self::__write_rsx_twin();

        try {
            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__derive_with(self::__files(self::PROBE_CLASS . '_Abstract', self::PROBE_CLASS)),
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
            self::__remove_rsx_twin();
        }
    }

    /** A NON-split framework class gets the clone recipe. */
    public static function test_extending_a_non_split_class_gets_the_clone_recipe()
    {
        self::__write_rsx_twin();

        try {
            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__derive_with(self::__files('Rsx_Controller_Abstract', self::PROBE_CLASS)),
                'Invalid class override pattern'
            );

            static::__assert_contains(
                'Copy the framework file to your rsx/ directory',
                $error->getMessage(),
                'copy-and-replace is the answer for a class with no base to extend'
            );
        } finally {
            self::__remove_rsx_twin();
        }
    }
}
