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
 * The emitter hash-diff engine (Realtime_Emitter_Service::run_emitters_engine).
 *
 * Registry entries are seeded straight into the Redis SET 'rsx_rt:subs' via the same
 * raw, unprefixed connection PHP publishes on (Realtime::_testing_redis()), so the
 * engine sees exactly what the Node relay would write. Publishes are observed through
 * the publish capture seam (no live relay/subscriber). The engine is called directly
 * (run_emitters_engine, no Task_Instance) so the assertions are deterministic.
 *
 * Redis only — no DB — so the class opts out of the per-test transaction.
 */
class Realtime_Emitter_Engine_Test extends Rsx_Test_Abstract
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

    /**
     * Remove the registry set and every stored emitter hash so each run starts clean.
     */
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
     * Seed one registry entry for the fixture emitter's topic.
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

    public static function test_absent_baseline_publishes_then_settles()
    {
        // THE BELT. A work item exists only because a live subscription for it is in the
        // registry, so an absent baseline at write time is never "the subscriber already
        // has this" - it is the subscribe->seed race, a wiped redis (every maintenance
        // window restarts it empty), or TTL expiry under a long-lived quiet subscription.
        // All three need the frame; suppressing here is what silently swallowed the first
        // change after any hash gap. The second run then settles (baseline now stored).
        //
        // setup() runs once per class, so each method re-cleans Redis for isolation.
        static::__clean_redis();
        static::__seed_registry();
        Realtime::_testing_start_publish_capture();

        Realtime_Emitter_Fixture_Service::$next_value = 'seed-value';
        $result = Realtime_Emitter_Service::run_emitters_engine();

        static::__assert_equals(1, $result['ran'], 'the one subscribed emitter ran');
        static::__assert_equals(1, $result['published'], 'an absent baseline publishes (the belt)');
        static::__assert_count(1, Realtime::_testing_published(), 'exactly one frame captured');
        static::__assert_count(
            1,
            static::__redis()->keys('rsx_rt:em:*'),
            'the baseline was stored on the same pass'
        );

        $second = Realtime_Emitter_Service::run_emitters_engine();
        static::__assert_equals(0, $second['published'], 'the stored baseline silences the next identical run');
        static::__assert_count(1, Realtime::_testing_published(), 'still exactly one frame overall');
    }

    public static function test_unchanged_value_publishes_nothing()
    {
        static::__clean_redis();
        static::__seed_registry();

        Realtime_Emitter_Fixture_Service::$next_value = 'steady';
        Realtime_Emitter_Service::run_emitters_engine();   // establishes the baseline (belt fires)
        // Capture only what the SECOND run does - the priming run's belt frame is the
        // subject of test_absent_baseline_publishes_then_settles, not of this test.
        Realtime::_testing_start_publish_capture();
        $result = Realtime_Emitter_Service::run_emitters_engine();   // same value again

        static::__assert_equals(1, $result['ran'], 'the emitter re-ran');
        static::__assert_equals(0, $result['published'], 'an unchanged value publishes nothing');
        static::__assert_count(0, Realtime::_testing_published(), 'no publish across two identical runs');
    }

    public static function test_changed_value_publishes_once_with_filter_payload()
    {
        static::__clean_redis();
        static::__seed_registry();

        Realtime_Emitter_Fixture_Service::$next_value = 'before';
        // Establish the baseline the way production does: at subscribe time, silently.
        Realtime_Emitter_Service::seed_subscriptions_engine([[
            'site_id' => 1,
            'topic' => self::TOPIC,
            'filter' => self::FILTER,
        ]]);
        Realtime::_testing_start_publish_capture();

        Realtime_Emitter_Fixture_Service::$next_value = 'after';
        $result = Realtime_Emitter_Service::run_emitters_engine();   // changed

        static::__assert_equals(1, $result['published'], 'the changed value published once');

        $published = Realtime::_testing_published();
        static::__assert_count(1, $published, 'exactly one publish captured');
        static::__assert_equals(self::TOPIC, $published[0]['topic'], 'published to the emitter topic');
        static::__assert_equals(1, $published[0]['site_id'], 'scoped to the registry entry site');
        static::__assert_equals(
            self::FILTER,
            $published[0]['data'],
            'payload is the filter itself (so Node filter-matching routes it)'
        );
    }

    public static function test_entry_without_a_registered_emitter_is_ignored()
    {
        // A registry entry an emitter does not serve must not run anything, even alongside a
        // real emitter entry. Model_Changed_Topic carries a model-CONSTRAINED fixture emitter
        // (Realtime_Fixture_Model), so a Client_Model watcher on that topic is not served and
        // only the Realtime_Test_Public_Topic emitter runs.
        static::__clean_redis();
        static::__seed_registry();
        static::__redis()->sAdd('rsx_rt:subs', json_encode([
            'site_id' => 1,
            'topic' => 'Model_Changed_Topic',
            'filter' => ['model' => 'Client_Model', 'id' => 3],
        ]));
        Realtime::reset_registry_memo();
        Realtime::_testing_start_publish_capture();

        Realtime_Emitter_Fixture_Service::$next_value = 'x';
        $result = Realtime_Emitter_Service::run_emitters_engine();

        static::__assert_equals(1, $result['ran'], 'only the emitter-backed topic ran; the other was ignored');
    }
}
