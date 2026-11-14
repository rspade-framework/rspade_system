<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Cast;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Money\Php\Money_Fixture_Model;
use App\RSpade\Tests\Money\Php\Money_Fixture_Tables;

/**
 * The money column kind on a model: a column declared ['money' => true] reads as an
 * Rsx_Money, accepts an Rsx_Money / a strict decimal string / an int of whole units / the
 * envelope array, refuses a float and anything else, stores the canonical two-place string
 * (so a no-op save is not dirty and records no revision), serializes as the {__MONEY}
 * envelope, and combines with never_export and derived.
 */
class Money_Cast_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Money_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Money_Fixture_Tables::drop();
    }

    private static function __saved(array $values = []): Money_Fixture_Model
    {
        $record = new Money_Fixture_Model();
        $record->name = 'fixture';
        $record->price = '1.00';
        foreach ($values as $column => $value) {
            $record->{$column} = $value;
        }
        $record->save();

        return Money_Fixture_Model::find($record->id);
    }

    /** What the cast handed the attribute bag: the string the column will store. */
    private static function __stored_form(string $column, $value): ?string
    {
        $record = new Money_Fixture_Model();
        $record->{$column} = $value;

        return $record->getAttributes()[$column];
    }

    /**
     * An assignment the cast refuses: the exception, its reason, and (when given) its message.
     */
    private static function __assert_refused($value, string $reason, ?string $message = null): void
    {
        try {
            $record = new Money_Fixture_Model();
            $record->amount = $value;
        } catch (Rsx_Money_Exception $e) {
            static::__assert_equals($reason, $e->reason, 'the refusal reason for ' . get_debug_type($value));
            if ($message !== null) {
                static::__assert_equals($message, $e->getMessage());
            }

            return;
        }

        static::__fail('the cast accepted ' . get_debug_type($value) . ' ' . json_encode($value));
    }

    // MONEY-CAST-CASTS
    public static function test_a_money_column_carries_the_money_cast()
    {
        static::__assert_equals(Rsx_Money_Cast::class, (new Money_Fixture_Model())->getCasts()['amount']);
        static::__assert_equals(['amount', 'price', 'hidden', 'doubled'], Money_Fixture_Model::money_columns());
        static::__assert_true(Money_Fixture_Model::is_money_column('price'));
        static::__assert_false(Money_Fixture_Model::is_money_column('hours'), 'a DECIMAL declared [] is not money');
        static::__assert_false(isset((new Money_Fixture_Model())->getCasts()['hours']), 'and carries no cast');
    }

    // MONEY-CAST-READ
    public static function test_a_stored_value_reads_as_money_and_null_as_null()
    {
        $record = static::__saved(['amount' => '1234.5']);

        static::__assert_instance_of(Rsx_Money::class, $record->amount);
        static::__assert_equals('1234.50', $record->amount->to_decimal());
        static::__assert_equals('1.00', $record->price->to_decimal(), 'the NOT NULL column');
        static::__assert_null($record->hidden, 'a NULL column reads null, never zero');
    }

    // MONEY-CAST-ASSIGN: every accepted form is stored as the canonical two-place string.
    public static function test_each_accepted_form_is_stored_canonically()
    {
        static::__assert_equals('12.34', static::__stored_form('amount', Rsx_Money::from_decimal('12.34')), 'an Rsx_Money');
        static::__assert_equals('29.99', static::__stored_form('amount', '29.99'), 'a decimal string');
        static::__assert_equals('1.50', static::__stored_form('amount', '1.500'), 'trailing zeros carry nothing');
        static::__assert_equals('250000.00', static::__stored_form('amount', '250000'), 'a whole-number string');
        static::__assert_equals('5.00', static::__stored_form('amount', 5), 'an int is whole units');
        static::__assert_equals('7.25', static::__stored_form('amount', ['__MONEY' => '7.25']), 'the envelope array');
        static::__assert_equals('-0.01', static::__stored_form('amount', '-0.01'));
        static::__assert_null(static::__stored_form('amount', null), 'null');

        $record = new Money_Fixture_Model();
        $record->amount = '3';
        static::__assert_instance_of(Rsx_Money::class, $record->amount, 'an assigned string reads back as an Rsx_Money');
        static::__assert_equals('3.00', $record->amount->to_decimal());
    }

    // MONEY-CAST-REFUSE
    public static function test_a_float_and_other_values_are_refused()
    {
        static::__assert_refused(1.00, Rsx_Money_Exception::REASON_ARGUMENT,
            "Money_Fixture_Model::amount is a money column; a float is never assigned to it. Assign Rsx_Money::from_decimal('1.00') or the decimal string.");
        static::__assert_refused(true, Rsx_Money_Exception::REASON_ARGUMENT);
        static::__assert_refused(new \stdClass(), Rsx_Money_Exception::REASON_ARGUMENT);
        static::__assert_refused(['1.00'], Rsx_Money_Exception::REASON_ARGUMENT);
        static::__assert_refused('$5', Rsx_Money_Exception::REASON_FORMAT, Rsx_Money_Exception::MESSAGE_FORMAT);
        static::__assert_refused('1,234.00', Rsx_Money_Exception::REASON_FORMAT);
        static::__assert_refused(' 5.00', Rsx_Money_Exception::REASON_FORMAT);
        static::__assert_refused('1.505', Rsx_Money_Exception::REASON_SCALE, Rsx_Money_Exception::MESSAGE_SCALE);
        static::__assert_refused(['__MONEY' => '1.5'], Rsx_Money_Exception::REASON_ARGUMENT);
        static::__assert_refused(['__MONEY' => '1.50', 'x' => 1], Rsx_Money_Exception::REASON_ARGUMENT);
    }

    // MONEY-CAST-ROUND-TRIP: what is stored is exactly the canonical string, both through the
    // model and in the table.
    public static function test_a_save_and_reread_is_byte_identical()
    {
        $record = static::__saved(['amount' => Rsx_Money::from_decimal('1234567890123.45')]);

        static::__assert_equals('1234567890123.45', Money_Fixture_Tables::raw_row((int) $record->id)['amount']);
        static::__assert_equals('1234567890123.45', $record->amount->to_decimal());
        static::__assert_equals('1234567890123.45', $record->getRawOriginal('amount'));
    }

    // MONEY-CAST-NOOP: re-assigning the same amount in any form is not a change.
    public static function test_a_no_op_resave_is_not_dirty_and_records_no_revision()
    {
        $record = static::__saved(['amount' => '1234.50']);
        $revisions = $record->revisions()->count();

        foreach ([Rsx_Money::from_decimal('1234.5'), '1234.500', '1234.50', ['__MONEY' => '1234.50']] as $same) {
            $record->amount = $same;
            static::__assert_false($record->isDirty('amount'), 'the same amount as ' . json_encode($same) . ' is not dirty');
        }

        $record->price = 1;
        static::__assert_false($record->isDirty('price'), 'int 1 is the stored 1.00');

        $record->save();

        static::__assert_equals($revisions, $record->revisions()->count(), 'no revision for a no-op save');
    }

    // MONEY-CAST-REVISION: a change records the canonical strings.
    public static function test_a_revision_records_the_canonical_strings()
    {
        $record = static::__saved(['amount' => '1234.5']);

        $record->amount = Rsx_Money::from_decimal('1300');
        $record->save();

        $latest = $record->revisions()->first();
        static::__assert_equals(['1234.50', '1300.00'], $latest->diff()['amount']);
    }

    // MONEY-CAST-TOARRAY
    public static function test_to_array_carries_the_envelope_and_never_export_removes_it()
    {
        $record = static::__saved(['amount' => '99.99', 'hidden' => '5.00']);
        $array = $record->toArray();

        static::__assert_equals(['__MONEY' => '99.99'], $array['amount']);
        static::__assert_equals(['__MONEY' => '1.00'], $array['price']);
        static::__assert_false(array_key_exists('hidden', $array), 'a never-exported money column never leaves the server');

        $empty = static::__saved();
        static::__assert_null($empty->toArray()['amount'], 'a null amount is JSON null, never an envelope');

        static::__assert_equals('{"__MONEY":"99.99"}', json_encode($record->amount));
    }

    // MONEY-CAST-DERIVED: a derived money column is assigned through the cast.
    public static function test_a_derived_money_column_is_assigned_through_the_cast()
    {
        $record = static::__saved(['amount' => '10.25']);

        static::__assert_equals('20.50', Money_Fixture_Tables::raw_row((int) $record->id)['doubled']);
        static::__assert_equals('20.50', $record->doubled->to_decimal());

        $record->amount = '0.01';
        $record->save();

        static::__assert_equals('0.02', Money_Fixture_Tables::raw_row((int) $record->id)['doubled']);
    }
}
