<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Realtime\Realtime_Emitter_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Emitter_Fixture_Service;

/**
 * #[Emitter] discovery: the manifest attribute scan finds the fixture emitter without
 * any registration. Pure reflection over the manifest — no DB, no Redis.
 */
class Realtime_Emitter_Discovery_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_has_emitters_true()
    {
        Realtime_Emitter_Service::_testing_reset();

        static::__assert_true(
            Realtime_Emitter_Service::has_emitters(),
            'the fixture #[Emitter] makes has_emitters() true'
        );
    }

    public static function test_registered_list_contains_fixture_with_correct_topic()
    {
        Realtime_Emitter_Service::_testing_reset();

        $match = null;
        foreach (Realtime_Emitter_Service::registered_emitters() as $emitter) {
            if ($emitter['class'] === Realtime_Emitter_Fixture_Service::class
                && $emitter['method'] === 'fixture_value') {
                $match = $emitter;
                break;
            }
        }

        static::__assert_not_empty($match, 'the fixture emitter is discovered');
        static::__assert_equals('Realtime_Test_Public_Topic', $match['topic'], 'its declared topic is captured');
    }

    public static function test_emitter_topics_includes_the_fixture_topic()
    {
        Realtime_Emitter_Service::_testing_reset();

        static::__assert_true(
            in_array('Realtime_Test_Public_Topic', Realtime_Emitter_Service::emitter_topics(), true),
            'emitter_topics() lists the fixture topic'
        );
    }
}
