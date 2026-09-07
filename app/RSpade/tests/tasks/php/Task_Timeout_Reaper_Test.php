<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The timeout arm of the rsx:task:process reaper
 * (App\RSpade\Commands\Rsx\Task_Process_Command::detect_stuck_tasks() ->
 * enforce_task_timeout()).
 *
 * Contract: a RUNNING row whose worker is STILL ALIVE past its cap - the row's own
 * _tasks.timeout, else rsx.tasks.default_timeout - is killed through Task_Killer (SIGTERM ->
 * 5s -> SIGKILL) and settled exactly as rsx:tasks:kill settles it: KILLED for an on-demand
 * row, recycled to PENDING for a cron tracker. With neither cap defined the task runs
 * unbounded. The dead-worker arm (cleanup_stuck_after) is unchanged and covered elsewhere.
 *
 * Each test spawns a REAL killable child as the worker so the liveness probe sees a live
 * process that is NOT this test runner. The tick is driven with Artisan::call (no --once,
 * which would drain a task); the fixture rows are RUNNING, so has_pending_work() stays false.
 *
 * This class COMMITS rows via a real console command, so it declares a DB reset and no
 * per-test transactions (same pattern as Task_Schedule_Reconcile_Test).
 */
class Task_Timeout_Reaper_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    /**
     * Spawn a detached, killable victim and return its PID. It is backgrounded inside a subshell
     * that exits, so it reparents to init - a kill is reaped there and never leaves a zombie for
     * this test process (which lets a plain posix_kill($pid,0) confirm the death).
     */
    private static function __spawn_victim(): int
    {
        return (int) trim((string) shell_exec('bash -c ' . escapeshellarg('sleep 60 >/dev/null 2>&1 & echo $!')));
    }

    private static function __reap(int $pid): void
    {
        if ($pid > 0 && @posix_kill($pid, 0)) {
            @posix_kill($pid, 9);
        }
    }

    /**
     * Insert a RUNNING _tasks row owned by $pid. $overrides may set timeout, next_run_at and
     * started_at_seconds_ago (how long the task has already been running).
     */
    private static function __insert_running(int $pid, array $overrides = []): int
    {
        return DB::table('_tasks')->insertGetId([
            'class'         => $overrides['class'] ?? 'Test_Timeout_Service',
            'method'        => 'run',
            'queue'         => 'default',
            'status'        => Task_Status::RUNNING,
            'params'        => json_encode([]),
            'worker_pid'    => $pid,
            'timeout'       => $overrides['timeout'] ?? null,
            'next_run_at'   => $overrides['next_run_at'] ?? null,
            'started_at'    => now()->subSeconds($overrides['started_at_seconds_ago'] ?? 0),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private static function __run_tick(): string
    {
        Artisan::call('rsx:task:process');
        return Artisan::output();
    }

    public static function test_live_worker_past_its_own_timeout_is_killed()
    {
        $pid = self::__spawn_victim();
        try {
            static::__assert_true($pid > 0 && posix_kill($pid, 0), 'victim should be alive');

            $id = self::__insert_running($pid, ['timeout' => 60, 'started_at_seconds_ago' => 120]);

            $output = self::__run_tick();

            static::__assert_false((bool) posix_kill($pid, 0), 'the overrunning worker must be dead');

            $after = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::KILLED, $after->status, 'an on-demand row goes terminal KILLED');
            static::__assert_contains('timed out', (string) $after->status_reason);
            static::__assert_contains('cap 60s', (string) $after->status_reason, 'the cap in force is recorded');
            static::__assert_true($after->worker_pid === null, 'worker_pid must be cleared');
            static::__assert_not_null($after->completed_at, 'a terminal row records completed_at');
            static::__assert_contains('[TASK TIMEOUT]', $output, 'the kill is reported on one line');
        } finally {
            self::__reap($pid);
        }
    }

    public static function test_live_worker_within_its_timeout_is_untouched()
    {
        $pid = self::__spawn_victim();
        try {
            $id = self::__insert_running($pid, ['timeout' => 600, 'started_at_seconds_ago' => 10]);

            $output = self::__run_tick();

            static::__assert_true((bool) posix_kill($pid, 0), 'a worker inside its cap must survive');

            $after = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::RUNNING, $after->status, 'the row keeps running');
            static::__assert_null($after->status_reason);
            static::__assert_equals($pid, (int) $after->worker_pid);
            static::__assert_false(str_contains($output, '[TASK TIMEOUT]'), 'nothing is reported');
        } finally {
            self::__reap($pid);
        }
    }

    /**
     * A row with no timeout of its own inherits rsx.tasks.default_timeout.
     */
    public static function test_row_without_a_timeout_uses_the_config_default()
    {
        $original = config('rsx.tasks.default_timeout');
        $pid = self::__spawn_victim();

        try {
            config(['rsx.tasks.default_timeout' => 30]);

            $id = self::__insert_running($pid, ['timeout' => null, 'started_at_seconds_ago' => 90]);

            self::__run_tick();

            static::__assert_false((bool) posix_kill($pid, 0), 'the configured default caps an untimed row');

            $after = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::KILLED, $after->status);
            static::__assert_contains('cap 30s', (string) $after->status_reason);
        } finally {
            config(['rsx.tasks.default_timeout' => $original]);
            self::__reap($pid);
        }
    }

    /**
     * No row timeout and no configured default means no cap exists - the task runs unbounded
     * rather than being killed on an invented one.
     */
    public static function test_no_cap_anywhere_leaves_the_task_running()
    {
        $original = config('rsx.tasks.default_timeout');
        $pid = self::__spawn_victim();

        try {
            config(['rsx.tasks.default_timeout' => 0]);

            $id = self::__insert_running($pid, ['timeout' => null, 'started_at_seconds_ago' => 86400]);

            self::__run_tick();

            static::__assert_true((bool) posix_kill($pid, 0), 'an uncapped task is never timeout-killed');

            $after = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::RUNNING, $after->status);
        } finally {
            config(['rsx.tasks.default_timeout' => $original]);
            self::__reap($pid);
        }
    }

    /**
     * A recurring tracker must never go terminal on a timeout - that would silently stop the
     * schedule. Task_Killer recycles it to PENDING with the explanation recorded.
     *
     * The tracker borrows a REAL scheduled definition (class, method and cron_expression) from
     * the manifest: the same tick reconciles schedules after reaping, and would delete a tracker
     * whose class::method it does not recognize.
     */
    public static function test_timed_out_cron_tracker_recycles_to_pending()
    {
        $defs = \App\RSpade\Core\Task\Task::get_scheduled_tasks();
        if (empty($defs)) {
            static::__skip('no #[Schedule] task in the manifest to borrow a tracker identity from');
            return;
        }
        $def = $defs[0];

        $pid = self::__spawn_victim();
        try {
            $id = DB::table('_tasks')->insertGetId([
                'class'           => $def['class'],
                'method'          => $def['method'],
                'queue'           => $def['queue'],
                'status'          => Task_Status::RUNNING,
                'params'          => json_encode([]),
                'worker_pid'      => $pid,
                'timeout'         => 60,
                'next_run_at'     => now()->addMinutes(30),
                'cron_expression' => $def['cron_expression'],
                'started_at'      => now()->subSeconds(300),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            self::__run_tick();

            static::__assert_false((bool) posix_kill($pid, 0), 'the overrunning worker must be dead');

            $after = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::PENDING, $after->status, 'a tracker recycles, never goes terminal');
            static::__assert_not_null($after->next_run_at, 'the recurring schedule must survive');
            static::__assert_contains('timed out', (string) $after->status_reason);
        } finally {
            self::__reap($pid);
        }
    }
}
