<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Php;

use RuntimeException;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * What a CLUSTER lock does while maintenance mode holds the fleet down: NOTHING.
 *
 * CONTRACT CHANGE 2026-08-11 (owner ruling). This class previously pinned the opposite
 * behavior - maintenance degraded a cluster lock to flock() on this box, on the reasoning
 * that a single-box lock is equivalent to a cluster-wide one once the fleet is quiesced.
 * The reasoning was sound and the conclusion was still wrong: what the flock bought was
 * exclusion against a peer that maintenance had ALREADY removed (web 503, task runners
 * refused, workers killed), and what it cost was real contention against php-fpm during
 * rsx:debug and similar work. Paying a contention cost for exclusion nobody needs is a bad
 * trade, so a cluster lock under maintenance is now a no-op grant: a `maint:` token, real
 * bookkeeping, and no file, no daemon, no waiting.
 *
 * SYSTEM locks are deliberately untouched and still flock. Their contenders - a concurrent
 * artisan command, a lingering worker - are exactly what maintenance does NOT stop.
 *
 * Every test forces the maintenance answer with Framework_Maintenance::$force_active_for_tests
 * instead of raising the real flag, so the box under test never enters 503. Lock names are
 * test-scoped; each test releases what it takes.
 */
class Maintenance_Noop_Locks_Test extends Rsx_Test_Abstract
{
    // Pure lock behavior - no database needed.
    protected static $use_database_transactions = false;

    public static function setup()
    {
        Framework_Maintenance::$force_active_for_tests = true;
    }

    public static function teardown()
    {
        Framework_Maintenance::$force_active_for_tests = null;
    }

    /**
     * THE POINT OF THE CHANGE: a cluster lock is granted as a no-op and leaves nothing behind.
     */
    public static function test_a_cluster_lock_is_granted_as_a_noop_with_no_file()
    {
        $name = 'rsxtest noop/name';
        $token = RsxLocks::named_write_lock($name, 5);

        try {
            static::__assert_true(
                str_starts_with($token, 'maint:'),
                'maintenance must grant a no-op token, got: ' . $token
            );

            // The path the flock backend WOULD have used. Nothing may appear there.
            $flock_path = storage_path('flock/cluster__rsxtest_noop_name.lock');
            static::__assert_false(
                file_exists($flock_path),
                'a maintenance grant must not create a lock file at ' . $flock_path
            );
        } finally {
            static::__assert_true(RsxLocks::release_lock($token), 'release must report the lock as held');
        }
    }

    /**
     * THE CONTENTION THAT MOTIVATED THIS: another process must not be blocked. Under the old
     * flock degradation this same shape returned BLOCKED, which is precisely what was costing
     * php-fpm during rsx:debug.
     */
    public static function test_another_process_is_not_blocked()
    {
        $token = RsxLocks::named_write_lock('rsxtest_noop_excl', 5);
        $path = storage_path('flock/cluster__rsxtest_noop_excl.lock');

        try {
            $script = '$h = fopen(' . var_export($path, true) . ', "c");'
                . ' echo flock($h, LOCK_EX | LOCK_NB) ? "GOT" : "BLOCKED";';
            $out = [];
            $rc = 0;
            exec_safe('php -r ' . escapeshellarg($script), $out, $rc);

            static::__assert_equals(
                'GOT',
                trim(implode('', $out)),
                'a maintenance grant holds no flock, so nothing may contend with it'
            );
        } finally {
            RsxLocks::release_lock($token);
            @unlink($path);
        }
    }

    /**
     * Reentrancy is unchanged - it is client-side and never involved a backend.
     */
    public static function test_nesting_is_reentrant_and_unwinds()
    {
        $first = RsxLocks::named_write_lock('rsxtest_noop_nest', 5);

        try {
            $second = RsxLocks::named_write_lock('rsxtest_noop_nest', 5);
            static::__assert_equals($first, $second, 'a nested acquire must reuse the token');

            // Releasing the inner acquisition only decrements the count.
            static::__assert_true(RsxLocks::release_lock($second), 'inner release unwinds one level');

            $again = RsxLocks::named_write_lock('rsxtest_noop_nest', 5);
            static::__assert_equals($first, $again, 'still held, so still the same token');
            RsxLocks::release_lock($again);
        } finally {
            RsxLocks::release_lock($first);
        }
    }

    /**
     * A READ granted under maintenance has no server token and no daemon to ask, so
     * upgrade_lock has to handle it locally rather than reaching for a stopped socket.
     */
    public static function test_upgrading_a_read_grant_yields_an_exclusive_grant()
    {
        $read = RsxLocks::named_read_lock('rsxtest_noop_upgrade', 5);
        $upgraded = RsxLocks::upgrade_lock($read, 5);

        try {
            static::__assert_true(
                str_starts_with($upgraded, 'maint:'),
                'the upgrade must stay a no-op grant, got: ' . $upgraded
            );
            static::__assert_true($upgraded !== $read, 'upgrading a READ mints the WRITE identity');

            // The reentrancy count rode across, so ONE release ends it.
            static::__assert_true(RsxLocks::release_lock($upgraded), 'the upgraded token was held');
            static::__assert_false(
                RsxLocks::release_lock($upgraded),
                'a second release must report nothing held'
            );
        } finally {
            RsxLocks::release_lock($upgraded);
        }
    }

    /**
     * A WRITE grant upgrades to itself, exactly as an already-exclusive lock always has.
     */
    public static function test_upgrading_a_write_grant_is_a_trivial_hit()
    {
        $token = RsxLocks::named_write_lock('rsxtest_noop_upgrade_w', 5);

        try {
            static::__assert_equals($token, RsxLocks::upgrade_lock($token, 5), 'already exclusive');
        } finally {
            RsxLocks::release_lock($token);
        }
    }

    /**
     * SYSTEM locks are NOT degraded by maintenance - still flock, still a real file, still
     * exclusive against another process. This is the line the change must not cross.
     */
    public static function test_a_system_lock_is_untouched_by_maintenance()
    {
        $system = RsxLocks::system_lock('rsxtest_noop_system', 5);

        // Build THIS run's exact flock path from the same scope token the lock uses, rather
        // than globbing - flock files are never cleaned and a parallel worker on another
        // database leaves a sibling file under a different scope.
        $path = storage_path('flock/system__' . Rsx_Connection_Scope::token() . '__rsxtest_noop_system.lock');

        try {
            static::__assert_true(
                str_starts_with($system, 'flock:'),
                'a system lock stays flock-backed under maintenance, got: ' . $system
            );
            static::__assert_true(file_exists($path), 'expected a real lock file at ' . $path);

            $script = '$h = fopen(' . var_export($path, true) . ', "c");'
                . ' echo flock($h, LOCK_EX | LOCK_NB) ? "GOT" : "BLOCKED";';
            $out = [];
            $rc = 0;
            exec_safe('php -r ' . escapeshellarg($script), $out, $rc);
            static::__assert_equals(
                'BLOCKED',
                trim(implode('', $out)),
                'a system lock must still exclude another process while maintenance is up'
            );
        } finally {
            static::__assert_true(RsxLocks::release_lock($system), 'the system lock was held at release');
        }
    }

    /**
     * The two domains stay separate identities even though only one of them locks anything.
     */
    public static function test_the_two_domains_remain_distinct()
    {
        $system = RsxLocks::system_lock('rsxtest_noop_domain', 5);
        $cluster = RsxLocks::named_write_lock('rsxtest_noop_domain', 5);

        try {
            static::__assert_true($system !== $cluster, 'the two domains grant two separate locks');
            static::__assert_true(str_starts_with($system, 'flock:'), 'system -> flock');
            static::__assert_true(str_starts_with($cluster, 'maint:'), 'cluster -> no-op');
            static::__assert_false(
                file_exists(storage_path('flock/cluster__rsxtest_noop_domain.lock')),
                'the cluster grant must not create a file next to the system one'
            );
        } finally {
            static::__assert_true(RsxLocks::release_lock($cluster));
            static::__assert_true(RsxLocks::release_lock($system));
        }
    }

    public static function test_release_frees_it_for_reacquisition()
    {
        $first = RsxLocks::named_write_lock('rsxtest_noop_reacq', 5);
        RsxLocks::release_lock($first);

        $second = RsxLocks::named_write_lock('rsxtest_noop_reacq', 5);
        static::__assert_true($second !== $first, 'a fresh acquisition must mint a new token');
        RsxLocks::release_lock($second);
    }

    /**
     * force_clear_lock on a cluster lock has nothing to clear and must not go looking for a
     * lock file that a no-op grant never created.
     */
    public static function test_force_clear_on_a_cluster_lock_is_inert()
    {
        $token = RsxLocks::named_write_lock('rsxtest_noop_forceclear', 5);

        try {
            RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, 'rsxtest_noop_forceclear');
            static::__assert_false(
                file_exists(storage_path('flock/cluster__rsxtest_noop_forceclear.lock')),
                'force_clear must not create the file it would have unlinked'
            );

            // Our own bookkeeping is untouched, so the release still reports held.
            static::__assert_true(RsxLocks::release_lock($token));
        } finally {
            RsxLocks::release_lock($token);
        }
    }

    /**
     * The canonical timeout message still exists where a wait is still possible - a SYSTEM
     * lock. The updater classifies lock contention by grepping 'Failed to acquire.*lock', and a
     * CLI test fixture emits the same line verbatim, so the wording is load-bearing.
     */
    public static function test_a_system_lock_timeout_throws_the_canonical_message()
    {
        // Pre-hold the SCOPED flock file this name resolves to (built from the same scope
        // token the lock uses). The real attempt below opens a second descriptor on that
        // same file and blocks - the timeout under test.
        $path = storage_path('flock/system__' . Rsx_Connection_Scope::token() . '__rsxtest_noop_timeout.lock');

        $handle = fopen($path, 'c');
        flock($handle, LOCK_EX);

        $message = '';
        try {
            RsxLocks::system_lock('rsxtest_noop_timeout', 1);
            static::__fail('expected the acquisition to time out');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        static::__assert_true(
            preg_match('/Failed to acquire.*lock/i', $message) === 1,
            'timeout message must match the updater classification pattern, got: ' . $message
        );
        static::__assert_contains('rsxtest_noop_timeout', $message);
    }

    /** Concurrency capping is meaningless with the services stopped - semaphores go unlimited. */
    public static function test_semaphores_return_the_unlimited_sentinel()
    {
        $token = RsxLocks::acquire_semaphore('rsxtest_noop_sem', 1, 1);
        static::__assert_true(
            is_string($token) && str_starts_with($token, 'sem-unlimited-'),
            'expected the unlimited sentinel, got: ' . var_export($token, true)
        );

        // A second holder is granted too (no gating), and release is a no-op on the sentinel.
        $second = RsxLocks::acquire_semaphore('rsxtest_noop_sem', 1, 1);
        static::__assert_true(is_string($second) && str_starts_with($second, 'sem-unlimited-'));
        RsxLocks::release_semaphore($token);
        RsxLocks::release_semaphore($second);

        static::__assert_equals(0, RsxLocks::get_semaphore_usage('rsxtest_noop_sem', 1));
    }
}
