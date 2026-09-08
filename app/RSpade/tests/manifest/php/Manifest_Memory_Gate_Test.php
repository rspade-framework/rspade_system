<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE MEMORY BUDGET, AS TWO NUMBERS.
 *
 * Owner ruling: the manifest build's peak memory is proportional to the INDEX plus a
 * constant bounded by the largest single file, and NEVER proportional to the number of
 * files parsed. The acceptance numbers are a cold build of the reference tree under
 * **128 MB** and of a synthetic tree five times its size under **256 MB**.
 *
 * Both are one number each, and they are checked because the failure they guard against is
 * invisible: a rule that starts holding its own AST, an index that starts copying records,
 * a phase that stops freeing - none of them break anything, they just make the build cost
 * five times what it costs today, on the box with the least memory. The measured baseline
 * this replaced was 1,237 MB, of which 1.12 GB was per-rule static AST and token caches.
 *
 * A FAILURE HERE IS A FINDING, NOT A NUMBER TO RAISE. Profile the build with the xhprof
 * driver (docs.dev/manifest_review/05_PROFILING.md) and report what grew.
 *
 * WHY A CHILD PROCESS. A cold build's peak can only be measured in a process that started
 * without a manifest, and this one is holding one. So the gate spawns rsx:manifest:build
 * with a scratch storage root (nothing exists there, so the build is cold by construction)
 * and reads the MANIFEST_PEAK_BYTES line the --_manifest-report-peak internal flag prints.
 *
 * WHY THE SCAN LIST IS PASSED EXPLICITLY. Every child of a test run carries --_test-run,
 * which adds the three test trees - 600-odd fixtures the budget is not about. The gate
 * states the production list outright so it measures the tree a served site indexes.
 */
class Manifest_Memory_Gate_Test extends Rsx_Test_Abstract
{
    // A build in a child process; this one touches no database.
    protected static $use_database_transactions = false;

    /** The cold reference build's ceiling, in bytes. */
    private const REFERENCE_BUDGET = 128 * 1024 * 1024;

    /** The synthetic tree's ceiling, in bytes. */
    private const SYNTHETIC_BUDGET = 256 * 1024 * 1024;

    /** How many times the reference file count the synthetic tree must reach. */
    private const SYNTHETIC_MULTIPLE = 5;

    /** Absolute path of the scratch storage root. */
    private static string $storage = '';

    /** The synthetic tree, relative to base_path(). */
    private static string $synthetic = '';

    /**
     * The scan list a served site indexes - config's answer, with no test-tree additions.
     *
     * @return array<int,string>
     */
    private static function __production_scan_roots(): array
    {
        return array_values(config('rsx.manifest.scan_directories', ['rsx']));
    }

    /**
     * Build in a child and return its peak memory in bytes.
     *
     * @param array<int,string> $scan_roots The EXACT list the child indexes
     */
    private static function __peak_of_build(array $scan_roots): int
    {
        static::$storage = storage_path('rsx-tmp/manifest-gate/' . getmypid());

        static::__remove_directory(static::$storage);
        ensure_directory(static::$storage);

        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_manifest-storage-root=' . static::$storage,
            '--_manifest-scan-roots=' . implode(',', $scan_roots),
            '--_manifest-report-peak',
        ], $output);

        static::__remove_directory(static::$storage);

        static::__assert_equals(
            0,
            $exit,
            "the gate's build failed:\n" . implode("\n", $output)
        );

        foreach ($output as $line) {
            if (str_starts_with(trim($line), 'MANIFEST_PEAK_BYTES=')) {
                return (int) substr(trim($line), strlen('MANIFEST_PEAK_BYTES='));
            }
        }

        static::__fail(
            "the child never printed MANIFEST_PEAK_BYTES (--_manifest-report-peak is what prints it):\n"
            . implode("\n", $output)
        );

        return 0;
    }

    private static function __remove_directory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }

    private static function __megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    /**
     * THE BUDGET: a cold build of the reference tree stays under 128 MB.
     */
    public static function test_cold_build_of_the_reference_tree_is_under_128mb()
    {
        $peak = static::__peak_of_build(static::__production_scan_roots());

        static::__assert_true(
            $peak < self::REFERENCE_BUDGET,
            'a cold build of the reference tree peaked at ' . static::__megabytes($peak)
            . ', over the ' . static::__megabytes(self::REFERENCE_BUDGET) . ' budget.'
            . ' This number is an owner ruling, not a tuning knob: something in the build has'
            . ' started retaining memory in proportion to the files it parses. Profile it'
            . ' (docs.dev/manifest_review/05_PROFILING.md) and report what grew.'
        );
    }

    /**
     * THE BUDGET AT SCALE: five times the files, and peak tracks the INDEX rather than the
     * parsing.
     *
     * The synthetic tree is GENERATED, and deliberately made of the simplest shapes the
     * build handles - a class, a JS class, a stylesheet. Cloning the reference tree's real
     * files would test the generator rather than the budget: a renamed copy of a model, an
     * action or a controller has to satisfy the whole conventions suite, and what this
     * asserts is a memory curve, not a fixture library.
     */
    public static function test_cold_build_of_a_five_times_tree_is_under_256mb()
    {
        $reference_count = static::__count_indexed_files();

        // Four times the reference count ON TOP of the reference tree is five times in all.
        $to_generate = $reference_count * (self::SYNTHETIC_MULTIPLE - 1);

        static::__generate_synthetic_tree($to_generate);

        try {
            $roots = static::__production_scan_roots();
            $roots[] = static::$synthetic;

            $peak = static::__peak_of_build($roots);

            static::__assert_true(
                $peak < self::SYNTHETIC_BUDGET,
                'a cold build of a tree ' . self::SYNTHETIC_MULTIPLE . 'x the reference size ('
                . ($reference_count + $to_generate) . ' files) peaked at ' . static::__megabytes($peak)
                . ', over the ' . static::__megabytes(self::SYNTHETIC_BUDGET) . ' budget.'
                . ' Peak is supposed to track the INDEX plus one file, not the number of files'
                . ' parsed. Profile it (docs.dev/manifest_review/05_PROFILING.md).'
            );
        } finally {
            static::__remove_directory(base_path(static::$synthetic));
        }
    }

    /**
     * How many files the reference tree indexes, from the manifest this process is holding.
     */
    private static function __count_indexed_files(): int
    {
        return count(\App\RSpade\Core\Manifest\Manifest::get_all());
    }

    /**
     * Write $count generated files under a fresh tree.
     *
     * Deterministic and cheap: three shapes in rotation, one directory per hundred files so
     * no single directory holds ten thousand entries.
     */
    private static function __generate_synthetic_tree(int $count): void
    {
        static::$synthetic = 'app/RSpade/temp/manifest_synthetic' . getmypid();

        $root = base_path(static::$synthetic);
        static::__remove_directory($root);
        ensure_directory($root);

        $namespace_root = 'App\\RSpade\\Temp\\ManifestSynthetic' . getmypid();

        for ($i = 0; $i < $count; $i++) {
            $bucket = 'b' . intdiv($i, 100);
            $directory = $root . '/' . $bucket;

            if (!is_dir($directory)) {
                ensure_directory($directory);
            }

            $namespace = $namespace_root . '\\B' . intdiv($i, 100);

            switch ($i % 3) {
                case 0:
                    file_put_contents(
                        $directory . '/synth_class_' . $i . '.php',
                        "<?php\n\nnamespace {$namespace};\n\nclass Synth_Class_{$i}\n{\n"
                        . "    public static function label(): string\n    {\n        return 'synth {$i}';\n    }\n}\n"
                    );
                    break;

                case 1:
                    file_put_contents(
                        $directory . '/synth_thing_' . $i . '.js',
                        "class Synth_Thing_{$i} {\n    static label() {\n        return 'synth {$i}';\n    }\n}\n"
                    );
                    break;

                default:
                    file_put_contents(
                        $directory . '/synth_style_' . $i . '.scss',
                        ".Synth_Style_{$i} {\n    &__body {\n        color: #333;\n    }\n}\n"
                    );
                    break;
            }
        }
    }
}
