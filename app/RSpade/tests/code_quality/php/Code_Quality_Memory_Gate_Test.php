<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE MEMORY BUDGET OF A COLD rsx:check, AS TWO NUMBERS.
 *
 * rsx:check is the pre-commit gate. COLD - no validation ledger and no sanitized-content
 * cache, so every rule judges every file - is its most expensive run, and the one a fresh
 * checkout or a changed rule gets. Its peak must be proportional to the index plus a constant
 * bounded by the largest single file (the rule Source_Cache implements), never to the number
 * of files checked:
 *
 *   - a cold check of the reference tree stays under 160 MB (measured: 113 MB);
 *   - a cold check of a synthetic tree five times its size stays under 256 MB (measured:
 *     186 MB), below the development container's 320 MB CLI limit - so the gate holds even
 *     where the command's own raised limit (Rsx_Check_Command::PHP_MEMORY_LIMIT_MB) is not
 *     in force.
 *
 * The failures this guards against are invisible: a per-process memo of every file's
 * sanitized copy took a large application's cold check from about 170 MB to 445 MB, and the
 * validation ledger's write once held the whole ledger three times over; nothing broke until
 * the box's limit did.
 *
 * A FAILURE HERE IS A FINDING, NOT A NUMBER TO RAISE: find what started retaining memory per
 * file and report it.
 *
 * WHY CHILD PROCESSES. Cold can only be measured in a process that started with nothing, so
 * the gate builds the manifest into scratch build and tmp roots in one child (the build has
 * its own gate, Manifest_Memory_Gate_Test), then runs rsx:check over the same roots in a
 * second - the ledger and the sanitized cache live under tmp, so the check is cold - and reads
 * the CHECK_PEAK_BYTES line --_check-report-peak prints at shutdown. The scan list is passed
 * explicitly, as the manifest gate does: every child of a test run carries --_test-run, which
 * would add the test trees.
 */
class Code_Quality_Memory_Gate_Test extends Rsx_Test_Abstract
{
    // A check in a child process; this one touches no database.
    protected static $use_database_transactions = false;

    /** The cold reference check's ceiling, in bytes. */
    private const REFERENCE_BUDGET = 160 * 1024 * 1024;

    /** The synthetic tree's ceiling, in bytes. */
    private const SYNTHETIC_BUDGET = 256 * 1024 * 1024;

    /** How many times the reference file count the synthetic tree must reach. */
    private const SYNTHETIC_MULTIPLE = 5;

    /** How many PHP classes deep each synthetic inheritance chain goes. */
    private const CHAIN_DEPTH = 4;

    /** The synthetic tree, relative to base_path(). */
    private static string $synthetic = '';

    /**
     * @return array<int,string>
     */
    private static function __production_scan_roots(): array
    {
        return array_values(config('rsx.manifest.scan_directories', ['rsx']));
    }

    /**
     * Run a cold rsx:check over $scan_roots in a child and return its peak memory in bytes.
     *
     * The child's exit code is not asserted: the synthetic tree is not written to satisfy
     * every convention rule, and what this measures is memory, not findings. A child that
     * died at its memory limit still prints the peak (a shutdown function), and is failed
     * here by name.
     *
     * @param array<int,string> $scan_roots
     */
    private static function __peak_of_check(array $scan_roots): int
    {
        // Short: the node services put their unix sockets under the tmp root.
        $scratch = Rsx_Project_Paths::tmp_path('cqgate' . getmypid());
        rmdir_recursive($scratch);
        ensure_directory($scratch . '/build');
        ensure_directory($scratch . '/tmp');

        $roots = [
            '--_rsx-build-root=' . $scratch . '/build',
            '--_rsx-tmp-root=' . $scratch . '/tmp',
            '--_manifest-scan-roots=' . implode(',', $scan_roots),
        ];
        $output = [];

        try {
            $build_output = [];
            static::__assert_equals(0, Rsx_Artisan::run('rsx:manifest:build', $roots, $build_output), "the gate's manifest build failed:\n" . implode("\n", $build_output));

            Rsx_Artisan::run('rsx:check', [
                ...$roots,
                '--_check-report-peak',
            ], $output);
        } finally {
            rmdir_recursive($scratch);
        }

        $text = implode("\n", $output);

        static::__assert_false(
            str_contains($text, 'Allowed memory size'),
            "the cold check died at its memory limit:\n" . $text
        );

        foreach ($output as $line) {
            if (str_starts_with(trim($line), 'CHECK_PEAK_BYTES=')) {
                return (int) substr(trim($line), strlen('CHECK_PEAK_BYTES='));
            }
        }

        static::__fail("the child never printed CHECK_PEAK_BYTES (--_check-report-peak prints it):\n" . $text);

        return 0;
    }

    private static function __megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    /**
     * THE BUDGET: a cold check of the reference tree stays under 160 MB.
     */
    public static function test_cold_check_of_the_reference_tree_is_under_160mb()
    {
        $peak = static::__peak_of_check(static::__production_scan_roots());

        static::__assert_true(
            $peak < self::REFERENCE_BUDGET,
            'a cold rsx:check of the reference tree peaked at ' . static::__megabytes($peak)
            . ', over the ' . static::__megabytes(self::REFERENCE_BUDGET) . ' budget: something in'
            . ' the check has started retaining memory in proportion to the files it checks.'
        );
    }

    /**
     * THE BUDGET AT SCALE: five times the files, and the peak still tracks the index plus one
     * file. The synthetic PHP classes are large and chained by inheritance, so the cross-file
     * rules parse ancestors and the source cache's bounds are exercised, not just the count.
     */
    public static function test_cold_check_of_a_five_times_tree_is_under_256mb()
    {
        $reference_count = static::__count_production_files();
        $to_generate = $reference_count * (self::SYNTHETIC_MULTIPLE - 1);

        static::__generate_synthetic_tree($to_generate);

        try {
            $roots = static::__production_scan_roots();
            $roots[] = static::$synthetic;

            $peak = static::__peak_of_check($roots);

            static::__assert_true(
                $peak < self::SYNTHETIC_BUDGET,
                'a cold rsx:check of a tree ' . self::SYNTHETIC_MULTIPLE . 'x the reference size ('
                . ($reference_count + $to_generate) . ' files) peaked at ' . static::__megabytes($peak)
                . ', over the ' . static::__megabytes(self::SYNTHETIC_BUDGET) . ' budget. Peak is'
                . ' supposed to track the index plus one file: find what is retained per file checked.'
            );
        } finally {
            rmdir_recursive(base_path(static::$synthetic));
        }
    }

    /**
     * How many files the PRODUCTION scan list indexes. This process holds the test run's
     * manifest, which also carries the test trees; they are not part of the tree measured.
     */
    private static function __count_production_files(): int
    {
        $roots = static::__production_scan_roots();
        $count = 0;

        foreach (array_keys(\App\RSpade\Core\Manifest\Manifest::get_all()) as $key) {
            foreach ($roots as $root) {
                if (str_starts_with($key, rtrim($root, '/') . '/')) {
                    $count++;

                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Write $count generated files: PHP classes, JS classes and stylesheets in rotation, one
     * directory per hundred files, each class file named after its class (the app/RSpade
     * filename rule). Each PHP class carries ten methods (about 1 KB of source)
     * and extends the previous PHP class in chains of CHAIN_DEPTH, with no method overridden,
     * so the ancestry rules walk real chains without any finding. The depth is a realistic
     * hierarchy's: the manifest records every class's inherited methods, so a tree of
     * thirty-deep chains measures that, not the check.
     */
    private static function __generate_synthetic_tree(int $count): void
    {
        static::$synthetic = 'app/RSpade/temp/cq_synthetic' . getmypid();

        $root = base_path(static::$synthetic);
        rmdir_recursive($root);
        ensure_directory($root);

        $namespace_root = 'App\\RSpade\\Temp\\CqSynthetic' . getmypid();
        $previous_class = null;
        $php_classes = 0;

        for ($i = 0; $i < $count; $i++) {
            $bucket_index = intdiv($i, 100);
            $directory = $root . '/b' . $bucket_index;

            if (!is_dir($directory)) {
                ensure_directory($directory);
            }

            $namespace = $namespace_root . '\\B' . $bucket_index;

            switch ($i % 3) {
                case 0:
                    $class = 'Cq_Synth_Class_' . $i;
                    // The first class of each chain extends nothing. A parent is always in the
                    // same directory, so it is always in the same namespace.
                    $extends = ($php_classes % self::CHAIN_DEPTH !== 0 && $previous_class !== null
                        && $previous_class[0] === $bucket_index) ? ' extends ' . $previous_class[1] : '';
                    $php_classes++;
                    $methods = '';

                    for ($m = 0; $m < 10; $m++) {
                        $methods .= "    public static function label_{$i}_{$m}(): string\n    {\n"
                            . "        return 'synth {$i} {$m}';\n    }\n\n";
                    }

                    file_put_contents(
                        $directory . '/' . $class . '.php',
                        "<?php\n\nnamespace {$namespace};\n\nclass {$class}{$extends}\n{\n{$methods}}\n"
                    );
                    $previous_class = [$bucket_index, $class];
                    break;

                case 1:
                    file_put_contents(
                        $directory . '/Cq_Synth_Thing_' . $i . '.js',
                        "class Cq_Synth_Thing_{$i} {\n    static label() {\n        return 'synth {$i}';\n    }\n}\n"
                    );
                    break;

                default:
                    file_put_contents(
                        $directory . '/cq_synth_style_' . $i . '.scss',
                        ".Cq_Synth_Style_{$i} {\n    &__body {\n        color: #333;\n    }\n}\n"
                    );
                    break;
            }
        }
    }
}
