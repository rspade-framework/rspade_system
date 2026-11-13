<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Revisions\Revision;
use App\RSpade\Lib\Revisions\Revision_Model;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Column_Fixture_Tables;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Site_Fixture_Model;

/**
 * A derived column never appears in revision history: not in a create's document, not in an
 * update's, and a save that changes only derived columns records no revision at all. It is
 * excluded through revision_excluded_columns(), which lists every derived column.
 */
class Derived_Column_Revision_Test extends Rsx_Test_Abstract
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

        return $record;
    }

    /**
     * Every revision recorded for the record, oldest first (a result set walks by primary key).
     *
     * @return array<int, Revision_Model>
     */
    private static function __revisions(Derived_Site_Fixture_Model $record): array
    {
        return iterator_to_array($record->revisions(), false);
    }

    // DERIVED-REV-EXCLUDED-LIST
    public static function test_every_derived_column_is_revision_excluded()
    {
        static::__assert_true(in_array('label', Derived_Site_Fixture_Model::revision_excluded_columns(), true));
    }

    // DERIVED-REV-CREATE
    public static function test_a_create_records_no_derived_column()
    {
        $record = static::__record();

        $revisions = static::__revisions($record);
        static::__assert_count(1, $revisions);
        $diff = $revisions[0]->diff();

        static::__assert_array_has_key('name', $diff, 'the source column is recorded');
        static::__assert_false(array_key_exists('label', $diff), 'the derived column is not');
        static::__assert_equals('ALPHA', $record->label, 'although it was written');
    }

    // DERIVED-REV-UPDATE
    public static function test_an_update_records_the_source_and_not_the_derived_column()
    {
        $record = static::__record();
        Revision::_reset_request_state('test', 'update');

        $record->name = 'beta';
        $record->save();

        $revisions = static::__revisions($record);
        static::__assert_count(2, $revisions);
        $diff = $revisions[1]->diff();

        static::__assert_equals(['alpha', 'beta'], $diff['name']);
        static::__assert_false(array_key_exists('label', $diff), 'the re-derived label is not recorded');
        static::__assert_equals('BETA', $record->label);
    }

    // DERIVED-REV-DERIVED-ONLY: a save changing only derived columns records nothing.
    public static function test_a_derived_only_save_records_no_revision()
    {
        $record = static::__record();
        DB::update('UPDATE derived_site_fixtures SET label = ? WHERE id = ?', ['STALE', $record->id]);
        $record->refresh();
        Revision::_reset_request_state('test', 'rederive');

        static::__assert_true($record->rederive(), 'the stale label was rewritten');
        static::__assert_count(1, static::__revisions($record), 'and no revision row was added for it');
    }
}
