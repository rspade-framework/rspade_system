<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Detached_Processes;

/**
 * Task_Spawn_Admission_Test - who gets into the task worker pool, and who is never started.
 *
 * rsx-lockd accounts the pool (Task_Pool). A worker ADMITS ITSELF: under the pool lock it
 * counts the other members, exits when they fill rsx.tasks.global_max_workers, and joins
 * otherwise. Task::spawn_worker() reads the same count before starting a process, so a full
 * pool starts nothing, and a worker whose own task dispatches counts itself. Ahead of both,
 * the workers this process spawned that are still running cap a bulk script without a
 * daemon round trip - exercised against a fixture process whose command line is a worker's
 * (a php process that blocks on a FIFO until the test releases it, so it lives exactly as
 * long as the test needs and ends deterministically).
 *
 * OTHER MEMBERS are simulated on a second daemon connection - RsxLocks' own (Lockd_Client) -
 * joining this environment's pool with raw pool.* frames. It is a real member on a real
 * connection, distinct from the Task_Pool connection the code under test uses, so the count
 * a worker or a spawner reads includes it exactly as it would include another worker.
 *
 * And Task::spawn_workers(false) makes dispatch() enqueue ONLY - the test-suite default, which
 * a class overrides with Task::spawn_workers(true) and the harness resets at the class
 * boundary.
 *
 * The dispatch tests write _tasks rows inside the per-test transaction; the in-process
 * worker runs in it too, against an empty queue.
 */
class Task_Spawn_Admission_Test extends Rsx_Test_Abstract
{
    // =========================================================================
    // The worker admits itself
    // =========================================================================

    /**
     * A worker that finds the pool full exits without joining: the member count is unchanged
     * and the pool lock is free.
     */
    public static function test_a_worker_exits_when_the_pool_is_full()
    {
        config(['rsx.tasks.global_max_workers' => 1]);

        self::_phantom_join();
        try {
            $exit_code = Artisan::call('rsx:task:worker', ['--max-time' => 30]);
            $output = Artisan::output();

            static::__assert_equals(0, $exit_code, 'a full pool is an ordinary exit');
            static::__assert_contains('Worker pool is full', $output);
            static::__assert_null(Task_Pool::member_id(), 'the worker never joined');
            static::__assert_false(Task_Pool::holds_lock(), 'and released the pool lock');

            $stats = Task_Pool::stats();
            static::__assert_equals(1, $stats['members'], 'only the other member is in the pool');
            static::__assert_false($stats['holder'], 'nobody holds the pool lock');
        } finally {
            self::_phantom_leave();
        }

        static::__assert_equals(0, Task_Pool::stats()['members'], 'the other member left');
    }

    /**
     * Below the cap a worker joins, finds nothing to claim, and LEAVES before it returns - the
     * pool is exactly as it was.
     */
    public static function test_a_worker_below_the_cap_joins_and_leaves()
    {
        config(['rsx.tasks.global_max_workers' => 2]);

        self::_phantom_join();
        try {
            $exit_code = Artisan::call('rsx:task:worker', ['--max-time' => 30]);
            $output = Artisan::output();

            static::__assert_equals(0, $exit_code);
            static::__assert_true(
                preg_match('/Joined the pool \((pm_[^)]+)\)/', $output, $match) === 1,
                'the worker joined and named its member id: ' . $output
            );
            static::__assert_contains('No more pending tasks', $output);
            static::__assert_null(Task_Pool::member_id(), 'the worker left');
            static::__assert_false(Task_Pool::holds_lock(), 'and released the pool lock');

            $stats = Task_Pool::stats();
            static::__assert_equals(1, $stats['members'], 'the pool is back to the other member alone');
            static::__assert_false($stats['holder']);
        } finally {
            self::_phantom_leave();
        }
    }

    // =========================================================================
    // spawn_worker() reads the pool before it starts anything
    // =========================================================================

    /**
     * At the cap spawn_worker() starts nothing; once the pool has room it starts a real
     * detached worker.
     */
    public static function test_spawn_worker_is_gated_on_the_pool_count()
    {
        config(['rsx.tasks.global_max_workers' => 1]);
        self::_set_spawned_pids([]);

        $registry_before = self::_detached_registry();

        Task::spawn_workers(true);
        try {
            self::_phantom_join();
            try {
                static::__assert_equals(false, Task::spawn_worker(), 'the pool is full');
                static::__assert_equals($registry_before, self::_detached_registry(), 'no process was started');
                static::__assert_false(Task_Pool::holds_lock(), 'the count was read and the lock released');
            } finally {
                self::_phantom_leave();
            }

            static::__assert_equals(true, Task::spawn_worker(), 'with room, a worker is started');
        } finally {
            Task::spawn_workers(false);
        }

        $registered = trim(substr(self::_detached_registry(), strlen($registry_before)));
        static::__assert_true($registered !== '', 'the child was registered with the detached-process harness');

        // Wait for the child to exit - the harness's own wait, with no deadline:
        // rsx:task:worker ends as soon as it finds no pending task.
        Rsx_Test_Detached_Processes::contain();

        static::__assert_equals(0, Task_Pool::stats()['members'], 'the worker left the pool when it exited');
        self::_set_spawned_pids([]);
    }

    /**
     * A worker whose task dispatches is one of the workers: a member process counts itself,
     * so a pool of one that it fills starts nothing.
     */
    public static function test_a_member_counts_itself()
    {
        config(['rsx.tasks.global_max_workers' => 1]);
        self::_set_spawned_pids([]);

        $registry_before = self::_detached_registry();

        Task_Pool::lock();
        Task_Pool::join();
        Task_Pool::unlock();

        Task::spawn_workers(true);
        try {
            static::__assert_equals(false, Task::spawn_worker(), 'this member fills the pool of one');
            static::__assert_equals($registry_before, self::_detached_registry(), 'no process was started');
        } finally {
            Task::spawn_workers(false);
            Task_Pool::lock();
            Task_Pool::leave();
            Task_Pool::unlock();
        }
    }

    /**
     * Asking for the pool lock while holding it would park this process behind itself
     * forever; spawn_worker() refuses loudly instead.
     */
    public static function test_spawn_worker_refuses_under_the_pool_lock()
    {
        Task::spawn_workers(true);
        Task_Pool::lock();
        try {
            static::__assert_throws(RuntimeException::class, fn () => Task::spawn_worker(), 'holds the task pool lock');
        } finally {
            Task_Pool::unlock();
            Task::spawn_workers(false);
        }
    }

    // =========================================================================
    // The process-local count
    // =========================================================================

    /**
     * This process's own still-running spawns fill the cap: spawn_worker() refuses before it
     * reads the pool (which is empty) or starts anything.
     */
    public static function test_our_own_live_workers_fill_the_cap()
    {
        config(['rsx.tasks.global_max_workers' => 1]);

        $fixture = self::_start_fixture_worker();
        try {
            static::__assert_true(Task::is_worker_process($fixture['pid']), 'the fixture reads as a worker');
            static::__assert_equals(0, Task_Pool::stats()['members'], 'the pool is empty');

            self::_set_spawned_pids([$fixture['pid']]);
            $registry_before = self::_detached_registry();

            Task::spawn_workers(true);
            try {
                static::__assert_equals(false, Task::spawn_worker(), 'our own live worker fills the cap');
            } finally {
                Task::spawn_workers(false);
            }

            static::__assert_equals($registry_before, self::_detached_registry(), 'no process was started');
        } finally {
            self::_release_fixture($fixture);
            self::_set_spawned_pids([]);
        }

        static::__assert_equals(false, Task::is_worker_process($fixture['pid']), 'an exited worker is not a worker');
        static::__assert_equals(false, Task::is_worker_process(getmypid()), 'this test process is not a worker');
    }

    // =========================================================================
    // Task::dispatch() under the test suite, and the off switch
    // =========================================================================

    /**
     * Under the suite, dispatch() enqueues and starts nothing: the row is pending and no
     * process was registered with the detached-process harness.
     */
    public static function test_dispatch_under_the_suite_enqueues_only()
    {
        config(['rsx.tasks.global_max_workers' => 3]);

        $registry_before = self::_detached_registry();

        $id = Task::dispatch('Test_Echo_Service', 'echo_params', ['probe' => 'enqueue-only']);

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_not_null($row, 'the row was enqueued');
        static::__assert_equals(Task_Status::PENDING, $row->status);

        static::__assert_equals($registry_before, self::_detached_registry(), 'no detached process was started');
        static::__assert_equals(false, Task::spawn_worker(), 'spawn_worker() itself declines under the suite');
    }

    /**
     * Task::spawn_workers(false) makes dispatch() enqueue only for the rest of the process,
     * whatever was set before it.
     */
    public static function test_the_off_switch_enqueues_without_spawning()
    {
        config(['rsx.tasks.global_max_workers' => 3]);

        Task::spawn_workers(true);
        static::__assert_equals(true, Task::spawning_workers());
        Task::spawn_workers(false);
        static::__assert_equals(false, Task::spawning_workers());

        $registry_before = self::_detached_registry();

        $id = Task::dispatch('Test_Echo_Service', 'echo_params', ['probe' => 'off-switch']);

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_not_null($row, 'the row was enqueued');
        static::__assert_equals(Task_Status::PENDING, $row->status);
        static::__assert_equals($registry_before, self::_detached_registry(), 'no detached process was started');
    }

    /**
     * The harness puts the switch back to OFF at every class boundary, so a class's opt-in
     * never reaches the next class.
     */
    public static function test_the_class_boundary_turns_spawning_back_off()
    {
        Task::spawn_workers(true);
        static::__assert_equals(true, Task::spawning_workers());

        (new \ReflectionMethod(Rsx_Test_Abstract::class, '__restore_class_boundary'))->invoke(null);

        static::__assert_equals(false, Task::spawning_workers(), 'the boundary reset it');
    }

    // =========================================================================
    // teardown / helpers
    // =========================================================================

    public static function teardown()
    {
        Task_Pool::disconnect();
        self::_set_spawned_pids([]);
    }

    /**
     * Join this environment's pool on the RsxLocks connection - a member that is not this
     * process's Task_Pool connection. Returns its member id.
     */
    private static function _phantom_join(): string
    {
        self::_phantom('pool.lock', 'granted');
        $member_id = self::_phantom('pool.join')['member_id'];
        self::_phantom('pool.unlock');

        return $member_id;
    }

    /** The phantom member leaves (under the pool lock, as the protocol requires). */
    private static function _phantom_leave(): void
    {
        self::_phantom('pool.lock', 'granted');
        self::_phantom('pool.leave');
        self::_phantom('pool.unlock');
    }

    /** One acknowledged pool op on the RsxLocks connection. */
    private static function _phantom(string $op, string $expected_status = 'ok'): array
    {
        $response = Lockd_Client::request(['op' => $op, 'pool' => Task_Pool::pool_name()]);
        static::__assert_equals($expected_status, $response['status'] ?? null, "{$op} on the phantom connection: " . json_encode($response));

        return $response;
    }

    /** The detached-process harness registry, as text ('' when absent). */
    private static function _detached_registry(): string
    {
        $path = Rsx_Project_Paths::test_detached_registry_file();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Start a process whose command line is a task worker's - this project's artisan path and
     * rsx:task:worker - and return once it is running under that command line.
     *
     * It is a php process that opens the READY fifo for writing (which returns only when this
     * test opens it for reading - so the handshake below completes only after the fixture has
     * exec'd), then blocks reading the RELEASE fifo until _release_fixture() closes it. No
     * sleep, no deadline: it lives exactly until it is released.
     *
     * @return array{pid: int, ready: string, release: string}
     */
    private static function _start_fixture_worker(): array
    {
        $ready = Rsx_Project_Paths::scratch_file('task_spawn_ready', 'fifo');
        $release = Rsx_Project_Paths::scratch_file('task_spawn_release', 'fifo');
        static::__assert_true(posix_mkfifo($ready, 0600) && posix_mkfifo($release, 0600), 'fixture fifos created');

        $code = '$r = fopen($argv[1], "w"); fwrite($r, "ready\n"); fclose($r); $h = fopen($argv[2], "r"); fgets($h);';
        $argv = [PHP_BINARY, '-r', $code, '--', $ready, $release, base_path('artisan'), 'rsx:task:worker'];

        $command_line = implode(' ', array_map('escapeshellarg', $argv));
        $pid = (int) trim(exec_safe($command_line . ' > /dev/null 2>&1 & echo $!'));
        static::__assert_true($pid > 0, 'fixture started');

        Rsx_Test_Detached_Processes::register($pid);

        $handle = fopen($ready, 'r');
        static::__assert_equals("ready\n", fgets($handle), 'fixture reached its own code');
        fclose($handle);

        return ['pid' => $pid, 'ready' => $ready, 'release' => $release];
    }

    /**
     * Let a fixture worker exit, wait for it (the harness's own wait, no deadline), and remove
     * its fifos.
     *
     * @param array{pid: int, ready: string, release: string} $fixture
     * @return void
     */
    private static function _release_fixture(array $fixture): void
    {
        $handle = fopen($fixture['release'], 'w');
        fclose($handle);

        Rsx_Test_Detached_Processes::contain();

        unlink($fixture['ready']);
        unlink($fixture['release']);
    }

    /**
     * Replace the pids Task records as spawned by this process.
     *
     * @param int[] $pids
     * @return void
     */
    private static function _set_spawned_pids(array $pids): void
    {
        (new \ReflectionProperty(Task::class, 'spawned_worker_pids'))->setValue(null, $pids);
    }
}
