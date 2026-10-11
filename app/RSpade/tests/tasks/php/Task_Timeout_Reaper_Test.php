<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Kill_Worker;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Worker_Victim;

/**
 * The timeout arm of the rsx:task:process reaper
 * (App\RSpade\Commands\Rsx\Task_Process_Command::enforce_task_timeout()).
 *
 * Contract: a RUNNING run whose worker is STILL ALIVE on this host is under exactly one limit.
 *
 *   SILENCE (a run that names no timeout): no sign of life for rsx.tasks.silent_timeout
 *   seconds, the sign of life being the latest of started_at, last_heartbeat_at and
 *   last_report_at. A run that keeps reporting is never killed for time, however long it has
 *   run; a run that reports nothing is killed that long after it started; 0 = no limit.
 *
 *   A HARD LIMIT (a run dispatched with an explicit timeout): that many seconds from
 *   started_at, reporting or not.
 *
 * Past its limit the run gets a FORCE KILL request, carried out by a kill worker exactly as an
 * operator's force kill is: the worker is SIGKILLed and the run settles KILLED naming the
 * limit ("no report for Ns (cap Ms)" / "timed out after Ns (cap Ms)"). The abandoned-worker
 * arm is Task_Worker_Execution_Test; the kill itself is Task_Kill_Worker_Test; that every
 * reporting call stamps a sign of life is Task_Sign_Of_Life_Test.
 *
 * The worker is a REAL process - this test's child, whose command line is a task worker's
 * (Task_Worker_Victim) - on a run carrying a pool identity of an older rsx-lockd generation,
 * which the reaper judges by the pid on this host: still a task worker, so the timeout is what
 * decides. Under the suite no kill worker is spawned, so the
 * test carries the request out itself after the tick.
 *
 * Commits real rows, so this class provisions a clean baseline and opts out of transactions.
 */
class Task_Timeout_Reaper_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function teardown()
    {
        Task_Worker_Victim::reap_all();
    }

    /** A live process that reads as a task worker of this project (Task_Worker_Victim). */
    private static function __spawn_victim(): int
    {
        return Task_Worker_Victim::start_worker();
    }

    private static function __reap(int $pid): void
    {
        Task_Worker_Victim::reap($pid);
    }

    private static function __running(int $pid): bool
    {
        return Task_Worker_Victim::running($pid);
    }

    /**
     * A RUNNING run of a live worker on this host, started $seconds_ago, with $timeout.
     * $signs: last_heartbeat_at / last_report_at as seconds ago.
     */
    private static function __running_row(int $pid, ?int $timeout, int $seconds_ago, array $signs = []): int
    {
        $sign_fields = [];
        foreach ($signs as $column => $ago) {
            $sign_fields[$column] = date('Y-m-d H:i:s', time() - $ago) . '.000';
        }

        $current = Task_Pool::stats(Task_Pool::ON_DEMAND)['generation'];

        return Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, array_merge(Task_Runner::running_fields(), [
            'worker_pid' => $pid,
            'worker_id' => 61,
            'worker_generation' => $current === 1 ? 2 : $current - 1,
            'pool_id' => Task_Run_Model::POOL_ON_DEMAND,
            'timeout' => $timeout,
            'started_at' => date('Y-m-d H:i:s', time() - $seconds_ago) . '.000',
        ], $sign_fields));
    }

    /** Run $test with rsx.tasks.silent_timeout set to $seconds. */
    private static function __with_silent_timeout($seconds, callable $test): void
    {
        $original = config('rsx.tasks.silent_timeout');
        config(['rsx.tasks.silent_timeout' => $seconds]);
        try {
            $test();
        } finally {
            config(['rsx.tasks.silent_timeout' => $original]);
        }
    }

    private static function __tick(): string
    {
        Artisan::call('rsx:task:process');

        return Artisan::output();
    }

    /** Carry out every pending kill request of $task_id here, as a kill worker would. */
    private static function __carry_out(int $task_id): void
    {
        foreach (Task_Kill_Request_Model::where('task_id', $task_id)->where('status_id', Task_Kill_Request_Model::STATUS_PENDING)->pluck('id') as $request_id) {
            Task_Kill_Worker::claim_for_this_process((int) $request_id);
            Task_Kill_Worker::carry_out((int) $request_id);
        }
    }

    public static function test_a_live_worker_past_its_own_timeout_is_killed()
    {
        $pid = static::__spawn_victim();
        try {
            $id = static::__running_row($pid, 60, 120);

            $output = static::__tick();

            $request = Task_Kill_Request_Model::where('task_id', $id)->first();
            static::__assert_not_null($request, 'the tick requested a kill');
            static::__assert_equals(Task_Kill_Request_Model::MODE_FORCE_KILL, (int) $request->mode_id);
            static::__assert_contains('cap 60s', $request->explanation);
            static::__assert_contains('[TASK TIMEOUT]', $output, 'the request is reported on one line');
            static::__assert_true(static::__running($pid), 'the tick itself kills nothing');

            static::__carry_out($id);

            static::__assert_false(static::__running($pid), 'the overrunning worker is dead');
            $after = Task_Run_Model::find($id);
            static::__assert_equals(Task_Run_Model::STATUS_KILLED, (int) $after->status_id);
            static::__assert_contains('timed out after', (string) $after->status_reason);
            static::__assert_contains('cap 60s', (string) $after->status_reason, 'the cap in force is recorded');
            static::__assert_not_null($after->completed_at);
        } finally {
            static::__reap($pid);
        }
    }

    public static function test_an_inline_run_past_the_cap_is_never_killed_for_time()
    {
        $pid = static::__spawn_victim();
        try {
            // An inline run: no pool identity, its own process (here a stand-in) far past a
            // 60-second cap.
            $id = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, array_merge(Task_Runner::running_fields(), [
                'worker_pid' => $pid,
                'timeout' => 60,
                'started_at' => date('Y-m-d H:i:s', time() - 3600) . '.000',
            ]));

            $output = static::__tick();

            static::__assert_equals(0, Task_Kill_Request_Model::where('task_id', $id)->count(), 'no kill requested for an inline run');
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) Task_Run_Model::find($id)->status_id);
            static::__assert_false(str_contains($output, '[TASK TIMEOUT]'), 'nothing is reported');
            static::__assert_true(static::__running($pid), 'its process is untouched');
        } finally {
            static::__reap($pid);
        }
    }

    public static function test_a_live_worker_within_its_timeout_is_untouched()
    {
        $pid = static::__spawn_victim();
        try {
            $id = static::__running_row($pid, 600, 10);

            $output = static::__tick();

            static::__assert_equals(0, Task_Kill_Request_Model::where('task_id', $id)->count(), 'no kill requested');
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) Task_Run_Model::find($id)->status_id);
            static::__assert_false(str_contains($output, '[TASK TIMEOUT]'), 'nothing is reported');
        } finally {
            static::__reap($pid);
        }
    }

    // -------------------------------------------------------------------------
    // The silence limit (no timeout on the run)
    // -------------------------------------------------------------------------

    /** A task that reports nothing is killed rsx.tasks.silent_timeout after it started. */
    public static function test_a_silent_run_is_killed_at_the_silence_limit()
    {
        $pid = static::__spawn_victim();
        try {
            static::__with_silent_timeout(30, function () use ($pid) {
                $id = static::__running_row($pid, null, 90);

                $output = static::__tick();

                $explanation = (string) Task_Kill_Request_Model::where('task_id', $id)->value('explanation');
                static::__assert_true((bool) preg_match('/^no report for (9\d|1\d\d)s \(cap 30s\)$/', $explanation), "the reason names the silence and the cap: {$explanation}");
                static::__assert_contains('[TASK TIMEOUT]', $output);

                static::__carry_out($id);

                $after = Task_Run_Model::find($id);
                static::__assert_equals(Task_Run_Model::STATUS_KILLED, (int) $after->status_id);
                static::__assert_contains('no report for', (string) $after->status_reason, 'the run records that it was silent');
                static::__assert_false(static::__running($pid), 'the silent worker is dead');
            });
        } finally {
            static::__reap($pid);
        }
    }

    /** A silent run inside the limit is left alone. */
    public static function test_a_silent_run_inside_the_limit_is_untouched()
    {
        $pid = static::__spawn_victim();
        try {
            static::__with_silent_timeout(30, function () use ($pid) {
                $id = static::__running_row($pid, null, 10);

                static::__tick();

                static::__assert_equals(0, Task_Kill_Request_Model::where('task_id', $id)->count());
            });
        } finally {
            static::__reap($pid);
        }
    }

    /**
     * A task that keeps reporting has no limit on how long it runs: a day in, a report five
     * seconds old - by heartbeat alone, or by any other report alone - and nothing is asked.
     */
    public static function test_a_reporting_run_outlives_the_limit()
    {
        $pid = static::__spawn_victim();
        try {
            static::__with_silent_timeout(30, function () use ($pid) {
                $by_report = static::__running_row($pid, null, 86400, ['last_report_at' => 5]);
                $by_heartbeat = static::__running_row($pid, null, 86400, ['last_heartbeat_at' => 5]);
                $stale_heartbeat_fresh_report = static::__running_row($pid, null, 86400, ['last_heartbeat_at' => 4000, 'last_report_at' => 5]);

                $output = static::__tick();

                foreach ([$by_report, $by_heartbeat, $stale_heartbeat_fresh_report] as $id) {
                    static::__assert_equals(0, Task_Kill_Request_Model::where('task_id', $id)->count(), 'a run that reported 5 s ago is not killed');
                    static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) Task_Run_Model::find($id)->status_id);
                }
                static::__assert_false(str_contains($output, '[TASK TIMEOUT]'), 'nothing is reported');
                static::__assert_true(static::__running($pid), 'the worker is untouched');

                DB::table('_tasks')->whereIn('id', [$by_report, $by_heartbeat, $stale_heartbeat_fresh_report])->delete();
            });
        } finally {
            static::__reap($pid);
        }
    }

    /** The limit runs from the LAST sign of life: a run that reported, then went silent, is killed. */
    public static function test_a_run_that_stopped_reporting_is_killed_from_its_last_report()
    {
        $pid = static::__spawn_victim();
        try {
            static::__with_silent_timeout(30, function () use ($pid) {
                $id = static::__running_row($pid, null, 86400, ['last_heartbeat_at' => 500, 'last_report_at' => 90]);

                static::__tick();

                $explanation = (string) Task_Kill_Request_Model::where('task_id', $id)->value('explanation');
                static::__assert_true((bool) preg_match('/^no report for (9\d|1\d\d)s \(cap 30s\)$/', $explanation), "measured from the latest sign of life, not from the start: {$explanation}");

                static::__carry_out($id);
            });
        } finally {
            static::__reap($pid);
        }
    }

    /** silent_timeout 0 is no silence limit: a silent run is never killed for time. */
    public static function test_no_silence_limit_leaves_a_silent_run_alone()
    {
        $pid = static::__spawn_victim();
        try {
            static::__with_silent_timeout(0, function () use ($pid) {
                $id = static::__running_row($pid, null, 86400);

                static::__tick();

                static::__assert_equals(0, Task_Kill_Request_Model::where('task_id', $id)->count());
                static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) Task_Run_Model::find($id)->status_id);

                DB::table('_tasks')->where('id', $id)->delete();
            });
        } finally {
            static::__reap($pid);
        }
    }

    /** A value that is not a whole number of seconds is a configuration error, said loudly. */
    public static function test_an_invalid_silence_limit_throws()
    {
        $pid = static::__spawn_victim();
        $id = null;
        try {
            static::__with_silent_timeout('soon', function () use ($pid, &$id) {
                $id = static::__running_row($pid, null, 10);

                $thrown = null;
                try {
                    static::__tick();
                } catch (\RuntimeException $e) {
                    $thrown = $e->getMessage();
                }

                static::__assert_contains('rsx.tasks.silent_timeout must be an integer >= 0', (string) $thrown);
            });
        } finally {
            static::__reap($pid);
            DB::table('_tasks')->where('id', $id ?? 0)->delete();
        }
    }

    // -------------------------------------------------------------------------
    // An explicit timeout is a hard limit
    // -------------------------------------------------------------------------

    /** A run dispatched with a timeout is killed at it even while it reports. */
    public static function test_an_explicit_timeout_is_hard_even_for_a_reporting_run()
    {
        $pid = static::__spawn_victim();
        try {
            $id = static::__running_row($pid, 60, 120, ['last_report_at' => 1, 'last_heartbeat_at' => 1]);

            static::__tick();

            static::__assert_contains('timed out after', (string) Task_Kill_Request_Model::where('task_id', $id)->value('explanation'), 'measured from the start, reports or not');

            static::__carry_out($id);
        } finally {
            static::__reap($pid);
        }
    }

    /** ...and is under that limit only: silent past the silence limit, inside its own, it runs on. */
    public static function test_an_explicit_timeout_replaces_the_silence_limit()
    {
        $pid = static::__spawn_victim();
        try {
            static::__with_silent_timeout(30, function () use ($pid) {
                $id = static::__running_row($pid, 7200, 600);

                static::__tick();

                static::__assert_equals(0, Task_Kill_Request_Model::where('task_id', $id)->count(), 'ten silent minutes into a two-hour limit');

                DB::table('_tasks')->where('id', $id)->delete();
            });
        } finally {
            static::__reap($pid);
        }
    }

    /** A run that already has a live kill request is left to it: the next tick asks again for nothing. */
    public static function test_a_second_tick_requests_no_second_kill()
    {
        $pid = static::__spawn_victim();
        try {
            $id = static::__running_row($pid, 60, 120);

            static::__tick();
            $output = static::__tick();

            static::__assert_equals(1, Task_Kill_Request_Model::where('task_id', $id)->count());
            static::__assert_false(str_contains($output, '[TASK TIMEOUT]'), 'the second tick reports nothing');
        } finally {
            static::__reap($pid);
            DB::table('_tasks')->where('id', $id ?? 0)->delete();
        }
    }
}
