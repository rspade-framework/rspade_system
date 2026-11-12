<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Forms\Php;

use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Forms\Php\Form_Edit_Fixture_Model;
use App\RSpade\Tests\Forms\Php\Form_Edit_Grouped_Fixture_Model;

/**
 * Form_Edit with FACT GROUPS and the record's own fields: map - one fact stored in several
 * columns, and form fields named differently from the columns that store them.
 *
 * The record is Form_Edit_Grouped_Fixture_Model, whose 'when' group is all_day_date +
 * starts_at + ends_at. A save of any column of the group is a change to every field stored
 * in any column of it, always asked about (structured, the group named by its label, each
 * change carrying its own column's before and after); a field outside the group is not.
 * fields: renames an own column, and a column may feed several fields. Coupled, the grouped
 * model's declaration applies through its map. The declaration itself is checked against the
 * table at first use.
 */
class Form_Edit_Fact_Groups_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        DB::statement('DROP TABLE IF EXISTS form_edit_fixtures');
        DB::statement('DROP TABLE IF EXISTS form_edit_grouped_fixtures');

        $audit = 'created_at TIMESTAMP(3) NULL DEFAULT NULL,
            updated_at TIMESTAMP(3) NULL DEFAULT NULL,
            deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL,
            deleted_by_id BIGINT NULL DEFAULT NULL,
            deleted_by_type BIGINT NULL DEFAULT NULL';

        DB::statement("CREATE TABLE form_edit_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL, email VARCHAR(255) NULL, phone VARCHAR(255) NULL,
            level_id BIGINT NULL, secret_token VARCHAR(255) NULL,
            {$audit}
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::statement("CREATE TABLE form_edit_grouped_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NULL, all_day_date VARCHAR(20) NULL,
            starts_at VARCHAR(30) NULL, ends_at VARCHAR(30) NULL,
            billing_text VARCHAR(255) NULL, stamp VARCHAR(30) NULL,
            {$audit}
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // The declaration is checked against the live columns, so the schema must see the
        // tables this test just made.
        Rsx_Schema::forget();

        Type_Ref_Registry::class_to_id('Form_Edit_Fixture_Model');
        Type_Ref_Registry::class_to_id('Form_Edit_Grouped_Fixture_Model');
    }

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS form_edit_fixtures');
        DB::statement('DROP TABLE IF EXISTS form_edit_grouped_fixtures');
        Rsx_Schema::forget();
    }

    private static function __grouped(): Form_Edit_Grouped_Fixture_Model
    {
        $record = new Form_Edit_Grouped_Fixture_Model();
        $record->title = 'Original';
        $record->all_day_date = null;
        $record->starts_at = '2026-11-09 09:00';
        $record->ends_at = '2026-11-09 10:00';
        $record->billing_text = 'theirs before';
        $record->stamp = '2026-11-09 09:00';
        $record->save();

        return Form_Edit_Grouped_Fixture_Model::find($record->id);
    }

    /** Another editor's save, a clear millisecond after the last version. */
    private static function __other_save(Rsx_Model_Abstract $record, array $values): void
    {
        usleep(3000);
        $other = get_class($record)::find($record->id);
        foreach ($values as $column => $value) {
            $other->{$column} = $value;
        }
        $other->save();
    }

    /**
     * One submission of $values (each changed) on the grouped record, from version $loaded.
     * The callback set()s the identity-named fields and description (through the map); every
     * other field is the caller's concern.
     */
    private static function __submit(Form_Edit_Grouped_Fixture_Model $record, string $loaded, array $values, array $fields = [], array $answers = [])
    {
        $params = $values + ['_changed' => array_keys($values), '_loaded_updated_at' => $loaded];

        if ($answers) {
            $params['_answers'] = $answers;
        }

        usleep(3000);

        return Form_Edit::run($record, $params, function (Form_Edit $edit) use ($record, $values, $fields) {
            foreach (['title', 'all_day_date', 'starts_at', 'ends_at'] as $field) {
                $edit->set($field, $values[$field] ?? 'NOT SUBMITTED');
            }
            if (isset($fields['billing_text'])) {
                $edit->set('description', $values['description'] ?? 'NOT SUBMITTED');
            }
            $record->save();

            return ['id' => $record->id];
        }, null, fields: $fields);
    }

    private static function __question($result): array
    {
        static::__assert_instance_of(Error_Response::class, $result, 'a question was asked');

        return $result->get_metadata()['question'];
    }

    public static function test_a_save_of_one_column_of_a_group_conflicts_with_a_field_stored_in_another()
    {
        $record = static::__grouped();
        $loaded = Form_Edit::version_of($record);

        // They made it an all-day record; this stale submission moves the start time.
        static::__other_save($record, ['all_day_date' => '2026-11-10', 'starts_at' => null, 'ends_at' => null]);
        $question = static::__question(static::__submit($record, $loaded, ['starts_at' => '2026-11-09 11:00']));

        static::__assert_equals('field_conflict', $question['kind']);
        static::__assert_equals(['starts_at'], array_column($question['fields'], 'field'));

        $row = $question['fields'][0];
        static::__assert_equals('structured', $row['kind'], 'one value cannot stand for the group');
        static::__assert_equals(['Date and time'], $row['groups'], 'the group is named by its label');
        static::__assert_null($row['current']);
        static::__assert_null($row['yours']);

        $by_column = [];
        foreach ($row['changes'] as $change) {
            static::__assert_equals('Date and time', $change['group']);
            static::__assert_equals(['type' => 'Form_Edit_Grouped_Fixture_Model', 'id' => (int) $record->id], $change['record']);
            $by_column[$change['column']] = [$change['from'], $change['to']];
        }
        static::__assert_equals(['', '2026-11-10'], [(string) $by_column['all_day_date'][0], $by_column['all_day_date'][1]], 'before and after per column changed');
        static::__assert_equals('2026-11-09 09:00', $by_column['starts_at'][0]);

        $stored = Form_Edit_Grouped_Fixture_Model::find($record->id);
        static::__assert_equals('2026-11-10', $stored->all_day_date, 'nothing written');
        static::__assert_null($stored->starts_at);
    }

    public static function test_a_save_of_a_group_column_asks_even_when_the_field_column_did_not_move()
    {
        $record = static::__grouped();
        $loaded = Form_Edit::version_of($record);

        // Only ends_at moved; the submission sets starts_at to the value it already holds.
        // Compared with starts_at alone it would look like no overwrite at all.
        static::__other_save($record, ['ends_at' => '2026-11-09 12:00']);
        $question = static::__question(static::__submit($record, $loaded, ['starts_at' => '2026-11-09 09:00']));

        static::__assert_equals(['starts_at'], array_column($question['fields'], 'field'));
        static::__assert_equals(['ends_at'], array_column($question['fields'][0]['changes'], 'column'));
    }

    public static function test_a_save_of_a_group_column_asks_nothing_of_a_field_outside_the_group()
    {
        $record = static::__grouped();
        $loaded = Form_Edit::version_of($record);

        static::__other_save($record, ['all_day_date' => '2026-11-10']);
        $result = static::__submit($record, $loaded, ['title' => 'Mine']);

        static::__assert_true(is_array($result), 'no question');
        $stored = Form_Edit_Grouped_Fixture_Model::find($record->id);
        static::__assert_equals('Mine', $stored->title);
        static::__assert_equals('2026-11-10', $stored->all_day_date, 'their change stands');
    }

    public static function test_a_grouped_field_changed_only_in_its_own_column_to_the_same_value_asks_nothing()
    {
        $record = static::__grouped();
        $loaded = Form_Edit::version_of($record);

        static::__other_save($record, ['starts_at' => '2026-11-09 11:00']);
        $result = static::__submit($record, $loaded, ['starts_at' => '2026-11-09 11:00']);

        static::__assert_true(is_array($result), 'both set the one column to the same value: nothing is overwritten');
    }

    public static function test_fields_maps_an_own_column_to_a_differently_named_field()
    {
        $record = static::__grouped();
        $loaded = Form_Edit::version_of($record);
        $fields = ['billing_text' => 'description'];

        static::__other_save($record, ['billing_text' => 'theirs after']);
        $values = ['description' => 'mine'];
        $result = static::__submit($record, $loaded, $values, $fields);

        $question = static::__question($result);
        $row = $question['fields'][0];
        static::__assert_equals('description', $row['field'], 'the form field, not the column');
        static::__assert_equals('value', $row['kind']);
        static::__assert_equals('theirs after', $row['current']);
        static::__assert_equals('mine', $row['yours']);
        static::__assert_equals('theirs before', $row['loaded']);

        // Overwrite: set('description') writes the column the map names.
        static::__submit($record, $loaded, $values, $fields, [$result->get_metadata()['key'] => true]);
        static::__assert_equals('mine', Form_Edit_Grouped_Fixture_Model::find($record->id)->billing_text);
    }

    public static function test_without_the_map_a_renamed_field_matches_nothing()
    {
        $record = static::__grouped();
        $loaded = Form_Edit::version_of($record);

        static::__other_save($record, ['billing_text' => 'theirs after']);
        $result = static::__submit($record, $loaded, ['description' => 'mine']);

        static::__assert_true(is_array($result), 'identity is the default: no column is named description');
    }

    public static function test_a_column_mapped_to_two_fields_conflicts_with_a_submission_sending_either()
    {
        foreach (['stamp_day', 'stamp_hour'] as $sent) {
            $record = static::__grouped();
            $loaded = Form_Edit::version_of($record);

            static::__other_save($record, ['stamp' => '2026-11-10 14:00']);
            $question = static::__question(static::__submit($record, $loaded, [$sent => 'x'], ['stamp' => ['stamp_day', 'stamp_hour']]));

            static::__assert_equals([$sent], array_column($question['fields'], 'field'), "a change to stamp is a change to {$sent}");
            static::__assert_equals('stamp', $question['fields'][0]['changes'][0]['column']);
        }
    }

    public static function test_set_refuses_a_field_whose_column_it_shares()
    {
        $record = static::__grouped();
        $params = ['_changed' => ['stamp_day'], '_loaded_updated_at' => Form_Edit::version_of($record)];

        static::__assert_throws(
            RuntimeException::class,
            fn () => Form_Edit::run($record, $params, fn (Form_Edit $edit) => $edit->set('stamp_day', 'x'), null, fields: ['stamp' => ['stamp_day', 'stamp_hour']]),
            'changed'
        );
    }

    public static function test_a_coupled_record_s_group_applies_through_its_map()
    {
        $entry = new Form_Edit_Fixture_Model();
        $entry->name = 'Entry';
        $entry->save();
        $entry = Form_Edit_Fixture_Model::find($entry->id);
        $activity = static::__grouped();
        $loaded = Form_Edit::version_of($entry, $activity);

        // The activity was re-dated through its own screen, in a column the map never names.
        static::__other_save($activity, ['all_day_date' => '2026-11-12', 'starts_at' => null, 'ends_at' => null]);

        usleep(3000);
        $params = ['activity_time' => '2026-11-09 11:00', '_changed' => ['activity_time'], '_loaded_updated_at' => $loaded];
        $result = Form_Edit::run($entry, $params, function (Form_Edit $edit) use ($activity, $params) {
            if ($edit->changed('activity_time')) {
                $activity->starts_at = $params['activity_time'];
            }
            $activity->save();

            return [];
        }, null, coupled: [[$activity, ['starts_at' => 'activity_time']]]);

        $row = static::__question($result)['fields'][0];
        static::__assert_equals('activity_time', $row['field']);
        static::__assert_equals(['type' => 'Form_Edit_Grouped_Fixture_Model', 'id' => (int) $activity->id], $row['record']);
        static::__assert_equals(['Date and time'], $row['groups']);
        static::__assert_equals('2026-11-12', Form_Edit_Grouped_Fixture_Model::find($activity->id)->all_day_date, 'their re-date stands');
    }

    public static function test_the_declaration_is_read_checked_and_normalized()
    {
        static::__assert_equals(
            ['when' => ['label' => 'Date and time', 'columns' => ['all_day_date', 'starts_at', 'ends_at']]],
            Form_Edit_Grouped_Fixture_Model::fact_groups()
        );
        static::__assert_equals([], Form_Edit_Fixture_Model::fact_groups(), 'undeclared is no groups');
    }

    public static function test_a_malformed_declaration_fails_loud()
    {
        $columns = ['a' => [], 'b' => [], 'c' => []];
        $check = fn ($declaration, $table = null) => fn () => Rsx_Model_Abstract::_check_fact_groups('Probe_Model', $declaration, $table ?? $columns);

        static::__assert_throws(LogicException::class, $check(['g' => ['label' => 'G', 'columns' => ['a', 'nope']]]), "'nope', which is not a column");
        static::__assert_throws(LogicException::class, $check([
            'g' => ['label' => 'G', 'columns' => ['a', 'b']],
            'h' => ['label' => 'H', 'columns' => ['b', 'c']],
        ]), "'b' in both");
        static::__assert_throws(LogicException::class, $check(['g' => ['label' => 'G', 'columns' => ['a']]]), 'fewer than two columns');
        static::__assert_throws(LogicException::class, $check(['g' => ['label' => 'G', 'columns' => ['a', 'a']]]), 'fewer than two columns');
        static::__assert_throws(LogicException::class, $check(['g' => ['columns' => ['a', 'b']]]), 'no label');
        static::__assert_throws(LogicException::class, $check(['g' => ['label' => 'G', 'columns' => ['a', 'b'], 'extra' => 1]]), 'exactly');
        static::__assert_throws(LogicException::class, $check([['label' => 'G', 'columns' => ['a', 'b']]]), 'no name');
        static::__assert_throws(LogicException::class, fn () => Rsx_Model_Abstract::_check_fact_groups('Probe_Model', ['g' => ['label' => 'G', 'columns' => ['a', 'b']]], null), 'not in the connected database');
    }
}
