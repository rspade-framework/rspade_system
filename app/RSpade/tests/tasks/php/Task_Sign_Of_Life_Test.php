<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Run_Outcome;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * EVERY REPORT IS A SIGN OF LIFE: each Task_Instance reporting call leads to a write that
 * stamps the run's last_report_at (a heartbeat stamps last_heartbeat_at as well), which is
 * what the reaper's silence limit is measured from (Task_Timeout_Reaper_Test).
 *
 *   - every reporting call counts, including one that changes nothing a watcher reads: a
 *     status() repeating the last text, a queue_pop() or queue_clear() on a queue that never
 *     held an item, a queue_push_many([]), a queue_remove() matching nothing;
 *   - text the task echoes is captured as stdout and counts like stdout();
 *   - a report held back by the write rate (FLUSH_INTERVAL) is written by the next reporting
 *     call after the interval, so a task reporting steadily is never silent in the database
 *     for longer than that interval plus the gap between two of its reports;
 *   - is_stop_requested() is a read, not a report: by itself it stamps nothing.
 *
 * The row's stamp is first pushed into the past, so "stamped" is a moved value and never a
 * comparison of two clock readings taken a moment apart. The instance's last write time is
 * pinned through reflection, as in Task_Reports_Test. Attachments are Task_Attachments_Test
 * (they write files). Rows roll back with the per-test transaction.
 */
class Task_Sign_Of_Life_Test extends Rsx_Test_Abstract
{
    private const LONG_AGO = '2001-01-01 00:00:00.000';

    private static function __instance(): Task_Instance
    {
        $id = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields());

        return Task_Instance::find($id);
    }

    /** Push the row's signs of life into the past. */
    private static function __age(Task_Instance $task): void
    {
        DB::table('_tasks')->where('id', $task->get_id())->update(['last_report_at' => self::LONG_AGO, 'last_heartbeat_at' => self::LONG_AGO]);
    }

    private static function __stamped(Task_Instance $task, string $column = 'last_report_at'): bool
    {
        $value = DB::table('_tasks')->where('id', $task->get_id())->value($column);

        return $value !== null && strtotime((string) $value) > strtotime('2020-01-01');
    }

    /** Pin the instance's last write: true = just written (reports are held), false = never. */
    private static function __just_written(Task_Instance $task, bool $just_written): void
    {
        (new \ReflectionProperty(Task_Instance::class, 'last_flush'))->setValue($task, $just_written ? microtime(true) + 3600 : 0.0);
    }

    /**
     * Every reporting call, by name. Each is made on a fresh instance whose row was aged, with
     * no flush() after it: the write is the call's own.
     *
     * @return array<string, callable>
     */
    private static function __reporting_calls(): array
    {
        return [
            'heartbeat()' => fn (Task_Instance $task) => $task->heartbeat(),
            'status()' => fn (Task_Instance $task) => $task->status('working'),
            'progress()' => fn (Task_Instance $task) => $task->progress(12.5),
            'progress_count()' => fn (Task_Instance $task) => $task->progress_count(3, 257),
            'eta()' => fn (Task_Instance $task) => $task->eta(90),
            'state()' => fn (Task_Instance $task) => $task->state(['page' => 4]),
            'queue_push()' => fn (Task_Instance $task) => $task->queue_push('item'),
            'queue_push_many()' => fn (Task_Instance $task) => $task->queue_push_many(['a', 'b']),
            'queue_push_many([])' => fn (Task_Instance $task) => $task->queue_push_many([]),
            'queue_pop() on an empty queue' => fn (Task_Instance $task) => $task->queue_pop(),
            'queue_remove() matching nothing' => fn (Task_Instance $task) => $task->queue_remove('absent'),
            'queue_clear() on a queue that never held an item' => fn (Task_Instance $task) => $task->queue_clear(),
            'message()' => fn (Task_Instance $task) => $task->message('halfway'),
            'stdout()' => fn (Task_Instance $task) => $task->stdout('a line'),
            'stderr()' => fn (Task_Instance $task) => $task->stderr('a warning'),
            'summary()' => fn (Task_Instance $task) => $task->summary('done'),
        ];
    }

    public static function test_every_reporting_call_stamps_the_run_alive()
    {
        foreach (static::__reporting_calls() as $name => $call) {
            $task = static::__instance();
            static::__age($task);
            static::__just_written($task, false);

            $call($task);

            static::__assert_true(static::__stamped($task), "{$name} stamps last_report_at");
        }
    }

    public static function test_a_heartbeat_stamps_its_own_column_too()
    {
        $task = static::__instance();
        static::__age($task);
        static::__just_written($task, false);

        $task->heartbeat();

        static::__assert_true(static::__stamped($task, 'last_heartbeat_at'), 'last_heartbeat_at');
        static::__assert_true(static::__stamped($task, 'last_report_at'), 'and last_report_at, like every write');
    }

    /** The queue calls on a queue that HOLDS items stamp too (the rows are the report). */
    public static function test_queue_calls_on_a_live_queue_stamp_the_run_alive()
    {
        $calls = [
            'queue_pop()' => fn (Task_Instance $task) => $task->queue_pop(),
            'queue_remove()' => fn (Task_Instance $task) => $task->queue_remove('b'),
            'queue_clear()' => fn (Task_Instance $task) => $task->queue_clear(),
        ];

        foreach ($calls as $name => $call) {
            $task = static::__instance();
            $task->queue_push_many(['a', 'b', 'c']);
            $task->flush();
            static::__age($task);
            static::__just_written($task, false);

            $call($task);

            static::__assert_true(static::__stamped($task), "{$name} stamps last_report_at");
        }
    }

    /** A status that repeats the last text tells a watcher nothing new and still says "alive". */
    public static function test_an_unchanged_status_is_still_a_sign_of_life()
    {
        $task = static::__instance();
        $task->status('copying');
        $task->flush();
        $lines_before = DB::table('_task_output')->where('task_id', $task->get_id())->count();
        static::__age($task);
        static::__just_written($task, false);

        $task->status('copying');

        static::__assert_true(static::__stamped($task), 'the repeat is a report');
        static::__assert_equals($lines_before, DB::table('_task_output')->where('task_id', $task->get_id())->count(), 'and writes no second stderr line');
    }

    /**
     * echo inside the task is captured as stdout by the runner, and counts as a report. The
     * fixture only echoes one line and then throws, so the stamp is the echo's own.
     */
    public static function test_captured_echo_is_a_sign_of_life()
    {
        $id = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'prints_then_throws', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields());
        $task = Task_Instance::find($id);
        static::__age($task);
        static::__just_written($task, false);

        ob_start();
        try {
            $outcome = Task_Runner::execute($task, false);
        } finally {
            ob_end_clean();
        }

        static::__assert_false($outcome->success, 'the fixture throws after printing');
        static::__assert_true(static::__stamped($task), 'the echoed line was written, and stamped the run, while the task ran');
    }

    /**
     * The write rate holds a report back, never loses the sign of life: the next reporting call
     * after the interval writes it. So a steady reporter is silent in the database for no
     * longer than the interval plus the gap between two of its own reports.
     */
    public static function test_a_held_report_is_stamped_by_the_next_call_after_the_interval()
    {
        $task = static::__instance();
        static::__age($task);

        static::__just_written($task, true);
        $task->heartbeat();
        static::__assert_false(static::__stamped($task), 'inside the interval the report is held');

        // The interval has passed.
        (new \ReflectionProperty(Task_Instance::class, 'last_flush'))->setValue($task, microtime(true) - Task_Instance::FLUSH_INTERVAL);
        $task->status('copying');
        $task->status('copying');

        static::__assert_true(static::__stamped($task), 'the next reporting call writes, and stamps the run');
        static::__assert_true(static::__stamped($task, 'last_heartbeat_at'), 'with the heartbeat that was held');
    }

    /** A held report is also stamped when the run settles: nothing reported is lost at the end. */
    public static function test_a_held_report_is_stamped_at_the_settle()
    {
        $task = static::__instance();
        static::__age($task);
        static::__just_written($task, true);

        $task->status('copying');
        $task->status('copying');
        Task_Runner::settle($task, Task_Run_Outcome::from_return(null));

        static::__assert_true(static::__stamped($task));
    }

    /** A stop check reads the row; with nothing held it writes nothing and is not a report. */
    public static function test_a_stop_check_alone_is_not_a_report()
    {
        $task = static::__instance();
        static::__age($task);
        static::__just_written($task, false);

        static::__assert_false($task->is_stop_requested());

        static::__assert_false(static::__stamped($task), 'is_stop_requested() stamps nothing by itself');
        static::__assert_false(static::__stamped($task, 'last_heartbeat_at'));
    }
}
