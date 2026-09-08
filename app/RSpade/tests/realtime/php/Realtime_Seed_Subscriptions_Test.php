<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Realtime\Realtime_Emitter_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Emitter_Fixture_Service;

/**
 * The subscribe-time baseline seeder (Realtime_Emitter_Service::seed_subscriptions_engine).
 *
 * The Node relay POSTs newly-live registry members to /_realtime/subs_changed; PHP filters
 * them to emitter-served entries and dispatches this engine. It computes each emitter and
 * stores the baseline hash WITHOUT publishing - the subscriber has just resynced, so the
 * current value is not news to anyone.
 *
 * The load-bearing assertion is not "a key exists" but that the seeded baseline is the SAME
 * identity the run loop later compares against: seed, then run with an unchanged value and
 * observe silence (identities agree), then run with a changed value and observe exactly one
 * publish.
 *
 * Redis only - no DB - so the class opts out of the per-test transaction.
 */
class Realtime_Seed_Subscriptions_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const TOPIC = 'Realtime_Test_Public_Topic';
    private const FILTER = ['portal_user_id' => 42];

    public static function setup()
    {
        static::__clean_redis();
        Realtime_Emitter_Service::_testing_reset();
    }

    public static function teardown()
    {
        static::__clean_redis();
        Realtime::_testing_reset();
    }

    private static function __redis(): \Redis
    {
        return Realtime::_testing_redis();
    }

    private static function __clean_redis(): void
    {
        $redis = static::__redis();
        $redis->del('rsx_rt:subs');
        $keys = $redis->keys('rsx_rt:em:*');
        if (!empty($keys)) {
            $redis->del($keys);
        }
        Realtime::reset_registry_memo();
    }

    /**
     * The notify entry shape the endpoint hands the engine (decoded registry members).
     */
    private static function __entry(string $topic, array $filter, int $site_id = 1): array
    {
        return ['site_id' => $site_id, 'topic' => $topic, 'filter' => $filter];
    }

    /**
     * Seed the same tuple into the Node registry SET, so the run loop has work to do.
     */
    private static function __seed_registry(int $site_id = 1): void
    {
        static::__redis()->sAdd('rsx_rt:subs', json_encode([
            'site_id' => $site_id,
            'topic' => self::TOPIC,
            'filter' => self::FILTER,
        ]));
        Realtime::reset_registry_memo();
    }

    public static function test_seeding_stores_a_hash_and_publishes_nothing()
    {
        static::__clean_redis();
        Realtime::_testing_start_publish_capture();

        Realtime_Emitter_Fixture_Service::$next_value = 'seed-me';
        $result = Realtime_Emitter_Service::seed_subscriptions_engine([
            static::__entry(self::TOPIC, self::FILTER),
        ]);

        static::__assert_equals(1, $result['entries'], 'the emitter-served entry counted');
        static::__assert_equals(1, $result['seeded'], 'one baseline stored');
        static::__assert_count(0, Realtime::_testing_published(), 'seeding never publishes');
        static::__assert_count(
            1,
            static::__redis()->keys('rsx_rt:em:*'),
            'exactly one emitter hash key exists after seeding'
        );
    }

    public static function test_seeded_baseline_suppresses_the_next_unchanged_run()
    {
        // Proves the seed engine and the run loop derive the SAME identity: an unchanged
        // value after a seed must be silent (if the identities differed, the run loop would
        // see an absent baseline and the belt would publish).
        static::__clean_redis();
        static::__seed_registry();
        Realtime::_testing_start_publish_capture();

        Realtime_Emitter_Fixture_Service::$next_value = 'steady';
        Realtime_Emitter_Service::seed_subscriptions_engine([
            static::__entry(self::TOPIC, self::FILTER),
        ]);

        $result = Realtime_Emitter_Service::run_emitters_engine();

        static::__assert_equals(1, $result['ran'], 'the emitter re-ran for the registry entry');
        static::__assert_equals(0, $result['published'], 'the seeded baseline matched, so nothing published');
        static::__assert_count(0, Realtime::_testing_published(), 'no frame captured');
    }

    public static function test_change_after_seeding_publishes_once()
    {
        static::__clean_redis();
        static::__seed_registry();
        Realtime::_testing_start_publish_capture();

        Realtime_Emitter_Fixture_Service::$next_value = 'before';
        Realtime_Emitter_Service::seed_subscriptions_engine([
            static::__entry(self::TOPIC, self::FILTER),
        ]);

        Realtime_Emitter_Fixture_Service::$next_value = 'after';
        $result = Realtime_Emitter_Service::run_emitters_engine();

        static::__assert_equals(1, $result['published'], 'the change published once');

        $published = Realtime::_testing_published();
        static::__assert_count(1, $published, 'exactly one publish captured');
        static::__assert_equals(self::TOPIC, $published[0]['topic'], 'published to the emitter topic');
        static::__assert_equals(self::FILTER, $published[0]['data'], 'payload is the subscription filter');
    }

    public static function test_entries_no_emitter_serves_are_skipped()
    {
        // The relay is deliberately dumb and reports EVERY new member; PHP filters. A topic
        // with no emitter, and a Model_Changed_Topic entry for a model the constrained
        // fixture emitter does not serve, must both seed nothing.
        static::__clean_redis();
        Realtime::_testing_start_publish_capture();

        $result = Realtime_Emitter_Service::seed_subscriptions_engine([
            static::__entry('Realtime_Test_Private_Topic', ['id' => 1]),
            static::__entry('Model_Changed_Topic', ['model' => 'Absent_Fixture_Model', 'id' => 3]),
        ]);

        static::__assert_equals(0, $result['entries'], 'neither entry is served by an emitter');
        static::__assert_equals(0, $result['seeded'], 'nothing seeded');
        static::__assert_count(0, static::__redis()->keys('rsx_rt:em:*'), 'no hash key written');
    }

    public static function test_reseeding_is_idempotent_and_refreshes_the_ttl()
    {
        // Unsubscribe -> resubscribe re-notifies the same member. The put is unconditional
        // (no get), so it both stays correct and refreshes the 86400s TTL.
        static::__clean_redis();
        Realtime::_testing_start_publish_capture();

        Realtime_Emitter_Fixture_Service::$next_value = 'v1';
        Realtime_Emitter_Service::seed_subscriptions_engine([static::__entry(self::TOPIC, self::FILTER)]);

        $keys = static::__redis()->keys('rsx_rt:em:*');
        static::__assert_count(1, $keys, 'one baseline after the first seed');

        Realtime_Emitter_Service::seed_subscriptions_engine([static::__entry(self::TOPIC, self::FILTER)]);

        static::__assert_count(1, static::__redis()->keys('rsx_rt:em:*'), 'still one key (same identity)');
        static::__assert_greater_than(
            0,
            static::__redis()->ttl($keys[0]),
            'the reseed left a live TTL on the key'
        );
        static::__assert_count(0, Realtime::_testing_published(), 'reseeding never publishes');
    }
}
