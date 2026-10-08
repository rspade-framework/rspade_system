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
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * THE QUEUE REPORT IS ROWS: queue_push / queue_push_many / queue_pop / queue_remove /
 * queue_clear / queue_depth on Task_Instance, read back through Task_Run_Model::queue() and
 * queue_depth().
 *
 * The queue is the report that shows whether a run is moving, so it is advanced per item -
 * which is affordable only because a push is one INSERT at the tail and a pop one DELETE at
 * the head, with every other row left alone. These tests hold that: what each call leaves in
 * the queue, that held calls are written in the order they were made, and that sliding a
 * window forward touches two rows however many the window holds.
 *
 * Each test drives an instance of a RUNNING inline row; rows roll back with the per-test
 * transaction. The instance's last write time is pinned through reflection, as in
 * Task_Reports_Test: held = calls coalesce until flush().
 */
class Task_Queue_Test extends Rsx_Test_Abstract
{
    private static function __instance(bool $held = true): Task_Instance
    {
        $task = Task_Instance::find(Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields()));
        (new \ReflectionProperty(Task_Instance::class, 'last_flush'))->setValue($task, $held ? microtime(true) + 3600 : 0.0);

        return $task;
    }

    private static function __run(Task_Instance $task): Task_Run_Model
    {
        return Task_Run_Model::find($task->get_id());
    }

    /** @return int[] The stored queue row ids of the run, in order. */
    private static function __row_ids(Task_Instance $task): array
    {
        return DB::table('_task_queue')->where('task_id', $task->get_id())->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function test_pushes_append_in_order_and_items_keep_their_type()
    {
        $task = static::__instance();
        $task->queue_push('first');
        $task->queue_push_many([['n' => 2], 'third']);
        $task->flush();

        $run = static::__run($task);
        static::__assert_equals(['first', ['n' => 2], 'third'], $run->queue());
        static::__assert_equals(3, $run->queue_depth());
        static::__assert_equals(3, $task->queue_depth());
        static::__assert_equals(['first', ['n' => 2]], $run->queue(2), 'a limit reads the head');
    }

    public static function test_pop_removes_the_head()
    {
        $task = static::__instance();
        $task->queue_push_many(['a', 'b', 'c']);
        $task->flush();

        $task->queue_pop();
        $task->flush();

        static::__assert_equals(['b', 'c'], static::__run($task)->queue());
        static::__assert_equals(2, $task->queue_depth());
    }

    /**
     * Calls held between two writes are applied in the order they were made: a pop takes a
     * stored item while one remains and a held push after that, and a pop on an empty queue
     * is nothing.
     */
    public static function test_held_calls_are_applied_in_call_order()
    {
        $task = static::__instance();
        $task->queue_push('stored');
        $task->flush();

        $task->queue_pop();          // takes 'stored'
        $task->queue_pop();          // empty: nothing
        $task->queue_push('x');
        $task->queue_push('y');
        $task->queue_pop();          // takes the held 'x'
        $task->queue_push('z');
        static::__assert_equals(2, $task->queue_depth(), 'the depth counts what is held');
        $task->flush();

        static::__assert_equals(['y', 'z'], static::__run($task)->queue());
    }

    /**
     * The point of rows: advancing a window by one item writes one DELETE and one INSERT,
     * and every row in between is left exactly as it was.
     */
    public static function test_sliding_the_window_leaves_the_rows_between_untouched()
    {
        $task = static::__instance();
        $task->queue_push_many(['1', '2', '3', '4', '5']);
        $task->flush();
        $before = static::__row_ids($task);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            // The writes. (The first pop also counts the stored queue, once per run.)
            $verb = strtolower(strtok(ltrim($query->sql), ' '));
            if (str_contains($query->sql, '_task_queue') && $verb !== 'select') {
                $statements[] = $verb;
            }
        });

        $task->queue_pop();
        $task->queue_push('6');
        $task->flush();

        static::__assert_equals(['delete', 'insert'], $statements, 'one DELETE, one INSERT');

        $after = static::__row_ids($task);
        static::__assert_equals(array_slice($before, 1), array_slice($after, 0, 4), 'the four rows between kept their ids');
        static::__assert_equals(['2', '3', '4', '5', '6'], static::__run($task)->queue());
    }

    public static function test_remove_takes_one_exact_match_the_earliest()
    {
        $task = static::__instance();
        $task->queue_push_many(['report.pdf', 'Report.pdf', 'report.pdf', 'report.pdf.bak']);
        $task->flush();
        $ids = static::__row_ids($task);

        $task->queue_remove('report.pdf');

        static::__assert_equals(['Report.pdf', 'report.pdf', 'report.pdf.bak'], static::__run($task)->queue(), 'one match gone: exact, not case-folded, not a substring');
        static::__assert_equals(array_slice($ids, 1), static::__row_ids($task), 'and it was the earliest');
        static::__assert_equals(3, $task->queue_depth());

        $task->queue_remove('not queued');
        static::__assert_equals(3, static::__run($task)->queue_depth(), 'no match removes nothing');
    }

    public static function test_remove_sees_calls_still_held()
    {
        $task = static::__instance();
        $task->queue_push_many(['a', ['id' => 7], 'c']);

        $task->queue_remove(['id' => 7]);

        static::__assert_equals(['a', 'c'], static::__run($task)->queue(), 'the held pushes were written first');
    }

    public static function test_clear_then_push_many_declares_the_queue()
    {
        $task = static::__instance();
        $task->queue_push_many(['old 1', 'old 2']);
        $task->flush();

        $task->queue_pop();
        $task->queue_clear();
        $task->queue_push_many(['new 1', 'new 2', 'new 3']);
        static::__assert_equals(3, $task->queue_depth());
        $task->flush();

        static::__assert_equals(['new 1', 'new 2', 'new 3'], static::__run($task)->queue());
    }

    /**
     * The report comes into being with its first item: a queue that never held one is not
     * a report the run made, and one that did is an empty queue once emptied.
     */
    public static function test_the_report_exists_once_the_queue_has_held_an_item()
    {
        $task = static::__instance();
        $task->queue_clear();
        $task->queue_pop();
        $task->queue_push_many([]);
        $task->flush();

        $run = static::__run($task);
        static::__assert_false(in_array('queue', $run->available_reports(), true), 'nothing recorded for a queue never used');
        static::__assert_null($run->queue());
        static::__assert_equals(0, $run->queue_depth());

        $task->queue_push('a');
        $task->flush();
        $task->queue_clear();
        $task->flush();

        $run = static::__run($task);
        static::__assert_true(in_array('queue', $run->available_reports(), true), 'a queue that held an item stays reported');
        static::__assert_equals([], $run->queue(), 'and reads as empty');
    }

    /** With no write held back, each call is its own write and the result is the same. */
    public static function test_unheld_calls_write_as_they_are_made()
    {
        $task = static::__instance(false);
        $task->queue_push('a');

        static::__assert_equals(['a'], static::__run($task)->queue(), 'the first call after a quiet spell is written at once');
    }
}
