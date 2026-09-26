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
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Tests for the task WORKER's execution behavior driven in-process.
 *
 * Artisan::call('rsx:task:worker', ['--max-time' => 30]) runs the real worker
 * loop in THIS process against the test DB, draining the queue. The fixture's
 * static $run_order array therefore captures the true execution order across the
 * worker's task calls (DATETIME started_at has no sub-second resolution, so
 * ordering can only be observed this way).
 *
 * The worker does NOT reconcile schedules; the processor (rsx:task:process) does
 * and DELETES any tracker whose class::method is not a real manifest #[Schedule].
 * So the priority + cron-recycle tests point synthetic cron trackers at the
 * fixture and drive the WORKER; the stuck-CRON test uses a REAL scheduled task so
 * reconcile leaves it in place.
 *
 * The worker joins this environment's rsx-lockd task pool on this process's Task_Pool
 * connection and leaves it before returning. The stuck-row tests plant the pool member id
 * a worker would have written: one that is not a member (a dead worker) or this process's
 * own live membership, plus one row with no member id (claimed before rows carried one), judged by its pid.
 *
 * Commits real rows to _tasks, so this class provisions a clean baseline once
 * and opts out of per-test transactions. Each test clears its own residue before running.
 */
class Task_Worker_Execution_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = 'App\\RSpade\\Tests\\Tasks\\Php\\Task_Exec_Fixture_Service';

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** A member id the daemon never minted (its ids are 'pm_' + hex), so it is never alive. */
    const DEAD_MEMBER = 'pm_never_a_member';

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    const DEAD_PID = 2147480000;

    /**
     * Remove any residual fixture rows and reset the fixture's execution log.
     */
    private static function __reset_fixture(): void
    {
        DB::table('_tasks')->where('class', self::FIX)->delete();
        Task_Exec_Fixture_Service::$run_order = [];
    }

    /**
     * Plant an on-demand fixture row RUNNING since 2000 s ago (past the default
     * cleanup_stuck_after of 1800 s) under the given worker.
     */
    private static function __plant_running_row(?string $member_id, int $pid): int
    {
        return DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'default',
            'status' => Task_Status::RUNNING,
            'params' => json_encode([]),
            'next_run_at' => null,
            'started_at' => date('Y-m-d H:i:s', time() - 2000),
            'worker_pid' => $pid,
            'worker_member_key' => $member_id,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * A tier-1 on-demand row (next_run_at NULL) is claimed before a due tier-2
     * cron row, regardless of insertion order.
     */
    public static function test_priority_run_now_claimed_before_due_cron()
    {
        static::__reset_fixture();

        $now = date('Y-m-d H:i:s');

        // Tier-2 cron tracker for marker_a: due now.
        DB::table('_tasks')->insert([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'scheduled',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => $now,
            'cron_expression' => 'every 5 minutes',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Tier-1 on-demand for marker_b.
        DB::table('_tasks')->insert([
            'class' => self::FIX,
            'method' => 'marker_b',
            'queue' => 'default',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => null,
            'scheduled_for' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Artisan::call('rsx:task:worker', ['--max-time' => 30]);

        // Tier-1 on-demand B runs before tier-2 cron A.
        static::__assert_equals(['B', 'A'], Task_Exec_Fixture_Service::$run_order);
    }

    /**
     * A cron tracker row is recycled after it runs: it survives, returns to
     * pending with a cleared worker_pid, its next_run_at is advanced into the
     * future, and its result is recorded.
     */
    public static function test_cron_tracker_recycles_after_run()
    {
        static::__reset_fixture();

        $now = date('Y-m-d H:i:s');

        $id = DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'scheduled',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => $now,
            'cron_expression' => 'every 5 minutes',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Artisan::call('rsx:task:worker', ['--max-time' => 30]);

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_not_null($row, 'cron tracker should still exist after run');
        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_null($row->worker_pid);
        static::__assert_greater_than(time(), strtotime($row->next_run_at));
        static::__assert_not_null($row->result);
    }

    /**
     * A tier-1 on-demand row runs to a terminal completed status with a result.
     */
    public static function test_on_demand_task_completes()
    {
        static::__reset_fixture();

        $now = date('Y-m-d H:i:s');

        $id = DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'default',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => null,
            'scheduled_for' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Artisan::call('rsx:task:worker', ['--max-time' => 30]);

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::COMPLETED, $row->status);
        static::__assert_not_null($row->result);
    }

    /**
     * A worker's claim records both its pid and its pool member id on the row, and the row
     * keeps them once it completes.
     */
    public static function test_claim_records_the_pool_member()
    {
        static::__reset_fixture();

        $now = date('Y-m-d H:i:s');

        $id = DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'default',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => null,
            'scheduled_for' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Artisan::call('rsx:task:worker', ['--max-time' => 30]);

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::COMPLETED, $row->status);
        static::__assert_equals(getmypid(), (int) $row->worker_pid, 'the in-process worker is this pid');
        static::__assert_true(
            is_string($row->worker_member_key) && str_starts_with($row->worker_member_key, 'pm_'),
            'the claim recorded the daemon-minted member id: ' . var_export($row->worker_member_key, true)
        );

        Task_Pool::lock();
        $alive = Task_Pool::member_alive($row->worker_member_key);
        Task_Pool::unlock();
        static::__assert_false($alive, 'the worker left the pool when it finished');
    }

    /**
     * A stuck on-demand row whose pool member is gone is failed by the processor's stuck-task
     * recovery - even though its pid is alive (this process): the pool, not the pid, decides.
     */
    public static function test_stuck_on_demand_row_marked_failed()
    {
        static::__reset_fixture();

        $id = static::__plant_running_row(self::DEAD_MEMBER, getmypid());

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::FAILED, $row->status);
    }

    /**
     * A RUNNING row whose worker is still a pool member is left alone, however old it is.
     */
    public static function test_row_of_a_live_member_is_not_reaped()
    {
        static::__reset_fixture();

        Task_Pool::lock();
        $member_id = Task_Pool::join();
        Task_Pool::unlock();

        try {
            // A dead pid on purpose: the membership is the verdict, not the pid.
            $id = static::__plant_running_row($member_id, self::DEAD_PID);

            Artisan::call('rsx:task:process');

            $row = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::RUNNING, $row->status, 'a live member\'s row keeps running');
            static::__assert_equals($member_id, $row->worker_member_key);
        } finally {
            Task_Pool::lock();
            Task_Pool::leave();
            Task_Pool::unlock();
            DB::table('_tasks')->where('class', self::FIX)->delete();
        }
    }

    /**
     * A RUNNING row with no pool member id - claimed before rows carried one - is judged by the
     * local pid: a dead pid fails it.
     */
    public static function test_row_without_a_member_is_judged_by_pid()
    {
        static::__reset_fixture();

        $id = static::__plant_running_row(null, self::DEAD_PID);

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::FAILED, $row->status);
    }

    /**
     * A stuck CRON tracker (RUNNING, old started_at, pool member gone) is recycled to
     * pending, not failed, and both worker columns are cleared. Uses a REAL manifest
     * #[Schedule] task so the processor's reconcile step leaves the tracker in place.
     */
    public static function test_stuck_cron_tracker_recycled_not_failed()
    {
        $scheduled = Task::get_scheduled_tasks();
        if (empty($scheduled)) {
            static::__skip('No manifest #[Schedule] task available to test stuck cron recovery');
        }
        $def = $scheduled[0];

        // Clear any existing tracker for this real schedule so ours is the one row.
        DB::table('_tasks')
            ->where('class', $def['class'])
            ->where('method', $def['method'])
            ->whereNotNull('next_run_at')
            ->delete();

        $id = DB::table('_tasks')->insertGetId([
            'class' => $def['class'],
            'method' => $def['method'],
            'queue' => $def['queue'],
            'status' => Task_Status::RUNNING,
            'params' => json_encode([]),
            'next_run_at' => date('Y-m-d H:i:s', time() + 3600),
            'cron_expression' => $def['cron_expression'],
            'started_at' => date('Y-m-d H:i:s', time() - 2000),
            'worker_pid' => self::DEAD_PID,
            'worker_member_key' => self::DEAD_MEMBER,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_not_null($row, 'real-schedule cron tracker should survive reconcile');
        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_null($row->worker_pid);
        static::__assert_null($row->worker_member_key);
    }

    /**
     * Task::dispatch() returns a pollable integer id backing a real pending row.
     *
     * Under the test suite dispatch() enqueues ONLY - no detached worker is spawned
     * (Task::spawn_workers(); Task_Spawn_Admission_Test) - so the row is still
     * pending when it is read back.
     */
    public static function test_dispatch_returns_pollable_id()
    {
        $id = Task::dispatch('Test_Echo_Service', 'echo_params', ['probe' => 1]);

        static::__assert_true(is_int($id));
        static::__assert_greater_than(0, $id);

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_not_null($row);
        static::__assert_equals(Task_Status::PENDING, $row->status);
    }
}
