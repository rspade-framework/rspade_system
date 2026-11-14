<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Money\Php\Money_Fixture_Model;
use App\RSpade\Tests\Money\Php\Money_Fixture_Tables;

/**
 * Form_Edit and money columns: a money field is kind 'money'; a submission setting the
 * amount another editor already saved - as an Rsx_Money or as the typed text - overwrites
 * nothing and is not asked about; and the conflict question shows every amount formatted
 * (the revision's stored strings and the live values alike).
 *
 * A save is "another editor" because the test saves the record directly between loading it
 * and submitting. Each save lets the clock pass a few milliseconds: updated_at is the
 * version, and two saves inside one millisecond would be one version.
 */
class Money_Form_Edit_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Money_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Money_Fixture_Tables::drop();
    }

    private static function __record(): Money_Fixture_Model
    {
        $record = new Money_Fixture_Model();
        $record->name = 'Original';
        $record->amount = '1234.50';
        $record->price = '1.00';
        $record->save();

        return Money_Fixture_Model::find($record->id);
    }

    /** Another editor's save of the amount, a clear millisecond after the last version. */
    private static function __other_save(int $id, string $amount): void
    {
        usleep(3000);
        $other = Money_Fixture_Model::find($id);
        $other->amount = $amount;
        $other->save();
    }

    /** One submission changing the amount (and nothing else) to $submitted. */
    private static function __submit(Money_Fixture_Model $record, string $loaded, $submitted)
    {
        usleep(3000);

        return Form_Edit::run(
            $record,
            ['amount' => $submitted, '_changed' => ['amount'], '_loaded_updated_at' => $loaded],
            function (Form_Edit $edit) use ($record, $submitted) {
                $edit->set('amount', Rsx_Money::from_submitted($submitted));
                $record->save();

                return ['id' => $record->id];
            }
        );
    }

    private static function __question($result): array
    {
        static::__assert_instance_of(Error_Response::class, $result, 'the submission is asked about');
        static::__assert_equals(Ajax::ERROR_QUESTION, $result->get_error_code());

        return $result->get_metadata()['question'];
    }

    // MONEY-FORM-SAME: the same amount, however it arrives, overwrites nothing.
    public static function test_the_amount_the_other_editor_saved_is_not_asked_about()
    {
        foreach ([Rsx_Money::from_decimal('1300'), '$1,300.00', '1300'] as $submitted) {
            $record = static::__record();
            $loaded = Form_Edit::version_of($record);

            static::__other_save($record->id, '1300.00');
            $result = static::__submit($record, $loaded, $submitted);

            static::__assert_true(is_array($result), 'no question for ' . json_encode($submitted));
            static::__assert_equals('1300.00', Money_Fixture_Model::find($record->id)->amount->to_decimal());
        }
    }

    // MONEY-FORM-CONFLICT: a different amount is asked about, kind money, every value formatted.
    public static function test_a_different_amount_asks_with_formatted_values()
    {
        $record = static::__record();
        $loaded = Form_Edit::version_of($record);

        static::__other_save($record->id, '1300.00');
        $question = static::__question(static::__submit($record, $loaded, Rsx_Money::from_decimal('-5')));

        static::__assert_count(1, $question['fields']);
        $field = $question['fields'][0];

        static::__assert_equals('amount', $field['field']);
        static::__assert_equals(Form_Edit::FIELD_MONEY, $field['kind']);
        static::__assert_equals('$1,234.50', $field['loaded'], 'what the form was opened with');
        static::__assert_equals('$1,300.00', $field['current'], 'the live value');
        static::__assert_equals('-$5.00', $field['yours'], 'the submitted value');
        static::__assert_equals('$1,234.50', $field['changes'][0]['from'], 'the revision\'s stored string, formatted');
        static::__assert_equals('$1,300.00', $field['changes'][0]['to']);

        static::__assert_equals('1300.00', Money_Fixture_Model::find($record->id)->amount->to_decimal(), 'nothing written before the answer');
    }

    // MONEY-FORM-TYPED: a submitted amount that is not an amount at all differs from anything
    // stored (assigning it would throw, and the endpoint reports that) and shows as typed.
    public static function test_an_unparseable_submission_differs_and_shows_as_typed()
    {
        $record = static::__record();
        $loaded = Form_Edit::version_of($record);

        static::__other_save($record->id, '1300.00');

        usleep(3000);
        $result = Form_Edit::run(
            $record,
            ['amount' => 'lots', '_changed' => ['amount'], '_loaded_updated_at' => $loaded],
            fn (Form_Edit $edit) => static::__fail('the write never runs before the question is answered')
        );

        static::__assert_equals('lots', static::__question($result)['fields'][0]['yours']);
    }
}
