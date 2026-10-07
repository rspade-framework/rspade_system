<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Kill_Worker;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Force stops and force kills, carried out by a kill worker (Task_Kill_Worker).
 *
 * request() records a _task_kill_requests row bound to the HOST and PID running the target;
 * carry_out() waits until it is due, re-reading the run, then SIGKILLs the worker - on this
 * host only - and settles the run KILLED with the explanation and an operator line. A run
 * that ended first makes the request MOOT, and an inline run inside a web request (no pool
 * identity, not an artisan process) is never signalled.
 *
 * The victim is a REAL process: `sleep` started with proc_open() as this test's child, its
 * pid written onto a RUNNING row. A row carrying a worker_id is a pool worker's, so carry_out
 * signals it; the test then reaps the child. carry_out() is driven in this process after a
 * claim_for_this_process() - the path rsx:tasks:kill-all takes, and the one a kill worker
 * takes after its pool claim.
 *
 * Commits real rows, so this class provisions a clean baseline and opts out of transactions.
 */
class Task_Kill_Worker_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    const DEAD_PID = 2147480000;

    const OTHER_HOST = 'task-kill-worker-test.other-host.invalid';

    /** @var array<int, resource> victim pid => proc_open handle */
    private static array $victims = [];

    public static function setup()
    {
        Session::logout();
    }

    public static function teardown()
    {
        foreach (array_keys(self::$victims) as $pid) {
            static::__reap($pid);
        }
    }

    /**
     * Start a `sleep` child and return its pid once it is running AS sleep: until the child
     * has exec'd, its command line is not yet the one the kill worker reads. A bounded poll on
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

    /** Kill (if still running) and reap a victim. */
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

    /** Running, by its /proc entry (a zombie has an empty command line and is not). */
    private static function __running(int $pid): bool
    {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');

        return $cmdline !== false && $cmdline !== '';
    }

    /** A RUNNING row whose worker is $pid on $host; a worker_id makes it a pool worker's. */
    private static function __running_row(int $pid, ?int $worker_id = 41, ?string $host = null, array $fields = []): Task_Run_Model
    {
        $id = Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, array_merge(Task_Runner::running_fields(), [
            'worker_pid' => $pid,
            'worker_id' => $worker_id,
            'worker_generation' => $worker_id === null ? null : 7,
            'pool_id' => $worker_id === null ? null : Task_Run_Model::POOL_ON_DEMAND,
            'worker_host' => $host ?? Task_Pool::host(),
        ], $fields));

        return Task_Run_Model::find($id);
    }

    private static function __operator_lines(int $task_id): array
    {
        return array_column(Task_Run_Model::find($task_id)->output_after(null, ['operator']), 'line');
    }

    /** Claim a request for this process and carry it out, as rsx:tasks:kill-all does. */
    private static function __carry_out(Task_Kill_Request_Model $request): string
    {
        static::__assert_true(Task_Kill_Worker::claim_for_this_process((int) $request->id), 'the request was claimable');

        return Task_Kill_Worker::carry_out((int) $request->id);
    }

    // -------------------------------------------------------------------------
    // The request
    // -------------------------------------------------------------------------

    public static function test_a_request_binds_the_host_and_pid_running_the_target()
    {
        $run = static::__running_row(static::DEAD_PID);
        $before = time();

        $request = Task_Kill_Worker::request($run, Task_Kill_Request_Model::MODE_FORCE_STOP, 45, 'the reason', 'a tester');

        static::__assert_not_null($request);
        static::__assert_equals((int) $run->id, (int) $request->task_id);
        static::__assert_equals(Task_Pool::host(), $request->host);
        static::__assert_equals(static::DEAD_PID, (int) $request->target_pid);
        static::__assert_equals(Task_Kill_Request_Model::MODE_FORCE_STOP, (int) $request->mode_id);
        static::__assert_equals(Task_Kill_Request_Model::STATUS_PENDING, (int) $request->status_id);
        static::__assert_equals('the reason', $request->explanation);
        $due = strtotime($request->kill_after_at);
        static::__assert_true($due >= $before + 45 && $due <= time() + 45, 'due after the grace: ' . $request->kill_after_at);
        static::__assert_true(Task_Kill_Worker::has_due_requests(Task_Pool::host()), 'this host has a request for a kill worker');

        static::__assert_null(
            Task_Kill_Worker::request(Task_Run_Model::find($run->id), Task_Kill_Request_Model::MODE_FORCE_STOP, 45, 'again', 'a tester'),
            'one live request per run and mode'
        );

        $elsewhere = Task_Kill_Worker::request(static::__running_row(static::DEAD_PID, 42, self::OTHER_HOST), Task_Kill_Request_Model::MODE_FORCE_KILL, 0, 'x', 'a tester');
        static::__assert_equals(self::OTHER_HOST, $elsewhere->host, 'a pid names a process on one host: the request goes to that host');
    }

    public static function test_only_a_running_run_with_a_worker_can_be_requested()
    {
        $pending = Task_Run_Model::find(Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED));
        static::__assert_null(Task_Kill_Worker::request($pending, Task_Kill_Request_Model::MODE_FORCE_KILL, 0, 'x', 'a tester'), 'a pending run');

        $no_pid = static::__running_row(static::DEAD_PID);
        DB::table('_tasks')->where('id', $no_pid->id)->update(['worker_pid' => null]);
        static::__assert_null(Task_Kill_Worker::request(Task_Run_Model::find($no_pid->id), Task_Kill_Request_Model::MODE_FORCE_KILL, 0, 'x', 'a tester'), 'a run with no worker pid');
    }

    // -------------------------------------------------------------------------
    // Carrying it out
    // -------------------------------------------------------------------------

    /**
     * A force kill of a pool worker's run: the process is SIGKILLed and gone, the run settles
     * KILLED with the explanation, and both the request and the kill are operator lines.
     */
    public static function test_a_force_kill_signals_the_worker_and_settles_the_run_killed()
    {
        $pid = static::__spawn_victim();
        try {
            static::__assert_true(static::__running($pid), 'fixture: the victim is running');
            $run = static::__running_row($pid);

            static::__assert_true($run->force_kill('runaway'));
            $request = Task_Kill_Request_Model::where('task_id', $run->id)->first();

            static::__assert_equals('killed', substr(static::__carry_out($request), -6), 'carried out');
            static::__assert_false(static::__running($pid), 'the worker process is gone');

            $after = Task_Run_Model::find($run->id);
            static::__assert_equals(Task_Run_Model::STATUS_KILLED, (int) $after->status_id);
            static::__assert_equals('force kill by the command line: runaway', $after->status_reason);
            static::__assert_not_null($after->completed_at);
            static::__assert_equals(
                ['Force kill requested by the command line: runaway', 'Killed (Force kill): force kill by the command line: runaway'],
                static::__operator_lines($run->id)
            );

            $request = Task_Kill_Request_Model::find($request->id);
            static::__assert_equals(Task_Kill_Request_Model::STATUS_DONE, (int) $request->status_id);
            static::__assert_equals('killed', $request->outcome);
            static::__assert_not_null($request->completed_at);
        } finally {
            static::__reap($pid);
        }
    }

    public static function test_a_request_for_a_run_that_ended_is_moot()
    {
        $pid = static::__spawn_victim();
        try {
            $run = static::__running_row($pid);
            $request = Task_Kill_Worker::request($run, Task_Kill_Request_Model::MODE_FORCE_STOP, 3600, 'grace', 'a tester');

            // The task stopped on its own during the grace period.
            DB::table('_tasks')->where('id', $run->id)->update(['status_id' => Task_Run_Model::STATUS_STOPPED, 'completed_at' => now()]);

            static::__assert_contains('the run ended before it was due', static::__carry_out($request));
            static::__assert_true(static::__running($pid), 'nothing was signalled');
            static::__assert_equals(Task_Run_Model::STATUS_STOPPED, (int) Task_Run_Model::find($run->id)->status_id, 'the run keeps its own verdict');
            static::__assert_equals(Task_Kill_Request_Model::STATUS_MOOT, (int) Task_Kill_Request_Model::find($request->id)->status_id);
        } finally {
            static::__reap($pid);
        }
    }

    public static function test_a_request_whose_worker_changed_is_moot()
    {
        $pid = static::__spawn_victim();
        try {
            $run = static::__running_row(static::DEAD_PID);
            $request = Task_Kill_Worker::request($run, Task_Kill_Request_Model::MODE_FORCE_KILL, 0, 'x', 'a tester');

            // The run was abandoned and retried under another worker before the kill.
            DB::table('_tasks')->where('id', $run->id)->update(['worker_pid' => $pid]);

            static::__assert_contains('the run ended before it was due', static::__carry_out($request));
            static::__assert_true(static::__running($pid), 'the new worker is never signalled');
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) Task_Run_Model::find($run->id)->status_id);
        } finally {
            static::__reap($pid);
        }
    }

    /**
     * An inline run with no pool identity whose pid is not an artisan process is a web
     * request's php-fpm worker: it serves other requests and is never signalled.
     */
    public static function test_an_inline_run_inside_a_web_request_is_never_signalled()
    {
        $pid = static::__spawn_victim();
        try {
            $run = static::__running_row($pid, null);
            $request = Task_Kill_Worker::request($run, Task_Kill_Request_Model::MODE_FORCE_KILL, 0, 'x', 'a tester');

            static::__assert_contains('inline run inside a web request; not signalled', static::__carry_out($request));
            static::__assert_true(static::__running($pid), 'the process was not signalled');
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) Task_Run_Model::find($run->id)->status_id);
            static::__assert_contains('Only a graceful stop applies', implode("\n", static::__operator_lines($run->id)));
        } finally {
            static::__reap($pid);
        }
    }

    public static function test_a_worker_already_gone_is_settled_killed_without_a_signal()
    {
        $run = static::__running_row(static::DEAD_PID);
        $request = Task_Kill_Worker::request($run, Task_Kill_Request_Model::MODE_FORCE_KILL, 0, 'gone', 'a tester');

        static::__assert_contains('killed_no_process', static::__carry_out($request));
        static::__assert_equals(Task_Run_Model::STATUS_KILLED, (int) Task_Run_Model::find($run->id)->status_id);
        static::__assert_contains('the worker process was already gone', implode("\n", static::__operator_lines($run->id)));
    }

    /** A killed scheduled run is a failed run of its schedule. */
    public static function test_a_killed_scheduled_run_counts_on_its_schedule()
    {
        $schedule_id = DB::table('_task_schedules')->insertGetId([
            'class' => Task_Exec_Fixture_Service::class,
            'method' => 'marker_a',
            'cron_expression' => 'daily at 3am',
            'next_run_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $run = static::__running_row(static::DEAD_PID, 43, null, ['schedule_id' => $schedule_id, 'origin_id' => Task_Run_Model::ORIGIN_SCHEDULED]);

        static::__carry_out(Task_Kill_Worker::request($run, Task_Kill_Request_Model::MODE_FORCE_KILL, 0, 'too slow', 'a tester'));

        $schedule = DB::table('_task_schedules')->where('id', $schedule_id)->first();
        static::__assert_equals(1, (int) $schedule->consecutive_failures);
        static::__assert_equals('killed: too slow', $schedule->last_error);
        static::__assert_not_null($schedule->last_error_at);
        static::__assert_equals((int) $run->id, (int) $schedule->last_task_id);

        DB::table('_tasks')->where('schedule_id', $schedule_id)->delete();
        DB::table('_task_schedules')->where('id', $schedule_id)->delete();
    }

    // -------------------------------------------------------------------------
    // rsx:tasks:kill-all
    // -------------------------------------------------------------------------

    public static function test_kill_all_requires_an_explanation()
    {
        static::__assert_true(Artisan::call('rsx:tasks:kill-all') !== 0, 'rsx:tasks:kill-all without --explanation must fail');
    }

    /**
     * kill-all settles this host's runs KILLED before it returns, and leaves another host's run
     * with a pending request for that host's kill workers.
     */
    public static function test_kill_all_kills_this_hosts_runs_synchronously()
    {
        DB::table('_tasks')->where('status_id', Task_Run_Model::STATUS_RUNNING)->delete();

        $pid = static::__spawn_victim();
        try {
            $local = static::__running_row($pid);
            $remote = static::__running_row(static::DEAD_PID, 44, self::OTHER_HOST);

            static::__assert_equals(0, Artisan::call('rsx:tasks:kill-all', ['--explanation' => 'quiesce']));
            $output = Artisan::output();

            static::__assert_false(static::__running($pid), 'this host\'s worker is dead');
            static::__assert_equals(Task_Run_Model::STATUS_KILLED, (int) Task_Run_Model::find($local->id)->status_id);
            static::__assert_equals('quiesce', Task_Run_Model::find($local->id)->status_reason);

            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) Task_Run_Model::find($remote->id)->status_id, 'another host\'s run is left to that host');
            static::__assert_equals(Task_Kill_Request_Model::STATUS_PENDING, (int) Task_Kill_Request_Model::where('task_id', $remote->id)->value('status_id'));
            static::__assert_contains('queued for host ' . self::OTHER_HOST, $output);
        } finally {
            static::__reap($pid);
            DB::table('_tasks')->where('status_id', Task_Run_Model::STATUS_RUNNING)->delete();
        }
    }
}
