<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Helpers\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * bytes_to_human() - the one byte formatter.
 *
 * Pins the CURRENT behavior, boundary included: a unit is climbed while the value EXCEEDS
 * 1024, so exactly 1024 bytes prints "1024 B" and exactly 1 MiB prints "1024 KB".
 */
class Bytes_To_Human_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_units_climb_while_the_value_exceeds_1024()
    {
        static::__assert_equals('0 B', bytes_to_human(0));
        static::__assert_equals('1023 B', bytes_to_human(1023));
        static::__assert_equals('1024 B', bytes_to_human(1024));
        static::__assert_equals('1 KB', bytes_to_human(1025));
        static::__assert_equals('1024 KB', bytes_to_human(1048576));
        static::__assert_equals('1 MB', bytes_to_human(1048577));
    }

    public static function test_the_ladder_continues_to_gb_and_stops_at_pb()
    {
        static::__assert_equals('5 GB', bytes_to_human(5 * 1024 ** 3));
        static::__assert_equals('3072 PB', bytes_to_human(3 * 1024 ** 6));
    }

    public static function test_precision_rounds_with_a_default_of_two()
    {
        static::__assert_equals('1.55 KB', bytes_to_human(1587));
        static::__assert_equals('1.5 KB', bytes_to_human(1587, 1));
    }

    public static function test_numeric_strings_format_and_anything_else_is_dashes()
    {
        static::__assert_equals('2 KB', bytes_to_human('2048'));
        static::__assert_equals('---', bytes_to_human('abc'));
        static::__assert_equals('---', bytes_to_human(null));
    }
}
