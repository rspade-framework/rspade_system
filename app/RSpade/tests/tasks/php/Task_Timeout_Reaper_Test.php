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

/**
 * The timeout arm of the rsx:task:process reaper
 * (App\RSpade\Commands\Rsx\Task_Process_Command::enforce_task_timeout()).
 *
 * Contract: a RUNNING run whose worker is STILL ALIVE on this host past its cap - the run's
 * own timeout, else rsx.tasks.default_timeout - gets a FORCE KILL request, carried out by a
 * kill worker exactly as an operator's force kill is: the worker is SIGKILLed and the run
 * settles KILLED naming the cap. With neither cap the run is unbounded. The abandoned-worker
 * arm is Task_Worker_Execution_Test; the kill itself is Task_Kill_Worker_Test.
 *
 * The worker is a REAL process - `sleep`, this test's child - on a run carrying a pool
 * identity of an older rsx-lockd generation, which the reaper judges by the pid on this host:
 * alive, so the timeout is what decides. Under the suite no kill worker is spawned, so the
 * test carries the request out itself after the tick.
 *
 * Commits real rows, so this class provisions a clean baseline and opts out of transactions.
 */
class Task_Timeout_Reaper_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    /** @var array<int, resource> victim pid => proc_open handle */
    private static array $victims = [];

    public static function teardown()
    {
        foreach (array_keys(self::$victims) as $pid) {
            static::__reap($pid);
        }
    }

    /**
     * Start a `sleep` child and return its pid once it is running AS sleep. A bounded poll on
     * a process this test does not schedule (the house bound, tests/CLAUDE.md).
     */
    private static function __spawn_victim(): int
    {
        $process = proc_open(['sleep', '300'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $pid = (int) proc_get_status($process)['pid'];
        self::$victims[$pid] = $process;

        for ($poll = 0; $poll < 1200; $poll++) {
            if (str_starts_with((string) @file_get_contents('/proc/' . $pid . '/cmdline'), "sleep\0")) {
                return $pid;
            }
            usleep(100000);
        }

        throw new \RuntimeException("the victim process {$pid} never started running sleep");
    }

    private static function __reap(int $pid): void
    {
        if (!isset(self::$victims[$pid])) {
            return;
        }
        if (proc_get_status(self::$victims[$pid])['running']) {
            posix_kill($pid, SIGKILL);
        }
        proc_close(self::$victims[$pid]);
        unset(self::$victims[$pid]);
    }

    private static function __running(int $pid): bool
    {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');

        return $cmdline !== false && $cmdline !== '';
    }

    /** A RUNNING run of a live worker on this host, started $seconds_ago, with $timeout. */
    private static function __running_row(int $pid, ?int $timeout, int $seconds_ago): int
    {
        $current = Task_Pool::stats(Task_Pool::ON_DEMAND)['generation'];

        return Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, array_merge(Task_Runner::running_fields(), [
            'worker_pid' => $pid,
            'worker_id' => 61,
            'worker_generation' => $current === 1 ? 2 : $current - 1,
            'pool_id' => Task_Run_Model::POOL_ON_DEMAND,
            'timeout' => $timeout,
            'started_at' => date('Y-m-d H:i:s', time() - $seconds_ago) . '.000',
        ]));
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

    public static function test_a_run_without_a_timeout_uses_the_config_default()
    {
        $original = config('rsx.tasks.default_timeout');
        $pid = static::__spawn_victim();

        try {
            config(['rsx.tasks.default_timeout' => 30]);
            $id = static::__running_row($pid, null, 90);

            static::__tick();

            static::__assert_contains('cap 30s', (string) Task_Kill_Request_Model::where('task_id', $id)->value('explanation'), 'the configured default caps an untimed run');
        } finally {
            config(['rsx.tasks.default_timeout' => $original]);
            static::__reap($pid);
        }
    }

    /**
     * No run timeout and no configured default means no cap exists - the task runs unbounded
     * rather than being killed on an invented one.
     */
    public static function test_no_cap_anywhere_leaves_the_run_alone()
    {
        $original = config('rsx.tasks.default_timeout');
        $pid = static::__spawn_victim();

        try {
            config(['rsx.tasks.default_timeout' => 0]);
            $id = static::__running_row($pid, null, 86400);

            static::__tick();

            static::__assert_equals(0, Task_Kill_Request_Model::where('task_id', $id)->count());
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) Task_Run_Model::find($id)->status_id);
        } finally {
            config(['rsx.tasks.default_timeout' => $original]);
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
