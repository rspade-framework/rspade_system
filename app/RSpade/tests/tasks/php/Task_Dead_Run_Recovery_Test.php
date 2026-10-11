<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Task\Task_Waiting;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Concurrency_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Worker_Victim;

/**
 * Recovery after the machinery under the tasks went away: what the first rsx:task:process tick
 * and the first workers do with what an outage left in the tables.
 *
 *   THE HOST CAME BACK (same hostname, a restarted rsx-lockd, every old pid gone): every
 *   RUNNING run it left is settled by ONE tick - a dispatched run back to PENDING for a paced
 *   retry, a scheduled run FAILED and counted on its schedule, an inline run FAILED.
 *
 *   AN OLD PID IS HELD BY AN UNRELATED PROCESS: a pool run is still abandoned (its pid is
 *   held to a task worker's command line), the process is never signalled, and no kill is
 *   requested for it.
 *
 *   ANOTHER HOST'S RUN THAT NEVER SETTLES does not hold the queue back: its #[Exclusive]
 *   identity runs again, because the run lock lived in the lock daemon and went with it.
 *
 *   A RUN THAT WAS QUEUED BUT NEVER CLAIMED (a schedule tick queued behind its exclusive
 *   group) is claimed by whichever worker comes next and counted on its schedule.
 *
 *   OVERDUE WORK: a schedule whose next run is a month in the past runs ONCE and is then set
 *   to its next cadence from now; pending runs long past due each run once, oldest due first.
 *
 * The baseline abandonment cases (a worker that left the pool of the daemon's own generation,
 * the retry pacing, the attempt cap) are Task_Abandonment_Retry_Test and
 * Task_Worker_Execution_Test.
 *
 * Commits real rows, so this class provisions a clean baseline and opts out of transactions.
 */
class Task_Dead_Run_Recovery_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = Task_Exec_Fixture_Service::class;

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    const DEAD_PID = 2147480000;

    const OTHER_HOST = 'task-dead-run-recovery-test.other-host.invalid';

    public static function teardown()
    {
        Task_Worker_Victim::reap_all();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Remove every live run and every fixture run, and forget the fixture's run order. */
    private static function __reset(): void
    {
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_tasks')->whereIn('class', [self::FIX, Task_Concurrency_Fixture_Service::class])->delete();
        Task_Exec_Fixture_Service::$run_order = [];
    }

    /** A generation that is not the daemon's own: what every claim of a previous daemon lifetime carries. */
    private static function __older_generation(): int
    {
        $current = Task_Pool::stats(Task_Pool::ON_DEMAND)['generation'];

        return $current === 1 ? 2 : $current - 1;
    }

    /** A run RUNNING under a pool worker of a previous daemon generation. */
    private static function __pool_run(string $class, string $method, int $pid, int $wid, array $fields = []): int
    {
        return Task_Runner::insert_row($class, $method, [], $fields['origin_id'] ?? Task_Run_Model::ORIGIN_DISPATCHED, array_merge(Task_Runner::running_fields(), [
            'worker_pid' => $pid,
            'worker_id' => $wid,
            'worker_generation' => static::__older_generation(),
            'pool_id' => Task_Run_Model::POOL_ON_DEMAND,
        ], $fields));
    }

    private static function __status(int $id): int
    {
        return (int) DB::table('_tasks')->where('id', $id)->value('status_id');
    }

    // -------------------------------------------------------------------------
    // The host came back
    // -------------------------------------------------------------------------

    /**
     * One tick after a reboot settles everything the host left RUNNING: the pool runs carry a
     * previous generation and pids that no longer exist, the inline run a pid that no longer
     * exists.
     */
    public static function test_one_tick_settles_every_run_a_rebooted_host_left_running()
    {
        static::__reset();

        $scheduled = Task::get_scheduled_tasks();
        if (empty($scheduled)) {
            static::__skip('No manifest #[Schedule] task available to stage a scheduled run');
        }
        $declaration = $scheduled[0];

        // A settling tick registers the declared schedules, so the measured one keeps the row.
        Artisan::call('rsx:task:process');
        $schedule = DB::table('_task_schedules')->where('class', $declaration['class'])->where('method', $declaration['method'])->first();
        DB::table('_task_schedules')->where('id', $schedule->id)->update(['consecutive_failures' => 0]);
        $next_run_at = DB::table('_task_schedules')->where('id', $schedule->id)->value('next_run_at');

        $dispatched = static::__pool_run(self::FIX, 'marker_a', self::DEAD_PID, 31);
        $scheduled_run = static::__pool_run($declaration['class'], $declaration['method'], self::DEAD_PID + 1, 32, [
            'origin_id' => Task_Run_Model::ORIGIN_SCHEDULED,
            'schedule_id' => $schedule->id,
            'pool_id' => Task_Run_Model::POOL_SCHEDULED,
        ]);
        $inline = Task_Runner::insert_row(self::FIX, 'marker_b', [], Task_Run_Model::ORIGIN_INLINE, array_merge(Task_Runner::running_fields(), [
            'worker_pid' => self::DEAD_PID + 2,
        ]));

        Artisan::call('rsx:task:process');

        $row = Task_Run_Model::find($dispatched);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $row->status_id, 'a dispatched run is retried');
        static::__assert_equals(1, (int) $row->abandon_count);
        static::__assert_null($row->worker_pid, 'its worker columns are clear');
        static::__assert_true(strtotime($row->scheduled_for) > time(), 'the retry is paced into the future');

        $row = Task_Run_Model::find($scheduled_run);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $row->status_id, 'a scheduled run is failed, never retried');
        $after = DB::table('_task_schedules')->where('id', $schedule->id)->first();
        static::__assert_equals(1, (int) $after->consecutive_failures, 'the failure is counted on the schedule');
        static::__assert_equals($next_run_at, $after->next_run_at, 'the schedule keeps its next run: it runs again at its cadence');

        $row = Task_Run_Model::find($inline);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $row->status_id, 'an inline run is failed: its caller is gone');
        static::__assert_contains('is gone', (string) $row->error);

        DB::table('_tasks')->whereIn('id', [$dispatched, $scheduled_run, $inline])->delete();
    }

    // -------------------------------------------------------------------------
    // An old pid held by an unrelated process
    // -------------------------------------------------------------------------

    /**
     * A pool run whose pid the kernel has handed to an unrelated process is abandoned on the
     * first tick, however long ago it last reported - and the silence limit, which applies
     * only to a run whose worker is alive, never asks for that process to be killed.
     */
    public static function test_a_reused_pid_neither_keeps_a_dead_run_alive_nor_gets_killed()
    {
        static::__reset();

        $pid = Task_Worker_Victim::start_plain();
        try {
            // Started two hours ago: far past the silence limit, were the run judged alive.
            $id = static::__pool_run(self::FIX, 'marker_a', $pid, 33, [
                'started_at' => date('Y-m-d H:i:s', time() - 7200) . '.000',
            ]);

            Artisan::call('rsx:task:process');
            $output = Artisan::output();

            static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($id), 'the run is abandoned and retried');
            static::__assert_contains("PID {$pid} is not a task worker", (string) DB::table('_tasks')->where('id', $id)->value('status_reason'));
            static::__assert_equals(0, Task_Kill_Request_Model::where('task_id', $id)->count(), 'no kill is requested for the pid');
            static::__assert_false(str_contains($output, '[TASK TIMEOUT]'), 'the silence limit never sees it');
            static::__assert_true(Task_Worker_Victim::running($pid), 'the unrelated process is untouched');
        } finally {
            Task_Worker_Victim::reap($pid);
            static::__reset();
        }
    }

    // -------------------------------------------------------------------------
    // A run on a host that never settles it
    // -------------------------------------------------------------------------

    /**
     * A previous generation's run on another host stays RUNNING on this host's tick - and its
     * #[Exclusive] identity is not held by it: the next dispatch queues, reads as claimable,
     * and a worker runs it.
     */
    public static function test_another_hosts_unsettled_run_does_not_hold_its_exclusive_identity()
    {
        static::__reset();

        $stuck = static::__pool_run(Task_Concurrency_Fixture_Service::class, 'exclusive_task', self::DEAD_PID, 34, ['worker_host' => self::OTHER_HOST]);

        Artisan::call('rsx:task:process');
        static::__assert_equals(Task_Run_Model::STATUS_RUNNING, static::__status($stuck), 'another host\'s run is left for that host');

        $next = Task::dispatch('Task_Concurrency_Fixture_Service', 'exclusive_task');
        static::__assert_true($next !== $stuck, 'a new run is queued');
        static::__assert_equals(Task_Waiting::REASON_QUEUED, Task_Waiting::of(Task_Run_Model::find($next))['reason'], 'it waits for nothing but a worker');

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($next), 'the identity ran again');
        static::__assert_equals(Task_Run_Model::STATUS_RUNNING, static::__status($stuck), 'the unsettled run is as it was');

        static::__reset();
    }

    // -------------------------------------------------------------------------
    // Queued, never claimed
    // -------------------------------------------------------------------------

    /**
     * A schedule's run that was queued PENDING and never claimed - the process that queued it
     * is gone - is claimed by the next worker like any pending run, and counted on its
     * schedule.
     */
    public static function test_a_schedules_queued_run_is_claimed_by_the_next_worker()
    {
        static::__reset();

        $schedule_id = DB::table('_task_schedules')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'cron_expression' => 'daily at 3am',
            'next_run_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            $id = Task_Runner::insert_row(self::FIX, 'marker_a', [], Task_Run_Model::ORIGIN_SCHEDULED, [
                'schedule_id' => $schedule_id,
                'scheduled_for' => date('Y-m-d H:i:s', time() - 86400) . '.000',
            ]);

            Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);

            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($id));
            $schedule = DB::table('_task_schedules')->where('id', $schedule_id)->first();
            static::__assert_equals((int) $id, (int) $schedule->last_task_id, 'the schedule records the run');
            static::__assert_not_null($schedule->last_success_at);
        } finally {
            DB::table('_tasks')->where('schedule_id', $schedule_id)->delete();
            DB::table('_task_schedules')->where('id', $schedule_id)->delete();
            static::__reset();
        }
    }

    // -------------------------------------------------------------------------
    // Overdue work
    // -------------------------------------------------------------------------

    /**
     * A schedule that should have run every minute for a month runs ONCE when a worker comes
     * back: the claim sets its next run from now, not from the run it missed.
     */
    public static function test_a_long_overdue_schedule_runs_once()
    {
        static::__reset();
        $other_schedules = DB::table('_task_schedules')->get()->map(fn ($row) => (array) $row)->all();
        DB::table('_task_schedules')->delete();

        $schedule_id = DB::table('_task_schedules')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'cron_expression' => '* * * * *',
            'next_run_at' => date('Y-m-d H:i:s', time() - 30 * 86400) . '.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);

            static::__assert_equals(1, DB::table('_tasks')->where('schedule_id', $schedule_id)->count(), 'one catch-up run, not one per missed minute');
            static::__assert_equals(['A'], Task_Exec_Fixture_Service::$run_order);
            static::__assert_true(strtotime(DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at')) > time(), 'the next run is in the future');

            Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
            static::__assert_equals(1, DB::table('_tasks')->where('schedule_id', $schedule_id)->count(), 'a second worker finds nothing due');
        } finally {
            DB::table('_tasks')->where('schedule_id', $schedule_id)->delete();
            DB::table('_task_schedules')->where('id', $schedule_id)->delete();
            if ($other_schedules !== []) {
                DB::table('_task_schedules')->insert($other_schedules);
            }
            static::__reset();
        }
    }

    /** Pending runs long past due each run once, the oldest due first. */
    public static function test_long_overdue_pending_runs_each_run_once_oldest_first()
    {
        static::__reset();

        $ids = [];
        foreach ([[10, 'marker_b'], [30, 'marker_a'], [20, 'marker_b']] as [$days_ago, $method]) {
            $ids[] = Task_Runner::insert_row(self::FIX, $method, [], Task_Run_Model::ORIGIN_DISPATCHED, [
                'scheduled_for' => date('Y-m-d H:i:s', time() - $days_ago * 86400) . '.000',
            ]);
        }

        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);

        static::__assert_equals(['A', 'B', 'B'], Task_Exec_Fixture_Service::$run_order, 'oldest due first');
        foreach ($ids as $id) {
            static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($id));
        }
        static::__assert_equals(3, DB::table('_tasks')->where('class', self::FIX)->count(), 'no run was repeated');

        static::__reset();
    }
}
