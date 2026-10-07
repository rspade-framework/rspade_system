<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Test_Echo_Service;

/**
 * A run's output: what it writes with stdout() / stderr(), what it prints with echo, and the
 * console streams a runner hands it.
 *
 *   - every line is a _task_output row on its stream, in order;
 *   - a CONSOLE RUNNER (rsx:task:run, a #[Command]) passes its stdout and stderr, and lines are
 *     echoed there live - stdout() to stdout, stderr() and status() changes to stderr; an
 *     operator line is never echoed; with no streams (every other caller) nothing is echoed;
 *   - echo / print / an output buffer the task left open are CAPTURED as stdout rows, in order
 *     with the task's own calls, and pass through to the process's output unchanged.
 *
 * Streams are php://memory handles standing in for STDOUT and STDERR. Rows roll back with the
 * per-test transaction.
 */
class Task_Output_Test extends Rsx_Test_Abstract
{
    /** @return resource */
    private static function __stream()
    {
        return fopen('php://memory', 'r+');
    }

    /** @param resource $stream */
    private static function __read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private static function __instance(): Task_Instance
    {
        return Task_Instance::find(Task_Runner::insert_row(Test_Echo_Service::class, 'echo_params', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields()));
    }

    private static function __lines(int $task_id, array $streams = ['stdout', 'stderr', 'operator']): array
    {
        return array_column(Task_Run_Model::find($task_id)->output_after(null, $streams), 'line');
    }

    // -------------------------------------------------------------------------
    // Rows
    // -------------------------------------------------------------------------

    public static function test_each_line_is_a_row_on_its_stream()
    {
        $task = static::__instance();
        $task->stdout("out one\nout two\n");
        $task->stderr('err one');
        $task->stdout("crlf\r\nline");
        $task->flush();

        static::__assert_equals(['out one', 'out two', 'crlf', 'line'], static::__lines($task->get_id(), ['stdout']), 'one row per line; a trailing newline adds none');
        static::__assert_equals(['err one'], static::__lines($task->get_id(), ['stderr']));
        static::__assert_equals(['out one', 'out two', 'err one', 'crlf', 'line'], static::__lines($task->get_id()), 'in the order written');
    }

    // -------------------------------------------------------------------------
    // Console streams
    // -------------------------------------------------------------------------

    public static function test_console_streams_echo_each_stream_live()
    {
        $stdout = static::__stream();
        $stderr = static::__stream();
        $task = static::__instance();
        $task->set_console_streams($stdout, $stderr);

        $task->stdout('to stdout');
        static::__assert_equals("to stdout\n", static::__read($stdout), 'written before the next line exists');

        $task->stderr('to stderr');
        $task->status('a status');
        $task->status('a status');
        Task_Instance::record_operator_line($task->get_id(), 'an operator line');

        static::__assert_equals("to stdout\n", static::__read($stdout), 'stdout carries stdout only');
        static::__assert_equals("to stderr\na status\n", static::__read($stderr), 'stderr carries stderr and each status change; never an operator line');

        fclose($stdout);
        fclose($stderr);
    }

    /**
     * -q: the runner passes no stderr. stdout is still echoed, and every line is still recorded.
     */
    public static function test_a_quiet_runner_still_echoes_stdout_and_records_everything()
    {
        $stdout = static::__stream();
        $task = static::__instance();
        $task->set_console_streams($stdout, null);

        $task->stdout('value');
        $task->stderr('narration');
        $task->flush();

        static::__assert_equals("value\n", static::__read($stdout));
        static::__assert_equals(['value', 'narration'], static::__lines($task->get_id()), 'the run records both');

        fclose($stdout);
    }

    public static function test_internal_with_streams_narrates_the_run_and_its_failure()
    {
        $stdout = static::__stream();
        $stderr = static::__stream();

        $run = Task::internal('Test_Echo_Service', 'echo_params', ['a' => 1], [$stdout, $stderr]);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_equals('{"echo":{"a":1}}' . "\n", static::__read($stdout));
        static::__assert_equals("echo_params started\nreceived 1 param(s)\n", static::__read($stderr));

        $fail_stdout = static::__stream();
        $fail_stderr = static::__stream();
        static::__assert_throws(\Exception::class, fn () => Task::internal('Test_Echo_Service', 'always_fail', [], [$fail_stdout, $fail_stderr]), 'deliberate test failure');
        static::__assert_equals('', static::__read($fail_stdout));
        static::__assert_equals("about to throw\nTask failed: Exception: deliberate test failure\n", static::__read($fail_stderr), 'the failure line follows what the task wrote');

        foreach ([$stdout, $stderr, $fail_stdout, $fail_stderr] as $stream) {
            fclose($stream);
        }
    }

    // -------------------------------------------------------------------------
    // Echo capture
    // -------------------------------------------------------------------------

    /**
     * What a task prints is recorded as stdout rows, in order with its own calls: each complete
     * line as it is printed, a buffer the task left open flushed into the capture, the trailing
     * partial line at the end. The printed text also passes through to this process's output
     * (swallowed here by an outer buffer), and is not echoed a second time to the console
     * stream.
     */
    public static function test_printed_output_is_captured_in_order()
    {
        $stdout = static::__stream();
        $level = ob_get_level();

        ob_start();
        try {
            $run = Task::internal('Task_Exec_Fixture_Service', 'prints_output', [], [$stdout, null]);
        } finally {
            $passed_through = (string) ob_get_clean();
        }

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $run->status_id);
        static::__assert_equals(
            ['before the echo', 'first printed line', 'second printed line', 'between', 'from a buffer left open', 'trailing partial'],
            array_column($run->output_after(), 'line'),
            'printed lines interleave with the task\'s own lines in the order they happened'
        );
        static::__assert_equals(
            ['stdout', 'stdout', 'stdout', 'stderr', 'stdout', 'stdout'],
            array_column($run->output_after(), 'stream')
        );
        static::__assert_equals("first printed line\nsecond printed line\nfrom a buffer left open\ntrailing partial", $passed_through, 'printed text passes through unchanged');
        static::__assert_equals("before the echo\n", static::__read($stdout), 'captured text is recorded, not echoed again');
        static::__assert_equals($level, ob_get_level(), 'the buffer the task left open does not outlive the run');

        fclose($stdout);
    }

    public static function test_printed_output_survives_a_throw()
    {
        $level = ob_get_level();
        $before = (int) Task_Run_Model::max('id');

        ob_start();
        try {
            static::__assert_throws(\Exception::class, fn () => Task::internal('Task_Exec_Fixture_Service', 'prints_then_throws'), 'fixture exploded after printing');
        } finally {
            ob_end_clean();
        }

        $run = Task_Run_Model::where('id', '>', $before)->where('method', 'prints_then_throws')->first();
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $run->status_id);
        static::__assert_equals(['printed before the throw'], array_column($run->output_after(null, ['stdout']), 'line'), 'the printed line was recorded');
        static::__assert_equals($level, ob_get_level(), 'the capture closed its buffer on the throw');
    }
}
