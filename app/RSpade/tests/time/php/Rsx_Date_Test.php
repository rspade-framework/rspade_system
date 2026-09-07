<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Date;

/**
 * Framework test for Rsx_Date - pure, deterministic calendar logic.
 *
 * A framework test: it lives under app/RSpade/, so it runs under
 * `php artisan rsx:test --framework`, not the default application suite.
 */
class Rsx_Date_Test extends Rsx_Test_Abstract
{
    // Pure calendar math - no database access needed.
    protected static $use_database_transactions = false;

    public static function test_format()
    {
        static::__assert_equals('Dec 24, 2025', Rsx_Date::format('2025-12-24'));
    }

    public static function test_add_days_crosses_boundaries()
    {
        static::__assert_equals('2026-01-01', Rsx_Date::add_days('2025-12-24', 8));
        static::__assert_equals('2025-11-30', Rsx_Date::add_days('2025-12-24', -24));
    }

    public static function test_diff_days_is_signed()
    {
        static::__assert_equals(7, Rsx_Date::diff_days('2025-12-24', '2025-12-31'));
        static::__assert_equals(-7, Rsx_Date::diff_days('2025-12-31', '2025-12-24'));
    }

    public static function test_month_boundaries()
    {
        static::__assert_equals('2025-12-01', Rsx_Date::start_of_month('2025-12-24'));
        static::__assert_equals('2025-12-31', Rsx_Date::end_of_month('2025-12-24'));
    }

    public static function test_week_runs_monday_to_sunday()
    {
        // Wednesday 2025-12-24 -> Monday the 22nd .. Sunday the 28th
        static::__assert_equals('2025-12-22', Rsx_Date::start_of_week('2025-12-24'));
        static::__assert_equals('2025-12-28', Rsx_Date::end_of_week('2025-12-24'));
    }

    public static function test_weekend_detection()
    {
        static::__assert_true(Rsx_Date::is_weekend('2025-12-27'));   // Saturday
        static::__assert_false(Rsx_Date::is_weekend('2025-12-24'));  // Wednesday
    }

    public static function test_component_extractors()
    {
        static::__assert_equals(24, Rsx_Date::day('2025-12-24'));
        static::__assert_equals(12, Rsx_Date::month('2025-12-24'));
        static::__assert_equals(2025, Rsx_Date::year('2025-12-24'));
        static::__assert_equals('Thursday', Rsx_Date::dow_human('2025-12-25'));
        static::__assert_equals('December', Rsx_Date::month_human('2025-12-24'));
    }

    public static function test_is_date_rejects_datetime()
    {
        static::__assert_true(Rsx_Date::is_date('2025-12-24'));
        static::__assert_false(Rsx_Date::is_date('2025-12-24T10:00:00Z'));
    }

    public static function test_parse_throws_on_datetime()
    {
        $e = static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => Rsx_Date::parse('2025-12-24T10:00:00Z'),
            'datetime'
        );
        static::__assert_contains('Rsx_Time::parse()', $e->getMessage());
    }
}
