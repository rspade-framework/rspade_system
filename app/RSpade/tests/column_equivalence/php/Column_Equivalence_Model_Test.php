<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ColumnEquivalence\Php;

use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Emissions;
use App\RSpade\Core\Database\Models\Column_Equivalence;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Revisions\Revision;
use App\RSpade\Tests\ColumnEquivalence\Php\Column_Equivalence_Fixture_Model;
use App\RSpade\Tests\ColumnEquivalence\Php\Column_Equivalence_Fixture_Tables;

/**
 * The rule wired into the model: Rsx_Model_Abstract::originalIsEquivalent() asks
 * Column_Equivalence, so a value assigned in another shape of the same value is not dirty - no
 * write, no revision, updated_at and the updated_by pair unmoved, no realtime frame, no
 * after_update() - while a real change behaves as before and records the column's storage
 * form. Also: a save changing only revision_exclude columns leaves the stamp alone,
 * Model::same_value(), storage_form(), the descriptors, and Revision::meaningful_changes() over
 * stored diffs.
 */
class Column_Equivalence_Model_Test extends Rsx_Test_Abstract
{
    private const OLD_STAMP = '2020-01-02 03:04:05.678';

    public static function setup()
    {
        Column_Equivalence_Fixture_Tables::create();
        config(['rsx.realtime.enabled' => true]);
    }

    public static function teardown()
    {
        config(['rsx.realtime.enabled' => false]);
        Realtime_Emissions::_testing_reset();
        Model_Lifecycle_Emissions::_testing_reset();
        Column_Equivalence_Fixture_Tables::drop();
    }

    /**
     * A stored record holding one value of every judged kind, its audit columns pinned to
     * known bytes behind the model's back, reloaded, and the effect queues emptied.
     */
    private static function __stored(): Column_Equivalence_Fixture_Model
    {
        $record = new Column_Equivalence_Fixture_Model();
        $record->hours = '1.00';
        $record->budget = '1.50';
        $record->is_flag = true;
        $record->happened_at = '2026-11-16T12:00:55.000Z';
        $record->doc = '{"a": 1, "b": 2}';
        $record->last_seen_at = '2026-11-16T09:00:00Z';
        $record->owner_type = 'Column_Equivalence_Fixture_Model';
        $record->owner_id = 1;
        $record->save();

        DB::update(
            'UPDATE ' . Column_Equivalence_Fixture_Tables::TABLE . ' SET updated_at = ?, updated_by_id = 4242, updated_by_type = 4343 WHERE id = ?',
            [self::OLD_STAMP, $record->id]
        );

        $record = Column_Equivalence_Fixture_Model::find($record->id);
        Realtime_Emissions::_testing_reset();
        Model_Lifecycle_Emissions::_testing_reset();

        return $record;
    }

    /** @return array<int, \App\RSpade\Lib\Revisions\Revision_Model> */
    private static function __revisions(Column_Equivalence_Fixture_Model $record): array
    {
        return iterator_to_array($record->revisions(), false);
    }

    /** The record's newest revision, by id. */
    private static function __newest_revision(Column_Equivalence_Fixture_Model $record): \App\RSpade\Lib\Revisions\Revision_Model
    {
        $all = static::__revisions($record);
        usort($all, fn ($a, $b) => (int) $b->id <=> (int) $a->id);

        return $all[0];
    }

    /**
     * Assert a save after the given assignments was a no-op in every respect.
     *
     * @param array<string, mixed> $assignments
     */
    private static function __assert_no_op(array $assignments, string $what): void
    {
        static::__acting_as_user(1);
        $record = static::__stored();
        $revisions = count(static::__revisions($record));
        $before = Column_Equivalence_Fixture_Tables::raw_row((int) $record->id);

        foreach ($assignments as $column => $value) {
            $record->{$column} = $value;
        }

        static::__assert_false($record->isDirty(), "{$what}: not dirty (dirty: " . implode(', ', array_keys($record->getDirty())) . ')');
        static::__assert_true($record->save(), "{$what}: save() answers true");

        $after = Column_Equivalence_Fixture_Tables::raw_row((int) $record->id);
        static::__assert_equals($before, $after, "{$what}: the row is byte for byte what it was (updated_at, updated_by)");
        static::__assert_count($revisions, static::__revisions($record), "{$what}: no revision");
        static::__assert_count(0, Realtime_Emissions::_testing_pending(), "{$what}: no realtime frame");
        static::__assert_count(0, Model_Lifecycle_Emissions::_testing_pending(), "{$what}: no after_update()");
    }

    // =========================================================================
    // Ask 2 - the dirty check
    // =========================================================================

    // CE-DIRTY-DECIMAL
    public static function test_a_decimal_assigned_in_another_shape_is_not_a_change()
    {
        static::__assert_no_op(['hours' => 1], "1 over '1.00'");
        static::__assert_no_op(['hours' => 1.0], "1.0 over '1.00'");
        static::__assert_no_op(['hours' => '1'], "'1' over '1.00'");
    }

    // CE-DIRTY-INSTANT
    public static function test_the_same_instant_in_another_form_is_not_a_change()
    {
        static::__assert_no_op(['happened_at' => '2026-11-16T12:00:55Z'], 'ISO without milliseconds');
        static::__assert_no_op(['happened_at' => '2026-11-16T14:00:55+02:00'], 'a zoned form');
        static::__assert_no_op(['happened_at' => '2026-11-16 12:00:55'], 'the stored form without milliseconds');
    }

    // CE-DIRTY-JSON / CE-DIRTY-BLANK / CE-DIRTY-OTHERS
    public static function test_json_in_another_key_order_blank_over_null_and_the_other_kinds_are_not_changes()
    {
        static::__assert_no_op(['doc' => '{"b":2,"a":1}'], 'the same JSON document in another key order');
        static::__assert_no_op(['name' => ''], "'' over NULL on a nullable text column");
        static::__assert_no_op(['budget' => '1.5'], "'1.5' over a money column holding 1.50");
        static::__assert_no_op(['is_flag' => '1'], "'1' over a boolean holding 1");
        static::__assert_no_op([], 'a save with nothing assigned');
    }

    // CE-DIRTY-NEVER-LOOSENED
    public static function test_the_never_loosened_cases_are_changes()
    {
        $record = static::__stored();

        $record->name = 'x';
        $record->save();
        $record->name = null;
        static::__assert_true($record->isDirty('name'), "NULL over 'x' is a change");
        $record->save();

        $record->hours = null;
        $record->save();
        $record->hours = 0;
        static::__assert_true($record->isDirty('hours'), '0 over NULL on a DECIMAL is a change');
        $record->save();

        $record->is_flag = null;
        $record->save();
        $record->is_flag = false;
        static::__assert_true($record->isDirty('is_flag'), 'false over NULL on a nullable boolean is a change');

        $fresh = static::__stored();
        $fresh->code = '';
        static::__assert_true($fresh->isDirty('code'), "'' over NULL on a blank_distinct column is a change");
    }

    // CE-DIRTY-REAL-CHANGE
    public static function test_a_real_change_is_written_stamped_recorded_in_storage_form_and_announced()
    {
        static::__acting_as_user(1);
        $record = static::__stored();
        $revisions = count(static::__revisions($record));

        $record->hours = 1.5;
        $record->is_flag = false;
        static::__assert_true($record->isDirty(), 'a different value is dirty');
        $record->save();

        $after = Column_Equivalence_Fixture_Tables::raw_row((int) $record->id);
        static::__assert_equals('1.50', $after['hours'], 'written');
        static::__assert_not_equals(self::OLD_STAMP, $after['updated_at'], 'updated_at moved');
        static::__assert_not_equals(4242, (int) $after['updated_by_id'], 'the updater was stamped');

        $all = static::__revisions($record);
        static::__assert_count($revisions + 1, $all, 'one revision');
        $diff = end($all)->diff();
        static::__assert_equals(['1.00', '1.50'], $diff['hours'], "the after is the column's storage form, '1.50', not 1.5");
        static::__assert_equals([1, 0], $diff['is_flag'], 'a boolean is recorded 1 / 0, never true / false');

        static::__assert_count(1, Realtime_Emissions::_testing_pending(), 'one realtime frame');
        $lifecycle = Model_Lifecycle_Emissions::_testing_pending();
        static::__assert_count(1, $lifecycle, 'after_update() queued');
        static::__assert_equals(['hours', 'hours_label', 'is_flag', 'updated_at', 'updated_by_id', 'updated_by_type'], static::__sorted($lifecycle[0]['changed']), 'naming what changed, the derived column included');
    }

    // CE-REV-CREATE-STORAGE-FORM
    public static function test_a_create_records_storage_forms_and_leaves_out_values_the_same_as_null()
    {
        $record = new Column_Equivalence_Fixture_Model();
        $record->name = '';
        $record->hours = 2;
        $record->is_flag = true;
        $record->happened_at = '2026-11-16T12:00:55Z';
        $record->save();

        $diff = static::__revisions($record)[0]->diff();
        static::__assert_equals([null, '2.00'], $diff['hours'], 'a DECIMAL in its storage form');
        static::__assert_equals([null, 1], $diff['is_flag'], 'a boolean as 1');
        static::__assert_equals([null, '2026-11-16 12:00:55.000'], $diff['happened_at'], 'a DATETIME(3) at its precision');
        static::__assert_false(array_key_exists('name', $diff), "'' on a nullable column is the same as NULL, so nothing to record");
    }

    // CE-UNSTAMPED-REVISION-EXCLUDE
    public static function test_a_save_of_only_revision_exclude_columns_leaves_the_stamp_alone()
    {
        static::__acting_as_user(1);
        $record = static::__stored();
        $revisions = count(static::__revisions($record));

        $record->last_seen_at = '2026-11-17T08:00:00Z';
        $record->save();

        $after = Column_Equivalence_Fixture_Tables::raw_row((int) $record->id);
        static::__assert_equals('2026-11-17 08:00:00', $after['last_seen_at'], 'the bookkeeping value was written');
        static::__assert_equals(self::OLD_STAMP, $after['updated_at'], 'updated_at is untouched');
        static::__assert_equals(4242, (int) $after['updated_by_id'], 'the updated_by pair is untouched');
        static::__assert_count($revisions, static::__revisions($record), 'no revision');
        static::__assert_true($record->timestamps, 'Eloquent timestamps are back on for the next save');

        // Mixed with an edit, the save is an ordinary one.
        $record->last_seen_at = '2026-11-18T08:00:00Z';
        $record->name = 'edited';
        $record->save();
        static::__assert_not_equals(self::OLD_STAMP, Column_Equivalence_Fixture_Tables::raw_row((int) $record->id)['updated_at'], 'an edit beside it moves updated_at');
    }

    // =========================================================================
    // Ask 1 - the API on the model
    // =========================================================================

    // CE-SAME-VALUE
    public static function test_same_value_asks_the_columns_kind()
    {
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('hours', '1.00', 1));
        static::__assert_false(Column_Equivalence_Fixture_Model::same_value('hours', '0.00', null), '0 is never NULL');
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('name', '', null), "'' is NULL on a nullable column");
        static::__assert_false(Column_Equivalence_Fixture_Model::same_value('code', '', null), 'unless declared blank_distinct');
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('budget', Rsx_Money::from_decimal('1.5'), '1.50'));
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('token', 'abc', 'abc'), 'a never_export column compares normally');
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value(
            'owner_type',
            'Column_Equivalence_Fixture_Model',
            Type_Ref_Registry::class_to_id('Column_Equivalence_Fixture_Model')
        ), 'a class name and its type-ref id');
        static::__assert_throws(RuntimeException::class, fn () => Column_Equivalence_Fixture_Model::same_value('no_such_column', 1, 1), "has no column 'no_such_column'");
    }

    // CE-DESCRIPTORS
    public static function test_the_descriptors_come_from_the_declaration_and_the_schema()
    {
        $d = Column_Equivalence::descriptors(Column_Equivalence_Fixture_Model::class);

        static::__assert_equals('decimal', $d['hours']['kind']);
        static::__assert_equals(2, $d['hours']['scale']);
        static::__assert_equals('money', $d['budget']['kind']);
        static::__assert_equals('boolean', $d['is_flag']['kind']);
        static::__assert_equals('datetime', $d['happened_at']['kind']);
        static::__assert_equals(3, $d['happened_at']['fsp']);
        static::__assert_equals(0, $d['last_seen_at']['fsp']);
        static::__assert_equals('json', $d['doc']['kind']);
        static::__assert_equals('type_ref', $d['owner_type']['kind']);
        static::__assert_equals('integer', $d['owner_id']['kind']);
        static::__assert_equals('string', $d['name']['kind']);
        static::__assert_true($d['name']['nullable']);
        static::__assert_true($d['code']['blank_distinct']);
        static::__assert_equals(['code'], Column_Equivalence_Fixture_Model::blank_distinct_columns());
    }

    // CE-STORAGE-FORM
    public static function test_storage_form_is_what_the_column_holds()
    {
        $d = Column_Equivalence::descriptors(Column_Equivalence_Fixture_Model::class);

        static::__assert_equals('1.00', Column_Equivalence::storage_form($d['hours'], 1));
        static::__assert_equals('1.01', Column_Equivalence::storage_form($d['hours'], '1.005'), 'rounded as MySQL stores it');
        static::__assert_equals(1, Column_Equivalence::storage_form($d['is_flag'], true));
        static::__assert_equals('2026-11-16 12:00:55.000', Column_Equivalence::storage_form($d['happened_at'], '2026-11-16T14:00:55+02:00'));
        static::__assert_equals(23, Column_Equivalence::storage_form($d['owner_id'], '23'));
        static::__assert_equals('1.50', Column_Equivalence::storage_form($d['budget'], Rsx_Money::from_decimal('1.5')));
        static::__assert_equals(
            Type_Ref_Registry::class_to_id('Column_Equivalence_Fixture_Model'),
            Column_Equivalence::storage_form($d['owner_type'], 'Column_Equivalence_Fixture_Model')
        );
        static::__assert_equals('abc', Column_Equivalence::storage_form($d['owner_id'], 'abc'), 'a value the kind cannot read is returned unchanged');
        static::__assert_equals('', Column_Equivalence::storage_form($d['name'], ''), 'a string keeps its blank');
        static::__assert_null(Column_Equivalence::storage_form($d['hours'], ''), "a DECIMAL's blank on a nullable column is NULL");
    }

    // CE-DESCRIPTOR-REFUSALS
    public static function test_a_malformed_descriptor_throws()
    {
        static::__assert_throws(LogicException::class, fn () => Column_Equivalence::same(['kind' => 'nope'], 1, 2), 'unknown kind');
        static::__assert_throws(LogicException::class, fn () => Column_Equivalence::same(['kind' => 'text_type'], 'a', 'b'), 'names its Rsx_Text_Abstract class');
    }

    // =========================================================================
    // Ask 3 - the reader helper
    // =========================================================================

    // CE-MEANINGFUL-CHANGES
    public static function test_meaningful_changes_drops_stored_noise_and_keeps_real_changes()
    {
        $stored = [
            'hours' => ['1.00', 1],
            'budget' => [null, '0.00'],
            'name' => ['a', 'b'],
            'last_seen_at' => ['2026-11-16 09:00:00', '2026-11-17 09:00:00'],
            'updated_at' => ['2026-11-16 09:00:00.000', '2026-11-17 09:00:00.000'],
            'updated_by_id' => [1, 2],
            '_rsx_state' => [1, 2],
            'happened_at' => ['2026-11-16 12:00:55.000', '2026-11-16T12:00:55Z'],
            'doc' => ['{"a": 1, "b": 2}', '{"b":2,"a":1}'],
            'token' => [Revision::REDACTED, Revision::REDACTED],
            'gone_column' => ['x', 'x'],
        ];

        static::__assert_equals(
            [
                'budget' => [null, '0.00'],
                'name' => ['a', 'b'],
                'token' => [Revision::REDACTED, Revision::REDACTED],
                'gone_column' => ['x', 'x'],
            ],
            Revision::meaningful_changes('Column_Equivalence_Fixture_Model', $stored),
            "equal pairs and unrecorded columns dropped; [null, '0.00'] on money, a redacted pair and a column the table no longer has kept"
        );

        static::__assert_equals(
            ['name' => ['a', 'b']],
            Revision::meaningful_changes(Column_Equivalence_Fixture_Model::class, ['name' => ['a', 'b'], 'hours' => ['1.50', '1.5']]),
            'a FQCN names the model too'
        );
    }

    // CE-MEANINGFUL-UNKNOWN-MODEL
    public static function test_meaningful_changes_returns_the_diff_of_a_model_no_longer_shipped_as_stored()
    {
        $stored = ['a' => [1, 2], 'b' => ['x', 'x'], 'updated_at' => ['2026-01-01 00:00:00', '2026-01-02 00:00:00']];

        $removed = -1;
        static::__assert_equals($stored, Revision::meaningful_changes('No_Such_Probe_Model', $stored, $removed), 'a simple name the manifest does not know');
        static::__assert_equals(0, $removed, 'nothing removed');

        static::__assert_equals($stored, Revision::meaningful_changes('Rsx\\Models\\No_Such_Probe_Model', $stored), 'an FQCN that names no class');
    }

    // CE-MEANINGFUL-UNITS
    public static function test_meaningful_changes_keeps_a_polymorphic_pair_and_a_fact_group_whole()
    {
        static::__assert_equals(
            ['owner_type' => [5, 5], 'owner_id' => [1, 2]],
            Revision::meaningful_changes('Column_Equivalence_Fixture_Model', ['owner_type' => [5, 5], 'owner_id' => [1, 2]]),
            'one half of a type-ref pair changed: both halves kept'
        );
        static::__assert_equals(
            ['owner_type' => [5, 6], 'owner_id' => ['1', 1]],
            Revision::meaningful_changes('Column_Equivalence_Fixture_Model', ['owner_type' => [5, 6], 'owner_id' => ['1', 1]]),
            'the type half changed: the id half is kept beside it'
        );

        $removed = null;
        static::__assert_equals(
            [],
            Revision::meaningful_changes('Column_Equivalence_Fixture_Model', ['owner_type' => [5, 5], 'owner_id' => ['1', 1]], $removed),
            'neither half changed: both dropped'
        );
        static::__assert_equals(2, $removed);

        static::__assert_equals(
            ['owner_id' => [1, 2]],
            Revision::meaningful_changes('Column_Equivalence_Fixture_Model', ['owner_id' => [1, 2]]),
            'a half the diff does not hold is not invented'
        );

        static::__assert_equals(
            ['happened_at' => ['2026-11-16 12:00:55.000', '2026-11-16 13:00:00.000'], 'doc' => ['{"a": 1}', '{"a":1}']],
            Revision::meaningful_changes('Column_Equivalence_Fixture_Model', [
                'happened_at' => ['2026-11-16 12:00:55.000', '2026-11-16 13:00:00.000'],
                'doc' => ['{"a": 1}', '{"a":1}'],
            ]),
            'one column of a fact group changed: the group is kept whole'
        );
        static::__assert_equals(
            [],
            Revision::meaningful_changes('Column_Equivalence_Fixture_Model', [
                'happened_at' => ['2026-11-16 12:00:55.000', '2026-11-16T12:00:55Z'],
                'doc' => ['{"a": 1}', '{"a":1}'],
            ]),
            'no column of the group changed: the group is dropped'
        );
    }

    // CE-MEANINGFUL-REMOVED
    public static function test_meaningful_changes_counts_the_pairs_it_leaves_out()
    {
        $removed = null;
        $kept = Revision::meaningful_changes('Column_Equivalence_Fixture_Model', [
            'hours' => ['1.00', 1],
            'name' => ['a', 'b'],
            'last_seen_at' => ['2026-11-16 09:00:00', '2026-11-17 09:00:00'],
            'updated_at' => ['2026-11-16 09:00:00.000', '2026-11-17 09:00:00.000'],
            'hours_label' => ['h1.00', 'h2.00'],
            'token' => [Revision::REDACTED, Revision::REDACTED],
        ], $removed);

        static::__assert_equals(['name', 'token'], array_keys($kept));
        static::__assert_equals(4, $removed, 'an equal pair, a revision_exclude column, updated_at and a derived column');

        Revision::meaningful_changes('Column_Equivalence_Fixture_Model', ['name' => ['a', 'b'], 'hours' => ['1.00', '2.00']], $removed);
        static::__assert_equals(0, $removed, 'an all-real diff removes nothing');

        Revision::meaningful_changes('Column_Equivalence_Fixture_Model', [], $removed);
        static::__assert_equals(0, $removed, 'an empty diff removes nothing');
    }

    // =========================================================================
    // The number on a text-type column, the DATE with a midnight tail
    // =========================================================================

    // CE-TEXT-TYPE-NUMBER
    public static function test_a_number_against_a_decimal_text_type_column_is_read_at_the_columns_scale()
    {
        $d = Column_Equivalence::descriptors(Column_Equivalence_Fixture_Model::class);
        static::__assert_equals('text_type', $d['rate']['kind']);
        static::__assert_equals(4, $d['rate']['scale'], "a text-type column on a DECIMAL carries the column's scale");
        static::__assert_equals(4, Column_Equivalence::to_browser($d['rate'])['scale'], 'and the browser receives it');
        static::__assert_null($d['token']['scale'], 'a column that is no DECIMAL carries none');

        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('rate', '60.0000', 60));
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('rate', '60.0000', 60.0));
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('rate', '0.7250', 0.725));
        static::__assert_false(Column_Equivalence_Fixture_Model::same_value('rate', '60.0000', 61));
        static::__assert_false(Column_Equivalence_Fixture_Model::same_value('rate', null, 0), '0 is never NULL');
    }

    // CE-DATE-MIDNIGHT
    public static function test_a_date_with_a_midnight_time_is_that_date()
    {
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('due_on', '2026-01-02', '2026-01-02 00:00:00'));
        static::__assert_true(Column_Equivalence_Fixture_Model::same_value('due_on', '2026-01-02', '2026-01-02T00:00:00.000'));
        static::__assert_false(Column_Equivalence_Fixture_Model::same_value('due_on', '2026-01-02', '2026-01-02 00:00:01'), 'a non-midnight time is never truncated');
        static::__assert_equals('2026-01-02', Column_Equivalence::storage_form(Column_Equivalence::descriptor(Column_Equivalence_Fixture_Model::class, 'due_on'), '2026-01-02 00:00:00'));

        static::__assert_equals(
            [],
            Revision::meaningful_changes('Column_Equivalence_Fixture_Model', ['due_on' => ['2026-01-02 00:00:00', '2026-01-02']]),
            'a stored pair of the date and the date at midnight is no change'
        );
    }

    // =========================================================================
    // recorded_dirty() / recorded_changes()
    // =========================================================================

    // CE-RECORDED-NOTHING
    public static function test_recorded_dirty_and_changes_are_empty_for_bookkeeping_excluded_and_derived_columns()
    {
        static::__acting_as_user(1);
        $record = static::__stored();
        static::__assert_equals([], $record->recorded_changes(), 'no save of this instance yet');

        $record->last_seen_at = '2026-12-01 09:00:00';
        $record->updated_at = '2026-12-01 09:00:00.000';
        static::__assert_equals([], $record->recorded_dirty(), 'updated_at and a revision_exclude column are dirty, none recorded');
        $record->save();
        static::__assert_equals([], $record->recorded_changes(), 'the save wrote nothing a revision records');

        DB::update('UPDATE ' . Column_Equivalence_Fixture_Tables::TABLE . " SET hours_label = 'stale' WHERE id = ?", [$record->id]);
        $record = Column_Equivalence_Fixture_Model::find($record->id);
        $revisions = count(static::__revisions($record));
        static::__assert_true($record->rederive(), 'the derived column is rewritten');
        static::__assert_equals([], $record->recorded_changes(), 'a derived-only save records nothing');
        static::__assert_count($revisions, static::__revisions($record), 'and no revision was written');
    }

    // CE-RECORDED-MIXED
    public static function test_recorded_dirty_and_changes_name_the_recorded_columns_and_agree_with_the_revision()
    {
        static::__acting_as_user(1);
        $record = static::__stored();
        $record->name = 'renamed';
        $record->hours = 1;
        $record->token = 'secret';
        $record->last_seen_at = '2026-12-01 09:00:00';
        $record->owner_id = 2;

        static::__assert_equals(['name', 'owner_id', 'token'], static::__sorted($record->recorded_dirty()), "hours 1 over '1.00' and the excluded column are not recorded");

        $record->save();
        $recorded = static::__sorted($record->recorded_changes());
        static::__assert_equals(['name', 'owner_id', 'token'], $recorded);
        static::__assert_equals($recorded, static::__sorted(array_keys(static::__newest_revision($record)->diff())), 'the revision the save wrote holds exactly these columns');

        $created = new Column_Equivalence_Fixture_Model();
        $created->name = 'new';
        $created->hours = 3;
        $created->code = '';
        static::__assert_equals(['code', 'hours', 'name'], static::__sorted($created->recorded_dirty()), 'before a create: the values assigned so far');
        $created->save();
        static::__assert_equals(
            static::__sorted(array_keys(static::__newest_revision($created)->diff())),
            static::__sorted($created->recorded_changes()),
            "after a create: the create revision's columns"
        );
        static::__assert_false(in_array('hours_label', $created->recorded_changes(), true), 'the derived column is never one of them');
    }

    /** @param array<int, string> $list */
    private static function __sorted(array $list): array
    {
        sort($list);

        return $list;
    }
}
