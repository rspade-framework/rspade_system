<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use LogicException;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Column_Fixture_Tables;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Site_Fixture_Model;

/**
 * Form_Edit never writes a derived column: set() of one throws - whether the form names the
 * column itself or maps a field onto it, and whether or not the submission changed it - while
 * a write of its source column derives it through the record's own save().
 */
class Derived_Column_Form_Edit_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Derived_Column_Fixture_Tables::create();
    }

    public static function teardown()
    {
        Derived_Column_Fixture_Tables::drop();
    }

    private static function __record(): Derived_Site_Fixture_Model
    {
        static::__acting_as_user(1);

        $record = new Derived_Site_Fixture_Model();
        $record->name = 'alpha';
        $record->save();

        return Derived_Site_Fixture_Model::find($record->id);
    }

    // DERIVED-FORM-SET-REFUSED
    public static function test_set_of_a_derived_column_throws()
    {
        static::__acting_as_user(1);
        $record = new Derived_Site_Fixture_Model();

        static::__assert_throws(
            LogicException::class,
            fn () => Form_Edit::run($record, ['_changed' => []], fn (Form_Edit $edit) => $edit->set('label', 'HAND')),
            "Form_Edit::set('label'): Derived_Site_Fixture_Model::label is a derived column (derive_label(), from [name]); a form never writes it."
        );
        static::__assert_false($record->exists, 'nothing was written');
    }

    // DERIVED-FORM-SET-MAPPED: a field mapped onto the derived column is refused the same way.
    public static function test_set_of_a_field_mapped_onto_a_derived_column_throws()
    {
        static::__acting_as_user(1);
        $record = new Derived_Site_Fixture_Model();

        static::__assert_throws(
            LogicException::class,
            fn () => Form_Edit::run(
                $record,
                ['_changed' => []],
                fn (Form_Edit $edit) => $edit->set('shown_label', 'HAND'),
                null,
                [],
                ['label' => 'shown_label']
            ),
            "Form_Edit::set('shown_label'): Derived_Site_Fixture_Model::label is a derived column"
        );
    }

    // DERIVED-FORM-SET-UNCHANGED: refused before the changed() test - a form that sends the
    // field unchanged is still writing to it.
    public static function test_set_of_a_derived_column_the_submission_did_not_change_still_throws()
    {
        $record = static::__record();

        static::__assert_throws(
            LogicException::class,
            fn () => Form_Edit::run(
                $record,
                ['_changed' => ['note'], '_loaded_updated_at' => Form_Edit::version_of($record), 'note' => 'x'],
                fn (Form_Edit $edit) => $edit->set('label', 'HAND')
            ),
            'is a derived column'
        );
    }

    // DERIVED-FORM-SOURCE: writing the source column derives the column.
    public static function test_a_create_and_an_edit_of_the_source_derive_the_column()
    {
        static::__acting_as_user(1);
        $record = new Derived_Site_Fixture_Model();

        Form_Edit::run($record, ['_changed' => []], function (Form_Edit $edit) use ($record) {
            $edit->set('name', 'gamma');
            $record->save();

            return ['id' => $record->id];
        });

        static::__assert_equals('GAMMA', Derived_Column_Fixture_Tables::raw_row('derived_site_fixtures', (int) $record->id)['label']);

        $loaded = Derived_Site_Fixture_Model::find($record->id);
        Form_Edit::run(
            $loaded,
            ['_changed' => ['name'], '_loaded_updated_at' => Form_Edit::version_of($loaded), 'name' => 'delta'],
            function (Form_Edit $edit) use ($loaded) {
                $edit->set('name', 'delta');
                $loaded->save();

                return ['id' => $loaded->id];
            }
        );

        static::__assert_equals('DELTA', Derived_Column_Fixture_Tables::raw_row('derived_site_fixtures', (int) $record->id)['label']);
    }
}
