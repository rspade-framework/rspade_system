<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Money\Php\Money_Fixture_Tables;

/**
 * The Ajax boundary and money: a {__MONEY} envelope in the params, at any depth, reaches the
 * endpoint as an Rsx_Money (the browser's toJSON() writes one); a malformed envelope fails
 * the call; an array without __MONEY is untouched; and a returned value or record serializes
 * as the envelope. Ajax::internal() is the path a batched call takes, the same execute() the
 * direct browser call runs.
 */
class Money_Ajax_Hydration_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        Money_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Money_Fixture_Tables::drop();
    }

    // MONEY-AJAX-IN
    public static function test_an_envelope_param_arrives_as_rsx_money()
    {
        $result = Ajax::internal('Money_Ajax_Fixture_Controller', 'describe_params', [
            'amount' => ['__MONEY' => '1234.50'],
        ]);

        static::__assert_equals(['type' => 'Rsx_Money', 'decimal' => '1234.50'], $result['amount']);
    }

    // MONEY-AJAX-NESTED
    public static function test_a_nested_envelope_arrives_as_rsx_money()
    {
        $result = Ajax::internal('Money_Ajax_Fixture_Controller', 'describe_params', [
            'lines' => [
                ['price' => ['__MONEY' => '1.00']],
                ['price' => ['__MONEY' => '-0.01']],
                ['price' => ['value' => '1.00']],
            ],
        ]);

        static::__assert_equals([
            ['type' => 'Rsx_Money', 'decimal' => '1.00'],
            ['type' => 'Rsx_Money', 'decimal' => '-0.01'],
            ['type' => 'array', 'decimal' => null],
        ], $result['lines'], 'every depth; an array without __MONEY is untouched');
    }

    // MONEY-AJAX-PLAIN
    public static function test_a_typed_string_is_untouched()
    {
        $result = Ajax::internal('Money_Ajax_Fixture_Controller', 'describe_params', ['amount' => '$1,234.50']);

        static::__assert_equals('string', $result['amount']['type'], 'the endpoint reads it with from_submitted()');
    }

    // MONEY-AJAX-MALFORMED: only toJSON() writes an envelope, so a malformed one is a client
    // bug and fails the call.
    public static function test_a_malformed_envelope_fails_the_call()
    {
        foreach ([
            ['__MONEY' => '12.3'],
            ['__MONEY' => '12.30', 'extra' => 1],
            ['__MONEY' => 12.3],
            ['__MONEY' => null],
            ['__MONEY' => '12.30', '__TEXT' => 'Some_Text', 'raw' => 'x'],
        ] as $envelope) {
            $e = static::__assert_throws(
                Rsx_Money_Exception::class,
                fn () => Ajax::internal('Money_Ajax_Fixture_Controller', 'describe_params', ['amount' => $envelope]),
                'Rsx_Money::from_'
            );
            static::__assert_equals(Rsx_Money_Exception::REASON_ARGUMENT, $e->reason, json_encode($envelope));
        }
    }

    // MONEY-AJAX-OUT
    public static function test_a_returned_value_and_record_serialize_as_the_envelope()
    {
        $result = Ajax::internal('Money_Ajax_Fixture_Controller', 'answer_money', []);

        static::__assert_equals(['__MONEY' => '-0.05'], $result['value']);
        static::__assert_equals(['__MONEY' => '1234.56'], $result['record']['amount']);
        static::__assert_equals(['__MONEY' => '0.00'], $result['record']['price']);
        static::__assert_equals('1.00', Rsx_Money::from_envelope(['__MONEY' => '1.00'])->to_decimal(), 'the envelope reads back');
    }
}
