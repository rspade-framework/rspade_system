<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use LogicException;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Money\Php\Money_Fixture_Model;
use App\RSpade\Tests\Money\Php\Money_Fixture_Tables;
use App\RSpade\Tests\Money\Php\Money_Wide_Fixture_Model;

/**
 * An amount checked against ITS COLUMN: money_limit() is the largest amount a money column
 * holds, from the schema's DECIMAL precision and scale and never beyond Rsx_Money's own
 * maximum; money_from_submitted() and the money cast refuse an amount past it, either sign,
 * with Rsx_Money_Exception::column_range() - an input problem, before the database sees it.
 */
class Money_Column_Limit_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Money_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Money_Fixture_Tables::drop();
    }

    /** A call the column limit refuses: range, the column message, an input problem. */
    private static function __assert_column_refusal(callable $fn, string $label): void
    {
        try {
            $fn();
        } catch (Rsx_Money_Exception $e) {
            static::__assert_equals(Rsx_Money_Exception::REASON_RANGE, $e->reason, "{$label}: the reason");
            static::__assert_equals(Rsx_Money_Exception::MESSAGE_COLUMN_RANGE, $e->getMessage(), "{$label}: the message");
            static::__assert_true($e->is_input_problem(), "{$label}: an input problem");

            return;
        }

        static::__fail("{$label}: accepted");
    }

    public static function test_money_limit_is_the_column_maximum()
    {
        static::__assert_equals('9999999999999.99', Money_Fixture_Model::money_limit('amount')->to_decimal(), 'DECIMAL(15,2)');
        static::__assert_equals('99999999.99', Money_Fixture_Model::money_limit('price')->to_decimal(), 'DECIMAL(10,2)');
        static::__assert_equals('99999.99', Money_Wide_Fixture_Model::money_limit('rate')->to_decimal(), 'DECIMAL(8,3) at whole cents');
        static::__assert_true(
            Money_Fixture_Model::money_limit('amount') === Money_Fixture_Model::money_limit('amount'),
            'memoized: the same object'
        );
    }

    public static function test_a_column_wider_than_the_type_caps_at_the_type_maximum()
    {
        static::__assert_equals(
            (string) PHP_INT_MAX,
            Money_Wide_Fixture_Model::money_limit('wide')->to_cents_string(),
            'DECIMAL(19,2) answers Rsx_Money\'s maximum'
        );

        $most_negative = Rsx_Money::from_cents(PHP_INT_MIN);
        static::__assert_true(
            Money_Wide_Fixture_Model::money_from_submitted('wide', $most_negative)->equals($most_negative),
            'every Rsx_Money fits a column as wide as the type, the most negative included'
        );

        $record = new Money_Wide_Fixture_Model();
        $record->wide = Rsx_Money::from_cents(PHP_INT_MAX);
        static::__assert_equals('92233720368547758.07', $record->getAttributes()['wide'], 'the cast takes the type maximum');
    }

    public static function test_money_limit_of_a_column_that_is_not_money_throws()
    {
        static::__assert_throws(
            LogicException::class,
            fn () => Money_Fixture_Model::money_limit('hours'),
            "Money_Fixture_Model::hours is not a money column"
        );
        static::__assert_throws(
            LogicException::class,
            fn () => Money_Fixture_Model::money_from_submitted('name', '1.00'),
            "Money_Fixture_Model::name is not a money column"
        );
    }

    public static function test_the_door_accepts_the_limit_and_refuses_a_cent_past_it()
    {
        foreach (['9999999999999.99', '-9999999999999.99', '$9,999,999,999,999.99'] as $typed) {
            static::__assert_equals(
                ltrim(str_replace([',', '$'], '', $typed)),
                Money_Fixture_Model::money_from_submitted('amount', $typed)->to_decimal(),
                "'{$typed}' is within DECIMAL(15,2)"
            );
        }

        foreach (['10000000000000.00', '-10000000000000.00', '99999999999999', Rsx_Money::from_cents(PHP_INT_MIN)] as $value) {
            static::__assert_column_refusal(
                fn () => Money_Fixture_Model::money_from_submitted('amount', $value),
                'money_from_submitted ' . ($value instanceof Rsx_Money ? $value->to_decimal() : $value)
            );
        }

        static::__assert_null(Money_Fixture_Model::money_from_submitted('amount', '  '), 'blank is null');
        static::__assert_throws(
            Rsx_Money_Exception::class,
            fn () => Money_Fixture_Model::money_from_submitted('amount', '12.345'),
            Rsx_Money_Exception::MESSAGE_SCALE
        );
    }

    public static function test_the_cast_refuses_an_amount_beyond_the_column()
    {
        foreach (['100000000.00', '-100000000.00', 100000000, Rsx_Money::from_decimal('100000000'), ['__MONEY' => '100000000.00']] as $value) {
            static::__assert_column_refusal(function () use ($value) {
                $record = new Money_Fixture_Model();
                $record->price = $value;
            }, 'price = ' . json_encode($value));
        }

        $record = new Money_Fixture_Model();
        $record->price = '99999999.99';
        $record->amount = '-9999999999999.99';
        static::__assert_equals('99999999.99', $record->getAttributes()['price'], 'the limit is assignable');
        static::__assert_equals('-9999999999999.99', $record->getAttributes()['amount'], 'its negation is assignable');
    }
}
