<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Abandonment retries: what the rsx:task:process reaper does with a RUNNING row whose worker
 * is gone.
 *
 *   - An abandoned ONE-SHOT goes back to PENDING with scheduled_for = now + base * 2^(n-1)
 *     (n = its abandonment count, kept in consecutive_failures), and is FAILED for good on the
 *     rsx.tasks.retry.attempts-th abandonment.
 *   - An abandoned #[Schedule] TRACKER counts the run as done: PENDING, next_run_at the next
 *     cadence after now, the abandonment recorded as a failed run.
 *   - A task that THROWS is not retried: a one-shot is FAILED at once.
 *
 * Abandonment is staged the way Task_Worker_Execution_Test stages it: a RUNNING row under a
 * wid of the daemon's current generation that has already left the pool. Expected times are
 * computed from time() read around the tick, so nothing sleeps.
 *
 * Commits real rows to _tasks, so this class provisions a clean baseline once and opts out of
 * per-test transactions.
 */
class Task_Abandonment_Retry_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = 'App\\RSpade\\Tests\\Tasks\\Php\\Task_Exec_Fixture_Service';

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

    /** The identity of a worker of the daemon's CURRENT generation that has left the pool. */
    private static function __departed_identity(): array
    {
        Task_Pool::lock();
        try {
            $identity = Task_Pool::join();
            Task_Pool::leave();
        } finally {
            Task_Pool::unlock();
        }

        return $identity;
    }

    /** Put a row RUNNING under a departed worker, as a claim would. */
    private static function __run_under_departed_worker(int $id): array
    {
        $identity = static::__departed_identity();

        DB::table('_tasks')->where('id', $id)->update([
            'status' => Task_Status::RUNNING,
            'started_at' => date('Y-m-d H:i:s'),
            'worker_pid' => self::DEAD_PID,
            'worker_id' => $identity['wid'],
            'worker_generation' => $identity['generation'],
            'worker_host' => Task_Pool::host(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $identity;
    }

    /** A pending one-shot fixture row. */
    private static function __insert_one_shot(string $method, array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');

        return DB::table('_tasks')->insertGetId(array_merge([
            'class' => self::FIX,
            'method' => $method,
            'queue' => 'default',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => null,
            'scheduled_for' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    /**
     * Run one reaper tick and return the retry delay window the row may carry for
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
        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::PENDING, $row->status, "abandonment {$n} retries");
        static::__assert_equals($n, (int) $row->consecutive_failures, 'the abandonment is counted');
        $at = strtotime($row->scheduled_for);
        static::__assert_true($at >= $window[0] && $at <= $window[1], "scheduled_for {$row->scheduled_for} is now + base * 2^" . ($n - 1));
        static::__assert_null($row->started_at);
        static::__assert_null($row->worker_pid);
        static::__assert_null($row->worker_id);
        static::__assert_null($row->worker_generation);
        static::__assert_null($row->worker_host);
        static::__assert_null($row->completed_at);
        static::__assert_not_null($row->last_error_at);
        static::__assert_contains("worker {$wid} is no longer connected to rsx-lockd", (string) $row->error);
        static::__assert_contains('retry as attempt ' . ($n + 1) . " of {$attempts} at ", (string) $row->status_reason);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * The first abandonment schedules the retry base seconds out, the second twice that; the
     * row waits (the claim honours scheduled_for), and a retry that then succeeds clears the
     * streak.
     */
    public static function test_abandoned_one_shot_retries_with_doubling_delay()
    {
        static::__reset_fixture();

        $id = static::__insert_one_shot('marker_a');

        $identity = static::__run_under_departed_worker($id);
        static::__assert_retry_row($id, 1, static::__tick_expecting_retry(1), $identity['wid']);

        $identity = static::__run_under_departed_worker($id);
        static::__assert_retry_row($id, 2, static::__tick_expecting_retry(2), $identity['wid']);

        // Not due: a worker claims nothing.
        Artisan::call('rsx:task:worker', ['--max-time' => 30]);
        static::__assert_equals([], Task_Exec_Fixture_Service::$run_order, 'the retry waits for scheduled_for');
        static::__assert_equals(Task_Status::PENDING, DB::table('_tasks')->where('id', $id)->value('status'));

        // Due: it runs, completes, and the streak is cleared.
        DB::table('_tasks')->where('id', $id)->update(['scheduled_for' => date('Y-m-d H:i:s')]);
        Artisan::call('rsx:task:worker', ['--max-time' => 30]);

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals(['A'], Task_Exec_Fixture_Service::$run_order);
        static::__assert_equals(Task_Status::COMPLETED, $row->status);
        static::__assert_equals(0, (int) $row->consecutive_failures, 'a success clears the abandonment count');
        static::__assert_null($row->status_reason);

        static::__reset_fixture();
    }

    /**
     * The attempts-th abandonment fails the row for good, and the error names the limit.
     */
    public static function test_abandoned_one_shot_fails_on_the_last_attempt()
    {
        static::__reset_fixture();

        $attempts = config('rsx.tasks.retry.attempts');
        $id = static::__insert_one_shot('marker_a', ['consecutive_failures' => $attempts - 1]);

        static::__run_under_departed_worker($id);
        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals(Task_Status::FAILED, $row->status);
        static::__assert_equals($attempts, (int) $row->consecutive_failures);
        static::__assert_not_null($row->completed_at);
        static::__assert_contains("abandoned on all {$attempts} attempts", (string) $row->error);
        static::__assert_contains('rsx.tasks.retry.attempts', (string) $row->error);

        static::__reset_fixture();
    }

    /**
     * An abandoned tracker counts the run as done: PENDING, next_run_at the next cadence after
     * now (its planted next_run_at is in the past, as a long run leaves the claim's advanced
     * value), the abandonment recorded as a failed run. Uses a REAL manifest #[Schedule] so the
     * processor's reconcile step keeps the tracker.
     */
    public static function test_abandoned_tracker_waits_for_its_next_cadence()
    {
        $scheduled = Task::get_scheduled_tasks();
        if (empty($scheduled)) {
            static::__skip('No manifest #[Schedule] task available to test an abandoned tracker');
        }
        $def = $scheduled[0];

        DB::table('_tasks')
            ->where('class', $def['class'])
            ->where('method', $def['method'])
            ->whereNotNull('next_run_at')
            ->delete();

        $id = DB::table('_tasks')->insertGetId([
            'class' => $def['class'],
            'method' => $def['method'],
            'queue' => $def['queue'],
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => date('Y-m-d H:i:s', time() - 3600),
            'cron_expression' => $def['cron_expression'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $identity = static::__run_under_departed_worker($id);

        $parser = new Cron_Parser($def['cron_expression']);
        $earliest = $parser->get_next_run_time();
        Artisan::call('rsx:task:process');
        $latest = $parser->get_next_run_time();

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals(Task_Status::PENDING, $row->status);
        $next = strtotime($row->next_run_at);
        static::__assert_true($next >= $earliest && $next <= $latest, "next_run_at {$row->next_run_at} is the next cadence after now");
        static::__assert_greater_than(time(), $next, 'never due at once');
        static::__assert_null($row->worker_pid);
        static::__assert_null($row->worker_id);
        static::__assert_null($row->worker_generation);
        static::__assert_null($row->worker_host);
        static::__assert_equals(1, (int) $row->consecutive_failures, 'an abandoned run is a failed run');
        static::__assert_not_null($row->last_error_at);
        static::__assert_contains("worker {$identity['wid']} is no longer connected", (string) $row->error);
        static::__assert_contains('abandoned (recycled)', (string) $row->status_reason);

        DB::table('_tasks')->where('id', $id)->delete();
    }

    /**
     * A one-shot that THROWS is not retried: FAILED at once, whatever the retry config says.
     */
    public static function test_thrown_one_shot_is_not_retried()
    {
        static::__reset_fixture();

        $id = static::__insert_one_shot('always_throws');

        Artisan::call('rsx:task:worker', ['--max-time' => 30]);
        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals(Task_Status::FAILED, $row->status);
        static::__assert_contains('fixture exploded on purpose', (string) $row->error);
        static::__assert_equals(['THROW'], Task_Exec_Fixture_Service::$run_order, 'it ran exactly once');

        static::__reset_fixture();
    }
}
