<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Cli;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// @ARTISAN-SPAWN-01-EXCEPTION - the property under test IS that artisan keeps the VALUE and
// the NARRATION on two different streams. exec_safe() (and therefore Rsx_Artisan::run)
// merges them into one pipe by design, so every spawn here redirects the two streams to two
// files itself. Nothing holds a lock at this point, so there is nothing for a child to
// inherit.

/**
 * A #[Command] alias, driven for real: the output contract, end to end.
 *
 * The aliases are rsx_test:echo and rsx_test:fail, declared on this concern's fixture
 * service - side-effect-free tasks, one returning a value and one throwing, so a full
 * transcript can be asserted without a row, a file or a template feature being touched.
 *
 * What these prove that the php/ tests cannot: that an alias really is registered with
 * artisan, that it really is the rsx:task:run code path (identical stdout, byte for byte),
 * and that the two streams really are separate in a real process.
 */
class Task_Command_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run artisan with stdout and stderr captured to separate files.
     *
     * @param array<int, string> $argv Command name followed by its arguments.
     * @return array{0: int, 1: string, 2: string} [exit code, stdout, stderr]
     */
    private static function __run(array $argv): array
    {
        $stdout_file = tempnam(sys_get_temp_dir(), 'rsx-task-cmd-out-');
        $stderr_file = tempnam(sys_get_temp_dir(), 'rsx-task-cmd-err-');

        // THE CHILD MUST BE PART OF THIS TEST RUN. The command under test, rsx_test:echo, is
        // a #[Command] on a FIXTURE service, and the test trees are indexed only while
        // Rsx_Test_Abstract::suite_is_running() (Manifest::scan_directories()). Rsx_Artisan
        // attaches this token to every child it spawns; this spawn is raw by design (the
        // stream contract is the subject), so it attaches it itself. artisan strips every
        // --_ token from argv pre-boot, so it cannot reach the stdout/stderr under test.
        $parts = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            escapeshellarg(Rsx_Test_Abstract::TEST_RUN_FLAG),
        ];

        foreach ($argv as $token) {
            $parts[] = escapeshellarg($token);
        }

        $command = implode(' ', $parts)
            . ' > ' . escapeshellarg($stdout_file)
            . ' 2> ' . escapeshellarg($stderr_file);

        $exit_code = 0;
        \passthru('bash -c ' . escapeshellarg($command), $exit_code);

        $stdout = (string) file_get_contents($stdout_file);
        $stderr = (string) file_get_contents($stderr_file);

        unlink($stdout_file);
        unlink($stderr_file);

        return [$exit_code, $stdout, $stderr];
    }

    // -------------------------------------------------------------------------
    // The value, on stdout
    // -------------------------------------------------------------------------

    /**
     * Stdout is the return value as JSON and nothing else - no banner, no narration,
     * nothing a pipe has to be taught to skip.
     */
    public static function test_stdout_is_the_value_as_json()
    {
        [$exit_code, $stdout] = static::__run(['rsx_test:echo', '--a=1']);

        static::__assert_equals(0, $exit_code);

        $decoded = json_decode(trim($stdout), true);
        static::__assert_equals(JSON_ERROR_NONE, json_last_error(), 'stdout must parse as JSON: ' . $stdout);
        static::__assert_equals(['echo' => ['a' => '1']], $decoded);
    }

    /**
     * The alias IS rsx:task:run with the service and method already decided. Identical
     * stdout is the whole claim, so it is asserted byte for byte.
     */
    public static function test_the_alias_stdout_equals_the_rsx_task_run_stdout()
    {
        [, $alias_stdout] = static::__run(['rsx_test:echo', '--a=1']);
        [, $run_stdout] = static::__run(['rsx:task:run', 'Test_Echo_Service', 'echo_params', '--a=1']);

        static::__assert_equals($run_stdout, $alias_stdout);
    }

    public static function test_debug_wraps_the_value()
    {
        [$exit_code, $stdout] = static::__run(['rsx_test:echo', '--debug']);

        static::__assert_equals(0, $exit_code);

        $decoded = json_decode(trim($stdout), true);
        static::__assert_true($decoded['success'], 'the --debug envelope carries success: ' . $stdout);
        static::__assert_equals(['echo' => []], $decoded['result']);
    }

    // -------------------------------------------------------------------------
    // The narration, on stderr
    // -------------------------------------------------------------------------

    public static function test_stderr_carries_the_info_lines_live()
    {
        [, , $stderr] = static::__run(['rsx_test:echo', '--a=1']);

        static::__assert_contains('[info] echo_params started', $stderr);
        static::__assert_contains('[info] received 1 param(s)', $stderr);
    }

    public static function test_quiet_empties_stderr_without_touching_stdout()
    {
        [$exit_code, $stdout, $stderr] = static::__run(['rsx_test:echo', '--a=1', '-q']);
        [, $loud_stdout] = static::__run(['rsx_test:echo', '--a=1']);

        static::__assert_equals(0, $exit_code);
        static::__assert_equals('', trim($stderr), '-q silences the narration');
        static::__assert_equals($loud_stdout, $stdout, '-q never silences the value');
    }

    // -------------------------------------------------------------------------
    // Failure
    // -------------------------------------------------------------------------

    /**
     * A throwing task: the JSON error on stdout, exit 1, and the [error] narration on
     * stderr ahead of it.
     */
    public static function test_a_throwing_task_exits_one_with_the_json_error_on_stdout()
    {
        [$exit_code, $stdout, $stderr] = static::__run(['rsx_test:fail']);

        static::__assert_equals(1, $exit_code);

        $decoded = json_decode(trim($stdout), true);
        static::__assert_equals(JSON_ERROR_NONE, json_last_error(), 'stdout must parse as JSON: ' . $stdout);
        static::__assert_false($decoded['success']);
        static::__assert_equals('deliberate test failure', $decoded['error']);

        static::__assert_contains('[error] Task failed: deliberate test failure', $stderr);
    }

    // -------------------------------------------------------------------------
    // rsx:task:list
    // -------------------------------------------------------------------------

    /**
     * The COMMAND column: the alias name beside the task that declares one, '-' beside
     * every task that does not.
     */
    public static function test_task_list_shows_the_command_column()
    {
        [$exit_code, $stdout] = static::__run(['rsx:task:list']);

        static::__assert_equals(0, $exit_code);
        static::__assert_contains('COMMAND', $stdout);

        $echo_line = static::__line_containing($stdout, 'echo_params');
        static::__assert_contains('rsx_test:echo', $echo_line);

        // A task with no #[Command] shows a dash, never a blank column.
        $bare_line = static::__line_containing($stdout, 'cleanup_request_log');
        static::__assert_contains('-', $bare_line);
        static::__assert_false(str_contains($bare_line, ':'), 'a task with no #[Command] names none: ' . $bare_line);
    }

    /**
     * The first line of $text containing $needle, or '' when there is none.
     */
    private static function __line_containing(string $text, string $needle): string
    {
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        return '';
    }
}
