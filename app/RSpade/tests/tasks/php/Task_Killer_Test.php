<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Killer;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Task_Killer force-kills a running task's worker (SIGTERM -> 5s -> SIGKILL) and settles the
 * _tasks row: an ON-DEMAND row goes terminal KILLED with the explanation; a CRON TRACKER row
 * (next_run_at set) recycles to PENDING so its schedule survives. Each test spawns a REAL,
 * killable child process and asserts it actually dies.
 */
class Task_Killer_Test extends Rsx_Test_Abstract
{
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

    public static function test_kills_on_demand_task_and_marks_killed()
    {
        $pid = self::__spawn_victim();
        try {
            static::__assert_true($pid > 0 && posix_kill($pid, 0), 'victim should be alive');

            $id = DB::table('_tasks')->insertGetId([
                'class' => 'Test_Killer_Service', 'method' => 'run', 'queue' => 'default',
                'status' => Task_Status::RUNNING, 'worker_pid' => $pid, 'next_run_at' => null,
                'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $row = DB::table('_tasks')->where('id', $id)->first();
            $outcome = Task_Killer::kill($row, 'unit test kill');

            static::__assert_equals('killed', $outcome);
            static::__assert_false((bool) posix_kill($pid, 0), 'victim process must be dead');

            $after = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::KILLED, $after->status);
            static::__assert_equals('unit test kill', $after->status_reason);
            static::__assert_true($after->worker_pid === null, 'worker_pid must be cleared');
        } finally {
            self::__reap($pid);
        }
    }

    public static function test_cron_tracker_recycles_to_pending()
    {
        $pid = self::__spawn_victim();
        try {
            $id = DB::table('_tasks')->insertGetId([
                'class' => 'Test_Cron_Service', 'method' => 'tick', 'queue' => 'default',
                'status' => Task_Status::RUNNING, 'worker_pid' => $pid,
                'next_run_at' => now()->addMinutes(5),
                'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            $row = DB::table('_tasks')->where('id', $id)->first();
            $outcome = Task_Killer::kill($row, 'quiesce');
            static::__assert_equals('recycled', $outcome);

            $after = DB::table('_tasks')->where('id', $id)->first();
            static::__assert_equals(Task_Status::PENDING, $after->status, 'cron tracker must recycle, not go terminal');
            static::__assert_true($after->next_run_at !== null, 'the recurring schedule must survive');
            static::__assert_true(str_contains((string) $after->status_reason, 'quiesce'));
        } finally {
            self::__reap($pid);
        }
    }

    public static function test_kill_all_requires_explanation()
    {
        $code = Artisan::call('rsx:tasks:kill-all');
        static::__assert_true($code !== 0, 'rsx:tasks:kill-all without --explanation must fail');
    }
}
