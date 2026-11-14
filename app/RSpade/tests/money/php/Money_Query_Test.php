<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use Illuminate\Support\Facades\DB;
use LogicException;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Money\Php\Money_Fixture_Model;
use App\RSpade\Tests\Money\Php\Money_Fixture_Tables;
use App\RSpade\Tests\Money\Php\Money_Wide_Fixture_Model;

/**
 * An Rsx_Money in a query: every where-family value and every raw update value binds as its
 * canonical decimal string, and sum_money() totals a money column as an Rsx_Money (zero over
 * no rows, a throw past the range).
 */
class Money_Query_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Money_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Money_Fixture_Tables::drop();
    }

    /** Three rows: 10.00, 20.50, 30.25 under the names a, b, c. */
    private static function __rows(): void
    {
        foreach (['a' => '10.00', 'b' => '20.50', 'c' => '30.25'] as $name => $amount) {
            $record = new Money_Fixture_Model();
            $record->name = $name;
            $record->amount = $amount;
            $record->price = '1.00';
            $record->save();
        }
    }

    private static function __names($query): array
    {
        return $query->orderBy('name')->pluck('name')->all();
    }

    // MONEY-QUERY-WHERE
    public static function test_where_compares_an_rsx_money_by_its_amount()
    {
        static::__rows();
        $twenty = Rsx_Money::from_decimal('20.5');

        static::__assert_equals(['b'], static::__names(Money_Fixture_Model::where('amount', $twenty)), 'the 2-argument form');
        static::__assert_equals(['c'], static::__names(Money_Fixture_Model::where('amount', '>', $twenty)), 'the 3-argument form');
        static::__assert_equals(['b'], static::__names(Money_Fixture_Model::where(['amount' => $twenty])), 'the array form');
        static::__assert_equals(['a', 'b'], static::__names(Money_Fixture_Model::where('amount', '<', Rsx_Money::from_decimal('30'))
            ->orWhere('amount', $twenty)), 'orWhere');
        static::__assert_equals(['a', 'c'], static::__names(Money_Fixture_Model::whereNot('amount', $twenty)), 'whereNot');
    }

    // MONEY-QUERY-IN-BETWEEN
    public static function test_where_in_and_where_between_take_rsx_money_values()
    {
        static::__rows();

        static::__assert_equals(['a', 'c'], static::__names(Money_Fixture_Model::whereIn('amount', [
            Rsx_Money::from_decimal('10'), Rsx_Money::from_decimal('30.25'),
        ])));
        static::__assert_equals(['b'], static::__names(Money_Fixture_Model::whereNotIn('amount', [
            Rsx_Money::from_decimal('10'), Rsx_Money::from_decimal('30.25'),
        ])));
        static::__assert_equals(['a', 'b'], static::__names(Money_Fixture_Model::whereBetween('amount', [
            Rsx_Money::from_decimal('10'), Rsx_Money::from_decimal('20.50'),
        ])), 'inclusive bounds');
        static::__assert_equals(['a', 'b', 'c'], static::__names(Money_Fixture_Model::where('name', 'a')->orWhereBetween('amount', [
            Rsx_Money::from_decimal('20'), Rsx_Money::from_decimal('31'),
        ])), 'orWhereBetween');
    }

    // MONEY-QUERY-NOT-BETWEEN
    public static function test_where_not_between_takes_rsx_money_values()
    {
        static::__rows();

        static::__assert_equals(['c'], static::__names(Money_Fixture_Model::whereNotBetween('amount', [
            Rsx_Money::from_decimal('10'), Rsx_Money::from_decimal('20.50'),
        ])), 'inclusive bounds are excluded');
        static::__assert_equals(['a', 'b'], static::__names(Money_Fixture_Model::where('name', 'b')->orWhereNotBetween('amount', [
            Rsx_Money::from_decimal('20'), Rsx_Money::from_decimal('31'),
        ])), 'orWhereNotBetween');
    }

    // MONEY-QUERY-RAW-UPDATE: the raw statement goes through no cast, and still binds the amount.
    public static function test_a_raw_bulk_update_writes_the_decimal_string()
    {
        static::__rows();

        $count = Money_Fixture_Model::where('name', 'b')->raw_bulk()->update(['amount' => Rsx_Money::from_decimal('99.9')]);

        static::__assert_equals(1, $count);
        static::__assert_equals('99.90', Money_Fixture_Model::where('name', 'b')->first()->amount->to_decimal());
    }

    // MONEY-QUERY-SUM
    public static function test_sum_money_totals_the_column_as_rsx_money()
    {
        static::__rows();

        $total = Money_Fixture_Model::query()->sum_money('amount');
        static::__assert_instance_of(Rsx_Money::class, $total);
        static::__assert_equals('60.75', $total->to_decimal());

        static::__assert_equals('30.50', Money_Fixture_Model::whereIn('name', ['a', 'b'])->sum_money('amount')->to_decimal(), 'over the query\'s rows');
    }

    public static function test_sum_money_over_no_rows_is_zero()
    {
        $total = Money_Fixture_Model::where('name', 'nobody')->sum_money('amount');

        static::__assert_instance_of(Rsx_Money::class, $total);
        static::__assert_true($total->is_zero(), 'an empty set totals 0.00, never null');
    }

    public static function test_sum_money_past_the_range_throws()
    {
        // wide is DECIMAL(19,2): each row holds an amount Rsx_Money can hold, and MySQL totals
        // the two exactly, but the total is past the signed 64-bit range of cents.
        foreach (['x', 'y'] as $name) {
            DB::insert('INSERT INTO ' . Money_Fixture_Tables::TABLE . ' (name, wide, price) VALUES (?, ?, 0)', [$name, '90000000000000000.00']);
        }

        static::__assert_throws(
            Rsx_Money_Exception::class,
            fn () => Money_Wide_Fixture_Model::query()->sum_money('wide'),
            Rsx_Money_Exception::MESSAGE_RANGE
        );
    }

    public static function test_sum_money_of_a_column_that_is_not_money_throws()
    {
        static::__assert_throws(
            LogicException::class,
            fn () => Money_Fixture_Model::query()->sum_money('hours'),
            'Money_Fixture_Model::hours is not a money column'
        );
    }
}
