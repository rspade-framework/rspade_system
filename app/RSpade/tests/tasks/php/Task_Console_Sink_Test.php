<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Test_Echo_Service;

/**
 * The live console sink: what a runner sees while a task narrates itself.
 *
 * The sink is display only. Every test below asserts BOTH halves - what reached the stream
 * and what the in-memory log holds - because the whole contract is that a console
 * transcript and Task::status()['logs'] read identically.
 *
 * Immediate instances only (no id, no row), so no DB writes: default isolation.
 */
class Task_Console_Sink_Test extends Rsx_Test_Abstract
{
    /**
     * A detached in-memory stream standing in for STDERR.
     *
     * @return resource
     */
    private static function __sink()
    {
        return fopen('php://memory', 'r+');
    }

    /**
     * Everything written to a sink so far.
     *
     * @param resource $sink
     */
    private static function __read($sink): string
    {
        rewind($sink);

        return (string) stream_get_contents($sink);
    }

    private static function __instance(): Task_Instance
    {
        return new Task_Instance(Test_Echo_Service::class, 'echo_params', [], 'default', true);
    }

    // -------------------------------------------------------------------------
    // The sink itself
    // -------------------------------------------------------------------------

    public static function test_no_sink_means_nothing_is_written_anywhere_but_memory()
    {
        $task = static::__instance();
        $task->info('quiet as the grave');

        static::__assert_count(1, $task->get_logs());
        static::__assert_contains('[info] quiet as the grave', $task->get_logs()[0]);
    }

    public static function test_each_line_reaches_the_sink_and_the_log_identically()
    {
        $sink = static::__sink();
        $task = static::__instance();
        $task->set_console_sink($sink);

        $task->info('one');
        $task->error('two');
        $task->debug('three');

        $written = static::__read($sink);
        fclose($sink);

        $logs = $task->get_logs();
        static::__assert_count(3, $logs);

        // The stream holds exactly the log lines, in order, one per line.
        static::__assert_equals(implode("\n", $logs) . "\n", $written);

        static::__assert_contains('[info] one', $written);
        static::__assert_contains('[error] two', $written);
        static::__assert_contains('[debug] three', $written);
    }

    /**
     * "Live" is the property that matters - an operator watching a long task must see
     * line one before line two exists, not one buffered dump at the end.
     */
    public static function test_lines_arrive_live_rather_than_at_the_end()
    {
        $sink = static::__sink();
        $task = static::__instance();
        $task->set_console_sink($sink);

        $task->info('first');
        static::__assert_contains('first', static::__read($sink));
        static::__assert_false(str_contains(static::__read($sink), 'second'));

        $task->info('second');
        static::__assert_contains('second', static::__read($sink));

        fclose($sink);
    }

    public static function test_detaching_the_sink_stops_the_echo()
    {
        $sink = static::__sink();
        $task = static::__instance();

        $task->set_console_sink($sink);
        $task->info('heard');
        $task->set_console_sink(null);
        $task->info('unheard');

        $written = static::__read($sink);
        fclose($sink);

        static::__assert_contains('heard', $written);
        static::__assert_false(str_contains($written, 'unheard'), 'a detached sink receives nothing');
        static::__assert_count(2, $task->get_logs(), 'detaching the sink never drops a log line');
    }

    // -------------------------------------------------------------------------
    // update_progress formatting
    // -------------------------------------------------------------------------

    public static function test_update_progress_formats_as_bracketed_percent_with_message()
    {
        $sink = static::__sink();
        $task = static::__instance();
        $task->set_console_sink($sink);

        $task->update_progress(45, 'Halfway');

        $written = static::__read($sink);
        fclose($sink);

        static::__assert_contains('[info] [45%] Halfway', $written);
        static::__assert_contains('[info] [45%] Halfway', $task->get_logs()[0]);
    }

    public static function test_update_progress_without_a_message_is_the_percent_alone()
    {
        $task = static::__instance();
        $task->update_progress(7);

        static::__assert_contains('[info] [7%]', $task->get_logs()[0]);
    }

    public static function test_update_progress_clamps_to_the_zero_hundred_range()
    {
        $task = static::__instance();
        $task->update_progress(-5);
        $task->update_progress(250);

        static::__assert_contains('[0%]', $task->get_logs()[0]);
        static::__assert_contains('[100%]', $task->get_logs()[1]);
    }

    // -------------------------------------------------------------------------
    // Task::internal() - the runner-only seam
    // -------------------------------------------------------------------------

    /**
     * The default. Application code - a web request, a task calling another task - calls
     * internal() with three arguments and prints to nobody's console.
     */
    public static function test_internal_without_a_sink_writes_nothing()
    {
        $sink = static::__sink();

        $result = Task::internal('Test_Echo_Service', 'echo_params', ['a' => 1]);

        static::__assert_equals(['echo' => ['a' => 1]], $result);
        static::__assert_equals('', static::__read($sink), 'no sink was passed, so nothing may be written');

        fclose($sink);
    }

    public static function test_internal_with_a_sink_narrates_the_run()
    {
        $sink = static::__sink();

        $result = Task::internal('Test_Echo_Service', 'echo_params', ['a' => 1], $sink);

        $written = static::__read($sink);
        fclose($sink);

        static::__assert_equals(['echo' => ['a' => 1]], $result);
        static::__assert_contains('[info] echo_params started', $written);
        static::__assert_contains('[info] received 1 param(s)', $written);
    }

    /**
     * A failure narrates its [error] line before the exception leaves internal() - which
     * is what puts it on stderr ahead of the JSON error the runner prints to stdout.
     */
    public static function test_internal_with_a_sink_narrates_a_failure_before_rethrowing()
    {
        $sink = static::__sink();

        static::__assert_throws(
            \Exception::class,
            fn() => Task::internal('Test_Echo_Service', 'always_fail', [], $sink),
            'deliberate test failure'
        );

        $written = static::__read($sink);
        fclose($sink);

        static::__assert_contains('[info] about to throw', $written);
        static::__assert_contains('[error] Task failed: deliberate test failure', $written);
    }
}
