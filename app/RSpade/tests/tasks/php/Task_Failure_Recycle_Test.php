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
 * The invariant: a cron tracker is NEVER permanently terminal.
 *
 * One tracker row exists per #[Schedule] and IS that schedule. A terminal status on it
 * stops the schedule forever while the row still looks registered - the failure mode this
 * suite exists to prevent ("failing them would silently kill the cron").
 *
 * Two mechanisms are covered:
 *   1. The terminal writers (Task_Instance::mark_failed/mark_completed) recycle a tracker
 *      to PENDING in the SAME update that records the outcome, so no terminal window
 *      exists for a killed worker to strand the schedule in. The failure is still recorded
 *      (error, status_reason, last_error_at, consecutive_failures).
 *   2. The rsx:task:process backstop (revive_stranded_trackers) rescues a tracker already
 *      sitting terminal - a pre-fix row arriving via framework pull, a hand-edited row.
 *
 * The failure/success tests drive the WORKER (which does not reconcile schedules) against
 * synthetic trackers pointed at the fixture service. The backstop test drives the
 * PROCESSOR, which DELETES any tracker whose class::method is not a real manifest
 * #[Schedule] - so it borrows real scheduled identities.
 *
 * Commits real rows to _tasks, so this class provisions a clean baseline once and opts out
 * of per-test transactions.
 */
class Task_Failure_Recycle_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = 'App\\RSpade\\Tests\\Tasks\\Php\\Task_Exec_Fixture_Service';

    /**
     * A schedule whose next occurrence is comfortably far away. The worker advances
     * next_run_at BEFORE running, so a tight cadence could come due again inside the same
     * worker loop and run the fixture twice.
     */
    const SLOW_CRON = 'daily at 3am';

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

    /**
     * Insert a due cron tracker pointed at a fixture method.
     */
    private static function __insert_tracker(string $method, array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');

        return DB::table('_tasks')->insertGetId(array_merge([
            'class' => self::FIX,
            'method' => $method,
            'queue' => 'scheduled',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => $now,
            'cron_expression' => self::SLOW_CRON,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private static function __run_worker(): void
    {
        static::__clear_registry();
        Artisan::call('rsx:task:worker', ['--max-time' => 30]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * A tracker whose task throws is recycled to PENDING - never FAILED - with the
     * failure fully recorded and the recurring schedule intact.
     */
    public static function test_cron_tracker_failure_recycles_to_pending()
    {
        static::__reset_fixture();

        $id = self::__insert_tracker('always_throws');

        self::__run_worker();

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_not_null($row, 'the tracker must survive its own failure');
        static::__assert_equals(Task_Status::PENDING, $row->status, 'a tracker is never left terminal');
        static::__assert_null($row->worker_pid);
        static::__assert_contains('fixture exploded on purpose', (string) $row->error);
        static::__assert_true(
            str_starts_with((string) $row->status_reason, 'failed (recycled): '),
            'the recycle is labelled on status_reason'
        );
        static::__assert_equals(1, (int) $row->consecutive_failures);
        static::__assert_not_null($row->last_error_at, 'the failure is dated');
        static::__assert_null($row->completed_at, 'completed_at means LAST SUCCESSFUL RUN - a failure never sets it');
        static::__assert_not_null($row->next_run_at, 'the recurring schedule survives');
        static::__assert_greater_than(time(), strtotime($row->next_run_at), 'the failure fires again next cadence');
        static::__assert_equals(['THROW'], Task_Exec_Fixture_Service::$run_order, 'the failing task ran exactly once');
    }

    /**
     * A task that raises an \Error (not an Exception) is recorded the same way. Only the
     * worker's Throwable catch makes this visible at all - under a \Exception catch the
     * error text was lost entirely.
     */
    public static function test_cron_tracker_type_error_is_recorded()
    {
        static::__reset_fixture();

        $id = self::__insert_tracker('raises_type_error');

        self::__run_worker();

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_contains('fixture type error on purpose', (string) $row->error, 'a TypeError records its message');
        static::__assert_equals(1, (int) $row->consecutive_failures);
        static::__assert_not_null($row->last_error_at);
        static::__assert_equals(['TYPE_ERROR'], Task_Exec_Fixture_Service::$run_order);
    }

    /**
     * The one-shot contract is unchanged: an on-demand row that throws goes terminal FAILED
     * with completed_at, now additionally dated by last_error_at.
     */
    public static function test_one_shot_failure_stays_terminal()
    {
        static::__reset_fixture();

        $now = date('Y-m-d H:i:s');

        $id = DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'always_throws',
            'queue' => 'default',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'next_run_at' => null,
            'scheduled_for' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::__run_worker();

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::FAILED, $row->status, 'a one-shot row is terminal on failure');
        static::__assert_not_null($row->completed_at);
        static::__assert_not_null($row->last_error_at);
        static::__assert_contains('fixture exploded on purpose', (string) $row->error);
        static::__assert_equals(1, (int) $row->consecutive_failures);
        static::__assert_null($row->status_reason, 'nothing was recycled, so nothing explains a recycle');
    }

    /**
     * A successful run clears the failure counter, so consecutive_failures always means
     * "failures in a row RIGHT NOW", and stamps completed_at as the last successful run.
     */
    public static function test_success_resets_consecutive_failures()
    {
        static::__reset_fixture();

        $id = self::__insert_tracker('marker_a', [
            'consecutive_failures' => 3,
            'status_reason' => 'failed (recycled): earlier run blew up',
        ]);

        self::__run_worker();

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_equals(0, (int) $row->consecutive_failures, 'a success clears the streak');
        static::__assert_null($row->status_reason, 'a success clears the recycled-failure reason too');
        static::__assert_not_null($row->completed_at, 'completed_at records the last successful run');
        static::__assert_not_null($row->result);
        static::__assert_equals(['A'], Task_Exec_Fixture_Service::$run_order);
    }

    /**
     * The backstop: a tracker ALREADY stranded terminal - by a pre-fix release, a hand-run
     * UPDATE, a crash window - is revived by the next rsx:task:process tick, with its
     * failure record left intact.
     *
     * Both rows borrow REAL manifest #[Schedule] identities: the same tick reconciles
     * schedules and would delete a tracker whose class::method it does not recognize.
     */
    public static function test_backstop_revives_stranded_trackers()
    {
        static::__reset_fixture();

        $defs = Task::get_scheduled_tasks();
        if (count($defs) < 2) {
            static::__skip('need two manifest #[Schedule] identities to strand');
            return;
        }

        $ids = [];
        $plant = [Task_Status::FAILED, Task_Status::KILLED];

        foreach ($plant as $index => $status) {
            $def = $defs[$index];

            DB::table('_tasks')
                ->where('class', $def['class'])
                ->where('method', $def['method'])
                ->whereNotNull('next_run_at')
                ->delete();

            $ids[$status] = DB::table('_tasks')->insertGetId([
                'class' => $def['class'],
                'method' => $def['method'],
                'queue' => $def['queue'],
                'status' => $status,
                'params' => json_encode([]),
                'error' => 'stranded before the fix',
                'status_reason' => 'stranded before the fix',
                'consecutive_failures' => 4,
                'next_run_at' => date('Y-m-d H:i:s', time() + 3600),
                'cron_expression' => $def['cron_expression'],
                'worker_pid' => 2147480000,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        Artisan::call('rsx:task:process');
        $output = Artisan::output();

        foreach ($plant as $status) {
            $row = DB::table('_tasks')->where('id', $ids[$status])->first();

            static::__assert_not_null($row, "the {$status} tracker must survive reconcile");
            static::__assert_equals(Task_Status::PENDING, $row->status, "a {$status} tracker is revived");
            static::__assert_null($row->worker_pid);
            static::__assert_equals('stranded before the fix', (string) $row->error, 'the failure record is preserved');
            static::__assert_equals(4, (int) $row->consecutive_failures, 'the failure count is preserved');
        }

        static::__assert_contains('[STRANDED SCHEDULE]', $output, 'each rescue is reported');
    }
}
