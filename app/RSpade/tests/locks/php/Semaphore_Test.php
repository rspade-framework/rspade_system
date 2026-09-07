<?php

namespace App\RSpade\Tests\Locks\Php;

use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * RsxLocks counting semaphores (max-concurrency quotas).
 *
 * Unlike the readers-writer lock (one writer / many readers), a semaphore grants up to
 * $max_slots simultaneous holders of one name. Served by rsx-lockd, so the quota spans
 * processes and containers, and a slot is held exactly like a lock - until released or until
 * the holder's connection dies. There is no lease: a crashed holder frees its slot instantly
 * (proved cross-process in ../http/lockd_death_release.sh).
 *
 * Signature: acquire_semaphore($name, $max_slots, ?$timeout = null). A null timeout waits
 * forever, so every test here passes an explicit one - a test that could wait forever is a
 * test that can hang the suite.
 *
 * Each test uses a unique semaphore name and releases what it takes.
 */
class Semaphore_Test extends Rsx_Test_Abstract
{
    // Pure lock behavior - no database needed.
    protected static $use_database_transactions = false;

    public static function test_quota_is_enforced()
    {
        $name = 'test_sem_' . uniqid();

        $t1 = RsxLocks::acquire_semaphore($name, 2, 1);
        $t2 = RsxLocks::acquire_semaphore($name, 2, 1);
        static::__assert_not_null($t1, 'first slot granted');
        static::__assert_not_null($t2, 'second slot granted');

        // Third acquire must fail fast (quota is 2). A 0-second wait budget parks and is
        // answered on the next tick, which is how "do not wait at all" is expressed.
        $t3 = RsxLocks::acquire_semaphore($name, 2, 0);
        static::__assert_null($t3, 'third acquire denied - quota reached');

        // Releasing one frees a slot for a new acquire.
        RsxLocks::release_semaphore($t1);
        $t4 = RsxLocks::acquire_semaphore($name, 2, 1);
        static::__assert_not_null($t4, 'slot reusable after release');

        RsxLocks::release_semaphore($t2);
        RsxLocks::release_semaphore($t4);
    }

    public static function test_zero_is_unlimited()
    {
        $name = 'test_sem_unl_' . uniqid();

        $tokens = [];
        for ($i = 0; $i < 25; $i++) {
            $token = RsxLocks::acquire_semaphore($name, 0, 0);
            static::__assert_not_null($token, 'unlimited quota always grants');
            $tokens[] = $token;
        }

        // An unlimited quota is not a quota: nothing is tracked, so nothing is reported.
        static::__assert_equals(0, RsxLocks::get_semaphore_usage($name, 0), 'unlimited reports no usage');

        foreach ($tokens as $token) {
            RsxLocks::release_semaphore($token);
        }
    }

    public static function test_usage_reporting()
    {
        $name = 'test_sem_usage_' . uniqid();

        static::__assert_equals(0, RsxLocks::get_semaphore_usage($name, 3), 'starts empty');

        $t1 = RsxLocks::acquire_semaphore($name, 3, 1);
        $t2 = RsxLocks::acquire_semaphore($name, 3, 1);
        static::__assert_equals(2, RsxLocks::get_semaphore_usage($name, 3), 'two slots held');

        RsxLocks::release_semaphore($t1);
        static::__assert_equals(1, RsxLocks::get_semaphore_usage($name, 3), 'one slot after release');

        RsxLocks::release_semaphore($t2);
        static::__assert_equals(0, RsxLocks::get_semaphore_usage($name, 3), 'empty after all released');
    }

    public static function test_release_null_and_sentinels_are_safe()
    {
        // Should not throw.
        RsxLocks::release_semaphore(null);
        $unlimited = RsxLocks::acquire_semaphore('test_sem_sentinel_' . uniqid(), 0, 0);
        RsxLocks::release_semaphore($unlimited);
        RsxLocks::release_semaphore($unlimited);
        static::__pass('null, sentinel and repeated releases are no-ops');
    }
}
