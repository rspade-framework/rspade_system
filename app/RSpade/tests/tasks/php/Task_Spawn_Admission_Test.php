<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Worker_Registry;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Testing\Rsx_Test_Detached_Processes;

/**
 * Task_Spawn_Admission_Test - a worker is admitted BEFORE it is spawned.
 *
 * Task::spawn_worker() reserves a slot in the Redis worker registry first and starts a
 * process only when the reservation succeeded; the child converts the reservation into its
 * live slot. So a full pool starts nothing at all (no PHP boot that exists only to decline),
 * concurrent spawners can never start more than the cap between them, and a reservation is
 * released deterministically - by the child converting it, by the spawner when the spawn
 * failed, or by the reaper when the process it names is gone. No reservation carries a TTL.
 *
 * The registry is not the only count, because it lives in Redis and a flush empties it under
 * running workers: the spawner also refuses while the workers its own process spawned are
 * still running, and while the workers running on this host fill the cap (both read from
 * /proc). Those two are exercised against fixture processes whose command lines are a
 * worker's - a php process that blocks on a FIFO until the test releases it, so it lives
 * exactly as long as the test needs and ends deterministically.
 *
 * And Task::spawn_workers(false) makes dispatch() enqueue ONLY - the test-suite default, which
 * a class overrides with Task::spawn_workers(true) and the harness resets at the class
 * boundary.
 *
 * Pure Redis for the registry half; the dispatch half writes _tasks rows inside the per-test
 * transaction. Other live workers and reservations are simulated by writing the registry keys
 * directly, exactly as Task_Worker_Registry_Test does.
 */
class Task_Spawn_Admission_Test extends Rsx_Test_Abstract
{
    private const ZSET_BASE = 'rsx:tasks:workers';

    /** A pid no Linux host hands out (pid_max tops out at 4194304). */
    private const DEAD_PID = 2147480000;

    // =========================================================================
    // Reservation admission
    // =========================================================================

    /**
     * Reservations count against the cap exactly like live workers: a pool of two admits two
     * reservations and refuses the third, and an unreserved worker is refused behind them.
     */
    public static function test_reservations_count_against_the_cap()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 2, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        static::__assert_not_null(Task_Worker_Registry::reserve_spawn(), 'the first reservation fits');
        static::__assert_not_null(Task_Worker_Registry::reserve_spawn(), 'the second reservation fits');
        static::__assert_null(Task_Worker_Registry::reserve_spawn(), 'the third does not - the pool is full');
        static::__assert_equals(2, Task_Worker_Registry::reserved_count());

        static::__assert_equals(false, Task_Worker_Registry::admit(), 'an unreserved worker is refused behind the reservations');

        self::_reset();
    }

    /**
     * Live workers and reservations share one count: one live worker plus one reservation
     * fills a pool of two.
     */
    public static function test_live_workers_and_reservations_share_the_cap()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 2, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        self::_redis()->zAdd(self::_zset_key(), time(), 'fake:live');

        static::__assert_not_null(Task_Worker_Registry::reserve_spawn());
        static::__assert_null(Task_Worker_Registry::reserve_spawn(), 'live + reserved = cap');

        self::_reset();
    }

    /**
     * A worker spawned under a reservation CONVERTS it: admitted even though the pool reads
     * full (its slot was counted when it was reserved), the reservation gone, the live count
     * one higher.
     */
    public static function test_admit_converts_its_reservation_into_a_live_slot()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 1, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        $token = Task_Worker_Registry::reserve_spawn();
        static::__assert_not_null($token);
        static::__assert_equals(false, Task_Worker_Registry::admit(), 'the pool reads full to an unreserved worker');

        static::__assert_equals(true, Task_Worker_Registry::admit($token), 'the reserved worker is admitted');
        static::__assert_equals(0, Task_Worker_Registry::reserved_count(), 'the reservation became the slot');
        static::__assert_equals(1, Task_Worker_Registry::live_count());

        self::_reset();
    }

    /**
     * A reservation that is no longer outstanding (reclaimed before its worker started) is
     * not a free pass: that worker competes for a slot like any other.
     */
    public static function test_a_reclaimed_reservation_admits_the_ordinary_way()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 1, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        self::_redis()->zAdd(self::_zset_key(), time(), 'fake:live');

        static::__assert_equals(false, Task_Worker_Registry::admit('no-such-reservation'), 'full pool, no outstanding reservation');

        self::_reset();
    }

    // =========================================================================
    // Deterministic release
    // =========================================================================

    /**
     * The spawner's release gives the slot back at once.
     */
    public static function test_release_returns_the_slot()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 1, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        $token = Task_Worker_Registry::reserve_spawn();
        static::__assert_null(Task_Worker_Registry::reserve_spawn(), 'full while reserved');

        Task_Worker_Registry::release_reservation($token);
        static::__assert_equals(0, Task_Worker_Registry::reserved_count());
        static::__assert_not_null(Task_Worker_Registry::reserve_spawn(), 'free again');

        self::_reset();
    }

    /**
     * A reservation starts owned by the spawner and is handed to the child's pid; a hand-off
     * after the child already converted it recreates nothing.
     */
    public static function test_hand_off_names_the_child_and_never_resurrects()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 2, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        $host = gethostname();

        $token = Task_Worker_Registry::reserve_spawn();
        static::__assert_equals($host . ':' . getmypid(), self::_owner_of($token), 'owned by the spawner while it spawns');

        Task_Worker_Registry::hand_off_reservation($token, 4242);
        static::__assert_equals($host . ':4242', self::_owner_of($token), 'owned by the child once spawned');

        static::__assert_equals(true, Task_Worker_Registry::admit($token));
        Task_Worker_Registry::hand_off_reservation($token, 4242);
        static::__assert_equals(0, Task_Worker_Registry::reserved_count(), 'a late hand-off does not bring a converted reservation back');

        self::_reset();
    }

    /**
     * The reaper releases a reservation whose owner is gone from THIS host, and leaves alone
     * one whose owner still runs and one owned on another host (a pid means nothing there).
     */
    public static function test_reclaim_releases_only_this_hosts_dead_owners()
    {
        self::_reset();
        $redis = self::_redis();
        $key = self::_reservations_key();

        $redis->hSet($key, 'dead', gethostname() . ':' . self::DEAD_PID);
        $redis->hSet($key, 'alive', gethostname() . ':' . getmypid());
        $redis->hSet($key, 'elsewhere', 'another-host.invalid:' . self::DEAD_PID);

        static::__assert_equals(1, Task_Worker_Registry::reclaim_orphaned_reservations());

        $left = $redis->hGetAll($key);
        ksort($left);
        static::__assert_equals(['alive', 'elsewhere'], array_keys($left));

        self::_reset();
    }

    // =========================================================================
    // Task::dispatch() under the test suite
    // =========================================================================

    /**
     * Under the suite, dispatch() enqueues and starts nothing: the row is pending, no process
     * was registered with the detached-process harness, and no reservation was taken.
     */
    public static function test_dispatch_under_the_suite_enqueues_only()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 3, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        $registry_before = self::_detached_registry();

        $id = Task::dispatch('Test_Echo_Service', 'echo_params', ['probe' => 'enqueue-only']);

        $row = DB::table('_tasks')->where('id', $id)->first();
        static::__assert_not_null($row, 'the row was enqueued');
        static::__assert_equals(Task_Status::PENDING, $row->status);

        static::__assert_equals($registry_before, self::_detached_registry(), 'no detached process was started');
        static::__assert_equals(0, Task_Worker_Registry::reserved_count(), 'no slot was reserved');
        static::__assert_equals(false, Task::spawn_worker(), 'spawn_worker() itself declines under the suite');

        self::_reset();
    }

    /**
     * Opted in, spawn_worker() starts a real detached worker under a reservation handed to
     * the child's pid - and once that child has exited, its reservation is gone, whichever way
     * it went: converted by the child, or released by the reaper because its pid is gone. A
     * reservation never outlives its process.
     */
    public static function test_an_opted_in_spawn_starts_a_worker_under_a_reservation()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 3, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        $registry_before = self::_detached_registry();

        Task::spawn_workers(true);
        try {
            static::__assert_equals(true, Task::spawn_worker(), 'the opted-in spawn started a worker');
        } finally {
            Task::spawn_workers(false);
        }

        $registered = trim(substr(self::_detached_registry(), strlen($registry_before)));
        static::__assert_true($registered !== '', 'the child was registered with the detached-process harness');
        $child_pid = (int) explode(' ', $registered)[0];

        $owners = self::_redis()->hGetAll(self::_reservations_key());
        static::__assert_true(
            $owners === [] || array_values($owners) === [gethostname() . ':' . $child_pid],
            'the reservation names the child (or the child already converted it)'
        );

        // Wait for the child (and anything it spawned) to exit - the harness's own wait, with
        // no deadline: rsx:task:worker ends as soon as it finds no pending task.
        Rsx_Test_Detached_Processes::contain();

        Task_Worker_Registry::reclaim_orphaned_reservations();
        static::__assert_equals(0, Task_Worker_Registry::reserved_count(), 'no reservation outlives its process');

        self::_reset();
    }

    // =========================================================================
    // Flush-proof counts: the spawner's own workers, and the host's
    // =========================================================================

    /**
     * A flushed registry with this process's own worker still running produces no spawn: the
     * registry reads an empty pool, the host floor does not see the fixture (it carries no
     * --_test-run, so it is outside this test's pool), and the process-local count alone
     * refuses - before any reservation is taken or any process started.
     */
    public static function test_a_flushed_registry_with_our_own_worker_alive_spawns_nothing()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 1, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

        $fixture = self::_start_fixture_worker(false);
        try {
            static::__assert_true(Task_Worker_Registry::is_worker_process($fixture['pid']), 'the fixture reads as a worker');
            static::__assert_equals(0, Task_Worker_Registry::host_worker_count(), 'the host floor does not count it');
            static::__assert_equals(0, Task_Worker_Registry::live_count(), 'the registry is empty');

            self::_set_spawned_pids([$fixture['pid']]);
            $registry_before = self::_detached_registry();

            Task::spawn_workers(true);
            try {
                static::__assert_equals(false, Task::spawn_worker(), 'our own live worker fills the cap');
            } finally {
                Task::spawn_workers(false);
            }

            static::__assert_equals(0, Task_Worker_Registry::reserved_count(), 'no slot was reserved');
            static::__assert_equals($registry_before, self::_detached_registry(), 'no process was started');
        } finally {
            self::_release_fixture($fixture);
            self::_set_spawned_pids([]);
        }

        static::__assert_equals(false, Task_Worker_Registry::is_worker_process($fixture['pid']), 'an exited worker is not a worker');

        self::_reset();
    }

    /**
     * The host floor: with the registry empty and this process having spawned nothing, a
     * worker of this pool already running on the host fills a cap of one - the reservation the
     * registry granted is given back and nothing is started.
     */
    public static function test_the_host_floor_refuses_at_the_cap()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 1, 'rsx.tasks.worker_heartbeat_ttl' => 90]);
        self::_set_spawned_pids([]);

        $fixture = self::_start_fixture_worker(true);
        try {
            static::__assert_equals(1, Task_Worker_Registry::host_worker_count(), 'the host floor counts this pool\'s worker');
            static::__assert_equals(0, Task_Worker_Registry::live_count(), 'the registry is empty');

            $registry_before = self::_detached_registry();

            Task::spawn_workers(true);
            try {
                static::__assert_equals(false, Task::spawn_worker(), 'the host is at the cap');
            } finally {
                Task::spawn_workers(false);
            }

            static::__assert_equals(0, Task_Worker_Registry::reserved_count(), 'the reservation was given back');
            static::__assert_equals($registry_before, self::_detached_registry(), 'no process was started');
        } finally {
            self::_release_fixture($fixture);
        }

        static::__assert_equals(0, Task_Worker_Registry::host_worker_count(), 'the floor drops when the worker exits');

        self::_reset();
    }

    // =========================================================================
    // The off switch
    // =========================================================================

    /**
     * Task::spawn_workers(false) makes dispatch() enqueue only for the rest of the process,
     * whatever was set before it.
     */
    public static function test_the_off_switch_enqueues_without_spawning()
    {
        self::_reset();
        config(['rsx.tasks.global_max_workers' => 3, 'rsx.tasks.worker_heartbeat_ttl' => 90]);

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
        static::__assert_equals(0, Task_Worker_Registry::reserved_count(), 'no slot was reserved');

        self::_reset();
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
        self::_reset();
    }

    private static function _redis(): \Redis
    {
        $r = new \Redis();
        $r->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
        $r->select(1);

        return $r;
    }

    private static function _zset_key(): string
    {
        return self::ZSET_BASE . ':' . Rsx_Connection_Scope::token();
    }

    private static function _reservations_key(): string
    {
        return self::_zset_key() . ':reserved';
    }

    private static function _owner_of(string $token): ?string
    {
        $owner = self::_redis()->hGet(self::_reservations_key(), $token);

        return $owner === false ? null : $owner;
    }

    /** The detached-process harness registry, as text ('' when absent). */
    private static function _detached_registry(): string
    {
        $path = Rsx_Project_Paths::test_detached_registry_file();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Start a process whose command line is a task worker's - this project's artisan path and
     * rsx:task:worker, plus --_test-run when $in_this_pool - and return once it is running
     * under that command line.
     *
     * It is a php process that opens the READY fifo for writing (which returns only when this
     * test opens it for reading - so the handshake below completes only after the fixture has
     * exec'd), then blocks reading the RELEASE fifo until _release_fixture() closes it. No
     * sleep, no deadline: it lives exactly until it is released.
     *
     * @return array{pid: int, ready: string, release: string}
     */
    private static function _start_fixture_worker(bool $in_this_pool): array
    {
        $ready = Rsx_Project_Paths::scratch_file('task_spawn_ready', 'fifo');
        $release = Rsx_Project_Paths::scratch_file('task_spawn_release', 'fifo');
        static::__assert_true(posix_mkfifo($ready, 0600) && posix_mkfifo($release, 0600), 'fixture fifos created');

        $code = '$r = fopen($argv[1], "w"); fwrite($r, "ready\n"); fclose($r); $h = fopen($argv[2], "r"); fgets($h);';
        $argv = [PHP_BINARY, '-r', $code, '--', $ready, $release, base_path('artisan'), 'rsx:task:worker'];
        if ($in_this_pool) {
            $argv[] = Rsx_Test_Abstract::TEST_RUN_FLAG;
        }

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

    /** Drop this process's slot and clear the live ZSET and the reservation HASH. */
    private static function _reset(): void
    {
        Task_Worker_Registry::deregister();
        self::_redis()->del(self::_zset_key(), self::_reservations_key());
    }
}
