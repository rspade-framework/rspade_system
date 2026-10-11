<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Health_Checks;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Detached_Processes;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;
use App\RSpade\Tests\Tasks\Php\Task_Worker_Victim;

// @ARTISAN-SPAWN-01-EXCEPTION - these tests need an artisan command running in ANOTHER
// process (a task being stopped, an inline caller waiting for the switch) while this one
// acts, pointed at the test database. Rsx_Artisan's
// synchronous forms wait for their child and its detached form takes no environment, so the
// child is opened here with proc_open. This process holds no lock at that point, so there is
// nothing for the child to inherit.

/**
 * The task system's switch (Task::is_enabled() / disabled_state() / disable() / enable(),
 * `php artisan rsx:tasks:disable` / `rsx:tasks:enable`).
 *
 * OFF means: a worker claims nothing and leaves (one that is busy leaves after the run it
 * holds); nothing starts a task worker - not dispatch(), not the tick - while a kill worker is
 * still started and the tick still settles dead runs; dispatch() still enqueues; no schedule
 * produces a run, and each overdue one runs once after the switch is back on. An inline run
 * (Task::internal(), rsx:task:run, a #[Command]) is queued PENDING while its caller waits;
 * once the system is back on the waiter claims and runs it - or a worker does, never both -
 * and a waiter that died leaves an ordinary pending run.
 *
 * rsx:tasks:disable throws the switch first and then force-stops what is running: a task that
 * checks for a stop ends on its own inside the grace, one that does not is killed after it,
 * another host's run keeps its kill request for that host, an inline run inside a web request
 * is asked to stop, never signalled, listed, and makes the exit code 2, and pending runs are
 * left queued.
 *
 * Commits real rows (a second process reads them), so this class provisions a clean baseline
 * and opts out of transactions. Every test leaves the switch on.
 */
class Task_System_Switch_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    const FIX = Task_Exec_Fixture_Service::class;

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    const DEAD_PID = 2147480000;

    const OTHER_HOST = 'task-system-switch-test.other-host.invalid';

    /** 100 ms polls: 120 s, the house bound (tests/CLAUDE.md). */
    const POLLS = 1200;

    /** @var array<int, resource> rsx:task:run children: pid => proc_open handle */
    private static array $children = [];

    public static function teardown()
    {
        Task::enable();
        Task::spawn_workers(false);
        Task_Worker_Victim::reap_all();

        foreach (self::$children as $pid => $process) {
            if (proc_get_status($process)['running']) {
                posix_kill($pid, SIGKILL);
            }
            proc_close($process);
        }
        self::$children = [];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** The switch on, no live run, no fixture run, no schedule. */
    private static function __reset(): void
    {
        Task::enable();
        DB::table('_tasks')->whereIn('status_id', Task_Run_Model::LIVE_STATUSES)->delete();
        DB::table('_tasks')->where('class', self::FIX)->delete();
        DB::table('_task_schedules')->delete();
        Task_Exec_Fixture_Service::$run_order = [];
    }

    private static function __pending(string $method, array $fields = []): int
    {
        return Task_Runner::insert_row(self::FIX, $method, [], Task_Run_Model::ORIGIN_DISPATCHED, $fields);
    }

    /** A RUNNING run of a pool worker with $pid, on $host (null = this host). */
    private static function __running_row(int $pid, int $wid, ?string $host = null): int
    {
        return Task_Runner::insert_row(self::FIX, 'marker_a', [], Task_Run_Model::ORIGIN_DISPATCHED, array_merge(Task_Runner::running_fields(), [
            'worker_pid' => $pid,
            'worker_id' => $wid,
            'worker_generation' => 7,
            'pool_id' => Task_Run_Model::POOL_ON_DEMAND,
            'worker_host' => $host ?? Task_Pool::host(),
        ]));
    }

    private static function __status(int $id): int
    {
        return (int) DB::table('_tasks')->where('id', $id)->value('status_id');
    }

    private static function __detached_registry(): string
    {
        $path = Rsx_Project_Paths::test_detached_registry_file();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Start an artisan command in another process on the test database, stderr to a file.
     *
     * @param array<int, string> $argv Command name followed by its arguments
     * @return array{pid: int, stderr_file: string}
     */
    private static function __start_child(array $argv): array
    {
        $stderr_file = Rsx_Project_Paths::scratch_file('task_switch_child', 'err');
        $command = array_merge([PHP_BINARY, base_path('artisan'), Rsx_Test_Abstract::TEST_RUN_FLAG], $argv);
        $environment = array_merge(getenv(), ['DB_DATABASE' => (string) config('database.connections.test.database')]);

        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr_file, 'w']],
            $pipes,
            null,
            $environment
        );
        $pid = (int) proc_get_status($process)['pid'];
        self::$children[$pid] = $process;

        return ['pid' => $pid, 'stderr_file' => $stderr_file];
    }

    /** Wait for a child to exit - it ends once its task has - and return its exit code. */
    private static function __wait_child(array $child): int
    {
        $code = proc_close(self::$children[$child['pid']]);
        unset(self::$children[$child['pid']]);

        return $code;
    }

    /**
     * The id of the fixture's run of $method once it is in $status_id. A bounded poll on a
     * process this test does not schedule.
     */
    private static function __await_run(string $method, int $status_id): int
    {
        for ($poll = 0; $poll < self::POLLS; $poll++) {
            $id = DB::table('_tasks')->where('method', $method)->where('status_id', $status_id)->orderByDesc('id')->value('id');
            if ($id !== null) {
                return (int) $id;
            }
            usleep(100000);
        }

        throw new \RuntimeException("no run of {$method} ever reached status {$status_id}");
    }

    /** Start `rsx:task:run <fixture> runs_until_stopped` in a child; its run's id once RUNNING. */
    private static function __start_cooperative_run(): int
    {
        static::__start_child(['rsx:task:run', 'Task_Exec_Fixture_Service', 'runs_until_stopped']);

        return static::__await_run('runs_until_stopped', Task_Run_Model::STATUS_RUNNING);
    }

    // -------------------------------------------------------------------------
    // The switch and its record
    // -------------------------------------------------------------------------

    public static function test_the_switch_round_trips_and_records_who_threw_it()
    {
        static::__reset();

        static::__assert_true(Task::is_enabled(), 'on by default');
        static::__assert_null(Task::disabled_state());

        $before = time();
        static::__assert_true(Task::disable('replacing the server'), 'this call turned it off');

        static::__assert_false(Task::is_enabled());
        $state = Task::disabled_state();
        static::__assert_equals('replacing the server', $state['reason']);
        static::__assert_equals('the command line', $state['disabled_by']);
        static::__assert_equals(Task_Pool::host(), $state['host']);
        $at = strtotime($state['disabled_at']);
        static::__assert_true($at >= $before - 1 && $at <= time() + 1, 'disabled_at is now: ' . $state['disabled_at']);

        static::__assert_false(Task::disable('a second reason'), 'already off');
        static::__assert_equals($state, Task::disabled_state(), 'the first record is kept');

        static::__assert_true(Task::enable(), 'this call turned it on');
        static::__assert_true(Task::is_enabled());
        static::__assert_null(Task::disabled_state());
        static::__assert_false(Task::enable(), 'already on');

        Task::disable('   ');
        static::__assert_null(Task::disabled_state()['reason'], 'a blank reason is no reason');
        Task::enable();
    }

    public static function test_the_commands_are_idempotent_and_say_what_they_found()
    {
        static::__reset();

        static::__assert_equals(0, Artisan::call('rsx:tasks:enable'));
        static::__assert_contains('already on', Artisan::output());

        static::__assert_equals(0, Artisan::call('rsx:tasks:disable', ['--reason' => 'first']));
        $output = Artisan::output();
        static::__assert_contains('The task system is OFF', $output);
        static::__assert_contains('No running tasks.', $output);

        static::__assert_equals(0, Artisan::call('rsx:tasks:disable', ['--reason' => 'second']));
        static::__assert_contains('already off', Artisan::output());
        static::__assert_equals('first', Task::disabled_state()['reason'], 'the original record stands');

        static::__assert_true(Artisan::call('rsx:tasks:disable', ['--grace' => 'soon']) !== 0, '--grace must be whole seconds');

        Artisan::call('rsx:tasks:list');
        $listing = Artisan::output();
        static::__assert_contains('Task system: DISABLED since', $listing);
        static::__assert_contains('(first)', $listing);
        static::__assert_contains('php artisan rsx:tasks:enable', $listing);

        static::__assert_equals(0, Artisan::call('rsx:tasks:enable'));
        static::__assert_contains('The task system is ON', Artisan::output());

        Artisan::call('rsx:tasks:list');
        static::__assert_contains('Task system: enabled', Artisan::output());
    }

    // -------------------------------------------------------------------------
    // What OFF means
    // -------------------------------------------------------------------------

    /** A worker started while the system is off claims nothing, in either pool. */
    public static function test_a_worker_claims_nothing_while_off()
    {
        static::__reset();
        $id = static::__pending('marker_a');
        Task::disable('test');

        foreach ([Task_Pool::ON_DEMAND, Task_Pool::SCHEDULED] as $pool) {
            static::__assert_equals(0, Artisan::call('rsx:task:worker', ['--pool' => $pool]));
            static::__assert_contains('The task system is disabled', Artisan::output());
            static::__assert_equals(0, Task_Pool::stats($pool)['members'], "the worker is not left in the {$pool} pool");
        }

        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($id), 'the run waits');
        static::__assert_equals([], Task_Exec_Fixture_Service::$run_order);

        Task::enable();
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($id), 'and runs once the system is back on');

        static::__reset();
    }

    /**
     * The switch thrown while a worker runs a task: the run it holds finishes, and the worker
     * reads the switch before its next claim and leaves.
     */
    public static function test_a_busy_worker_finishes_its_run_and_leaves()
    {
        static::__reset();
        $first = static::__pending('disables_task_system', ['scheduled_for' => date('Y-m-d H:i:s', time() - 60) . '.000']);
        $second = static::__pending('marker_a');

        static::__assert_equals(0, Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]));
        $output = Artisan::output();

        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, static::__status($first), 'the run in hand completes');
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($second), 'the next run is not claimed');
        static::__assert_equals(['OFF'], Task_Exec_Fixture_Service::$run_order);
        static::__assert_contains('The task system is disabled', $output);
        static::__assert_equals(0, Task_Pool::stats(Task_Pool::ON_DEMAND)['members'], 'the worker left its pool');

        static::__reset();
    }

    /** dispatch() writes the row and starts no worker; a kill worker is not held back. */
    public static function test_dispatch_enqueues_and_starts_no_task_worker()
    {
        static::__reset();
        $registry_before = static::__detached_registry();

        Task::disable('test');
        Task::spawn_workers(true);
        try {
            $id = Task::dispatch('Task_Exec_Fixture_Service', 'marker_a');

            static::__assert_equals(false, Task::spawn_worker(Task_Pool::ON_DEMAND), 'no on_demand worker');
            static::__assert_equals(false, Task::spawn_worker(Task_Pool::SCHEDULED), 'no scheduled worker');
            static::__assert_equals($registry_before, static::__detached_registry(), 'no process was started');

            static::__assert_equals(true, Task::spawn_worker(Task_Pool::KILL), 'the kill pool is not switched off');
        } finally {
            Task::spawn_workers(false);
            Rsx_Test_Detached_Processes::contain();
        }

        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($id), 'the run is queued');

        static::__reset();
    }

    /**
     * A tick while the system is off still settles a dead run and still starts a kill worker
     * for a due kill request, and starts no task worker for the due work it sees.
     */
    public static function test_the_tick_recovers_and_starts_no_task_worker()
    {
        static::__reset();

        // A run whose worker left the pool of the daemon's own generation: abandoned.
        Task_Pool::lock(Task_Pool::ON_DEMAND);
        try {
            $departed = Task_Pool::join(Task_Pool::ON_DEMAND);
            Task_Pool::leave(Task_Pool::ON_DEMAND);
        } finally {
            Task_Pool::unlock(Task_Pool::ON_DEMAND);
        }
        $dead = static::__pending('marker_a');
        DB::table('_tasks')->where('id', $dead)->update(array_merge(Task_Runner::running_fields(), [
            'worker_pid' => self::DEAD_PID,
            'worker_id' => $departed['wid'],
            'worker_generation' => $departed['generation'],
            'pool_id' => Task_Run_Model::POOL_ON_DEMAND,
        ]));

        $due = static::__pending('marker_b');

        Task::disable('test');
        Task::spawn_workers(true);
        try {
            static::__assert_equals(0, Artisan::call('rsx:task:process'));
            $output = Artisan::output();
        } finally {
            Task::spawn_workers(false);
            Rsx_Test_Detached_Processes::contain();
        }

        static::__assert_contains('[ABANDONED TASK]', $output, 'the dead run is still recognised');
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($dead), 'and settled: back to pending for its retry');
        static::__assert_equals(1, (int) DB::table('_tasks')->where('id', $dead)->value('abandon_count'));

        static::__assert_false(str_contains($output, 'spawned a worker'), 'no task worker for the due run: ' . $output);
        static::__assert_false(str_contains($output, 'spawned a scheduled worker'), 'nor for a schedule');
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($due));

        static::__assert_equals(0, Artisan::call('rsx:task:process', ['--once' => true]));
        static::__assert_contains('The task system is disabled', Artisan::output());
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($due), '--once runs nothing');

        static::__reset();
    }

    /**
     * No schedule produces a run while the system is off, and a schedule that came due many
     * times meanwhile runs exactly once after it is back on.
     */
    public static function test_schedules_wait_and_catch_up_with_one_run()
    {
        static::__reset();

        $overdue = date('Y-m-d H:i:s', time() - 30 * 86400) . '.000';
        $schedule_id = DB::table('_task_schedules')->insertGetId([
            'class' => self::FIX,
            'method' => 'marker_a',
            'cron_expression' => '* * * * *',
            'next_run_at' => $overdue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Task::disable('test');
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);

        static::__assert_equals(0, DB::table('_tasks')->where('schedule_id', $schedule_id)->count(), 'no run while off');
        static::__assert_equals(strtotime($overdue), strtotime(DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at')), 'the schedule is not advanced');

        Task::enable();
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::SCHEDULED]);

        static::__assert_equals(1, DB::table('_tasks')->where('schedule_id', $schedule_id)->count(), 'one catch-up run');
        static::__assert_equals(['A'], Task_Exec_Fixture_Service::$run_order);
        static::__assert_true(strtotime(DB::table('_task_schedules')->where('id', $schedule_id)->value('next_run_at')) > time(), 'then its next cadence');

        static::__reset();
    }

    /**
     * An inline run asked for while the system is off is QUEUED and its caller WAITS: the row
     * is PENDING, nothing executes, the caller says on stderr what it waits for. Once the
     * system is back on the waiting process runs its own run - exactly once - and exits with
     * the task's code.
     */
    public static function test_an_inline_caller_queues_waits_and_runs_after_enable()
    {
        static::__reset();
        $path = Rsx_Project_Paths::scratch_file('task_switch_ran', 'txt');
        Task::disable('replacing the server');

        $child = static::__start_child(['rsx:task:run', 'Task_Exec_Fixture_Service', 'appends_line', '--path=' . $path]);
        $id = static::__await_run('appends_line', Task_Run_Model::STATUS_PENDING);

        $row = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::ORIGIN_INLINE, (int) $row->origin_id);
        static::__assert_null($row->worker_pid, 'queued: nobody runs it');
        static::__assert_equals('disabled', $row->to_status_array()['waiting']['reason'], 'its wait line says why');
        static::__assert_false(is_file($path), 'the task body was not entered');
        static::__assert_true(proc_get_status(self::$children[$child['pid']])['running'], 'the caller is waiting');

        // A worker claims nothing while the system is off, an inline run's queued row included.
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($id));

        Task::enable();

        static::__assert_equals(0, static::__wait_child($child), 'the command exits with the task\'s code');
        $row = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $row->status_id);
        static::__assert_null($row->worker_id, 'the waiting process ran it itself, inline');
        static::__assert_equals("ran\n", (string) file_get_contents($path), 'exactly once');
        static::__assert_equals(1, DB::table('_tasks')->where('method', 'appends_line')->count(), 'one run row');
        static::__assert_contains('Waiting for: php artisan rsx:tasks:enable', (string) file_get_contents($child['stderr_file']));

        unlink($path);
        static::__reset();
    }

    /**
     * The waiter and a worker both reach for the queued run once the system is back on: the
     * claim is one guarded write, so the body is entered once, and a waiter that lost the
     * claim answers with the run the worker settled - here a #[Command] alias exiting with
     * the code its task returned.
     */
    public static function test_a_waiter_and_a_worker_never_both_run_the_queued_run()
    {
        static::__reset();
        Task::disable('test');

        $child = static::__start_child(['rsx_test:exit', '--code=7']);
        $id = static::__await_run('exit_with', Task_Run_Model::STATUS_PENDING);

        Task::enable();
        // An in-process worker races the waiter for the row; whoever loses the guarded claim
        // leaves it alone.
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);

        static::__assert_equals(7, static::__wait_child($child), 'the alias exits with the task\'s code, whoever ran it');
        $row = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_FAILED, (int) $row->status_id, 'a non-zero return is a failed run');
        static::__assert_equals(7, (int) $row->return_code);
        static::__assert_equals(1, DB::table('_tasks')->where('method', 'exit_with')->count(), 'one run row, settled once');

        static::__reset();
    }

    /**
     * A waiter that dies while it waits - the server restart the switch exists for - leaves an
     * ordinary PENDING run: no tick touches it, and a pool worker runs it after the system is
     * back on.
     */
    public static function test_a_waiter_that_dies_leaves_its_run_for_a_worker()
    {
        static::__reset();
        $path = Rsx_Project_Paths::scratch_file('task_switch_orphan', 'txt');
        Task::disable('test');

        $child = static::__start_child(['rsx:task:run', 'Task_Exec_Fixture_Service', 'appends_line', '--path=' . $path]);
        $id = static::__await_run('appends_line', Task_Run_Model::STATUS_PENDING);

        posix_kill($child['pid'], SIGKILL);
        static::__wait_child($child);

        Artisan::call('rsx:task:process');
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($id), 'a tick finds nothing to recover: the run is simply queued');
        static::__assert_false(is_file($path));

        Task::enable();
        Artisan::call('rsx:task:worker', ['--pool' => Task_Pool::ON_DEMAND]);

        $row = Task_Run_Model::find($id);
        static::__assert_equals(Task_Run_Model::STATUS_COMPLETED, (int) $row->status_id, 'a worker ran it');
        static::__assert_not_null($row->worker_id, 'under its pool identity');
        static::__assert_equals("ran\n", (string) file_get_contents($path), 'exactly once');

        unlink($path);
        static::__reset();
    }

    // -------------------------------------------------------------------------
    // The health row
    // -------------------------------------------------------------------------

    public static function test_the_health_row_fails_while_off()
    {
        static::__reset();

        static::__assert_equals('OK', Task_Health_Checks::task_system()['status']);

        Task::disable('replacing the server');
        $row = Task_Health_Checks::task_system();
        static::__assert_equals('FAIL', $row['status']);
        static::__assert_contains('disabled since', $row['detail']);
        static::__assert_contains('by the command line', $row['detail']);
        static::__assert_contains('(replacing the server)', $row['detail']);
        static::__assert_equals('php artisan rsx:tasks:enable', $row['remediation']);

        static::__assert_equals('INFO', Task_Health_Checks::task_scheduler_liveness()['status'], 'the liveness row defers to it');

        static::__reset();
    }

    // -------------------------------------------------------------------------
    // rsx:tasks:disable stops what is running
    // -------------------------------------------------------------------------

    /**
     * A task that checks for a stop ends on its own inside the grace: the command returns as
     * soon as it has, the run settles STOPPED, and nothing is killed.
     */
    public static function test_disable_stops_a_cooperative_run_gracefully()
    {
        static::__reset();
        $id = static::__start_cooperative_run();
        $queued = static::__pending('marker_a');

        $started = time();
        static::__assert_equals(0, Artisan::call('rsx:tasks:disable', ['--reason' => 'cooperative', '--grace' => 3600]));
        $output = Artisan::output();

        static::__assert_false(Task::is_enabled(), 'the switch is off');
        static::__assert_equals(Task_Run_Model::STATUS_STOPPED, static::__status($id), 'the task stopped itself: ' . $output);
        static::__assert_true(time() - $started < 3600, 'the command did not sit out the grace');
        static::__assert_contains("Stopping task {$id}", $output);
        // The request may be held by a kill worker the stop started, which notices the run
        // has ended on its next re-read: wait for it to let go, then read the verdict.
        while ((int) Task_Kill_Request_Model::where('task_id', $id)->value('status_id') === Task_Kill_Request_Model::STATUS_CLAIMED) {
            usleep(100000);
        }
        // The request may be held by a kill worker the stop started, which notices the run
        // has ended on its next re-read: wait for it to let go, then read the verdict.
        while ((int) Task_Kill_Request_Model::where('task_id', $id)->value('status_id') === Task_Kill_Request_Model::STATUS_CLAIMED) {
            usleep(100000);
        }
        static::__assert_equals(
            Task_Kill_Request_Model::STATUS_MOOT,
            (int) Task_Kill_Request_Model::where('task_id', $id)->value('status_id'),
            'its kill was never needed'
        );
        static::__assert_equals(Task_Run_Model::STATUS_PENDING, static::__status($queued), 'a pending run is left queued');

        static::__reset();
    }

    /**
     * A run that ignores the request is killed once the grace has passed, in this process; a
     * run on another host is named and left with its kill request for that host.
     */
    public static function test_disable_kills_a_run_that_ignores_the_stop_after_the_grace()
    {
        static::__reset();

        $pid = Task_Worker_Victim::start_worker();
        try {
            $local = static::__running_row($pid, 51);
            $remote = static::__running_row(self::DEAD_PID, 52, self::OTHER_HOST);

            static::__assert_equals(0, Artisan::call('rsx:tasks:disable', ['--reason' => 'replacing the server', '--grace' => 1]));
            $output = Artisan::output();

            static::__assert_false(Task_Worker_Victim::running($pid), 'the worker that ignored the stop is dead');
            $row = Task_Run_Model::find($local);
            static::__assert_equals(Task_Run_Model::STATUS_KILLED, (int) $row->status_id);
            static::__assert_not_null($row->stop_requested_at, 'it was asked to stop first');
            static::__assert_contains('the task system was turned off: replacing the server', (string) $row->status_reason);

            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, static::__status($remote), 'another host\'s run is left to that host');
            static::__assert_equals(Task_Kill_Request_Model::STATUS_PENDING, (int) Task_Kill_Request_Model::where('task_id', $remote)->value('status_id'));
            static::__assert_contains('runs on host ' . self::OTHER_HOST, $output);
            static::__assert_contains('No task is running on this host', $output);
        } finally {
            Task_Worker_Victim::reap($pid);
            static::__reset();
        }
    }

    /**
     * An inline run inside a web request - no pool identity, a pid that is no artisan
     * process - is asked to stop and never signalled: the command lists it and exits 2.
     */
    public static function test_disable_reports_a_web_request_run_it_cannot_kill()
    {
        static::__reset();

        $pid = Task_Worker_Victim::start_plain();
        try {
            $id = Task_Runner::insert_row(self::FIX, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, array_merge(Task_Runner::running_fields(), [
                'worker_pid' => $pid,
            ]));

            static::__assert_equals(2, Artisan::call('rsx:tasks:disable', ['--grace' => 1]));
            $output = Artisan::output();

            static::__assert_false(Task::is_enabled(), 'the switch is off all the same');
            static::__assert_true(Task_Worker_Victim::running($pid), 'the process was not signalled');
            $row = Task_Run_Model::find($id);
            static::__assert_equals(Task_Run_Model::STATUS_RUNNING, (int) $row->status_id);
            static::__assert_not_null($row->stop_requested_at, 'it was asked to stop');
            static::__assert_contains("Task {$id} (Task_Exec_Fixture_Service::marker_a) is still running inside a web request", $output);
            static::__assert_contains('1 inline run(s) inside web requests are still running on this host', $output);
        } finally {
            Task_Worker_Victim::reap($pid);
            static::__reset();
        }
    }
}
