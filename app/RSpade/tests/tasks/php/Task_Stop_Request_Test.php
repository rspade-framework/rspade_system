<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * The cooperative stop: Task::request_stop() sets _tasks.stop_requested, and a task that
 * checks Task_Instance::is_stop_requested() between units of work ends early.
 *
 * THE SUBJECT IS THAT NOTHING HAPPENS AUTOMATICALLY. The flag interrupts nothing: a task
 * stops only because its own loop asked. So the end-to-end case is a fixture task whose
 * loop checks before every batch, with a request arriving mid-run; and the flag's
 * lifecycle on a recurring tracker (cleared when the run it applied to ends) is pinned
 * against a one-shot row (kept, since a stopped one-shot stays stopped).
 *
 * Rows are written directly and roll back with the per-test transaction; the worker is
 * not needed to prove any of it.
 */
class Task_Stop_Request_Test extends Rsx_Test_Abstract
{
    private const FIX = Task_Exec_Fixture_Service::class;

    private static function __row(string $status, ?string $next_run_at = null, string $method = 'marker_a', array $params = []): int
    {
        return DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => $method,
            'queue' => 'default',
            'status' => $status,
            'params' => json_encode($params),
            'next_run_at' => $next_run_at,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function __flag(int $task_id): int
    {
        return (int) DB::table('_tasks')->where('id', $task_id)->value('stop_requested');
    }

    /**
     * TSR-01: a pending or running task can be asked to stop; the task and Task::status()
     * both see it.
     */
    public static function test_request_stop_flags_a_pending_or_running_task()
    {
        foreach ([Task_Status::PENDING, Task_Status::RUNNING] as $status) {
            $task_id = static::__row($status);

            static::__assert_false(Task_Instance::find($task_id)->is_stop_requested(), "{$status}: not requested yet");
            static::__assert_false(Task::status($task_id)['stop_requested'], "{$status}: status() before");

            static::__assert_true(Task::request_stop($task_id), "{$status}: flagged");
            static::__assert_true(Task_Instance::find($task_id)->is_stop_requested(), "{$status}: the task sees it");
            static::__assert_true(Task::status($task_id)['stop_requested'], "{$status}: status() reports it");
        }
    }

    /**
     * TSR-02: a finished or missing task cannot be asked to stop.
     */
    public static function test_request_stop_refuses_a_finished_or_missing_task()
    {
        foreach ([Task_Status::COMPLETED, Task_Status::FAILED] as $status) {
            $task_id = static::__row($status);

            static::__assert_false(Task::request_stop($task_id), "{$status}: refused");
            static::__assert_equals(0, static::__flag($task_id), "{$status}: flag untouched");
        }

        static::__assert_false(Task::request_stop(2147480000), 'a missing task');
    }

    /**
     * TSR-03: an immediate-mode instance has no row and is never stopped.
     */
    public static function test_an_immediate_task_is_never_stopped()
    {
        $task = new Task_Instance(self::FIX, 'marker_a', [], 'default', true);

        static::__assert_false($task->is_stop_requested());
    }

    /**
     * TSR-04: a task that checks between batches ends at the first check after the request
     * - the request lands after batch 2, so batch 3 is never started.
     */
    public static function test_a_task_that_checks_ends_its_work_early()
    {
        $params = ['batches' => 10, 'request_stop_after' => 2];
        $task_id = static::__row(Task_Status::RUNNING, null, 'stoppable_batches', $params);

        $result = Task_Exec_Fixture_Service::stoppable_batches(Task_Instance::find($task_id), $params);

        static::__assert_equals(['batches_done' => 2], $result);
        static::__assert_equals(1, static::__flag($task_id), 'the one-shot keeps its request');
    }

    /**
     * TSR-05: a recurring tracker's request applies to one run and is cleared when that run
     * ends, completed or failed; a one-shot keeps its request.
     */
    public static function test_a_tracker_clears_the_request_when_its_run_ends()
    {
        $later = date('Y-m-d H:i:s', time() + 3600);

        $completed_tracker = static::__row(Task_Status::RUNNING, $later);
        Task::request_stop($completed_tracker);
        Task_Instance::find($completed_tracker)->mark_completed();

        static::__assert_equals(0, static::__flag($completed_tracker), 'completed run clears it');
        static::__assert_equals(Task_Status::PENDING, DB::table('_tasks')->where('id', $completed_tracker)->value('status'));

        $failed_tracker = static::__row(Task_Status::RUNNING, $later);
        Task::request_stop($failed_tracker);
        Task_Instance::find($failed_tracker)->mark_failed('boom');

        static::__assert_equals(0, static::__flag($failed_tracker), 'failed run clears it');

        $one_shot = static::__row(Task_Status::RUNNING);
        Task::request_stop($one_shot);
        Task_Instance::find($one_shot)->mark_completed();

        static::__assert_equals(1, static::__flag($one_shot), 'a one-shot keeps it');
    }
}
