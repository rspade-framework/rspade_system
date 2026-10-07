<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Cli;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// @ARTISAN-SPAWN-01-EXCEPTION - the property under test IS that artisan keeps the task's
// STDOUT and STDERR on two different streams. exec_safe() (and therefore Rsx_Artisan::run)
// merges them into one pipe by design, so every spawn here opens the two streams onto two
// files itself. Nothing holds a lock at this point, so there is nothing for a child to
// inherit.

/**
 * A #[Command] alias, driven for real: the console output contract, end to end.
 *
 *   STDOUT     the task's stdout - what it writes with $task->stdout() - and nothing else;
 *   STDERR     the task's stderr, every status() change, and the failure line of a failed
 *              run; -q silences it and never stdout;
 *   EXIT CODE  the run's return code: 0 for success, the code it returned (clamped to 1..255)
 *              for a failure, 1 for false or a throw.
 *
 * The aliases are rsx_test:echo, rsx_test:fail and rsx_test:exit, declared on this concern's
 * fixture service. What these prove that the php/ tests cannot: that an alias really is
 * registered with artisan, that it really is the rsx:task:run code path (identical stdout,
 * byte for byte), and that the two streams really are separate in a real process.
 *
 * Every run records a _tasks row, so each child is pointed at the TEST database (DB_DATABASE,
 * an environment fact) and the class provisions a clean baseline for the rows they commit.
 */
class Task_Command_Cli_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
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

        // THE CHILD MUST BE PART OF THIS TEST RUN. The commands under test are #[Command]s on a
        // FIXTURE service, and the test trees are indexed only while
        // Rsx_Test_Abstract::suite_is_running() (Manifest::scan_directories()). Rsx_Artisan
        // attaches this token to every child it spawns; this spawn is raw by design (the
        // stream contract is the subject), so it attaches it itself. artisan strips every
        // --_ token from argv pre-boot, so it cannot reach the streams under test.
        $command = array_merge([PHP_BINARY, base_path('artisan'), Rsx_Test_Abstract::TEST_RUN_FLAG], $argv);

        $environment = array_merge(getenv(), ['DB_DATABASE' => (string) config('database.connections.test.database')]);

        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdout_file, 'w'], 2 => ['file', $stderr_file, 'w']],
            $pipes,
            null,
            $environment
        );
        $exit_code = proc_close($process);

        $stdout = (string) file_get_contents($stdout_file);
        $stderr = (string) file_get_contents($stderr_file);

        unlink($stdout_file);
        unlink($stderr_file);

        return [$exit_code, $stdout, $stderr];
    }

    // -------------------------------------------------------------------------
    // stdout
    // -------------------------------------------------------------------------

    /**
     * Stdout is the task's stdout and nothing else - no banner, no narration, nothing a pipe
     * has to be taught to skip.
     */
    public static function test_stdout_is_the_tasks_stdout()
    {
        [$exit_code, $stdout] = static::__run(['rsx_test:echo', '--a=1']);

        static::__assert_equals(0, $exit_code);
        static::__assert_equals('{"echo":{"a":"1"}}' . "\n", $stdout);
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

    /**
     * There is no value envelope: --debug is a task parameter like any other option.
     */
    public static function test_every_option_is_a_task_parameter()
    {
        [$exit_code, $stdout] = static::__run(['rsx_test:echo', '--debug', '--data={"k":[1,2]}']);

        static::__assert_equals(0, $exit_code);
        $echo = json_decode($stdout, true)['echo'] ?? null;
        static::__assert_true($echo['debug'] ?? null, 'a bare flag is true: ' . $stdout);
        static::__assert_equals(['k' => [1, 2]], $echo['data'] ?? null, 'a JSON value is decoded');
        static::__assert_equals(2, count($echo), 'and nothing else is a parameter');
    }

    // -------------------------------------------------------------------------
    // stderr
    // -------------------------------------------------------------------------

    public static function test_stderr_carries_the_tasks_stderr()
    {
        [, $stdout, $stderr] = static::__run(['rsx_test:echo', '--a=1']);

        static::__assert_contains("echo_params started\nreceived 1 param(s)\n", $stderr);
        static::__assert_false(str_contains($stdout, 'echo_params started'), 'and stdout does not');
    }

    public static function test_quiet_empties_stderr_without_touching_stdout()
    {
        [$exit_code, $stdout, $stderr] = static::__run(['rsx_test:echo', '--a=1', '-q']);
        [, $loud_stdout] = static::__run(['rsx_test:echo', '--a=1']);

        static::__assert_equals(0, $exit_code);
        static::__assert_equals('', trim($stderr), '-q silences stderr; it was: ' . $stderr);
        static::__assert_equals($loud_stdout, $stdout, '-q never silences stdout');
    }

    // -------------------------------------------------------------------------
    // Failure and the exit code
    // -------------------------------------------------------------------------

    /**
     * A throwing task: exit 1, nothing on stdout, and the failure line on stderr after what
     * the task wrote there.
     */
    public static function test_a_throwing_task_exits_one_with_the_failure_on_stderr()
    {
        [$exit_code, $stdout, $stderr] = static::__run(['rsx_test:fail']);

        static::__assert_equals(1, $exit_code);
        static::__assert_equals('', $stdout);
        static::__assert_contains("about to throw\nTask failed: Exception: deliberate test failure\n", $stderr);
    }

    public static function test_the_exit_code_is_the_return_code_clamped()
    {
        $cases = ['0' => 0, '3' => 3, '255' => 255, '300' => 1, '-2' => 1];

        foreach ($cases as $code => $exit_code) {
            [$actual, , $stderr] = static::__run(['rsx_test:exit', '--code=' . $code]);
            static::__assert_equals($exit_code, $actual, "a return of {$code} exits {$exit_code}");

            if ($exit_code !== 0) {
                static::__assert_contains("Task failed: The task returned exit code {$code}.", $stderr);
            }
        }
    }

    public static function test_an_unknown_task_exits_one_naming_it()
    {
        [$exit_code, $stdout, $stderr] = static::__run(['rsx:task:run', 'Test_Echo_Service', 'no_such_task']);

        static::__assert_equals(1, $exit_code);
        static::__assert_equals('', $stdout);
        static::__assert_contains('[ERROR] Task no_such_task not found in service', $stderr);
    }

    // -------------------------------------------------------------------------
    // The run is recorded
    // -------------------------------------------------------------------------

    public static function test_a_command_run_is_a_recorded_inline_run()
    {
        $marker = 'cli-' . uniqid();
        static::__run(['rsx_test:echo', '--marker=' . $marker]);

        $run = Task_Run_Model::where('method', 'echo_params')->get()->first(fn ($r) => ($r->params['marker'] ?? null) === $marker);
        static::__assert_not_null($run, 'the child recorded its run in this database');
        static::__assert_equals(Task_Run_Model::ORIGIN_INLINE, (int) $run->origin_id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_equals(['echo_params started', 'received 1 param(s)'], array_column($run->output_after(null, ['stderr']), 'line'));

        DB::table('_tasks')->where('id', $run->id)->delete();
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
