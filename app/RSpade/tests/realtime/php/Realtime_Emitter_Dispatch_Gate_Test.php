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

/**
 * The smart dispatch gate (Realtime_Emitter_Service::has_watched_emitter_topic): the
 * emitter task is dispatched only when a subscribed topic actually has a registered
 * emitter. A registry full of Model_Changed_Topic watchers (no emitter) must NOT open
 * the gate — that is what stops write-driven task churn on pages that only watch model
 * changes. Asserted directly on the gate function (no real Task row), against a seeded
 * Redis registry. Redis only — no DB.
 */
class Realtime_Emitter_Dispatch_Gate_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__redis()->del('rsx_rt:subs');
        Realtime::reset_registry_memo();
    }

    public static function teardown()
    {
        static::__redis()->del('rsx_rt:subs');
        Realtime::_testing_reset();
    }

    private static function __redis(): \Redis
    {
        return Realtime::_testing_redis();
    }

    public static function test_empty_registry_does_not_open_the_gate()
    {
        Realtime::reset_registry_memo();

        static::__assert_false(
            Realtime_Emitter_Service::has_watched_emitter_topic(),
            'no subscribers -> no emitter dispatch'
        );
    }

    public static function test_only_model_changed_watchers_do_not_open_the_gate()
    {
        static::__redis()->sAdd('rsx_rt:subs', json_encode([
            'site_id' => 1,
            'topic' => 'Model_Changed_Topic',
            'filter' => ['model' => 'Absent_Fixture_Model', 'id' => 9],
        ]));
        Realtime::reset_registry_memo();

        static::__assert_false(
            Realtime_Emitter_Service::has_watched_emitter_topic(),
            'a topic with no registered emitter must not open the gate'
        );
    }

    public static function test_a_watched_emitter_topic_opens_the_gate()
    {
        static::__redis()->sAdd('rsx_rt:subs', json_encode([
            'site_id' => 1,
            'topic' => 'Realtime_Test_Public_Topic',
            'filter' => ['portal_user_id' => 5],
        ]));
        Realtime::reset_registry_memo();

        static::__assert_true(
            Realtime_Emitter_Service::has_watched_emitter_topic(),
            'a subscription to a topic with a registered emitter opens the gate'
        );
    }
}
