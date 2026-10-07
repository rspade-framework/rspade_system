<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Health_Checks;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * The worker loop driven in-process, and the abandoned-worker arm of the rsx:task:process
 * reaper.
 *
 * Artisan::call('rsx:task:worker') runs the real worker loop in THIS process against the test
 * database: it joins the pool on this process's Task_Pool connection, drains the due pending
 * runs (oldest due first) and leaves the pool before returning. The fixture's static
 * $run_order captures the true execution order.
 *
 * The abandoned-run tests plant the identity a claim writes (pool_id + worker_id +
 * worker_generation + worker_host + worker_pid): a wid of the daemon's own generation that has
 * left the pool (a dead worker) or this process's own live membership; a wid of an older
 * generation on this host (judged by its pid) or on another host (left alone); and a row with
 * no worker_id (an inline run), judged by its pid. Every planted run started NOW: there is no
 * grace period, so age never enters a verdict. The retry pacing of an abandoned dispatched run
 * is Task_Abandonment_Retry_Test.
 *
 * Commits real rows, so this class provisions a clean baseline once and opts out of per-test
 * transactions. Each test clears the fixture's rows before it runs.
 */
class Task_Worker_Execution_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = Task_Exec_Fixture_Service::class;

    /** A host name no test box carries. */
    const OTHER_HOST = 'task-worker-execution-test.other-host.invalid';

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

    private static function __pending(string $method, array $fields = []): int
    {
        return Task_Runner::insert_row(self::FIX, $method, [], Task_Run_Model::ORIGIN_DISPATCHED, $fields);
    }

    /**
     * Plant a run RUNNING since just now under the given worker. A worker_id makes it a pool
     * worker's (on_demand); $host null = this host.
     */
    private static function __plant_running_row(?int $wid, ?int $generation, int $pid, ?string $host = null, int $origin_id = Task_Run_Model::ORIGIN_DISPATCHED): int
    {
        return Task_Runner::insert_row(self::FIX, 'marker_a', [], $origin_id, array_merge(Task_Runner::running_fields(), [
            'worker_pid' => $pid,
            'worker_id' => $wid,
            'worker_generation' => $generation,
            'pool_id' => $wid === null ? null : Task_Run_Model::POOL_ON_DEMAND,
            'worker_host' => $host ?? Task_Pool::host(),
        ]));
    }

    /** The identity of a worker of the daemon's CURRENT generation that has left the pool. */
    private static function __departed_identity(): array
    {
        Task_Pool::lock(Task_Pool::ON_DEMAND);
        try {
            $identity = Task_Pool::join(Task_Pool::ON_DEMAND);
            Task_Pool::leave(Task_Pool::ON_DEMAND);
        } finally {
            Task_Pool::unlock(Task_Pool::ON_DEMAND);
        }

        return $identity;
    }

    /** A generation that is not the daemon's own. */
    private static function __older_generation(): int
    {
        $current = Task_Pool::stats(Task_Pool::ON_DEMAND)['generation'];

        return $current === 1 ? 2 : $current - 1;
    }

    private static function __row(int $id): Task_Run_Model
    {
        return Task_Run_Model::find($id);
    }

    // -------------------------------------------------------------------------
    // The worker loop
    // -------------------------------------------------------------------------

    public static function test_the_oldest_due_run_is_claimed_first()
    {
        static::__reset_fixture();

        static::__pending('marker_a', ['scheduled_for' => date('Y-m-d H:i:s', time() - 10) . '.000']);
        static::__pending('marker_b', ['scheduled_for' => date('Y-m-d H:i:s', time() - 60) . '.000']);

        Artisan::call('rsx:task:worker');

        static::__assert_equals(['B', 'A'], Task_Exec_Fixture_Service::$run_order, 'due longest ago runs first, whatever the insertion order');
    }

    /**
     * A claim records the pool identity, host and pid; the run completes with its reports; the
     * worker leaves the pool when it finishes.
     */
    public static function test_a_pending_run_completes_under_the_claiming_worker()
    {
        static::__reset_fixture();
        $id = static::__pending('marker_a');

        Artisan::call('rsx:task:worker');

        $row = static::__row($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $row->status_id);
        static::__assert_equals(0, (int) $row->return_code);
        static::__assert_not_null($row->started_at);
        static::__assert_not_null($row->completed_at);
        static::__assert_equals(['marker' => 'A'], $row->state());
        static::__assert_equals(Task_Run_Model::POOL_ON_DEMAND, (int) $row->pool_id, 'claimed by the on_demand pool');
        static::__assert_equals(getmypid(), (int) $row->worker_pid, 'the in-process worker is this pid');
        static::__assert_not_null($row->worker_id, 'the claim recorded the wid');
        static::__assert_equals(Task_Pool::stats(Task_Pool::ON_DEMAND)['generation'], (int) $row->worker_generation, 'and the daemon generation');
        static::__assert_equals(Task_Pool::host(), $row->worker_host, 'and this host');

        Task_Pool::lock(Task_Pool::ON_DEMAND);
        $verdict = Task_Pool::member_alive(Task_Pool::ON_DEMAND, (int) $row->worker_id, (int) $row->worker_generation);
        Task_Pool::unlock(Task_Pool::ON_DEMAND);
        static::__assert_equals(['alive' => false, 'known' => true], $verdict, 'the worker left the pool when it finished');
    }

    public static function test_a_failing_run_is_failed_and_the_worker_goes_on()
    {
        static::__reset_fixture();
        $failing = static::__pending('always_throws', ['scheduled_for' => date('Y-m-d H:i:s', time() - 60) . '.000']);
        $next = static::__pending('marker_a');

        Artisan::call('rsx:task:worker');

        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) static::__row($failing)->status_id);
        static::__assert_equals('Exception: fixture exploded on purpose', static::__row($failing)->error);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) static::__row($next)->status_id, 'the next run still ran');
        static::__assert_equals(['THROW', 'A'], Task_Exec_Fixture_Service::$run_order);
    }

    // -------------------------------------------------------------------------
    // Abandoned runs: the reaper's verdicts
    // -------------------------------------------------------------------------

    /**
     * A RUNNING run whose worker, of the daemon's current generation, is no longer a member is
     * abandoned on the next tick - started a moment ago, and with a live pid (this process):
     * the pool, not the pid or an age, decides.
     */
    public static function test_current_generation_departed_worker_is_abandoned_at_once()
    {
        static::__reset_fixture();

        $identity = static::__departed_identity();
        $id = static::__plant_running_row($identity['wid'], $identity['generation'], getmypid());

        Artisan::call('rsx:task:process');

        $row = static::__row($id);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $row->status_id, 'abandoned, so retried');
        static::__assert_contains("worker {$identity['wid']} is no longer connected to rsx-lockd", (string) $row->status_reason);
        static::__assert_equals(1, (int) $row->abandon_count);
        static::__assert_null($row->worker_id);
        static::__assert_greater_than(time(), strtotime($row->scheduled_for), 'the retry waits');
        static::__assert_contains('Abandoned - worker', implode("\n", array_column($row->output_after(null, ['operator']), 'line')), 'recorded as an operator line');
    }

    public static function test_row_of_a_live_member_is_not_reaped()
    {
        static::__reset_fixture();

        Task_Pool::lock(Task_Pool::ON_DEMAND);
        $identity = Task_Pool::join(Task_Pool::ON_DEMAND);
        Task_Pool::unlock(Task_Pool::ON_DEMAND);

        try {
            // A dead pid on purpose: the membership is the verdict, not the pid.
            $id = static::__plant_running_row($identity['wid'], $identity['generation'], self::DEAD_PID);

            Artisan::call('rsx:task:process');

            $row = static::__row($id);
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) $row->status_id, 'a live member\'s run keeps running');
            static::__assert_equals($identity['wid'], (int) $row->worker_id);
        } finally {
            Task_Pool::lock(Task_Pool::ON_DEMAND);
            Task_Pool::leave(Task_Pool::ON_DEMAND);
            Task_Pool::unlock(Task_Pool::ON_DEMAND);
            static::__reset_fixture();
        }
    }

    /**
     * A run from an OLDER daemon generation on THIS host is settled by its pid: a dead pid is
     * abandoned, a live one is left to record its own outcome.
     */
    public static function test_older_generation_on_this_host_is_judged_by_pid()
    {
        static::__reset_fixture();

        $older = static::__older_generation();
        $dead = static::__plant_running_row(17, $older, self::DEAD_PID);
        $live = static::__plant_running_row(18, $older, getmypid());

        Artisan::call('rsx:task:process');

        $dead_row = static::__row($dead);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) $dead_row->status_id, 'dead pid -> abandoned');
        static::__assert_equals(1, (int) $dead_row->abandon_count);
        static::__assert_contains('worker 17 of a previous rsx-lockd generation', (string) $dead_row->status_reason);

        $live_row = static::__row($live);
        static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) $live_row->status_id, 'live pid -> left alone');
        static::__assert_equals(18, (int) $live_row->worker_id);

        static::__reset_fixture();
    }

    /**
     * A run from an older generation on ANOTHER host is left alone - even with a dead pid,
     * which on this box means nothing - for that host's own tick, and counted by the health
     * row. A run of this host is not counted.
     */
    public static function test_older_generation_on_another_host_is_left_and_counted()
    {
        static::__reset_fixture();

        $current = Task_Pool::stats(Task_Pool::ON_DEMAND)['generation'];
        $older = static::__older_generation();

        $other = static::__plant_running_row(21, $older, self::DEAD_PID, self::OTHER_HOST);
        static::__plant_running_row(22, $older, getmypid(), self::OTHER_HOST);
        static::__plant_running_row(23, $older, getmypid(), 'b-' . self::OTHER_HOST);
        static::__plant_running_row(24, $older, getmypid());

        Artisan::call('rsx:task:process');

        static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) static::__row($other)->status_id, 'another host\'s older-generation run is left alone');

        static::__assert_equals(
            ['count' => 3, 'hosts' => ['b-' . self::OTHER_HOST, self::OTHER_HOST]],
            Task_Health_Checks::previous_generation_rows($current),
            'three runs on two other hosts; this host\'s run is not counted'
        );

        $health = Task_Health_Checks::task_worker_pool();
        static::__assert_equals('WARN', $health['status'], 'the health row warns: ' . json_encode($health));
        static::__assert_contains('3 running task(s) from a previous rsx-lockd generation on host(s) b-' . self::OTHER_HOST . ', ' . self::OTHER_HOST, $health['detail']);

        static::__reset_fixture();
        static::__assert_equals(['count' => 0, 'hosts' => []], Task_Health_Checks::previous_generation_rows($current));
    }

    /**
     * A RUNNING run with no worker_id - no pool member - is judged by its pid on its own host:
     * a dead pid abandons it (an inline run is FAILED, its caller gone; a dispatched one, run
     * by --once, is retried); another host's run is left alone.
     */
    public static function test_a_run_without_a_worker_id_is_judged_by_pid()
    {
        static::__reset_fixture();

        $inline = static::__plant_running_row(null, null, self::DEAD_PID, null, Task_Run_Model::ORIGIN_INLINE);
        $once = static::__plant_running_row(null, null, self::DEAD_PID);
        $other = static::__plant_running_row(null, null, self::DEAD_PID, self::OTHER_HOST);

        Artisan::call('rsx:task:process');

        $inline_row = static::__row($inline);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $inline_row->status_id, 'an abandoned inline run is failed');
        static::__assert_equals('Task abandoned - the process running it (PID ' . self::DEAD_PID . ') is gone.', $inline_row->error);
        static::__assert_equals(1, (int) $inline_row->return_code);

        static::__assert_equals(Task_Run_Model::STATUS_PENDING, (int) static::__row($once)->status_id, 'an abandoned dispatched run is retried');
        static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) static::__row($other)->status_id, 'another host\'s pid is not judged here');

        static::__reset_fixture();
    }
}
