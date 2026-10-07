<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * The lifecycle operations outside code performs on a run (Task_Run_Model), and the commands
 * over them (rsx:tasks:stop, rsx:tasks:cancel).
 *
 *   request_stop()   graceful: flags stop_requested_at; nothing is ever killed. A task that
 *                    checks is_stop_requested() ends early, and a successful return then
 *                    settles STOPPED.
 *   cancel()         a PENDING run never runs: CANCELLED.
 *   force_stop()     flags the stop and records a kill request due after the grace period;
 *                    a PENDING run is cancelled instead.
 *   force_kill()     records a kill request due now; a PENDING run is cancelled instead.
 *   rerun()          dispatches the same task and params again; refused while the run is live.
 *
 * Every operation writes an OPERATOR line on the run's output naming who did it. Nobody is
 * signed in here, so the actor is "the command line". Kill requests are only RECORDED here
 * (under the suite no kill worker is spawned); carrying them out is Task_Kill_Worker_Test.
 * Rows roll back with the per-test transaction.
 */
class Task_Lifecycle_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Session::logout();
    }

    private static function __row(int $status_id, array $fields = []): Task_Run_Model
    {
        $fields = ['status_id' => $status_id] + $fields;
        if ($status_id === Task_Run_Model::STATUS_RUNNING) {
            $fields += Task_Runner::running_fields();
        }
        if (!in_array($status_id, Task_Run_Model::LIVE_STATUSES, true)) {
            $fields += ['completed_at' => now()->format('Y-m-d H:i:s.v')];
        }

        return Task_Run_Model::find(Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', ['p' => 1], Task_Run_Model::ORIGIN_DISPATCHED, $fields));
    }

    private static function __operator_lines(int $task_id): array
    {
        return array_column(Task_Run_Model::find($task_id)->output_after(null, ['operator']), 'line');
    }

    // -------------------------------------------------------------------------
    // Graceful stop
    // -------------------------------------------------------------------------

    public static function test_request_stop_flags_a_pending_or_running_run()
    {
        foreach ([Task_Run_Model::STATUS_PENDING, Task_Run_Model::STATUS_RUNNING] as $status_id) {
            $run = static::__row($status_id);

            static::__assert_false(Task_Instance::find($run->id)->is_stop_requested(), "{$status_id}: not requested yet");
            static::__assert_true($run->request_stop('wrapping up'), "{$status_id}: flagged");
            static::__assert_not_null(Task_Run_Model::find($run->id)->stop_requested_at);
            static::__assert_true(Task_Instance::find($run->id)->is_stop_requested(), "{$status_id}: the task sees it");
            static::__assert_equals((int) $status_id, (int) Task_Run_Model::find($run->id)->status_id, 'nothing else about the run changes');
            static::__assert_equals(['Graceful stop requested by the command line: wrapping up'], static::__operator_lines($run->id));
        }
    }

    public static function test_a_second_request_keeps_the_first_moment()
    {
        $run = static::__row(Task_Run_Model::STATUS_RUNNING);
        $run->request_stop();
        $first = Task_Run_Model::find($run->id)->stop_requested_at;

        DB::table('_tasks')->where('id', $run->id)->update(['updated_at' => now()]);
        $run->request_stop();

        static::__assert_equals($first, Task_Run_Model::find($run->id)->stop_requested_at);
    }

    public static function test_request_stop_refuses_a_finished_run()
    {
        foreach ([Task_Run_Model::STATUS_COMPLETED, Task_Run_Model::STATUS_FAILED, Task_Run_Model::STATUS_CANCELLED] as $status_id) {
            $run = static::__row($status_id);

            static::__assert_false($run->request_stop(), "{$status_id}: refused");
            static::__assert_null(Task_Run_Model::find($run->id)->stop_requested_at, "{$status_id}: untouched");
            static::__assert_equals([], static::__operator_lines($run->id), "{$status_id}: nothing recorded");
        }
    }

    /**
     * A task that checks between batches ends at the first check after the request - the
     * request lands after batch 2, so batch 3 never starts - and its successful return
     * settles the run STOPPED.
     */
    public static function test_a_task_that_checks_stops_early_and_settles_stopped()
    {
        $run = Task::internal('Task_Exec_Fixture_Service', 'stoppable_batches', ['batches' => 10, 'request_stop_after' => 2]);

        static::__assert_equals(['batches_done' => 2], $run->state());
        static::__assert_equals(Task_Run_Model::STATUS_STOPPED, (int) $run->status_id);
        static::__assert_equals(0, (int) $run->return_code, 'a stop is a clean end');
        static::__assert_not_null($run->stop_requested_at);
    }

    /**
     * The verdict is the return value's: a run asked to stop that then FAILS is FAILED, and one
     * that never asked still settles STOPPED when it succeeds.
     */
    public static function test_the_settle_after_a_stop_follows_the_return()
    {
        $failing = Task_Instance::find(static::__row(Task_Run_Model::STATUS_RUNNING)->id);
        Task_Run_Model::find($failing->get_id())->request_stop();
        Task_Runner::settle($failing, \App\RSpade\Core\Task\Task_Run_Outcome::from_return(false));
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) Task_Run_Model::find($failing->get_id())->status_id);

        $ignoring = Task_Instance::find(static::__row(Task_Run_Model::STATUS_RUNNING)->id);
        Task_Run_Model::find($ignoring->get_id())->request_stop();
        Task_Runner::settle($ignoring, \App\RSpade\Core\Task\Task_Run_Outcome::from_return(null));
        static::__assert_equals(Task_Run_Model::STATUS_STOPPED, (int) Task_Run_Model::find($ignoring->get_id())->status_id);
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    public static function test_cancel_takes_a_pending_run_off_the_queue()
    {
        $run = static::__row(Task_Run_Model::STATUS_PENDING);

        static::__assert_true($run->cancel('not needed'));

        $after = Task_Run_Model::find($run->id);
        static::__assert_equals(Task_Run_Model::STATUS_CANCELLED, (int) $after->status_id);
        static::__assert_equals('cancelled by the command line: not needed', $after->status_reason);
        static::__assert_not_null($after->completed_at);
        static::__assert_equals(['Cancelled by the command line: not needed'], static::__operator_lines($run->id));
    }

    public static function test_cancel_refuses_a_run_that_started_or_ended()
    {
        foreach ([Task_Run_Model::STATUS_RUNNING, Task_Run_Model::STATUS_COMPLETED] as $status_id) {
            $run = static::__row($status_id);

            static::__assert_false($run->cancel(), "{$status_id}: refused");
            static::__assert_equals($status_id, (int) Task_Run_Model::find($run->id)->status_id, "{$status_id}: unchanged");
            static::__assert_equals([], static::__operator_lines($run->id));
        }
    }

    // -------------------------------------------------------------------------
    // Force stop and force kill
    // -------------------------------------------------------------------------

    public static function test_force_stop_and_force_kill_cancel_a_pending_run()
    {
        $stopped = static::__row(Task_Run_Model::STATUS_PENDING);
        static::__assert_true($stopped->force_stop(30));
        static::__assert_equals(Task_Run_Model::STATUS_CANCELLED, (int) Task_Run_Model::find($stopped->id)->status_id, 'force stop of a pending run cancels it');

        $killed = static::__row(Task_Run_Model::STATUS_PENDING);
        static::__assert_true($killed->force_kill());
        static::__assert_equals(Task_Run_Model::STATUS_CANCELLED, (int) Task_Run_Model::find($killed->id)->status_id, 'force kill of a pending run cancels it');

        static::__assert_equals(0, Task_Kill_Request_Model::whereIn('task_id', [$stopped->id, $killed->id])->count(), 'a pending run has no worker to kill');
    }

    public static function test_force_stop_flags_the_stop_and_requests_a_kill_after_the_grace()
    {
        $run = static::__row(Task_Run_Model::STATUS_RUNNING);
        $before = time();

        static::__assert_true($run->force_stop(30, 'stuck'));

        $after = Task_Run_Model::find($run->id);
        static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) $after->status_id, 'still running: the task gets its grace');
        static::__assert_not_null($after->stop_requested_at, 'and is asked to stop');

        $request = Task_Kill_Request_Model::where('task_id', $run->id)->first();
        static::__assert_equals(Task_Kill_Request_Model::MODE_FORCE_STOP, (int) $request->mode_id);
        static::__assert_equals(Task_Kill_Request_Model::STATUS_PENDING, (int) $request->status_id);
        static::__assert_equals(getmypid(), (int) $request->target_pid);
        $due = strtotime($request->kill_after_at);
        static::__assert_true($due >= $before + 30 && $due <= time() + 30, 'due after the grace: ' . $request->kill_after_at);
        static::__assert_equals('force stop by the command line: stuck', $request->explanation);
        static::__assert_equals(['Force stop requested: killed if still running in 30s by the command line: stuck'], static::__operator_lines($run->id));

        static::__assert_throws(\InvalidArgumentException::class, fn () => static::__row(Task_Run_Model::STATUS_RUNNING)->force_stop(-1), '0 seconds or more');
    }

    public static function test_force_kill_requests_a_kill_now()
    {
        $run = static::__row(Task_Run_Model::STATUS_RUNNING);

        static::__assert_true($run->force_kill('runaway'));

        $request = Task_Kill_Request_Model::where('task_id', $run->id)->first();
        static::__assert_equals(Task_Kill_Request_Model::MODE_FORCE_KILL, (int) $request->mode_id);
        static::__assert_true(strtotime($request->kill_after_at) <= time(), 'due now');
        static::__assert_null(Task_Run_Model::find($run->id)->stop_requested_at, 'a kill asks nothing of the task');
        static::__assert_equals(['Force kill requested by the command line: runaway'], static::__operator_lines($run->id));

        static::__assert_false(static::__row(Task_Run_Model::STATUS_COMPLETED)->force_kill(), 'a finished run has nothing to kill');
    }

    // -------------------------------------------------------------------------
    // Rerun
    // -------------------------------------------------------------------------

    public static function test_rerun_dispatches_a_new_run_of_a_finished_one()
    {
        $run = static::__row(Task_Run_Model::STATUS_FAILED);

        $new_id = $run->rerun();

        static::__assert_not_equals((int) $run->id, $new_id);
        $new = Task_Run_Model::find($new_id);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $new->status_id);
        static::__assert_equals(Task_Run_Model::ORIGIN_DISPATCHED, (int) $new->origin_id);
        static::__assert_equals([$run->class, $run->method, ['p' => 1]], [$new->class, $new->method, $new->params], 'the same task with the same params');
        static::__assert_equals(["Run again as task {$new_id} by the command line"], static::__operator_lines($run->id));
    }

    public static function test_rerun_refuses_a_live_run()
    {
        $run = static::__row(Task_Run_Model::STATUS_RUNNING);

        static::__assert_throws(\RuntimeException::class, fn () => $run->rerun(), 'is still running; only a finished run can be run again');
    }

    // -------------------------------------------------------------------------
    // The commands
    // -------------------------------------------------------------------------

    public static function test_tasks_stop_command()
    {
        $graceful = static::__row(Task_Run_Model::STATUS_RUNNING);
        static::__assert_equals(0, Artisan::call('rsx:tasks:stop', ['id' => $graceful->id, '--explanation' => 'ops']));
        static::__assert_contains('Graceful stop requested', Artisan::output());
        static::__assert_not_null(Task_Run_Model::find($graceful->id)->stop_requested_at);

        $forced = static::__row(Task_Run_Model::STATUS_RUNNING);
        static::__assert_equals(0, Artisan::call('rsx:tasks:stop', ['id' => $forced->id, '--force' => true, '--grace' => '5']));
        static::__assert_equals(Task_Kill_Request_Model::MODE_FORCE_STOP, (int) Task_Kill_Request_Model::where('task_id', $forced->id)->value('mode_id'));

        $killed = static::__row(Task_Run_Model::STATUS_RUNNING);
        static::__assert_equals(0, Artisan::call('rsx:tasks:stop', ['id' => $killed->id, '--kill' => true]));
        static::__assert_equals(Task_Kill_Request_Model::MODE_FORCE_KILL, (int) Task_Kill_Request_Model::where('task_id', $killed->id)->value('mode_id'));

        $finished = static::__row(Task_Run_Model::STATUS_COMPLETED);
        static::__assert_equals(1, Artisan::call('rsx:tasks:stop', ['id' => $finished->id]), 'a finished run has nothing to stop');
        static::__assert_equals(1, Artisan::call('rsx:tasks:stop', ['id' => 2147480000]), 'a missing run');
    }

    public static function test_tasks_cancel_command()
    {
        $pending = static::__row(Task_Run_Model::STATUS_PENDING);
        static::__assert_equals(0, Artisan::call('rsx:tasks:cancel', ['id' => $pending->id]));
        static::__assert_equals(Task_Run_Model::STATUS_CANCELLED, (int) Task_Run_Model::find($pending->id)->status_id);

        $running = static::__row(Task_Run_Model::STATUS_RUNNING);
        static::__assert_equals(1, Artisan::call('rsx:tasks:cancel', ['id' => $running->id]), 'only a pending run can be cancelled');
        static::__assert_contains('only a pending run can be cancelled', Artisan::output());
    }
}
