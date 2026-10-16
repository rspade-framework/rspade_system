<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Tasks\Php;

use DateTime;
use DateTimeZone;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Cron_Parser reads an expression on a timezone's wall clock (the server timezone unless
 * one is passed) and follows cron's daylight-saving rule for a FIXED-TIME job: a wall
 * time a forward jump skips runs at the first minute after the jump, and a wall time a
 * backward jump repeats runs once, at its first occurrence. A job with an asterisk in the
 * minute or hour field runs on real elapsed time through both transitions.
 *
 * Every case uses America/Chicago in 2026: clocks go forward at 2:00am on March 8 and
 * back at 2:00am on November 1.
 */
class Cron_Parser_Timezone_Test extends Rsx_Test_Abstract
{
    // Pure computation - no database access.
    protected static $use_database_transactions = false;

    private const ZONE = 'America/Chicago';

    /** The timestamp of a wall time in Chicago (an ambiguous one resolves to its first occurrence). */
    private static function __at(string $wall): int
    {
        return (new DateTime($wall, new DateTimeZone(self::ZONE)))->getTimestamp();
    }

    /** A timestamp rendered on the Chicago wall clock with its abbreviation. */
    private static function __wall(int $timestamp): string
    {
        return (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d H:i T');
    }

    private static function __next(string $expression, int $from): string
    {
        return static::__wall((new Cron_Parser($expression, self::ZONE))->get_next_run_time($from));
    }

    public static function test_the_expression_is_read_on_the_zones_wall_clock()
    {
        static::__assert_equals('2026-06-02 02:00 CDT', static::__next('daily at 2am', static::__at('2026-06-01 03:00')));

        $utc = (new Cron_Parser('daily at 2am', 'UTC'))->get_next_run_time(static::__at('2026-06-01 03:00'));
        static::__assert_equals('2026-06-02 02:00', gmdate('Y-m-d H:i', $utc), 'the same expression in UTC is 2am UTC');
    }

    public static function test_the_zone_defaults_to_the_server_timezone()
    {
        $original = config('rsx.datetime.server_timezone');
        try {
            config(['rsx.datetime.server_timezone' => self::ZONE]);
            static::__assert_equals(self::ZONE, (new Cron_Parser('daily at 2am'))->get_timezone());

            config(['rsx.datetime.server_timezone' => '']);
            static::__assert_equals(self::ZONE, (new Cron_Parser('daily at 2am', self::ZONE))->get_timezone(), 'an explicit zone wins');
        } finally {
            config(['rsx.datetime.server_timezone' => $original]);
        }
    }

    public static function test_an_unknown_zone_throws()
    {
        static::__assert_throws(\Exception::class, function () {
            new Cron_Parser('daily at 2am', 'Mars/Olympus_Mons');
        });
    }

    public static function test_a_fixed_time_skipped_by_spring_forward_runs_right_after_the_jump()
    {
        static::__assert_equals('2026-03-08 03:00 CDT', static::__next('daily at 2am', static::__at('2026-03-07 03:00')));
        static::__assert_equals('2026-03-08 03:00 CDT', static::__next('30 2 * * *', static::__at('2026-03-07 03:00')));
        static::__assert_equals('2026-03-09 02:00 CDT', static::__next('daily at 2am', static::__at('2026-03-08 03:00')), 'the cadence resumes the next day');
    }

    public static function test_a_fixed_time_repeated_by_fall_back_runs_once()
    {
        $first = static::__at('2026-11-01 01:30');
        static::__assert_equals('2026-11-01 01:30 CDT', static::__next('30 1 * * *', static::__at('2026-10-31 03:00')), 'the first occurrence');
        static::__assert_equals('2026-11-02 01:30 CST', static::__next('30 1 * * *', $first), 'the repeat is skipped');
    }

    public static function test_a_wildcard_job_runs_on_real_elapsed_time()
    {
        // Three real minutes after 1:57am CDT is 1:00am CST, the start of the repeated hour.
        static::__assert_equals('2026-11-01 01:00 CST', static::__next('every 5 minutes', static::__at('2026-11-01 01:57')));

        // An hourly job crosses the forward jump without an extra run at 3:00am.
        static::__assert_equals('2026-03-08 03:00 CDT', static::__next('hourly', static::__at('2026-03-08 01:30')));
        static::__assert_equals('2026-03-08 04:00 CDT', static::__next('hourly', static::__at('2026-03-08 03:00')));
    }
}
