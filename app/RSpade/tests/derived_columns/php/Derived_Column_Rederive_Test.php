<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Emissions;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Category_Fixture_Model;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Column_Fixture_Tables;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Lookup_Fixture_Model;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Plain_Fixture_Model;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Site_Fixture_Model;

/**
 * rederive() recomputes every derived column from the record as stored and saves only when a
 * value differs; that save is DERIVED-ONLY - updated_at and the updated_by pair stay byte for
 * byte what they were (asserted with plain SQL, since MySQL's ON UPDATE CURRENT_TIMESTAMP
 * would otherwise move updated_at), while realtime and after_update() still fire.
 * _derived_drift() reports the same differences without saving or dirtying anything.
 */
class Derived_Column_Rederive_Test extends Rsx_Test_Abstract
{
    private const OLD_STAMP = '2020-01-02 03:04:05.678';

    public static function setup()
    {
        Derived_Column_Fixture_Tables::create();
        config(['rsx.realtime.enabled' => true]);
    }

    public static function teardown()
    {
        config(['rsx.realtime.enabled' => false]);
        Realtime_Emissions::_testing_reset();
        Model_Lifecycle_Emissions::_testing_reset();
        Derived_Column_Fixture_Tables::drop();
    }

    private static function __person(): Derived_Plain_Fixture_Model
    {
        $record = new Derived_Plain_Fixture_Model();
        $record->first_name = 'Ada';
        $record->last_name = 'Lovelace';
        $record->save();

        return $record;
    }

    /**
     * Put a wrong value in a derived column and pin the audit columns to known bytes, behind
     * the model's back, then reload the record.
     */
    private static function __corrupt(string $table, Rsx_Model_Abstract $record, string $column, string $wrong): void
    {
        DB::update(
            "UPDATE {$table} SET {$column} = ?, updated_at = ?, updated_by_id = 4242, updated_by_type = 4343 WHERE id = ?",
            [$wrong, self::OLD_STAMP, $record->id]
        );

        $record->refresh();
    }

    // DERIVED-REDERIVE-NOOP
    public static function test_a_record_whose_values_are_right_is_not_saved()
    {
        $record = static::__person();

        static::__assert_false($record->rederive(), 'nothing differed, so nothing was saved');
        static::__assert_false($record->isDirty(), 'and the record is left clean');
    }

    // DERIVED-REDERIVE-SAVES
    public static function test_a_stale_value_is_recomputed_and_saved()
    {
        $record = static::__person();
        static::__corrupt('derived_plain_fixtures', $record, 'full_name', 'Stale');

        static::__assert_true($record->rederive(), 'a value differed, so it saved');
        static::__assert_equals(
            'Ada Lovelace',
            Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id)['full_name']
        );
        static::__assert_false($record->rederive(), 'and a second call finds nothing to do');
    }

    // DERIVED-ONLY-AUDIT: a derived-only save leaves updated_at and the updated_by pair untouched.
    public static function test_a_derived_only_save_leaves_updated_at_and_updated_by_alone()
    {
        static::__acting_as_user(1);

        $record = static::__person();
        static::__corrupt('derived_plain_fixtures', $record, 'full_name', 'Stale');
        $before = Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id);

        static::__assert_true($record->rederive());

        $after = Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id);
        static::__assert_equals('Ada Lovelace', $after['full_name'], 'the derived column was written');
        static::__assert_true($before['updated_at'] === $after['updated_at'], 'updated_at is byte-identical: ' . $before['updated_at'] . ' vs ' . $after['updated_at']);
        static::__assert_true($before['updated_by_id'] === $after['updated_by_id'], 'updated_by_id is byte-identical');
        static::__assert_true($before['updated_by_type'] === $after['updated_by_type'], 'updated_by_type is byte-identical');
        static::__assert_true($record->timestamps, 'Eloquent timestamps are back on for the next save');

        // The control: an ordinary save of the same record DOES move both.
        $record->note = 'edited';
        $record->save();

        $edited = Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id);
        static::__assert_not_equals($before['updated_at'], $edited['updated_at'], 'an ordinary save moves updated_at');
        static::__assert_not_equals($before['updated_by_id'], $edited['updated_by_id'], 'and stamps the updater');
    }

    // DERIVED-ONLY-EFFECTS: realtime and after_update() still fire on a derived-only save.
    public static function test_a_derived_only_save_still_queues_realtime_and_after_update()
    {
        static::__acting_as_user(1);

        $record = new Derived_Site_Fixture_Model();
        $record->name = 'alpha';
        $record->save();
        static::__corrupt('derived_site_fixtures', $record, 'label', 'STALE');

        Realtime_Emissions::_testing_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        static::__assert_true($record->rederive());

        $pending = Realtime_Emissions::_testing_pending();
        static::__assert_count(1, $pending, 'the derived-only save queued a realtime frame');
        static::__assert_equals('Derived_Site_Fixture_Model', $pending[0]['model']);
        static::__assert_equals((int) $record->id, $pending[0]['id']);

        $lifecycle = Model_Lifecycle_Emissions::_testing_pending();
        static::__assert_count(1, $lifecycle, 'and queued after_update()');
        static::__assert_equals(['label'], $lifecycle[0]['changed'], 'naming the derived column it changed');
    }

    // DERIVED-REDERIVE-OTHER-TABLE: the case rederive() exists for.
    public static function test_a_derivation_from_another_table_follows_it_on_rederive()
    {
        $category = new Derived_Category_Fixture_Model();
        $category->name = 'Hardware';
        $category->save();

        $record = new Derived_Lookup_Fixture_Model();
        $record->category_id = $category->id;
        $record->save();
        static::__assert_equals('Hardware', $record->category_name);

        $category->name = 'Tools';
        $category->save();

        static::__assert_equals('Hardware', $record->refresh()->category_name, 'a change elsewhere moves nothing here');
        static::__assert_equals(['category_name' => ['Hardware', 'Tools']], $record->_derived_drift(), 'the drift names it');
        static::__assert_true($record->rederive());
        static::__assert_equals(
            'Tools',
            Derived_Column_Fixture_Tables::raw_row('derived_lookup_fixtures', (int) $record->id)['category_name']
        );
    }

    // DERIVED-REDERIVE-UNSAVED
    public static function test_rederive_of_an_unsaved_record_throws()
    {
        $record = new Derived_Plain_Fixture_Model();
        $record->first_name = 'Ada';

        static::__assert_throws(RuntimeException::class, fn () => $record->rederive(), 'needs a saved record');
    }

    // DERIVED-REDERIVE-DIRTY: unsaved changes would ride the same save unasked.
    public static function test_rederive_of_a_record_with_unsaved_changes_throws()
    {
        $record = static::__person();
        $record->note = 'pending';

        static::__assert_throws(LogicException::class, fn () => $record->rederive(), 'has unsaved changes (note)');
    }

    // DERIVED-REDERIVE-NONE: a model with no derived columns has nothing to rederive.
    public static function test_rederive_on_a_model_without_derived_columns_answers_false()
    {
        $category = new Derived_Category_Fixture_Model();
        $category->name = 'Plain';
        $category->save();

        static::__assert_false($category->rederive());
        static::__assert_equals([], $category->_derived_drift());
    }

    // DERIVED-DRIFT: reports without saving or dirtying the record.
    public static function test_drift_reports_without_saving_or_dirtying()
    {
        $record = static::__person();
        static::__corrupt('derived_plain_fixtures', $record, 'full_name', 'Stale');

        static::__assert_equals(['full_name' => ['Stale', 'Ada Lovelace']], $record->_derived_drift());
        static::__assert_false($record->isDirty(), 'the record is still clean');
        static::__assert_equals('Stale', $record->full_name, 'and still holds the stored value');
        static::__assert_equals(
            'Stale',
            Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', (int) $record->id)['full_name'],
            'and nothing was written'
        );

        static::__assert_true($record->rederive(), 'the same difference is what rederive() saves');
        static::__assert_equals([], $record->_derived_drift(), 'after which there is none');
    }
}
