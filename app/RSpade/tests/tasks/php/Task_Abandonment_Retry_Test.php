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
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Abandonment: what the rsx:task:process reaper does with a RUNNING run whose worker is gone.
 * Abandonment is not a failure of the task, so:
 *
 *   - an abandoned DISPATCHED run goes back to PENDING with scheduled_for =
 *     now + base * 2^(n-1) (n = its abandon_count after this abandonment), and is FAILED for
 *     good on the rsx.tasks.retry.attempts-th abandonment;
 *   - an abandoned SCHEDULED run is FAILED - its schedule runs again at its next cadence - and
 *     counted on the schedule;
 *   - a task that THROWS is never retried: it is FAILED by its own worker.
 *
 * Abandonment is staged as Task_Worker_Execution_Test stages it: a RUNNING run under a wid of
 * the daemon's current generation that has already left the pool. Expected times come from
 * time() read around the tick, so nothing sleeps.
 *
 * Commits real rows, so this class provisions a clean baseline once and opts out of per-test
 * transactions.
 */
class Task_Abandonment_Retry_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = Task_Exec_Fixture_Service::class;

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    const DEAD_PID = 2147480000;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function __reset_fixture(): void
    {
        DB::table('_tasks')->where('class', self::FIX)->delete();
        Task_Exec_Fixture_Service::$run_order = [];
    }

    /**
     * The identity of a worker of the daemon's CURRENT generation that has left $pool - the
     * pool the reaper asks about the run.
     */
    private static function __departed_identity(string $pool): array
    {
        Task_Pool::lock($pool);
        try {
            $identity = Task_Pool::join($pool);
            Task_Pool::leave($pool);
        } finally {
            Task_Pool::unlock($pool);
        }

        return $identity;
    }

    /** Put a run RUNNING under a departed worker of the given pool, as a claim would. */
    private static function __run_under_departed_worker(int $id, int $pool_id = Task_Run_Model::POOL_ON_DEMAND): array
    {
        $identity = static::__departed_identity($pool_id === Task_Run_Model::POOL_SCHEDULED ? Task_Pool::SCHEDULED : Task_Pool::ON_DEMAND);

        DB::table('_tasks')->where('id', $id)->update(array_merge(Task_Runner::running_fields(), [
            'worker_pid' => self::DEAD_PID,
            'worker_id' => $identity['wid'],
            'worker_generation' => $identity['generation'],
            'pool_id' => $pool_id,
        ]));

        return $identity;
    }

    private static function __insert_dispatched(string $method, array $fields = []): int
    {
        return Task_Runner::insert_row(self::FIX, $method, [], Task_Run_Model::ORIGIN_DISPATCHED, $fields);
    }

    /**
     * Run one reaper tick and return the window the retry's scheduled_for may fall in for
     * abandonment $n: [earliest, latest] as epoch seconds.
     */
    private static function __tick_expecting_retry(int $n): array
    {
        $delay = config('rsx.tasks.retry.base_seconds') * (2 ** ($n - 1));

        $before = time();
        Artisan::call('rsx:task:process');
        $after = time();

        return [$before + $delay, $after + $delay];
    }

    private static function __assert_retry_row(int $id, int $n, array $window, int $wid): void
    {
        $attempts = config('rsx.tasks.retry.attempts');
        $row = Task_Run_Model::find($id);

        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $row->status_id, "abandonment {$n} retries");
        static::__assert_equals($n, (int) $row->abandon_count, 'the abandonment is counted');
        $at = strtotime($row->scheduled_for);
        static::__assert_true($at >= $window[0] && $at <= $window[1], "scheduled_for {$row->scheduled_for} is now + base * 2^" . ($n - 1));
        foreach (['started_at', 'pool_id', 'worker_pid', 'worker_id', 'worker_generation', 'worker_host', 'completed_at', 'error'] as $column) {
            static::__assert_null($row->{$column}, "{$column} is clear");
        }
        static::__assert_contains("worker {$wid} is no longer connected to rsx-lockd", (string) $row->status_reason);
        static::__assert_contains('retry as attempt ' . ($n + 1) . " of {$attempts} at ", (string) $row->status_reason);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * The first abandonment schedules the retry base seconds out, the second twice that; the
     * run waits (the claim honours scheduled_for), and a retry that then succeeds completes.
     */
    public static function test_abandoned_dispatched_run_retries_with_doubling_delay()
    {
        static::__reset_fixture();

        $id = static::__insert_dispatched('marker_a');

        $identity = static::__run_under_departed_worker($id);
        static::__assert_retry_row($id, 1, static::__tick_expecting_retry(1), $identity['wid']);

        $identity = static::__run_under_departed_worker($id);
        static::__assert_retry_row($id, 2, static::__tick_expecting_retry(2), $identity['wid']);

        // Not due: a worker claims nothing.
        Artisan::call('rsx:task:worker');
        static::__assert_equals([], Task_Exec_Fixture_Service::$run_order, 'the retry waits for scheduled_for');
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) Task_Run_Model::find($id)->status_id);

        // Due: it runs and completes.
        DB::table('_tasks')->where('id', $id)->update(['scheduled_for' => now()->format('Y-m-d H:i:s.v')]);
        Artisan::call('rsx:task:worker');

        static::__assert_equals(['A'], Task_Exec_Fixture_Service::$run_order);
        $row = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $row->status_id);
        static::__assert_equals(2, (int) $row->abandon_count, 'the run remembers how often it was abandoned');

        static::__reset_fixture();
    }

    /**
     * The attempts-th abandonment fails the run for good, and the error names the limit.
     */
    public static function test_abandoned_dispatched_run_fails_on_the_last_attempt()
    {
        static::__reset_fixture();

        $attempts = config('rsx.tasks.retry.attempts');
        $id = static::__insert_dispatched('marker_a', ['abandon_count' => $attempts - 1]);

        static::__run_under_departed_worker($id);
        Artisan::call('rsx:task:process');

        $row = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $row->status_id);
        static::__assert_equals($attempts, (int) $row->abandon_count);
        static::__assert_equals(1, (int) $row->return_code);
        static::__assert_not_null($row->completed_at);
        static::__assert_contains("abandoned on all {$attempts} attempts", (string) $row->error);
        static::__assert_contains('rsx.tasks.retry.attempts', (string) $row->error);
        static::__assert_contains("attempt {$attempts} of {$attempts}, not retried", (string) $row->status_reason);

        static::__reset_fixture();
    }

    /**
     * An abandoned scheduled run is FAILED - never retried - and counted on its schedule, which
     * runs again at its next cadence. Uses a REAL manifest #[Schedule] so the same tick's
     * reconcile keeps the schedule row.
     */
    public static function test_abandoned_scheduled_run_is_failed_and_counted_on_its_schedule()
    {
        $scheduled = Task::get_scheduled_tasks();
        if (empty($scheduled)) {
            static::__skip('No manifest #[Schedule] task available to test an abandoned scheduled run');
        }
        $def = $scheduled[0];

        Artisan::call('rsx:task:process');
        $schedule = DB::table('_task_schedules')->where('class', $def['class'])->where('method', $def['method'])->first();
        DB::table('_task_schedules')->where('id', $schedule->id)->update(['consecutive_failures' => 0]);

        $id = Task_Runner::insert_row($def['class'], $def['method'], [], Task_Run_Model::ORIGIN_SCHEDULED, ['schedule_id' => $schedule->id]);
        $identity = static::__run_under_departed_worker($id, Task_Run_Model::POOL_SCHEDULED);

        Artisan::call('rsx:task:process');

        $row = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $row->status_id, 'a scheduled run is never retried');
        static::__assert_contains("worker {$identity['wid']} is no longer connected", (string) $row->error);
        static::__assert_equals(1, (int) $row->abandon_count);

        $after = DB::table('_task_schedules')->where('id', $schedule->id)->first();
        static::__assert_equals(1, (int) $after->consecutive_failures, 'an abandoned run is a failed run of its schedule');
        static::__assert_equals((int) $id, (int) $after->last_task_id);
        static::__assert_not_null($after->last_error_at);

        DB::table('_tasks')->where('id', $id)->delete();
    }

    /**
     * A dispatched run that THROWS is not retried: FAILED at once, whatever the retry config.
     */
    public static function test_a_thrown_run_is_not_retried()
    {
        static::__reset_fixture();

        $id = static::__insert_dispatched('always_throws');

        Artisan::call('rsx:task:worker');
        Artisan::call('rsx:task:process');

        $row = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $row->status_id);
        static::__assert_equals('Exception: fixture exploded on purpose', $row->error);
        static::__assert_equals(0, (int) $row->abandon_count);
        static::__assert_equals(['THROW'], Task_Exec_Fixture_Service::$run_order, 'it ran exactly once');

        static::__reset_fixture();
    }
}
