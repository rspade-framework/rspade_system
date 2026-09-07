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
use App\RSpade\Tests\Realtime\Php\Realtime_Composition_Fixture_Service;

/**
 * The #[Emitter] MODEL CONSTRAINT (aggregate-emitter composition guard): an emitter composed
 * onto the shared Model_Changed_Topic with a second attribute arg
 * (#[Emitter('Model_Changed_Topic', 'Realtime_Fixture_Model')]) runs ONLY for registry entries
 * whose filter.model matches, and only such an entry opens the dispatch gate — so the no-churn
 * property survives even though every model write is on the same topic.
 *
 * Registry entries are seeded straight into 'rsx_rt:subs' (the raw connection PHP publishes on)
 * and publishes are observed through the publish-capture seam. Redis only — no DB.
 */
class Realtime_Emitter_Constraint_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const TOPIC = 'Model_Changed_Topic';
    private const CONSTRAINT_MODEL = 'Realtime_Fixture_Model';
    private const FOREIGN_MODEL = 'Realtime_Plain_Fixture_Model';

    public static function setup()
    {
        static::__clean_redis();
        Realtime_Emitter_Service::_testing_reset();
        Realtime_Composition_Fixture_Service::$forced_value = null;
    }

    public static function teardown()
    {
        static::__clean_redis();
        Realtime_Composition_Fixture_Service::$forced_value = null;
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
     * Seed one Model_Changed_Topic registry entry for a given model/id.
     */
    private static function __seed(string $model, int $id, int $site_id = 1): void
    {
        static::__redis()->sAdd('rsx_rt:subs', json_encode([
            'site_id' => $site_id,
            'topic' => self::TOPIC,
            'filter' => ['model' => $model, 'id' => $id],
        ]));
        Realtime::reset_registry_memo();
    }

    public static function test_engine_runs_emitter_only_for_the_matching_model_entry()
    {
        static::__clean_redis();
        Realtime_Emitter_Service::_testing_reset();

        static::__seed(self::CONSTRAINT_MODEL, 7);
        static::__seed(self::FOREIGN_MODEL, 9);
        Realtime::_testing_start_publish_capture();

        $result = Realtime_Emitter_Service::run_emitters_engine();

        static::__assert_equals(
            1,
            $result['ran'],
            'the composed emitter ran once (matching-model entry only); the foreign-model entry was skipped'
        );
    }

    public static function test_gate_closed_when_only_foreign_model_watchers_exist()
    {
        static::__clean_redis();
        Realtime_Emitter_Service::_testing_reset();

        static::__seed(self::FOREIGN_MODEL, 3);
        static::__seed(self::FOREIGN_MODEL, 4);

        static::__assert_false(
            Realtime_Emitter_Service::has_watched_emitter_topic(),
            'a constrained emitter is not served by watchers for a different model -> gate stays closed (no churn)'
        );
    }

    public static function test_gate_open_when_a_matching_watcher_exists()
    {
        static::__clean_redis();
        Realtime_Emitter_Service::_testing_reset();

        static::__seed(self::FOREIGN_MODEL, 3);
        static::__seed(self::CONSTRAINT_MODEL, 5);

        static::__assert_true(
            Realtime_Emitter_Service::has_watched_emitter_topic(),
            'a watcher for the constrained model opens the gate'
        );
    }

    public static function test_hash_diff_seed_then_change_publishes_once_with_filter_payload()
    {
        static::__clean_redis();
        Realtime_Emitter_Service::_testing_reset();

        static::__seed(self::CONSTRAINT_MODEL, 11);

        Realtime_Composition_Fixture_Service::$forced_value = 100;
        // Baselines are established at SUBSCRIBE time (silently). At WRITE time an absent
        // baseline publishes instead - see the belt in run_emitters_engine.
        $seed = Realtime_Emitter_Service::seed_subscriptions_engine([[
            'site_id' => 1,
            'topic' => self::TOPIC,
            'filter' => ['model' => self::CONSTRAINT_MODEL, 'id' => 11],
        ]]);
        Realtime::_testing_start_publish_capture();
        static::__assert_equals(1, $seed['seeded'], 'the constrained emitter seeded its baseline');
        static::__assert_count(0, Realtime::_testing_published(), 'nothing published on the seed');

        Realtime_Composition_Fixture_Service::$forced_value = 200;
        $changed = Realtime_Emitter_Service::run_emitters_engine();

        static::__assert_equals(1, $changed['published'], 'the changed derived value published once');

        $published = Realtime::_testing_published();
        static::__assert_count(1, $published, 'exactly one publish captured');
        static::__assert_equals(self::TOPIC, $published[0]['topic'], 'published to Model_Changed_Topic');
        static::__assert_equals(
            ['model' => self::CONSTRAINT_MODEL, 'id' => 11],
            $published[0]['data'],
            'payload is the registry filter itself (so Node routes it to the model watcher)'
        );
    }
}
