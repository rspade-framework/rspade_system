<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Php;

use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Maintenance-gated tolerance in the two redis consumers that would otherwise abort the update
 * window: RsxCache and Rsx_Counter (reads miss silently, writes are dropped with one log
 * warning; counters answer 0) and Realtime
 * (frames are semantically void - the relay is stopped and the subscriber registry was flushed
 * with redis). Both are forced with $force_active_for_tests, never the real flag.
 *
 * Outside maintenance both stay LOUD; that is covered by their own concerns' tests.
 */
class Maintenance_Redis_Tolerance_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function teardown()
    {
        Framework_Maintenance::$force_active_for_tests = null;
    }

    public static function test_cache_reads_miss_and_writes_are_dropped()
    {
        $key = 'rsxtest_maint_' . uniqid();

        // Seed a real value first, with maintenance OFF.
        Framework_Maintenance::$force_active_for_tests = false;
        RsxCache::set($key, 'seeded');
        static::__assert_equals('seeded', RsxCache::get($key), 'sanity: the value is cached normally');

        try {
            Framework_Maintenance::$force_active_for_tests = true;

            // Reads: silent miss (the default comes back, never the stored value).
            static::__assert_null(RsxCache::get($key));
            static::__assert_equals('fallen-through', RsxCache::get($key, 'fallen-through'));
            static::__assert_false(RsxCache::exists($key));

            // Writes: dropped, and typed correctly (bool signatures return false, never null).
            static::__assert_false(RsxCache::set($key, 'written-under-maintenance'));
            static::__assert_false(RsxCache::set_persistent($key, 'written-under-maintenance'));
            static::__assert_false(RsxCache::delete($key));

            // The transient-counter store is the same degraded contract on its own database:
            // a counter answers 0 without reaching redis, which is what makes a throttle
            // built on it fail OPEN while the web tier answers 503.
            static::__assert_equals(0, Rsx_Counter::increment($key, 300));
            static::__assert_equals(0, Rsx_Counter::get($key));
        } finally {
            Framework_Maintenance::$force_active_for_tests = false;
        }

        // Nothing was written through, and nothing was deleted: the seeded value survives.
        static::__assert_equals('seeded', RsxCache::get($key), 'the maintenance window must not mutate the cache');
        RsxCache::delete($key);
        Framework_Maintenance::$force_active_for_tests = null;
    }

    public static function test_realtime_registry_is_empty_under_maintenance()
    {
        $redis = Realtime::_testing_redis();
        $member = json_encode(['site_id' => 1, 'topic' => 'Rsxtest_Maint_Topic', 'filter' => []]);
        $redis->sAdd('rsx_rt:subs', $member);

        try {
            Realtime::reset_registry_memo();
            Framework_Maintenance::$force_active_for_tests = false;
            $normal = Realtime::subscribed_registry_entries();
            static::__assert_greater_than(0, count($normal), 'sanity: the seeded subscription is visible normally');

            Realtime::reset_registry_memo();
            Framework_Maintenance::$force_active_for_tests = true;
            static::__assert_count(0, Realtime::subscribed_registry_entries(), 'the registry must read empty under maintenance');
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
            Realtime::reset_registry_memo();
            $redis->sRem('rsx_rt:subs', $member);
        }
    }

    /**
     * publish() must return quietly under maintenance instead of reaching redis. The frame is
     * void by construction (relay stopped, registry flushed), and the alternative - a throw -
     * would abort `migrate` inside the update window, since model writes emit at commit
     * through an uncaught chain.
     */
    public static function test_realtime_publish_is_dropped_under_maintenance()
    {
        try {
            Framework_Maintenance::$force_active_for_tests = true;
            Realtime::publish('Rsxtest_Maint_Topic', ['id' => 1], 1);
            static::__pass('publish() returned without error under maintenance');
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }
    }
}
