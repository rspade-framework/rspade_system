<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use App\RSpade\Commands\Rsx\Task_Worker_Command;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Health_Checks;
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
 * connection and leaves it before returning. The abandoned-row tests plant the identity a
 * worker would have written (worker_id + worker_generation + worker_host + worker_pid): a wid
 * of the daemon's own generation that has left the pool (a dead worker) or this process's own
 * live membership; a wid of an older generation on this host (judged by its pid) or on another
 * host (left alone); and a row with no worker_id (an inline run), judged by its pid. Every
 * planted row started NOW: there is no grace period, so age never enters a verdict.
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

    /** A host name no test box carries. */
    const OTHER_HOST = 'task-worker-execution-test.other-host.invalid';

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
     * Plant an on-demand fixture row RUNNING since just now under the given worker.
     * $host null = this host.
     */
    private static function __plant_running_row(?int $wid, ?int $generation, int $pid, ?string $host = null): int
    {
        return DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'default',
            'status' => Task_Status::RUNNING,
            'params' => json_encode([]),
            'next_run_at' => null,
            'started_at' => date('Y-m-d H:i:s'),
            'worker_pid' => $pid,
            'worker_id' => $wid,
            'worker_generation' => $generation,
            'worker_host' => $host ?? Task_Pool::host(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
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

    /** A generation that is not the daemon's own. */
    private static function __older_generation(): int
    {
        $current = Task_Pool::stats()['generation'];

        return $current === 1 ? 2 : $current - 1;
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
     * A worker's claim records its pool identity (worker_id + worker_generation), its host and
     * its pid on the row, and the row keeps them once it completes.
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
        static::__assert_not_null($row->worker_id, 'the claim recorded the wid');
        static::__assert_equals(Task_Pool::stats()['generation'], (int) $row->worker_generation, 'and the daemon generation');
        static::__assert_equals(Task_Pool::host(), $row->worker_host, 'and this host');

        Task_Pool::lock();
        $verdict = Task_Pool::member_alive((int) $row->worker_id, (int) $row->worker_generation);
        Task_Pool::unlock();
        static::__assert_equals(['alive' => false, 'known' => true], $verdict, 'the worker left the pool when it finished');
    }

    /**
     * A RUNNING row whose worker, of the daemon's current generation, is no longer a member
     * is abandoned on the next tick - started a moment ago, and with a live pid (this
     * process): the pool, not the pid or an age, decides. An abandoned one-shot goes back to
     * pending for a paced retry (Task_Abandonment_Retry_Test covers the pacing).
     */
    public static function test_current_generation_departed_worker_is_abandoned_at_once()
    {
        static::__reset_fixture();

        $identity = static::__departed_identity();
        $id = static::__plant_running_row($identity['wid'], $identity['generation'], getmypid());

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_equals(Task_Status::PENDING, $row->status, 'abandoned, so retried');
        static::__assert_contains("worker {$identity['wid']} is no longer connected to rsx-lockd", (string) $row->error);
        static::__assert_null($row->worker_id);
        static::__assert_greater_than(time(), strtotime($row->scheduled_for), 'the retry waits');
    }

    /**
     * A RUNNING row whose worker is still a pool member is left alone.
     */
    public static function test_row_of_a_live_member_is_not_reaped()
    {
        static::__reset_fixture();

        Task_Pool::lock();
        $identity = Task_Pool::join();
        Task_Pool::unlock();

        try {
            // A dead pid on purpose: the membership is the verdict, not the pid.
            $id = static::__plant_running_row($identity['wid'], $identity['generation'], self::DEAD_PID);

            Artisan::call('rsx:task:process');

            $row = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::RUNNING, $row->status, 'a live member\'s row keeps running');
            static::__assert_equals($identity['wid'], (int) $row->worker_id);
        } finally {
            Task_Pool::lock();
            Task_Pool::leave();
            Task_Pool::unlock();
            DB::table('_tasks')->where('class', self::FIX)->delete();
        }
    }

    /**
     * A row from an OLDER daemon generation on THIS host is settled by its pid: a dead pid is
     * abandoned (back to pending for a retry), a live one is left to record its own outcome.
     */
    public static function test_older_generation_on_this_host_is_judged_by_pid()
    {
        static::__reset_fixture();

        $older = static::__older_generation();
        $dead = static::__plant_running_row(17, $older, self::DEAD_PID);
        $live = static::__plant_running_row(18, $older, getmypid());

        Artisan::call('rsx:task:process');

        $dead_row = DB::table('_tasks')->where('id', $dead)->first();
        static::__assert_equals(Task_Status::PENDING, $dead_row->status, 'dead pid -> abandoned');
        static::__assert_equals(1, (int) $dead_row->consecutive_failures);
        static::__assert_contains('worker 17 of a previous rsx-lockd generation', (string) $dead_row->error);

        $live_row = DB::table('_tasks')->where('id', $live)->first();
        static::__assert_equals(Task_Status::RUNNING, $live_row->status, 'live pid -> left alone');
        static::__assert_equals(18, (int) $live_row->worker_id);

        DB::table('_tasks')->where('class', self::FIX)->delete();
    }

    /**
     * A row from an older generation on ANOTHER host is left alone - even with a dead pid,
     * which on this box means nothing - for that host's own tick, and counted by the health
     * row. A row of the current generation or of this host is not counted.
     */
    public static function test_older_generation_on_another_host_is_left_and_counted()
    {
        static::__reset_fixture();

        $current = Task_Pool::stats()['generation'];
        $older = static::__older_generation();

        $other = static::__plant_running_row(21, $older, self::DEAD_PID, self::OTHER_HOST);
        static::__plant_running_row(22, $older, getmypid(), self::OTHER_HOST);
        static::__plant_running_row(23, $older, getmypid(), 'b-' . self::OTHER_HOST);
        static::__plant_running_row(24, $older, getmypid());

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $other)->first();
        static::__assert_equals(Task_Status::RUNNING, $row->status, 'another host\'s older-generation row is left alone');

        static::__assert_equals(
            ['count' => 3, 'hosts' => ['b-' . self::OTHER_HOST, self::OTHER_HOST]],
            Task_Health_Checks::previous_generation_rows($current),
            'three rows on two other hosts; this host\'s row is not counted'
        );

        $health = Task_Health_Checks::task_worker_pool();
        static::__assert_equals('WARN', $health['status'], 'the health row warns: ' . json_encode($health));
        static::__assert_contains('3 running task(s) from a previous rsx-lockd generation on host(s) b-' . self::OTHER_HOST . ', ' . self::OTHER_HOST, $health['detail']);

        DB::table('_tasks')->where('class', self::FIX)->delete();
        static::__assert_equals(['count' => 0, 'hosts' => []], Task_Health_Checks::previous_generation_rows($current));
    }

    /**
     * A RUNNING row with no worker_id - an inline run, no pool member - is judged by the pid on
     * its own host: a dead pid abandons it; another host's row is left alone.
     */
    public static function test_row_without_a_worker_id_is_judged_by_pid()
    {
        static::__reset_fixture();

        $id = static::__plant_running_row(null, null, self::DEAD_PID);
        $other = static::__plant_running_row(null, null, self::DEAD_PID, self::OTHER_HOST);

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_contains('worker PID ' . self::DEAD_PID . ' is no longer running', (string) $row->error);

        static::__assert_equals(Task_Status::RUNNING, DB::table('_tasks')->where('id', $other)->value('status'), 'another host\'s pid is not judged here');

        DB::table('_tasks')->where('class', self::FIX)->delete();
    }

    /**
     * An abandoned CRON tracker (RUNNING, its current-generation worker gone) is recycled to
     * pending, not failed, all four worker columns are cleared, and its next_run_at - planted
     * in the past, as a long run leaves the claim's advanced value - becomes the next cadence
     * after now. Uses a REAL manifest
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

        $identity = static::__departed_identity();

        $id = DB::table('_tasks')->insertGetId([
            'class' => $def['class'],
            'method' => $def['method'],
            'queue' => $def['queue'],
            'status' => Task_Status::RUNNING,
            'params' => json_encode([]),
            'next_run_at' => date('Y-m-d H:i:s', time() - 3600),
            'cron_expression' => $def['cron_expression'],
            'started_at' => date('Y-m-d H:i:s'),
            'worker_pid' => self::DEAD_PID,
            'worker_id' => $identity['wid'],
            'worker_generation' => $identity['generation'],
            'worker_host' => Task_Pool::host(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Artisan::call('rsx:task:process');

        $row = DB::table('_tasks')->where('id', $id)->first();

        static::__assert_not_null($row, 'real-schedule cron tracker should survive reconcile');
        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_null($row->worker_pid);
        static::__assert_null($row->worker_id);
        static::__assert_null($row->worker_generation);
        static::__assert_null($row->worker_host);
        static::__assert_contains('abandoned (recycled)', (string) $row->status_reason);
        static::__assert_greater_than(time(), strtotime($row->next_run_at), 'never due at once');
    }

    /**
     * A worker that lost its pool connection between the claim and the run hands its row back:
     * PENDING, started_at and the four worker columns cleared, a tracker's next_run_at restored
     * to the due time the claim advanced. A row the reaper already settled is left as it is.
     * (The loss itself cannot be staged in-process, so the hand-back is driven directly.)
     */
    public static function test_a_claim_lost_before_the_run_goes_back_to_pending()
    {
        static::__reset_fixture();

        $identity = ['wid' => 31, 'generation' => Task_Pool::stats()['generation']];
        $due = date('Y-m-d H:i:s', time() - 60);

        $id = DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'queue' => 'scheduled',
            'status' => Task_Status::RUNNING,
            'params' => json_encode([]),
            'next_run_at' => date('Y-m-d H:i:s', time() + 300),
            'cron_expression' => 'every 5 minutes',
            'started_at' => date('Y-m-d H:i:s'),
            'worker_pid' => getmypid(),
            'worker_id' => $identity['wid'],
            'worker_generation' => $identity['generation'],
            'worker_host' => Task_Pool::host(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $claimed_as = (object) ['id' => $id, 'class' => self::FIX, 'method' => 'marker_a', 'next_run_at' => $due];

        $output = static::__release_unrun_claim($claimed_as, $identity);

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_null($row->started_at);
        static::__assert_equals(strtotime($due), strtotime($row->next_run_at), 'the due time is restored');
        static::__assert_null($row->worker_pid);
        static::__assert_null($row->worker_id);
        static::__assert_null($row->worker_generation);
        static::__assert_null($row->worker_host);
        static::__assert_contains("claiming task {$id}", $output);
        static::__assert_contains('the row is back to pending', $output);

        // Already settled by the reaper: the hand-back matches nothing.
        DB::table('_tasks')->where('id', $id)->update(['status' => Task_Status::FAILED]);
        $output = static::__release_unrun_claim($claimed_as, $identity);
        static::__assert_equals(Task_Status::FAILED, DB::table('_tasks')->where('id', $id)->value('status'));
        static::__assert_contains('already settled by the reaper', $output);

        DB::table('_tasks')->where('class', self::FIX)->delete();
    }

    /** Drive Task_Worker_Command::release_unrun_claim() and return what it printed. */
    private static function __release_unrun_claim(object $task_row, array $identity): string
    {
        $buffer = new BufferedOutput();
        $command = new Task_Worker_Command();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        (new \ReflectionMethod(Task_Worker_Command::class, 'release_unrun_claim'))
            ->invoke($command, $task_row, null, $identity, 'test: connection lost');

        return $buffer->fetch();
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

    /**
     * Plant a pending on-demand fixture row, drain it with an in-process worker and hand back
     * the settled row. The worker passes printed text through to its own stdout, which here
     * is the test runner's: the outer buffer swallows it.
     */
    private static function __run_fixture(string $method): object
    {
        $id = DB::table('_tasks')->insertGetId([
            'class' => self::FIX,
            'method' => $method,
            'queue' => 'default',
            'status' => Task_Status::PENDING,
            'params' => json_encode([]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        ob_start();
        try {
            Artisan::call('rsx:task:worker', ['--max-time' => 30]);
        } finally {
            ob_end_clean();
        }

        return DB::table('_tasks')->where('id', $id)->first();
    }

    /** The message part of each log line at $level ("[ts] [level] message"). */
    private static function __log_messages(object $row, string $level): array
    {
        $messages = [];
        foreach (explode("\n", (string) $row->logs) as $line) {
            if (preg_match('/^\[[^\]]+\] \[' . preg_quote($level, '/') . '\] (.*)$/', $line, $match)) {
                $messages[] = $match[1];
            }
        }

        return $messages;
    }

    /**
     * task-exec-out-01 - what a task prints is recorded on its log as `output` lines, in
     * order with its logger calls: each complete line as it is printed, a buffer the task
     * left open flushed into the capture, the trailing partial line at the end.
     */
    public static function test_printed_output_is_recorded_on_the_log()
    {
        static::__reset_fixture();

        $level = ob_get_level();
        $row = static::__run_fixture('prints_output');

        static::__assert_equals(Task_Status::COMPLETED, $row->status, 'the run completed');
        static::__assert_equals(
            ['first printed line', 'second printed line', 'from a buffer left open', 'trailing partial'],
            static::__log_messages($row, 'output'),
            'every printed line, the open buffer and the partial tail are recorded'
        );

        $lines = explode("\n", $row->logs);
        $order = array_values(array_filter(array_map(function ($line) {
            return preg_match('/\] (before the echo|first printed line|between)$/', $line, $m) ? $m[1] : null;
        }, $lines)));
        static::__assert_equals(['before the echo', 'first printed line', 'between'], $order, 'printed lines interleave with logger lines in the order they happened');
        static::__assert_equals($level, ob_get_level(), 'the buffer the task left open does not outlive the run');
    }

    /**
     * task-exec-out-02 - a run that prints and then throws keeps what it printed, and still
     * settles failed with its error.
     */
    public static function test_printed_output_survives_a_throw()
    {
        static::__reset_fixture();

        $level = ob_get_level();
        $row = static::__run_fixture('prints_then_throws');

        static::__assert_equals(Task_Status::FAILED, $row->status, 'the run failed');
        static::__assert_equals(['printed before the throw'], static::__log_messages($row, 'output'), 'the printed line was recorded');
        static::__assert_contains('fixture exploded after printing', (string) $row->error, 'the error is recorded');
        static::__assert_equals($level, ob_get_level(), 'the capture closed its buffer on the throw');
    }
}
