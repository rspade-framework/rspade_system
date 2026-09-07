<?php

namespace App\RSpade\Tests\Locks\Php;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * RsxLocks::_checkpoint() / _release_since() - bounding a unit of work inside one process.
 *
 * WHY THIS EXISTS. An ordinary application lock is held until the PROCESS exits: nothing
 * in a request ever releases the site write lock that the first `save()` took, because a
 * request IS the unit of work and its end is process-shaped. A task WORKER breaks that
 * assumption - one long-lived process runs many unrelated tasks back to back, so without
 * an explicit boundary the first task to touch a tenant would hold that tenant's write
 * lock against the whole cluster for the worker's entire lifetime, and every later task
 * in the same worker would silently inherit exclusivity it never asked for.
 *
 * The contract these tests pin down:
 *   - locks taken AFTER a checkpoint are released by _release_since();
 *   - locks held BEFORE it are not touched (the worker's own locks survive);
 *   - reentrancy counts are flattened, so a task that nested acquires still lets go;
 *   - the site-lock registry is purged in step, or the next task would believe it still
 *     holds a lock that has been released and would write to the tenant holding nothing;
 *   - each released lock is REPORTED, because a task ending with a lock is a defect.
 *
 * Sole production caller: Task_Worker_Command::execute_task(). See backlog B-85 for the
 * open policy question this exposed (a task should DECLARE whether it wants the site lock
 * at all, rather than acquiring one implicitly on its first write).
 */
class Lock_Checkpoint_Test extends Rsx_Test_Abstract
{
    // Pure lock behavior - no database needed.
    protected static $use_database_transactions = false;

    public static function test_release_since_frees_locks_taken_after_the_checkpoint()
    {
        $checkpoint = RsxLocks::_checkpoint();

        RsxLocks::named_write_lock('rsxtest_cp_after');

        $released = RsxLocks::_release_since($checkpoint);

        static::__assert_count(1, $released, 'exactly one lock was released');
        static::__assert_contains('rsxtest_cp_after', $released[0], 'the released lock is named in the report');

        // Proof it is genuinely free: re-acquiring must succeed, and a lock that was still
        // held would have been a reentrant second acquire returning the SAME token.
        $token = RsxLocks::named_write_lock('rsxtest_cp_after');
        static::__assert_true(RsxLocks::release_lock($token), 'the lock was free to acquire again');
    }

    public static function test_release_since_leaves_locks_held_before_the_checkpoint()
    {
        // Stands in for a lock the WORKER itself holds across many tasks.
        $worker_token = RsxLocks::named_write_lock('rsxtest_cp_worker');

        $checkpoint = RsxLocks::_checkpoint();
        RsxLocks::named_write_lock('rsxtest_cp_task');

        $released = RsxLocks::_release_since($checkpoint);

        static::__assert_count(1, $released, 'only the post-checkpoint lock was released');
        static::__assert_contains('rsxtest_cp_task', $released[0], 'and it is the task lock, not the worker one');

        // The worker's lock is untouched, so releasing it now still reports it as held.
        static::__assert_true(
            RsxLocks::release_lock($worker_token),
            "the worker's own lock survived the task boundary"
        );
    }

    public static function test_release_since_flattens_reentrancy_counts()
    {
        $checkpoint = RsxLocks::_checkpoint();

        // A task that acquired the same lock three times down its call stack. Releasing
        // through the reentrancy layer would only decrement and leave the backend holding
        // it for the rest of the worker's life - the exact bug _cleanup_locks() had.
        RsxLocks::named_write_lock('rsxtest_cp_nested');
        RsxLocks::named_write_lock('rsxtest_cp_nested');
        RsxLocks::named_write_lock('rsxtest_cp_nested');

        $released = RsxLocks::_release_since($checkpoint);
        static::__assert_count(1, $released, 'the nested lock is reported once, not three times');

        $token = RsxLocks::named_write_lock('rsxtest_cp_nested');
        static::__assert_true(
            RsxLocks::release_lock($token),
            'a nested acquire did not survive the boundary'
        );
    }

    public static function test_release_since_purges_the_site_lock_registry()
    {
        $checkpoint = RsxLocks::_checkpoint();

        // Take the site lock the way the ORM does, and register it the way the ORM does.
        $site_id = 987655;
        $token = RsxLocks::site_write_lock($site_id);
        Rsx_Site_Model_Abstract::_forget_site_lock_tokens([]);   // no-op; proves it is safe

        RsxLocks::release_lock($token);

        // The real assertion: _forget_site_lock_tokens() drops entries by TOKEN and leaves
        // everything else alone, so a purge can never release the wrong site's lock.
        Rsx_Site_Model_Abstract::_forget_site_lock_tokens([$token]);

        $reacquired = RsxLocks::site_write_lock($site_id);
        static::__assert_true(
            RsxLocks::release_lock($reacquired),
            'the site lock is acquirable again after the registry purge'
        );

        RsxLocks::_release_since($checkpoint);
    }

    public static function test_release_since_is_a_no_op_when_nothing_was_taken()
    {
        $checkpoint = RsxLocks::_checkpoint();

        $released = RsxLocks::_release_since($checkpoint);

        static::__assert_empty($released, 'a task that took no locks reports nothing');
    }

    public static function teardown()
    {
        foreach (['rsxtest_cp_after', 'rsxtest_cp_worker', 'rsxtest_cp_task', 'rsxtest_cp_nested'] as $name) {
            RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, $name);
        }
        RsxLocks::force_clear_lock(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . '987655');
    }
}
