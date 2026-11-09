<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Forms\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Forms\Php\Form_Edit_Coupled_Fixture_Model;
use App\RSpade\Tests\Forms\Php\Form_Edit_Fixture_Model;
use App\RSpade\Tests\Forms\Php\Form_Edit_Plain_Fixture_Model;

/**
 * Form_Edit with a COUPLED record: an edit whose form stores some fields on another record
 * (run(..., coupled: [[$other, [column => field]]])).
 *
 * The record and its coupled record are one version (version_of(): the newest updated_at),
 * row-locked record first; a coupled record's revisions are read through its map, so a save
 * of a mapped field the submission also sends is a field_conflict naming the record it was
 * saved on, while an unmapped column, a field not sent, or a save before the read asks
 * nothing. Two columns mapped to one field conflict on that field; a coupled record that moved
 * with no revision is the plain confirm.
 *
 * The form here: name lives on the record; activity_kind (kind_id) and starts
 * (start_date + start_hour) live on the coupled record.
 */
class Form_Edit_Coupled_Test extends Rsx_Test_Abstract
{
    private const MAP = ['kind_id' => 'activity_kind', 'start_date' => 'starts', 'start_hour' => 'starts'];

    public static function setup()
    {
        DB::statement('DROP TABLE IF EXISTS form_edit_fixtures');
        DB::statement('DROP TABLE IF EXISTS form_edit_coupled_fixtures');

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

        DB::statement("CREATE TABLE form_edit_coupled_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kind_id BIGINT NULL, start_date VARCHAR(20) NULL, start_hour VARCHAR(20) NULL,
            note VARCHAR(255) NULL,
            {$audit}
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        Type_Ref_Registry::class_to_id('Form_Edit_Fixture_Model');
        Type_Ref_Registry::class_to_id('Form_Edit_Coupled_Fixture_Model');
    }

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS form_edit_fixtures');
        DB::statement('DROP TABLE IF EXISTS form_edit_coupled_fixtures');
    }

    /** @return array{0: Form_Edit_Fixture_Model, 1: Form_Edit_Coupled_Fixture_Model} */
    private static function __pair(): array
    {
        $record = new Form_Edit_Fixture_Model();
        $record->name = 'Original';
        $record->save();

        $other = new Form_Edit_Coupled_Fixture_Model();
        $other->kind_id = Form_Edit_Coupled_Fixture_Model::KIND_MEETING;
        $other->start_date = '2026-11-09';
        $other->start_hour = '09:00';
        $other->note = 'first';
        $other->save();

        return [Form_Edit_Fixture_Model::find($record->id), Form_Edit_Coupled_Fixture_Model::find($other->id)];
    }

    /** A save of the coupled record through its own screen, a clear millisecond later. */
    private static function __other_save(int $id, array $values): void
    {
        usleep(3000);
        $other = Form_Edit_Coupled_Fixture_Model::find($id);
        foreach ($values as $column => $value) {
            $other->{$column} = $value;
        }
        $other->save();
    }

    /**
     * One submission of $values (each changed) from version $loaded. The callback writes
     * name on the record and the coupled fields on the coupled record, each guarded by
     * changed() - how an endpoint writes a coupled record.
     */
    private static function __submit(Form_Edit_Fixture_Model $record, Form_Edit_Coupled_Fixture_Model $other, string $loaded, array $values, array $answers = [])
    {
        $params = $values + ['_changed' => array_keys($values), '_loaded_updated_at' => $loaded];

        if ($answers) {
            $params['_answers'] = $answers;
        }

        usleep(3000);

        return Form_Edit::run($record, $params, function (Form_Edit $edit) use ($record, $other, $values) {
            $edit->set('name', $values['name'] ?? 'NOT SUBMITTED');
            $record->save();

            if ($edit->changed('activity_kind')) {
                $other->kind_id = $values['activity_kind'];
            }
            if ($edit->changed('starts')) {
                [$other->start_date, $other->start_hour] = explode(' ', $values['starts']);
            }
            $other->save();

            return ['id' => $record->id];
        }, null, coupled: [[$other, self::MAP]]);
    }

    private static function __question($result): array
    {
        static::__assert_instance_of(Error_Response::class, $result, 'a question was asked');

        return $result->get_metadata()['question'];
    }

    public static function test_version_of_is_the_newest_updated_at_of_the_records_given()
    {
        [$record, $other] = static::__pair();
        static::__other_save($other->id, ['note' => 'later']);
        $other = Form_Edit_Coupled_Fixture_Model::find($other->id);

        static::__assert_equals(Rsx_Time::to_database($other->updated_at), Form_Edit::version_of($record, $other));
        static::__assert_equals(Rsx_Time::to_database($other->updated_at), Form_Edit::version_of($other, $record), 'order does not matter');
        static::__assert_equals(Rsx_Time::to_database($record->updated_at), Form_Edit::version_of($record), 'one record is its own version');
    }

    public static function test_a_coupled_save_of_a_field_this_submission_sends_asks_naming_that_record()
    {
        [$record, $other] = static::__pair();
        $loaded = Form_Edit::version_of($record, $other);

        static::__other_save($other->id, ['kind_id' => Form_Edit_Coupled_Fixture_Model::KIND_CALL]);
        $result = static::__submit($record, $other, $loaded, ['name' => 'Mine', 'activity_kind' => Form_Edit_Coupled_Fixture_Model::KIND_MEETING]);

        $question = static::__question($result);
        static::__assert_equals('field_conflict', $question['kind']);
        static::__assert_equals(['activity_kind'], array_column($question['fields'], 'field'), 'the form field, not the column');

        $row = $question['fields'][0];
        static::__assert_equals(['type' => 'Form_Edit_Coupled_Fixture_Model', 'id' => (int) $other->id], $row['record'], 'names the record it was saved on');
        static::__assert_equals('enum', $row['kind']);
        static::__assert_equals('Phone call', $row['current']);
        static::__assert_equals('Meeting', $row['yours']);

        static::__assert_equals('Original', Form_Edit_Fixture_Model::find($record->id)->name, 'nothing written');
        static::__assert_equals(Form_Edit_Coupled_Fixture_Model::KIND_CALL, (int) Form_Edit_Coupled_Fixture_Model::find($other->id)->kind_id, 'their value stands');
    }

    public static function test_keep_theirs_leaves_the_coupled_field_and_writes_the_rest()
    {
        [$record, $other] = static::__pair();
        $loaded = Form_Edit::version_of($record, $other);

        static::__other_save($other->id, ['kind_id' => Form_Edit_Coupled_Fixture_Model::KIND_CALL]);
        $values = ['name' => 'Mine', 'activity_kind' => Form_Edit_Coupled_Fixture_Model::KIND_MEETING];
        $key = static::__question_key(static::__submit($record, $other, $loaded, $values));

        static::__submit($record, $other, $loaded, $values, [$key => false]);

        static::__assert_equals('Mine', Form_Edit_Fixture_Model::find($record->id)->name, 'the rest is written');
        static::__assert_equals(Form_Edit_Coupled_Fixture_Model::KIND_CALL, (int) Form_Edit_Coupled_Fixture_Model::find($other->id)->kind_id, 'theirs is kept');
    }

    private static function __question_key($result): string
    {
        static::__assert_instance_of(Error_Response::class, $result, 'a question was asked');

        return $result->get_metadata()['key'];
    }

    public static function test_a_coupled_save_of_an_unmapped_column_or_an_unsent_field_asks_nothing()
    {
        [$record, $other] = static::__pair();
        $loaded = Form_Edit::version_of($record, $other);

        static::__other_save($other->id, ['note' => 'theirs', 'start_hour' => '10:00']);
        $result = static::__submit($record, $other, $loaded, ['name' => 'Mine', 'activity_kind' => Form_Edit_Coupled_Fixture_Model::KIND_CALL]);

        static::__assert_true(is_array($result), 'no question');
        $stored = Form_Edit_Coupled_Fixture_Model::find($other->id);
        static::__assert_equals('Mine', Form_Edit_Fixture_Model::find($record->id)->name, 'the write lands');
        static::__assert_equals(Form_Edit_Coupled_Fixture_Model::KIND_CALL, (int) $stored->kind_id, 'the coupled field this submission sent lands');
        static::__assert_equals('theirs', $stored->note, 'their unmapped change stands');
        static::__assert_equals('10:00', $stored->start_hour, 'their change to a field not sent stands');
        static::__assert_equals(Form_Edit::version_of(Form_Edit_Fixture_Model::find($record->id), $stored), $result['updated_at'], 'the result carries the composite version');
    }

    public static function test_a_coupled_save_before_the_read_asks_nothing()
    {
        [$record, $other] = static::__pair();
        static::__other_save($other->id, ['kind_id' => Form_Edit_Coupled_Fixture_Model::KIND_CALL]);
        $other = Form_Edit_Coupled_Fixture_Model::find($other->id);

        // The read happens now: it publishes the composite, which includes that save.
        $loaded = Form_Edit::version_of($record, $other);
        $result = static::__submit($record, $other, $loaded, ['name' => 'Mine', 'activity_kind' => Form_Edit_Coupled_Fixture_Model::KIND_MEETING]);

        static::__assert_true(is_array($result), 'no question: the caller had seen that save');
        static::__assert_equals(Form_Edit_Coupled_Fixture_Model::KIND_MEETING, (int) Form_Edit_Coupled_Fixture_Model::find($other->id)->kind_id);
    }

    public static function test_two_columns_mapped_to_one_field_conflict_on_that_field()
    {
        foreach (['start_date' => '2026-11-10', 'start_hour' => '11:00'] as $column => $value) {
            [$record, $other] = static::__pair();
            $loaded = Form_Edit::version_of($record, $other);

            static::__other_save($other->id, [$column => $value]);
            $question = static::__question(static::__submit($record, $other, $loaded, ['starts' => '2026-11-09 09:30']));

            static::__assert_equals(['starts'], array_column($question['fields'], 'field'), "a change to {$column} is a change to starts");
            static::__assert_equals('structured', $question['fields'][0]['kind'], 'no one value to show');
            static::__assert_null($question['fields'][0]['current']);
        }
    }

    public static function test_a_coupled_record_moved_with_no_revision_asks_a_plain_confirm()
    {
        [$record, $other] = static::__pair();
        $loaded = Form_Edit::version_of($record, $other);

        usleep(3000);
        DB::table('form_edit_coupled_fixtures')->where('id', $other->id)->update(['note' => 'raw', 'updated_at' => now()->format('Y-m-d H:i:s.v')]);

        $question = static::__question(static::__submit($record, $other, $loaded, ['name' => 'Mine']));
        static::__assert_equals('confirm', $question['kind']);
    }

    public static function test_every_record_is_row_locked_record_first()
    {
        [$record, $other] = static::__pair();
        $loaded = Form_Edit::version_of($record, $other);

        DB::enableQueryLog();
        static::__submit($record, $other, $loaded, ['name' => 'Mine']);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $locked = array_values(array_filter($queries, fn ($query) => stripos($query, 'for update') !== false));

        static::__assert_equals(2, count($locked), 'two locking reads');
        static::__assert_true(str_contains($locked[0], 'form_edit_fixtures'), 'the record first');
        static::__assert_true(str_contains($locked[1], 'form_edit_coupled_fixtures'), 'then the coupled record');
    }

    public static function test_a_coupled_record_without_revisions_is_refused_by_name()
    {
        [$record] = static::__pair();

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Form_Edit::run($record, [], fn () => null, null, coupled: [[new Form_Edit_Plain_Fixture_Model(), ['name' => 'name']]]),
            'Form_Edit_Plain_Fixture_Model'
        );
    }
}
