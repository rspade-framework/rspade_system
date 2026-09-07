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
 * Framework test for Rsx_Time - signed duration math.
 *
 * A framework test: it lives under app/RSpade/, so it runs under
 * `php artisan rsx:test --framework`, not the default application suite.
 *
 * Regression coverage for the diff_seconds() sign bug: diff_seconds($start, $end)
 * must return signed seconds FROM $start TO $end (positive when $end is later).
 */
class Rsx_Time_Test extends Rsx_Test_Abstract
{
    // Pure time math - no database access needed.
    protected static $use_database_transactions = false;

    // Tolerance (seconds) for cases anchored to now(): the reference instant and
    // the now() call inside the method are not the exact same moment.
    const TOLERANCE_SECONDS = 5;

    public static function test_diff_seconds_is_signed_fixed()
    {
        // Fully deterministic - directly encodes the bug report scenario.
        static::__assert_equals(
            7200,
            Rsx_Time::diff_seconds('2026-01-01T00:00:00Z', '2026-01-01T02:00:00Z')
        );
        static::__assert_equals(
            -7200,
            Rsx_Time::diff_seconds('2026-01-01T02:00:00Z', '2026-01-01T00:00:00Z')
        );
    }

    /**
     * Carbon 3's diffInSeconds() returns a float; a fractional gap used to be narrowed
     * IMPLICITLY through the `: int` return type ("Implicit conversion from float
     * 3984.950448 to int loses precision" - a TypeError in PHP 9). The cast is now
     * explicit and truncates toward zero, and no deprecation may be raised.
     */
    public static function test_diff_seconds_truncates_a_fractional_gap_without_a_deprecation()
    {
        $start = \Carbon\Carbon::parse('2026-08-31 10:00:00', 'UTC');
        $end = $start->copy()->addSeconds(3984)->addMicroseconds(950448);

        $raised = [];
        set_error_handler(function ($errno, $errstr) use (&$raised) {
            $raised[] = $errstr;
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $forward = Rsx_Time::diff_seconds($start, $end);
            $backward = Rsx_Time::diff_seconds($end, $start);
        } finally {
            restore_error_handler();
        }

        static::__assert_equals(3984, $forward, 'truncates toward zero, never rounds up');
        static::__assert_equals(-3984, $backward, 'truncation toward zero is symmetric across the sign');
        static::__assert_equals([], $raised, 'no deprecation is raised: ' . implode('; ', $raised));
    }

    public static function test_diff_seconds_antisymmetry()
    {
        $a = '2026-03-15T08:30:00Z';
        $b = '2026-03-15T09:45:30Z';

        static::__assert_equals(
            Rsx_Time::diff_seconds($a, $b),
            -Rsx_Time::diff_seconds($b, $a)
        );
    }

    public static function test_seconds_since_past_is_positive()
    {
        $past = Rsx_Time::subtract(Rsx_Time::now_iso(), 3600);

        static::__assert_equals_approx(3600, Rsx_Time::seconds_since($past), static::TOLERANCE_SECONDS);
    }

    public static function test_seconds_until_future_is_positive()
    {
        $future = Rsx_Time::add(Rsx_Time::now_iso(), 3600);

        static::__assert_equals_approx(3600, Rsx_Time::seconds_until($future), static::TOLERANCE_SECONDS);
    }

    public static function test_seconds_since_now_is_zero()
    {
        static::__assert_equals_approx(0, Rsx_Time::seconds_since(Rsx_Time::now_iso()), static::TOLERANCE_SECONDS);
    }
}
