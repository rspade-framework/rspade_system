<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Task\Task;
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
 * Commits real rows to _tasks, so this class provisions a clean baseline once
 * and opts out of per-test transactions. Each test clears its own residue and
 * the Redis worker-slot registry before running.
 */
class Task_Worker_Execution_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = 'App\\RSpade\\Tests\\Tasks\\Php\\Task_Exec_Fixture_Service';

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Clear the Redis worker-slot registry so a fresh worker admits into the pool.
     */
    private static function __clear_registry(): void
    {
        $redis = new \Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
        $redis->select(1);
        $redis->del('rsx:tasks:workers:' . Rsx_Connection_Scope::token());
        $redis->close();
    }

    /**
     * Remove any residual fixture rows and reset the fixture's execution log.
     */
    private static function __reset_fixture(): void
    {
        DB::table('_tasks')->where('class', self::FIX)->delete();
        Task_Exec_Fixture_Service::$run_order = [];
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
        static::__clear_registry();
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
        static::__clear_registry();
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
        static::__clear_registry();
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
     * A stuck on-demand row (RUNNING, old started_at, dead worker_pid) is failed
     * by the processor's stuck-task recovery.
     */
    public static function test_stuck_on_demand_row_marked_failed()
    {
        static::__reset_fixture();

        $id = DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'default',
            'status' => Task_Status::RUNNING,
            'params' => json_encode([]),
            'next_run_at' => null,
            'started_at' => date('Y-m-d H:i:s', time() - 2000),
            'worker_pid' => 2147480000,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::FAILED, $row->status);
    }

    /**
     * A stuck CRON tracker (RUNNING, old started_at, dead worker_pid) is recycled
     * to pending, not failed. Uses a REAL manifest #[Schedule] task so the
     * processor's reconcile step leaves the tracker in place.
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
            'worker_pid' => 2147480000,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_not_null($row, 'real-schedule cron tracker should survive reconcile');
        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_null($row->worker_pid);
    }

    /**
     * Task::dispatch() returns a pollable integer id backing a real pending row and
     * fires a prompt worker.
     *
     * NOTE: dispatch() also spawns a DETACHED worker. That worker connects to the dev
     * DB (the default connection outside this in-process test-DB swap), not this test
     * DB, so it is a harmless side effect and cannot mutate the row asserted on here.
     * Kept last because of that spawn.
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
