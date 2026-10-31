<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * The day-and-clock helpers in a NAMED zone (rsx:man time_value_design): local_date(),
 * local_clock(), combine_date_and_time(), day_start_utc(), day_after_utc().
 *
 * The table below is the one playwright/zone_helpers.js asserts for the JS twins, so the two
 * languages answer alike - both daylight-saving days of 2026 in America/Chicago included.
 */
class Rsx_Time_Zone_Helpers_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const ZONE = 'America/Chicago';

    public static function test_an_evening_instant_is_the_local_day_not_the_utc_one()
    {
        static::__assert_equals('2026-10-26', Rsx_Time::local_date('2026-10-27T04:00:00Z', self::ZONE), '11 PM Monday in Chicago is Tuesday in UTC');
        static::__assert_equals('23:00', Rsx_Time::local_clock('2026-10-27T04:00:00Z', self::ZONE));
        static::__assert_null(Rsx_Time::local_date(null, self::ZONE));
    }

    public static function test_a_date_and_clock_combine_in_the_named_zone()
    {
        static::__assert_equals('2026-10-27T04:00:00.000Z', Rsx_Time::combine_date_and_time('2026-10-26', '23:00', self::ZONE));
        static::__assert_equals('2026-10-27T04:00:30.000Z', Rsx_Time::combine_date_and_time('2026-10-26', '23:00:30', self::ZONE));
        static::__assert_null(Rsx_Time::combine_date_and_time('2026-10-26', null, self::ZONE), 'no clock, no instant');
        static::__assert_null(Rsx_Time::combine_date_and_time('2026-10-26', '', self::ZONE));
    }

    public static function test_daylight_saving_days()
    {
        static::__assert_equals('2026-03-08T08:30:00.000Z', Rsx_Time::combine_date_and_time('2026-03-08', '02:30', self::ZONE), 'a skipped clock is the instant after the jump');
        static::__assert_equals('2026-03-08T15:00:00.000Z', Rsx_Time::combine_date_and_time('2026-03-08', '10:00', self::ZONE), 'after the jump, CDT');
        static::__assert_equals('2026-11-01T06:30:00.000Z', Rsx_Time::combine_date_and_time('2026-11-01', '01:30', self::ZONE), 'an ambiguous clock is its first occurrence');
        static::__assert_equals('2026-11-01T05:00:00.000Z', Rsx_Time::day_start_utc('2026-11-01', self::ZONE));
        static::__assert_equals('2026-11-02T06:00:00.000Z', Rsx_Time::day_after_utc('2026-11-01', self::ZONE), 'a 25-hour day');
    }

    public static function test_bad_input_is_refused()
    {
        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Time::combine_date_and_time('10/26/2026', '23:00', self::ZONE));
        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Time::combine_date_and_time('2026-10-26', '11pm', self::ZONE));
        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Time::local_date('2026-10-27T04:00:00Z', 'Mars/Olympus_Mons'));
    }
}
