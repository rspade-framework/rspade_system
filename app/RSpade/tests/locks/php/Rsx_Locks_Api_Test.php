<?php

namespace App\RSpade\Tests\Locks\Php;

use RuntimeException;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * The RsxLocks public API against the running rsx-lockd daemon.
 *
 * TWO KINDS OF LOCK, and this class exercises both sides of that split:
 *
 *   CLUSTER_LOCK - named_*_lock() and site_*_lock(), served by the daemon over a socket,
 *       with real readers-writer semantics, a queue, and holder identity (writer_conn).
 *   SYSTEM_LOCK  - system_lock(), backed by flock() on this box only and EXCLUSIVE ONLY.
 *
 * Also covers the API's new contracts: release_lock() RETURNS whether the lock was still
 * held (false is how a caller learns the daemon restarted under it), every timeout argument
 * defaults to null meaning wait forever, and a request that would close a wait-for cycle
 * throws rather than hanging.
 *
 * Single-process constraint: RsxLocks is reentrant per process and multiplexes one
 * connection, so genuine contention between two holders needs a second process. The one
 * test that needs it (the deadlock refusal) spawns a background helper; cross-process
 * exclusion, FIFO and release-on-death live in the shell tests under ../http/.
 *
 * Lock names are test-scoped and teardown() force-clears every one of them.
 */
class Rsx_Locks_Api_Test extends Rsx_Test_Abstract
{
    // Pure lock behavior - no database needed.
    protected static $use_database_transactions = false;

    // Unlikely site id, clearly test-scoped, to avoid colliding with real tenant locks.
    const TEST_SITE_ID = 987654;

    // =============================================================================
    // Cluster locks (rsx-lockd)
    // =============================================================================

    public static function test_named_write_lock_acquires_and_releases()
    {
        $token = RsxLocks::named_write_lock('rsxtest_nw');
        static::__assert_true(is_string($token) && $token !== '', 'named_write_lock returns a non-empty token');

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_nw');
        static::__assert_true($stats['writer_active'], 'a writer is active on the cluster lock');
        static::__assert_not_null($stats['writer_conn'], 'the daemon names the holding connection');
        static::__assert_equals(0, $stats['queue_length'], 'nobody is queued behind an uncontended lock');

        static::__assert_true(RsxLocks::release_lock($token), 'release reports the lock was still held');

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_nw');
        static::__assert_false($stats['writer_active'], 'writer is no longer active after release');
        static::__assert_null($stats['writer_conn'], 'and no connection is named as the holder');
    }

    public static function test_named_read_lock_registers_reader()
    {
        $token = RsxLocks::named_read_lock('rsxtest_nr');
        static::__assert_true(is_string($token) && $token !== '', 'named_read_lock returns a non-empty token');

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_nr');
        static::__assert_greater_than(0, $stats['readers_active'], 'at least one reader registered');
        static::__assert_false($stats['writer_active'], 'a read lock is not a writer');
        static::__assert_equals(0, $stats['readers_waiting'], 'no reader is waiting');

        RsxLocks::release_lock($token);

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_nr');
        static::__assert_equals(0, $stats['readers_active'], 'no readers remain after release');
    }

    public static function test_site_write_lock_maps_to_the_tenant_lock()
    {
        $name = RsxLocks::LOCK_SITE_PREFIX . self::TEST_SITE_ID;

        $token = RsxLocks::site_write_lock(self::TEST_SITE_ID);
        static::__assert_true(is_string($token) && $token !== '', 'site_write_lock returns a non-empty token');

        // Proves site_write_lock is the SAME lock the framework takes automatically for a
        // web request mutating this tenant: cluster domain, SITE_<id> name, WRITE type.
        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $name);
        static::__assert_true($stats['writer_active'], 'writer active on the tenant lock');

        RsxLocks::release_lock($token);

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $name);
        static::__assert_false($stats['writer_active'], 'tenant writer released');
    }

    public static function test_site_read_lock_registers_reader()
    {
        $name = RsxLocks::LOCK_SITE_PREFIX . self::TEST_SITE_ID;

        $token = RsxLocks::site_read_lock(self::TEST_SITE_ID);
        static::__assert_true(is_string($token) && $token !== '', 'site_read_lock returns a non-empty token');

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $name);
        static::__assert_greater_than(0, $stats['readers_active'], 'at least one reader on the tenant lock');

        RsxLocks::release_lock($token);

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $name);
        static::__assert_equals(0, $stats['readers_active'], 'no tenant readers remain after release');
    }

    public static function test_release_lock_frees_for_reacquire()
    {
        $token1 = RsxLocks::named_write_lock('rsxtest_reacq');
        RsxLocks::release_lock($token1);

        // The release must have reached the daemon, so a fresh acquire succeeds.
        $token2 = RsxLocks::named_write_lock('rsxtest_reacq');
        static::__assert_true($token1 !== $token2, 'a fresh acquisition mints a new token');

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_reacq');
        static::__assert_true($stats['writer_active'], 'lock reacquired after release freed it');

        RsxLocks::release_lock($token2);
    }

    public static function test_named_write_lock_is_reentrant()
    {
        // Reentrancy is CLIENT-SIDE: a nested acquire never reaches the daemon (which would
        // answer `error`), so it returns the same token and only bumps a local count.
        $token1 = RsxLocks::named_write_lock('rsxtest_re');
        $token2 = RsxLocks::named_write_lock('rsxtest_re');
        static::__assert_equals($token1, $token2, 'reentrant acquire returns the same token');

        // First release: count goes 2 -> 1, lock stays held - and reports held.
        static::__assert_true(RsxLocks::release_lock($token1), 'a nested release still reports held');
        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_re');
        static::__assert_true($stats['writer_active'], 'writer still active after the nested release');

        // Second release: count goes 1 -> 0, lock truly released.
        static::__assert_true(RsxLocks::release_lock($token1), 'the final release reports held');
        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_re');
        static::__assert_false($stats['writer_active'], 'writer released after the final release');
    }

    /**
     * release_lock() answers a question, and both answers matter.
     *
     * FALSE means the backend no longer had us as the holder - for a cluster lock, that the
     * daemon restarted while we held it and mutual exclusion was NOT in force for the whole
     * critical section. force_clear_lock() reproduces exactly that state (the holder is
     * evicted and told nothing), which is the only deterministic way to observe it.
     */
    public static function test_release_lock_reports_whether_it_was_still_held()
    {
        static::__assert_false(
            RsxLocks::release_lock('lockd:not-a-real-token'),
            'releasing an unknown token reports nothing was held'
        );

        $token = RsxLocks::named_write_lock('rsxtest_lost');
        static::__assert_true(RsxLocks::release_lock($token), 'a genuinely held lock reports held');
        static::__assert_false(RsxLocks::release_lock($token), 'releasing the same token twice reports not held');

        // Now the interesting one: evicted by force_clear while we still think we hold it.
        $lost = RsxLocks::named_write_lock('rsxtest_lost');
        RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, 'rsxtest_lost');
        static::__assert_false(
            RsxLocks::release_lock($lost),
            'a lock the daemon no longer attributes to us reports NOT held (the loss signal)'
        );
    }

    /** force_clear drops the holder and leaves the lock takeable again. */
    public static function test_force_clear_releases_a_held_lock()
    {
        $token = RsxLocks::named_write_lock('rsxtest_force');
        static::__assert_true(RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_force')['writer_active']);

        RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, 'rsxtest_force');

        $stats = RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_force');
        static::__assert_false($stats['writer_active'], 'force_clear drops the holder');

        RsxLocks::release_lock($token);
    }

    // =============================================================================
    // System locks (flock, this box only)
    // =============================================================================

    public static function test_system_lock_is_flock_backed_and_exclusive()
    {
        $token = RsxLocks::system_lock('rsxtest_system');

        // The flock filename carries the per-database lock scope now (see
        // RsxLocks::__lock_scope_prefix), so build THIS run's exact path from the same token
        // rather than globbing - flock files are never cleaned and a parallel worker (its own
        // database, its own scope) leaves a sibling file under a DIFFERENT scope, so "the only
        // scoped file" is not a safe assumption. The current scope's file is the one our own
        // acquire just created.
        $path = storage_path('flock/system__' . Rsx_Connection_Scope::token() . '__rsxtest_system.lock');

        try {
            static::__assert_true(
                str_starts_with($token, 'flock:'),
                'a system lock is granted by the flock backend, got: ' . $token
            );

            static::__assert_true(file_exists($path), 'expected a lock file at ' . $path);

            // A SEPARATE process must not be able to take the same flock while we hold it.
            $script = '$h = fopen(' . var_export($path, true) . ', "c");'
                . ' echo flock($h, LOCK_EX | LOCK_NB) ? "GOT" : "BLOCKED";';
            $out = [];
            $rc = 0;
            exec_safe('php -r ' . escapeshellarg($script), $out, $rc);
            static::__assert_equals('BLOCKED', trim(implode('', $out)), 'a second process must be blocked');
        } finally {
            static::__assert_true(RsxLocks::release_lock($token), 'the system lock was held at release time');
        }

        $out = [];
        $rc = 0;
        $script = '$h = fopen(' . var_export($path, true) . ', "c");'
            . ' echo flock($h, LOCK_EX | LOCK_NB) ? "GOT" : "BLOCKED";';
        exec_safe('php -r ' . escapeshellarg($script), $out, $rc);
        static::__assert_equals('GOT', trim(implode('', $out)), 'the file is free once released');
    }

    /**
     * There is no such thing as a system READ lock: flock() is per open file description, so
     * READ vocabulary would be both a lie (every flock is LOCK_EX) and a trap (a
     * READ-then-WRITE nesting would open a second descriptor and block against itself
     * forever - a permanent hang, since waiting forever is the default).
     */
    public static function test_a_system_read_lock_is_refused()
    {
        static::__assert_throws(
            RuntimeException::class,
            function () {
                RsxLocks::get_lock(RsxLocks::SYSTEM_LOCK, 'rsxtest_system_read', RsxLocks::READ_LOCK);
            },
            'System locks are exclusive only'
        );
    }

    public static function test_an_unknown_domain_is_refused()
    {
        static::__assert_throws(
            RuntimeException::class,
            function () {
                RsxLocks::get_lock('database', 'rsxtest_bad_domain', RsxLocks::WRITE_LOCK);
            },
            'Invalid lock domain'
        );
    }

    /** The two domains are different mechanisms - the same name in each is two locks. */
    public static function test_system_and_cluster_locks_are_independent()
    {
        $system = RsxLocks::system_lock('rsxtest_both');
        $cluster = RsxLocks::named_write_lock('rsxtest_both');

        try {
            static::__assert_true($system !== $cluster, 'two distinct tokens');
            static::__assert_true(str_starts_with($system, 'flock:'), 'the system lock is flock-backed');
            static::__assert_true(str_starts_with($cluster, 'lockd:'), 'the cluster lock is daemon-backed');

            static::__assert_true(
                RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, 'rsxtest_both')['writer_active'],
                'only the cluster lock shows up on the daemon'
            );
        } finally {
            RsxLocks::release_lock($system);
            RsxLocks::release_lock($cluster);
        }
    }

    // =============================================================================
    // Contracts
    // =============================================================================

    /**
     * `?int $timeout = null` EVERYWHERE, meaning wait forever. A timeout is a WAIT budget a
     * caller opts into, never a bound on how long a lock is held - and the default is the
     * one that cannot silently give a critical section away.
     */
    public static function test_every_timeout_argument_defaults_to_wait_forever()
    {
        $methods = [
            'get_lock' => 3,
            'named_write_lock' => 1,
            'named_read_lock' => 1,
            'site_write_lock' => 1,
            'site_read_lock' => 1,
            'system_lock' => 1,
            'upgrade_lock' => 1,
            'acquire_semaphore' => 2,
        ];

        foreach ($methods as $method => $position) {
            $parameter = (new \ReflectionMethod(RsxLocks::class, $method))->getParameters()[$position];

            static::__assert_equals(
                'timeout',
                $parameter->getName(),
                "{$method}() parameter {$position} is the timeout"
            );
            static::__assert_true(
                $parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null,
                "{$method}() must default to null (wait forever)"
            );
            static::__assert_true(
                $parameter->allowsNull(),
                "{$method}() must accept null as the timeout"
            );
        }
    }

    /** A negative timeout is nonsense rather than a synonym for anything. */
    public static function test_a_negative_timeout_is_refused()
    {
        static::__assert_throws(
            RuntimeException::class,
            function () {
                RsxLocks::named_write_lock('rsxtest_negative', -5);
            },
            'timeout cannot be negative'
        );
    }

    /**
     * A request that would close a wait-for cycle THROWS instead of parking, and carries the
     * daemon's description of the cycle - because with wait-forever as the default, an
     * undetected cycle is a permanent hang instead of an error message.
     *
     * A cycle needs two connections, so this spawns a background helper process: the helper
     * holds B and waits for A while this process holds A and then asks for B.
     */
    public static function test_a_deadlock_throws_with_the_cycle_described()
    {
        $ready_file = storage_path('rsx-tmp/rsxtest_deadlock_ready');
        @unlink($ready_file);

        $helper_path = storage_path('rsx-tmp/rsxtest_deadlock_helper.php');
        ensure_directory(dirname($helper_path));
        file_put_contents($helper_path, static::__deadlock_helper_source($ready_file));

        $lock_a = 'rsxtest_dl_a';
        $lock_b = 'rsxtest_dl_b';

        $token_a = RsxLocks::named_write_lock($lock_a);

        // Detached, because the helper has to keep running while THIS process asserts.
        // \exec_safe() is the one sanctioned subprocess wrapper (proc_open is banned), and it
        // returns as soon as the subshell backgrounds the helper and closes the pipe.
        $out = [];
        $rc = 0;
        exec_safe('php ' . escapeshellarg($helper_path) . ' > /dev/null 2>&1 &', $out, $rc);

        $helper_pid = 0;

        try {
            // Wait for the helper to hold B (it writes its pid) and park on A.
            $parked = false;
            for ($attempt = 0; $attempt < 150; $attempt++) {
                if (file_exists($ready_file)
                    && RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $lock_a)['queue_length'] === 1) {
                    $helper_pid = (int) file_get_contents($ready_file);
                    $parked = true;
                    break;
                }
                usleep(100000);
            }
            static::__assert_true($parked, 'the helper is holding B and waiting for A');

            $exception = static::__assert_throws(
                RuntimeException::class,
                function () use ($lock_b) {
                    RsxLocks::named_write_lock($lock_b);
                },
                'wait-for cycle'
            );

            $message = $exception->getMessage();
            static::__assert_contains($lock_a, $message, 'the cycle names the lock we hold');
            static::__assert_contains($lock_b, $message, 'the cycle names the lock we asked for');
            static::__assert_true(
                preg_match('/Failed to acquire.*lock/i', $message) !== 1,
                'a deadlock must NOT be worded as a retryable timeout: ' . $message
            );

            // A refusal costs the caller nothing it already had.
            static::__assert_true(
                RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, $lock_a)['writer_active'],
                'the refused caller still holds the lock it already had'
            );
        } finally {
            // Releasing A lets the helper take it, release everything and exit on its own.
            RsxLocks::release_lock($token_a);

            if ($helper_pid > 0) {
                for ($attempt = 0; $attempt < 50; $attempt++) {
                    if (!file_exists('/proc/' . $helper_pid)) {
                        break;
                    }
                    usleep(100000);
                }
                // Belt: a helper that somehow outlived the test never becomes a stray holder.
                $kill_out = [];
                $kill_rc = 0;
                exec_safe('kill -9 ' . $helper_pid . ' 2>/dev/null', $kill_out, $kill_rc);
            }

            @unlink($ready_file);
            @unlink($helper_path);
        }
    }

    /**
     * The helper: hold B, publish its pid so the test can wait for it and clean up after it,
     * then park on A. Written out rather than shipped as a fixture because it is meaningless
     * outside this one test.
     *
     * Its wait on A is BOUNDED (30s) even though the API's default is wait forever: a test
     * fixture that could park indefinitely is a fixture that can wedge the suite.
     */
    protected static function __deadlock_helper_source(string $ready_file): string
    {
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        $bootstrap = var_export(base_path('bootstrap/app.php'), true);
        $ready = var_export($ready_file, true);

        return <<<PHP
<?php
require {$autoload};
\$app = require {$bootstrap};
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Database\Rsx_Connection_Scope;

// Coordinate under the SAME database scope as the test process. Lock names are namespaced
// by the current database now (RsxLocks::__lock_scope_prefix), and a freshly-booted helper
// would otherwise default to the dev database and land in a different namespace - which is
// exactly what would NOT happen in production, where every node shares one database. Point
// the default connection at the test database, as the test-suite process itself does.
config(['database.default' => 'test']);
Illuminate\Support\Facades\DB::purge('test');

\$b = RsxLocks::named_write_lock('rsxtest_dl_b', 30);
file_put_contents({$ready}, (string) getmypid());

// Parks until the other side releases A (or gives up, so this never wedges the suite).
try {
    \$a = RsxLocks::named_write_lock('rsxtest_dl_a', 30);
    RsxLocks::release_lock(\$a);
} catch (\Throwable \$e) {
    // The other side is the one asserting; nothing to report from here.
}

RsxLocks::release_lock(\$b);
PHP;
    }

    /**
     * Clear every lock name/site touched above so nothing leaks to another test class or,
     * worse, to the developer's own daemon.
     */
    public static function teardown()
    {
        $names = [
            'rsxtest_nw', 'rsxtest_nr', 'rsxtest_reacq', 'rsxtest_re', 'rsxtest_lost',
            'rsxtest_force', 'rsxtest_both', 'rsxtest_dl_a', 'rsxtest_dl_b',
            RsxLocks::LOCK_SITE_PREFIX . self::TEST_SITE_ID,
        ];

        foreach ($names as $name) {
            RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, $name);
        }
    }
}
