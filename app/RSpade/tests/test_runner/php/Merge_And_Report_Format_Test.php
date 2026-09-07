<?php

namespace App\RSpade\Tests\TestRunner\Php;

use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\RSpade\Commands\Rsx\Rsx_Test_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The docker runner's OUTPUT is its contract with a human reading a test run, and it is a
 * contract two code paths have to satisfy: the sequential loop prints what $class::run()
 * just returned, and merge_and_report() prints what N containers sent back over a socket.
 * They must be indistinguishable - a developer who reads a docker run's output has to be
 * able to trust it the way they trust the single-process one.
 *
 * That is enforced structurally (both call print_class_results() and print_summary()) and
 * asserted here BOTH ways: the exact lines merge_and_report() produces from a synthetic
 * results.jsonl, and then the same class's results pushed straight through the shared
 * printer, which must produce byte-identical output.
 *
 * @PHP-REFLECT-01-EXCEPTION The subject under test IS a set of protected methods on an
 * artisan command. Their observable behaviour is console output and an exit code, and the
 * command cannot be driven through handle() without running the real suite (or docker).
 * Reflection is the only way to assert the format that both run paths depend on.
 */
class Merge_And_Report_Format_Test extends Rsx_Test_Abstract
{
    /**
     * Pure formatting and file IO - no database.
     *
     * @var bool
     */
    protected static $use_database_transactions = false;

    /**
     * Build a Rsx_Test_Command wired to a buffered, UNDECORATED output, so what the
     * assertions see is the plain text a developer reads with colors off. The input is
     * bound to the real command definition, so $this->option() works exactly as it does in
     * a live run.
     *
     * @param array $options
     * @return array{0: Rsx_Test_Command, 1: BufferedOutput}
     */
    protected static function __make_command(array $options = []): array
    {
        $command = new Rsx_Test_Command();
        $command->setLaravel(app());

        $input = new ArrayInput($options, $command->getDefinition());
        $buffer = new BufferedOutput();
        $buffer->setDecorated(false);

        $property = new ReflectionProperty($command, 'input');
        $property->setAccessible(true);
        $property->setValue($command, $input);

        $property = new ReflectionProperty($command, 'output');
        $property->setAccessible(true);
        $property->setValue($command, new SymfonyStyle($input, $buffer));

        return [$command, $buffer];
    }

    /**
     * Call a protected method on the command.
     *
     * @param Rsx_Test_Command $command
     * @param string $method
     * @param array $args
     * @return mixed
     */
    protected static function __call_protected(Rsx_Test_Command $command, string $method, array $args = [])
    {
        $reflection = new ReflectionMethod($command, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($command, $args);
    }

    /**
     * The four class records a merge has to handle, plus the fifth case (a selected class
     * with NO record at all) expressed by leaving it out of the file.
     *
     * @return array
     */
    protected static function __synthetic_selection(): array
    {
        return [
            ['fqcn' => 'Fixture\\Passing_Test', 'short' => 'Passing_Test', 'class_matches' => false, 'file' => ''],
            ['fqcn' => 'Fixture\\Failing_Test', 'short' => 'Failing_Test', 'class_matches' => false, 'file' => ''],
            ['fqcn' => 'Fixture\\Erroring_Test', 'short' => 'Erroring_Test', 'class_matches' => false, 'file' => ''],
            ['fqcn' => 'Fixture\\Vanished_Test', 'short' => 'Vanished_Test', 'class_matches' => false, 'file' => ''],
        ];
    }

    /**
     * @return array<string, array>
     */
    protected static function __passing_results(): array
    {
        return [
            'test_one' => ['status' => 'passed'],
            'test_two' => ['status' => 'skipped', 'message' => 'nothing to do here'],
        ];
    }

    /**
     * @return array<string, array>
     */
    protected static function __failing_results(): array
    {
        return [
            'test_three' => [
                'status' => 'failed',
                'message' => 'expected 1, got 2',
                'file' => '/var/www/html/fixture.php',
                'line' => 42,
            ],
        ];
    }

    /**
     * Write the synthetic results.jsonl a container run would have produced, run the merge,
     * and hand back the printed output and the exit code.
     *
     * The real timings file is saved and restored around the call: merge_and_report()
     * persists what it measured as the next run's ordering hint, and a test must not leave
     * fixture class names in a live cache.
     *
     * @return array{0: string, 1: int}
     */
    protected static function __run_merge(): array
    {
        [$command, $buffer] = self::__make_command();

        $records = [
            ['class' => 'Fixture\\Passing_Test', 'short' => 'Passing_Test',
                'results' => self::__passing_results(), 'duration' => 1.25],
            ['class' => 'Fixture\\Failing_Test', 'short' => 'Failing_Test',
                'results' => self::__failing_results(), 'duration' => 0.5],
            ['class' => 'Fixture\\Erroring_Test', 'short' => 'Erroring_Test',
                'results' => [], 'duration' => 0.1, 'error' => 'Fixture\\Erroring_Test::setup() blew up'],
        ];

        $results_path = tempnam(sys_get_temp_dir(), 'rsx-merge-');
        $lines = '';
        foreach ($records as $record) {
            $lines .= json_encode($record) . "\n";
        }
        file_put_contents($results_path, $lines);

        $timings_path = self::__call_protected($command, 'timings_path');
        $timings_backup = is_file($timings_path) ? file_get_contents($timings_path) : null;

        try {
            $exit_code = self::__call_protected(
                $command,
                'merge_and_report',
                [$results_path, self::__synthetic_selection(), []]
            );
        } finally {
            unlink($results_path);
            if ($timings_backup === null) {
                @unlink($timings_path);
            } else {
                file_put_contents($timings_path, $timings_backup);
            }
        }

        return [$buffer->fetch(), (int) $exit_code];
    }

    /**
     * Every line of a merged run, in order. This is the format assertion: a change to any
     * of these strings changes what every developer reads after every parallel run.
     */
    public static function test_merge_and_report_prints_the_sequential_format()
    {
        [$output, $exit_code] = self::__run_merge();
        $lines = explode("\n", $output);

        $expected = [
            'Running: Passing_Test',
            '  [OK] test_one',
            '  - test_two (skipped)',
            '    nothing to do here',
            '',
            'Running: Failing_Test',
            '  [FAIL] test_three',
            '    expected 1, got 2',
            '    at /var/www/html/fixture.php:42',
            '',
            'Running: Erroring_Test',
            '  Error running test class: Fixture\\Erroring_Test::setup() blew up',
            '',
            'Running: Vanished_Test',
            '  [FAIL] Vanished_Test',
            '    class produced no result (worker terminated before it finished)',
            '',
            'Test Summary',
            '============',
            // A class-level failure - a throw from $class::run(), or a class that produced
            // no record at all - adds to `failed` WITHOUT adding to `tests`, because no
            // test method ever reported. That is what the sequential loop's catch does
            // too, so `Total` counts methods that answered and `Failed` counts everything
            // that went wrong. The two do not have to add up, and never did.
            'Total:   3',
            'Passed:  1',
            'Failed:  3',
            'Skipped: 1',
            '',
            'Tests failed!',
        ];

        foreach ($expected as $index => $line) {
            static::__assert_equals(
                $line,
                $lines[$index] ?? '(missing)',
                'merged output line ' . ($index + 1)
            );
        }

        static::__assert_equals(1, $exit_code, 'a merge with failures exits 1');
    }

    /**
     * Totals count TESTS, not classes: the two failures here are one failed test method and
     * one class that produced nothing. A class-level error adds to `failed` without adding
     * to `tests`, exactly as the sequential loop's catch does.
     */
    public static function test_a_class_error_and_a_missing_class_both_count_as_failures()
    {
        [$output] = self::__run_merge();

        static::__assert_contains('Total:   3', $output, 'three test METHODS reported results');
        static::__assert_contains(
            'Failed:  3',
            $output,
            'one failed method, one class-level throw and one class that produced nothing'
        );
        static::__assert_contains(
            'Error running test class: Fixture\\Erroring_Test::setup() blew up',
            $output,
            'the class-level throw is reported verbatim'
        );
        static::__assert_contains(
            'class produced no result (worker terminated before it finished)',
            $output,
            'a selected class with no record is a FAILURE, never a silent drop'
        );
    }

    /**
     * The other half of the one-printer guarantee: results pushed straight through
     * print_class_results() - which is what the sequential loop does with $class::run()'s
     * return value - produce the same lines the merge produced for the same class.
     */
    public static function test_the_sequential_printer_produces_identical_lines()
    {
        [$command, $buffer] = self::__make_command();

        $totals = ['tests' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];

        $command->getOutput()->writeln('Running: Passing_Test');
        self::__call_protected($command, 'print_class_results', [self::__passing_results(), false, [], &$totals]);
        $command->getOutput()->writeln('');
        $command->getOutput()->writeln('Running: Failing_Test');
        self::__call_protected($command, 'print_class_results', [self::__failing_results(), false, [], &$totals]);

        $sequential = $buffer->fetch();

        [$merged] = self::__run_merge();
        $merged_head = substr($merged, 0, strlen($sequential));

        static::__assert_equals(
            $sequential,
            $merged_head,
            'the sequential printer and the docker merge emit the same bytes for the same results'
        );

        static::__assert_equals(3, $totals['tests'], 'three methods counted');
        static::__assert_equals(1, $totals['passed'], 'one passed');
        static::__assert_equals(1, $totals['failed'], 'one failed');
        static::__assert_equals(1, $totals['skipped'], 'one skipped');
    }

    /**
     * A run with nothing failing exits 0 and says so; a run with no tests at all warns
     * rather than claiming success.
     */
    public static function test_print_summary_exit_codes()
    {
        [$command, $buffer] = self::__make_command();

        $passing = ['tests' => 5, 'passed' => 5, 'failed' => 0, 'skipped' => 0];
        $code = self::__call_protected($command, 'print_summary', [$passing]);
        static::__assert_equals(0, $code, 'a clean run exits 0');
        static::__assert_contains('All tests passed!', $buffer->fetch(), 'and says so');

        [$command, $buffer] = self::__make_command();
        $empty = ['tests' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];
        $code = self::__call_protected($command, 'print_summary', [$empty]);
        static::__assert_equals(0, $code, 'an empty run exits 0');
        static::__assert_contains('No tests were run.', $buffer->fetch(), 'but warns rather than claiming success');
    }

    /**
     * A --filter narrows the METHODS printed, in the merge exactly as in the sequential
     * loop - a class whose name matched the filter reports every one of its methods.
     */
    public static function test_a_filter_narrows_methods_in_the_merge_too()
    {
        [$command, $buffer] = self::__make_command();

        $totals = ['tests' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];
        self::__call_protected(
            $command,
            'print_class_results',
            [self::__passing_results(), false, ['test_one'], &$totals]
        );

        $output = $buffer->fetch();
        static::__assert_contains('[OK] test_one', $output, 'the matching method is printed');
        static::__assert_false(
            strpos($output, 'test_two') !== false,
            'a method the filter did not match is not printed'
        );
        static::__assert_equals(1, $totals['tests'], 'and is not counted either');

        [$command, $buffer] = self::__make_command();
        $totals = ['tests' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];
        self::__call_protected(
            $command,
            'print_class_results',
            [self::__passing_results(), true, ['Passing'], &$totals]
        );

        static::__assert_equals(
            2,
            $totals['tests'],
            'when the CLASS name matched the filter, every method reports'
        );
    }
}
