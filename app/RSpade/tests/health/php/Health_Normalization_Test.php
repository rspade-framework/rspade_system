<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Health\Php\Health_Fixture_Probe;

/**
 * Unit tests for Health_Check_Runner's return-contract normalization: single row vs list,
 * label inheritance + #n suffixing, invalid-shape / invalid-status -> FAIL-naming-offender,
 * and the throw-to-FAIL path (via run_one with a non-discovered probe class).
 *
 * Pure logic - no DB.
 */
class Health_Normalization_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_single_row_normalizes()
    {
        $rows = Health_Check_Runner::normalize_check_result(
            ['status' => 'OK', 'detail' => 'fine'],
            'My Label',
            'X::y'
        );

        static::__assert_count(1, $rows);
        static::__assert_equals('OK', $rows[0]['status']);
        static::__assert_equals('My Label', $rows[0]['label']);
        static::__assert_equals('fine', $rows[0]['detail']);
        static::__assert_null($rows[0]['remediation']);
    }

    public static function test_list_of_rows_normalizes()
    {
        $rows = Health_Check_Runner::normalize_check_result(
            [
                ['status' => 'OK', 'detail' => 'a'],
                ['status' => 'WARN', 'detail' => 'b', 'remediation' => 'do x'],
            ],
            'Multi',
            'X::y'
        );

        static::__assert_count(2, $rows);
        static::__assert_equals('WARN', $rows[1]['status']);
        static::__assert_equals('do x', $rows[1]['remediation']);
    }

    public static function test_missing_label_inherits_attribute_label_with_suffix()
    {
        $rows = Health_Check_Runner::normalize_check_result(
            [
                ['status' => 'OK', 'detail' => 'a'],
                ['status' => 'OK', 'detail' => 'b'],
                ['status' => 'INFO', 'detail' => 'c', 'label' => 'Explicit'],
            ],
            'Base',
            'X::y'
        );

        static::__assert_equals('Base', $rows[0]['label']);      // first row: no suffix
        static::__assert_equals('Base #2', $rows[1]['label']);   // second row: #2
        static::__assert_equals('Explicit', $rows[2]['label']);  // explicit per-row label wins
    }

    public static function test_invalid_status_becomes_fail_naming_offender()
    {
        $rows = Health_Check_Runner::normalize_check_result(
            ['status' => 'GREAT', 'detail' => 'x'],
            'Bad Check',
            'Foo::bar'
        );

        static::__assert_count(1, $rows);
        static::__assert_equals('FAIL', $rows[0]['status']);
        static::__assert_contains('Foo::bar', $rows[0]['detail'], 'FAIL row names the offending check');
    }

    public static function test_non_array_result_becomes_fail()
    {
        $rows = Health_Check_Runner::normalize_check_result('nope', 'Bad Check', 'Foo::bar');

        static::__assert_count(1, $rows);
        static::__assert_equals('FAIL', $rows[0]['status']);
        static::__assert_contains('Foo::bar', $rows[0]['detail']);
    }

    public static function test_associative_without_status_becomes_fail()
    {
        $rows = Health_Check_Runner::normalize_check_result(['detail' => 'no status here'], 'Bad', 'Foo::bar');

        static::__assert_count(1, $rows);
        static::__assert_equals('FAIL', $rows[0]['status']);
    }

    public static function test_throwing_check_becomes_fail_row()
    {
        $rows = Health_Check_Runner::run_one(Health_Fixture_Probe::class, 'boom', 'Boom Check');

        static::__assert_count(1, $rows);
        static::__assert_equals('FAIL', $rows[0]['status']);
        static::__assert_equals('Boom Check', $rows[0]['label']);
        static::__assert_contains('probe exploded', $rows[0]['detail'], 'FAIL row carries the exception message');
    }

    public static function test_run_one_happy_path()
    {
        $rows = Health_Check_Runner::run_one(Health_Fixture_Probe::class, 'ok_row', 'Probe');

        static::__assert_count(1, $rows);
        static::__assert_equals('OK', $rows[0]['status']);
    }
}
