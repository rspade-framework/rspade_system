<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Locks\Lockd_Connection;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * Task_Pool against the running rsx-lockd: the PHP client of the daemon's worker-pool
 * accountant (the `pool.*` ops).
 *
 * The daemon's own behavior (FIFO, isolation, drop-on-disconnect) is proven on a scratch
 * daemon by tests/locks/http/lockd_pool_*.sh. What is proven HERE is the PHP side of the
 * contract: every call returns only once the daemon has applied it, a refusal throws with the
 * daemon's message, the client's bookkeeping follows the daemon's answers, a real PHP
 * process that dies is no longer counted, and the pool connection is independent of the
 * RsxLocks connection in every respect.
 *
 * The OBSERVER. "Applied before the call returned" is read through a second connection -
 * RsxLocks' own (Lockd_Client) - asking `pool.stats`, which answers without the pool lock.
 * That it can see the pool connection's effects from the outside is also the first proof
 * the two connections are distinct.
 *
 * CHILD PROCESSES are ordinary children of this process (Symfony Process), each blocked on
 * its stdin pipe until it is SIGKILLed and reaped here - so none outlives its test, and one
 * orphaned by a crashed runner sees EOF and exits. The pool connection is closed before
 * spawning, because a child inherits every open descriptor, and a pool socket held open by a
 * child is a membership that outlives its owner.
 */
class Task_Pool_Test extends Rsx_Test_Abstract
{
    // The pool is daemon state; no database rows are written.
    protected static $use_database_transactions = false;

    /**
     * Upper bound, in 100 ms polls, for waiting on something a CHILD process does (boot the
     * framework, park on the lock) or on the daemon noticing a closed socket. 120 s is the
     * house bound (tests/CLAUDE.md, the contention principle): a loaded box gives a freshly
     * booting PHP process arbitrarily little CPU. Reaching the bound is evidence, not noise.
     */
    private const CHILD_POLLS = 1200;

    // =============================================================================
    // Acknowledgement and refusal
    // =============================================================================

    /**
     * Each call has been applied by the daemon by the time it returns - the observer sees it.
     */
    public static function test_every_call_is_applied_before_it_returns()
    {
        Task_Pool::disconnect();
        $base = static::__observe()['members'];

        Task_Pool::lock();
        static::__assert_true(Task_Pool::holds_lock(), 'the client records the granted lock');
        static::__assert_true(static::__observe()['holder'], 'the daemon shows the pool lock held once lock() returns');

        $member_id = Task_Pool::join();
        static::__assert_true(str_starts_with($member_id, 'pm_'), 'join() returns the daemon-minted member id: ' . $member_id);
        static::__assert_equals($member_id, Task_Pool::member_id(), 'the client records its membership');
        static::__assert_equals($base + 1, static::__observe()['members'], 'the daemon counts the member once join() returns');

        static::__assert_true(Task_Pool::member_alive($member_id), 'a joined member is alive');

        Task_Pool::leave();
        static::__assert_null(Task_Pool::member_id(), 'the client forgets its membership on leave');
        static::__assert_equals($base, static::__observe()['members'], 'the daemon dropped the member once leave() returns');
        static::__assert_false(Task_Pool::member_alive($member_id), 'a member that left is not alive');

        Task_Pool::unlock();
        static::__assert_false(Task_Pool::holds_lock(), 'the client records the release');
        static::__assert_false(static::__observe()['holder'], 'the daemon shows the pool lock free once unlock() returns');

        $stats = Task_Pool::stats();
        static::__assert_equals(
            ['members' => $base, 'holder' => false, 'waiting' => 0],
            $stats,
            'stats() answers without the lock and agrees with the observer'
        );
    }

    /**
     * join, leave, count and member_alive require the pool lock; the daemon's refusal is an
     * exception carrying its own message, and nothing is recorded client-side.
     */
    public static function test_locked_ops_are_refused_without_the_lock()
    {
        Task_Pool::disconnect();

        static::__assert_throws(RuntimeException::class, fn () => Task_Pool::join(), 'refused pool.join');
        static::__assert_null(Task_Pool::member_id(), 'a refused join records no membership');

        static::__assert_throws(RuntimeException::class, fn () => Task_Pool::count(), 'refused pool.count');
        static::__assert_throws(RuntimeException::class, fn () => Task_Pool::member_alive('pm_0'), 'refused pool.member_alive');
        static::__assert_throws(RuntimeException::class, fn () => Task_Pool::leave(), 'refused pool.leave');
        static::__assert_throws(RuntimeException::class, fn () => Task_Pool::unlock(), 'refused pool.unlock');

        // Holding the lock, a second join by the same connection is refused, and the first
        // membership stands.
        Task_Pool::lock();
        try {
            $member_id = Task_Pool::join();
            static::__assert_throws(RuntimeException::class, fn () => Task_Pool::join(), 'refused pool.join');
            static::__assert_equals($member_id, Task_Pool::member_id(), 'a refused second join leaves the first membership recorded');
            static::__assert_true(Task_Pool::member_alive($member_id), 'and in force at the daemon');
            Task_Pool::leave();

            // Leaving twice is refused too.
            static::__assert_throws(RuntimeException::class, fn () => Task_Pool::leave(), 'refused pool.leave');
        } finally {
            Task_Pool::unlock();
        }
    }

    /**
     * count() is "how many OTHER members are there" - the same answer before and after the
     * caller joins.
     */
    public static function test_count_excludes_the_caller()
    {
        Task_Pool::disconnect();

        Task_Pool::lock();
        try {
            $before = Task_Pool::count();
            Task_Pool::join();
            static::__assert_equals($before, Task_Pool::count(), 'count() does not include the caller once it has joined');
            static::__assert_equals($before + 1, Task_Pool::stats()['members'], 'while stats() counts every member');
            Task_Pool::leave();
            static::__assert_equals($before, Task_Pool::count(), 'and count() is unchanged after it leaves');
        } finally {
            Task_Pool::unlock();
        }
    }

    // =============================================================================
    // A member that dies
    // =============================================================================

    /**
     * A child that joined and is then SIGKILLed - no leave, no unlock, no shutdown code -
     * stops being counted and stops being alive.
     */
    public static function test_a_killed_member_is_no_longer_counted()
    {
        Task_Pool::disconnect();

        Task_Pool::lock();
        $base = Task_Pool::count();
        Task_Pool::unlock();
        Task_Pool::disconnect();

        $child = static::__start_child('member');

        try {
            $ready = static::__await_ready($child);
            static::__assert_equals(Task_Pool::pool_name(), $ready['pool'], 'the child joined THIS environment\'s pool');

            Task_Pool::lock();
            static::__assert_equals($base + 1, Task_Pool::count(), 'the joined child is counted');
            static::__assert_true(Task_Pool::member_alive($ready['member_id']), 'and alive');
            Task_Pool::unlock();

            static::__kill($child);

            // The daemon learns of the death from the socket closing, which it handles on its
            // own event loop - so ask until it has.
            $alive = true;
            for ($poll = 0; $poll < self::CHILD_POLLS && $alive; $poll++) {
                Task_Pool::lock();
                $alive = Task_Pool::member_alive($ready['member_id']);
                $count = Task_Pool::count();
                Task_Pool::unlock();
                if ($alive) {
                    usleep(100000);
                }
            }

            static::__assert_false($alive, 'a SIGKILLed member is not alive');
            static::__assert_equals($base, $count, 'and is no longer counted');
        } finally {
            static::__kill($child);
            Task_Pool::disconnect();
        }
    }

    /**
     * A child that joined and dies while HOLDING the pool lock hands the lock to the waiter:
     * this process parks in lock() and is granted when the holder is SIGKILLed.
     *
     * The child is the one that dies, and it does so itself: it polls pool.stats (unlocked)
     * until it sees a waiter - this process, parked in lock() - and then SIGKILLs itself.
     */
    public static function test_a_killed_lock_holder_hands_the_lock_to_the_waiter()
    {
        Task_Pool::disconnect();

        $child = static::__start_child('holder');

        try {
            $ready = static::__await_ready($child);
            static::__assert_true(static::__observe()['holder'], 'the child holds the pool lock');

            // Parks until the child is dead. No deadline: the child ends itself.
            Task_Pool::lock();
            static::__assert_true(Task_Pool::holds_lock(), 'the waiter was granted the lock the dead holder had');

            static::__reap($child['process']);
            static::__assert_equals(137, static::__exit_code($child['process']), 'the holder died by SIGKILL, with no unlock sent');
            static::__assert_false(Task_Pool::member_alive($ready['member_id']), 'and its membership went with it');

            Task_Pool::unlock();
        } finally {
            static::__kill($child);
            Task_Pool::disconnect();
        }
    }

    // =============================================================================
    // Independence from RsxLocks
    // =============================================================================

    /**
     * The pool connection and the RsxLocks connection are two connections: both locks can be
     * held at once, only the RsxLocks one carries the process's lock group, and closing
     * either leaves the other's grants in force.
     */
    public static function test_the_pool_connection_is_independent_of_rsxlocks()
    {
        Task_Pool::disconnect();
        $lock_name = 'rsxtest_pool_indep';

        $token = RsxLocks::named_write_lock($lock_name);
        try {
            Task_Pool::lock();

            static::__assert_true(
                RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $lock_name)['writer_active'],
                'the named lock is held alongside the pool lock'
            );
            static::__assert_true(static::__observe()['holder'], 'and the pool lock alongside the named lock');

            // Two connections from this pid: the RsxLocks one named our group, the pool one none.
            $pool_connection = null;
            $lock_connection = null;
            foreach (Lockd_Client::request(['op' => 'dump'])['connections'] as $connection) {
                if ((int) $connection['pid'] !== getmypid() || $connection['host'] !== gethostname()) {
                    continue;
                }
                foreach ($connection['pools'] as $view) {
                    if ($view['pool'] === Task_Pool::pool_name() && $view['holds_lock']) {
                        $pool_connection = $connection;
                    }
                }
                if (!empty($connection['holds'])) {
                    $lock_connection = $connection;
                }
            }
            static::__assert_not_null($pool_connection, 'the daemon shows this process holding the pool lock');
            static::__assert_not_null($lock_connection, 'the daemon shows this process holding the named lock');
            static::__assert_not_equals($pool_connection['conn_id'], $lock_connection['conn_id'], 'on two different connections');
            static::__assert_null($pool_connection['group_id'], 'the pool connection names no lock group');
            static::__assert_equals(
                Lockd_Client::current_group_id(),
                $lock_connection['group_id'],
                'the RsxLocks connection names this process\'s lock group'
            );

            // Closing the pool connection frees the pool lock and nothing else.
            Task_Pool::disconnect();
            $freed = false;
            for ($poll = 0; $poll < self::CHILD_POLLS && !$freed; $poll++) {
                $freed = !static::__observe()['holder'];
                if (!$freed) {
                    usleep(100000);
                }
            }
            static::__assert_true($freed, 'closing the pool connection frees the pool lock');
            static::__assert_true(
                RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $lock_name)['writer_active'],
                'the named lock survives the pool connection closing'
            );
        } finally {
            static::__assert_true(RsxLocks::release_lock($token), 'the named lock was held throughout');
        }

        // Closing the RsxLocks connection - release_all and all - leaves the pool lock held.
        Task_Pool::lock();
        try {
            Lockd_Client::close();
            static::__assert_false(Lockd_Client::is_connected(), 'the RsxLocks connection is closed');

            $stats = Task_Pool::stats();
            static::__assert_true($stats['holder'], 'the pool lock survives release_all and close on the RsxLocks connection');
            static::__assert_true(Task_Pool::holds_lock(), 'and the client still records it');
        } finally {
            Task_Pool::unlock();
            Task_Pool::disconnect();
        }
    }

    // =============================================================================
    // No child carries the pool connection
    // =============================================================================

    /**
     * While the pool connection is open its descriptor is one of this process's lock
     * descriptors - the set every spawn seam closes in the child - and once closed it is not.
     */
    public static function test_the_pool_socket_is_a_lock_descriptor()
    {
        Task_Pool::disconnect();
        $before = RsxLocks::inherited_lock_fds();

        Task_Pool::stats();
        try {
            $inodes = Lockd_Connection::open_socket_inodes();
            static::__assert_not_empty($inodes, 'the open pool connection is a daemon socket');

            $pool_fds = array_values(array_diff(RsxLocks::inherited_lock_fds(), $before));
            static::__assert_equals(1, count($pool_fds), 'exactly one more lock descriptor: the pool socket');
            static::__assert_true(
                in_array(readlink('/proc/self/fd/' . $pool_fds[0]), array_map(fn ($inode) => 'socket:[' . $inode . ']', $inodes), true),
                'and it is the pool connection\'s socket'
            );
            static::__assert_contains($pool_fds[0] . '>&-', RsxLocks::shell_prefix_without_inherited_locks(), 'which the detached-spawn prefix closes');
        } finally {
            Task_Pool::disconnect();
        }

        static::__assert_equals($before, RsxLocks::inherited_lock_fds(), 'a closed pool connection leaves nothing to close');
    }

    /**
     * THE ONE THAT MATTERS. A pool member that starts a detached process through Rsx_Artisan
     * (a task that dispatches, say) and is then SIGKILLed stops being a member at once, while
     * that process lives on: the child never held the member's socket, so the daemon saw the
     * connection close. Had the socket been inherited, the membership would outlive the
     * member for as long as the process it started.
     *
     * The grandchild is `artisan tinker` blocked reading a FIFO this test holds open for
     * writing (opened BEFORE the spawn, so the grandchild's open never waits for a writer):
     * it lives exactly until the test writes a line, with no sleep and no deadline.
     */
    public static function test_a_detached_child_does_not_carry_the_membership()
    {
        Task_Pool::disconnect();

        $release_path = Rsx_Project_Paths::scratch_file('task_pool_grandchild_release', 'fifo');
        static::__assert_true(posix_mkfifo($release_path, 0600), 'release fifo created');
        // r+ opens a FIFO without waiting for a reader, and keeps a writer present for as long
        // as the grandchild needs one.
        $release = fopen($release_path, 'r+');

        $child = static::__start_child('spawner', [$release_path]);
        $grandchild = null;

        try {
            $ready = static::__await_ready($child);
            $grandchild = (int) $ready['grandchild_pid'];
            static::__assert_greater_than(0, $grandchild, 'the member started a detached process');
            static::__assert_not_empty($ready['socket_inodes'], 'the member held a daemon socket when it spawned');

            // The kernel's view: no descriptor of the grandchild is one of the member's sockets.
            $grandchild_links = [];
            foreach ((array) @scandir('/proc/' . $grandchild . '/fd') as $entry) {
                if (ctype_digit((string) $entry)) {
                    $grandchild_links[] = @readlink('/proc/' . $grandchild . '/fd/' . $entry);
                }
            }
            foreach ($ready['socket_inodes'] as $inode) {
                static::__assert_false(
                    in_array('socket:[' . $inode . ']', $grandchild_links, true),
                    "the detached process holds the member's daemon socket socket:[{$inode}]"
                );
            }

            static::__kill($child);

            // The daemon's view: the membership ended with the member.
            $alive = true;
            for ($poll = 0; $poll < self::CHILD_POLLS && $alive; $poll++) {
                Task_Pool::lock();
                $alive = Task_Pool::member_alive($ready['member_id']);
                Task_Pool::unlock();
                if ($alive) {
                    usleep(100000);
                }
            }
            static::__assert_false($alive, 'the SIGKILLed member is no longer a member while the process it started lives');
            static::__assert_true(static::__process_running($grandchild), 'the detached process is still running');
        } finally {
            static::__kill($child);

            fwrite($release, "go\n");
            fflush($release);
            if ($grandchild) {
                // Bounded by the house bound (tests/CLAUDE.md): the grandchild is a process this
                // test does not control, and one that never exits fails the test loudly.
                for ($poll = 0; $poll < self::CHILD_POLLS && static::__process_running($grandchild); $poll++) {
                    usleep(100000);
                }
                static::__assert_false(static::__process_running($grandchild), 'the released tinker grandchild never exited');
            }
            fclose($release);
            @unlink($release_path);
            Task_Pool::disconnect();
        }
    }

    public static function teardown()
    {
        Task_Pool::disconnect();
        RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, 'rsxtest_pool_indep');
        @unlink(Rsx_Project_Paths::tmp_path('rsxtest_task_pool_child.php'));
    }

    // =============================================================================
    // Helpers
    // =============================================================================

    /** pool.stats for this environment's pool, asked over the RsxLocks connection. */
    protected static function __observe(): array
    {
        $response = Lockd_Client::request(['op' => 'pool.stats', 'pool' => Task_Pool::pool_name()]);
        static::__assert_equals('ok', $response['status'] ?? null, 'the observer\'s pool.stats is answered');

        return $response;
    }

    /**
     * Start a pool child. 'member' joins, unlocks and blocks on stdin; 'holder' joins, keeps
     * the lock, and SIGKILLs itself once it sees a waiter. Either writes a ready file first.
     */
    protected static function __start_child(string $mode, array $extra_args = []): array
    {
        $helper_path = Rsx_Project_Paths::tmp_path('rsxtest_task_pool_child.php');
        ensure_directory(dirname($helper_path));
        file_put_contents($helper_path, static::__child_source());

        $ready_file = Rsx_Project_Paths::tmp_path('rsxtest_task_pool_ready_' . $mode);
        @unlink($ready_file);

        $input = new InputStream();
        $process = new Process(array_merge(['php', $helper_path, $mode, $ready_file], $extra_args));
        $process->setInput($input);
        $process->setTimeout(null);
        $process->start();

        // The InputStream is carried with the child so its stdin stays open for its life.
        return ['process' => $process, 'input' => $input, 'ready_file' => $ready_file];
    }

    /** Wait for the child to write its ready file; fail loud, with its stderr, if it exits first. */
    protected static function __await_ready(array $child): array
    {
        $process = $child['process'];
        $ready_file = $child['ready_file'];

        for ($poll = 0; $poll < self::CHILD_POLLS; $poll++) {
            if (file_exists($ready_file)) {
                $ready = json_decode((string) file_get_contents($ready_file), true);
                if (is_array($ready)) {
                    @unlink($ready_file);

                    return $ready;
                }
            }
            if (!$process->isRunning()) {
                break;
            }
            usleep(100000);
        }

        throw new RuntimeException(
            'the pool child never became ready (exit ' . var_export($process->isRunning() ? null : $process->getExitCode(), true)
            . '): ' . trim($process->getErrorOutput() . ' ' . $process->getOutput())
        );
    }

    /** SIGKILL the child (if still running) and reap it. */
    protected static function __kill(array $child): void
    {
        $process = $child['process'];
        if ($process->isRunning()) {
            $process->signal(SIGKILL);
        }
        static::__reap($process);
    }

    /**
     * Wait for a child that is already dying (SIGKILLed) to be reaped. Polls isRunning()
     * rather than wait(), which throws for a child a signal ended unless this object sent it.
     */
    protected static function __reap(Process $process): void
    {
        while ($process->isRunning()) {
            usleep(10000);
        }
    }

    /** The shell-convention exit code: 128 + signal for a signalled child. */
    protected static function __exit_code(Process $process): int
    {
        if ($process->hasBeenSignaled()) {
            return 128 + $process->getTermSignal();
        }

        return (int) $process->getExitCode();
    }

    /**
     * Is this pid a running process? An exited one nobody has reaped yet (a zombie, possible
     * for a grandchild reparented to a container init that does not reap) has an empty
     * command line and counts as exited.
     */
    protected static function __process_running(int $pid): bool
    {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');

        return $cmdline !== false && $cmdline !== '';
    }

    /**
     * The child: boot, coordinate under the test database's scope (as the test process does),
     * lock, join, report. Written out rather than shipped as a fixture because it is
     * meaningless outside this class.
     */
    protected static function __child_source(): string
    {
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        $bootstrap = var_export(base_path('bootstrap/app.php'), true);

        return <<<PHP
<?php
require {$autoload};
\$app = require {$bootstrap};
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\RSpade\Core\Task\Task_Pool;

// The pool name is scoped by the current database, and a freshly-booted process defaults to
// the dev database. Point the default connection at the test database, as the test-suite
// process itself does, so both land in the same pool.
config(['database.default' => 'test']);
Illuminate\Support\Facades\DB::purge('test');

[\$script, \$mode, \$ready_file] = \$argv;

Task_Pool::lock();
\$member_id = Task_Pool::join();
if (\$mode === 'member' || \$mode === 'spawner') {
    Task_Pool::unlock();
}

// spawner: start a detached process the way a task does, while a member.
\$grandchild_pid = null;
if (\$mode === 'spawner') {
    \$code = '\$h = fopen(' . var_export(\$argv[3], true) . ', "r"); fgets(\$h);';
    \$grandchild_pid = App\\RSpade\\Core\\Console\\Rsx_Artisan::dispatch_detached('tinker', ['--execute=' . \$code]);
}

file_put_contents(\$ready_file, json_encode([
    'pid' => getmypid(),
    'member_id' => \$member_id,
    'pool' => Task_Pool::pool_name(),
    'grandchild_pid' => \$grandchild_pid,
    'socket_inodes' => App\\RSpade\\Core\\Locks\\Lockd_Connection::open_socket_inodes(),
]));

if (\$mode === 'member' || \$mode === 'spawner') {
    // Blocks until the parent kills us - or dies itself, which closes the pipe.
    fgets(STDIN);
    exit(0);
}

// holder: keep the lock until a waiter is parked behind it, then die without a word.
\$stdin = [STDIN];
while (true) {
    if (Task_Pool::stats()['waiting'] > 0) {
        posix_kill(getmypid(), SIGKILL);
    }

    // A readable stdin here can only be EOF: the parent is gone, and so is the point.
    \$read = \$stdin;
    \$write = null;
    \$except = null;
    if (stream_select(\$read, \$write, \$except, 0, 50000) > 0) {
        exit(0);
    }
}
PHP;
    }
}
