<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Forms\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Revisions\Revision_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Forms\Php\Form_Edit_Fixture_Model;
use App\RSpade\Tests\Forms\Php\Form_Edit_Plain_Fixture_Model;

/**
 * Form_Edit: only the fields a submission changed are written, and it asks only when
 * another save changed one of those same fields.
 *
 * A save is "another editor" here because the test saves the record directly between loading
 * it and submitting. Each save waits for the clock to pass a millisecond: updated_at is the
 * version, and two saves inside one millisecond would be one version.
 */
class Form_Edit_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        DB::statement('DROP TABLE IF EXISTS form_edit_fixtures');
        DB::statement('CREATE TABLE form_edit_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(255) NULL,
            level_id BIGINT NULL,
            secret_token VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT NULL,
            updated_at TIMESTAMP(3) NULL DEFAULT NULL,
            deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL,
            deleted_by_id BIGINT NULL DEFAULT NULL,
            deleted_by_type BIGINT NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        Type_Ref_Registry::class_to_id('Form_Edit_Fixture_Model');
    }

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS form_edit_fixtures');
    }

    private static function __record(): Form_Edit_Fixture_Model
    {
        $record = new Form_Edit_Fixture_Model();
        $record->name = 'Original';
        $record->email = 'original@example.com';
        $record->phone = '111';
        $record->level_id = Form_Edit_Fixture_Model::LEVEL_LOW;
        $record->save();

        return Form_Edit_Fixture_Model::find($record->id);
    }

    /**
     * The version a form would have loaded. This runtime table has no entry in the schema
     * cache, so the attribute is a Carbon rather than the string a real model answers, and
     * casting one to string drops its milliseconds.
     */
    private static function __version(Form_Edit_Fixture_Model $record): string
    {
        return Rsx_Time::to_database($record->updated_at);
    }

    /** Another editor's save of one field, a clear millisecond after the last version. */
    private static function __other_save(int $id, string $field, $value): void
    {
        usleep(3000);
        $other = Form_Edit_Fixture_Model::find($id);
        $other->{$field} = $value;
        $other->save();
    }

    /** One submission of the fields in $values, each of them changed. */
    private static function __submit(Form_Edit_Fixture_Model $record, string $loaded, array $values, array $answers = [])
    {
        $params = $values + ['_changed' => array_keys($values), '_loaded_updated_at' => $loaded];

        if ($answers) {
            $params['_answers'] = $answers;
        }

        usleep(3000);

        return Form_Edit::run($record, $params, function (Form_Edit $edit) use ($record, $values) {
            foreach (['name', 'email', 'phone', 'level_id', 'secret_token'] as $field) {
                $edit->set($field, $values[$field] ?? 'NOT SUBMITTED');
            }

            $record->save();

            return ['id' => $record->id];
        });
    }

    private static function __stored(int $id): Form_Edit_Fixture_Model
    {
        return Form_Edit_Fixture_Model::find($id);
    }

    /** FE-01 */
    public static function test_a_model_without_revisions_is_refused_by_name()
    {
        static::__assert_throws(
            \RuntimeException::class,
            fn () => Form_Edit::run(new Form_Edit_Plain_Fixture_Model(), [], fn () => null),
            'Form_Edit_Plain_Fixture_Model'
        );
    }

    /** FE-02 */
    public static function test_a_new_record_writes_every_field()
    {
        $record = new Form_Edit_Fixture_Model();

        $result = Form_Edit::run($record, ['_changed' => []], function (Form_Edit $edit) use ($record) {
            $edit->set('name', 'New');
            $record->save();

            return ['id' => $record->id];
        });

        static::__assert_equals('New', static::__stored($record->id)->name);
        static::__assert_true(!empty($result['updated_at']), 'the form is told the version');
    }

    /** FE-03 */
    public static function test_only_the_changed_fields_are_written()
    {
        $record = static::__record();

        static::__submit($record, static::__version($record), ['name' => 'Mine']);

        $stored = static::__stored($record->id);
        static::__assert_equals('Mine', $stored->name);
        static::__assert_equals('original@example.com', $stored->email, 'an unchanged field is left alone');
    }

    /** FE-04 */
    public static function test_saves_of_different_fields_both_stand_and_nobody_is_asked()
    {
        $record = static::__record();
        $loaded = static::__version($record);

        static::__other_save($record->id, 'email', 'theirs@example.com');
        $result = static::__submit($record, $loaded, ['name' => 'Mine']);

        static::__assert_true(is_array($result), 'no question');
        $stored = static::__stored($record->id);
        static::__assert_equals('Mine', $stored->name);
        static::__assert_equals('theirs@example.com', $stored->email);
    }

    /** FE-05 */
    public static function test_a_save_of_the_same_field_asks_and_writes_nothing()
    {
        $record = static::__record();
        $loaded = static::__version($record);

        static::__other_save($record->id, 'level_id', Form_Edit_Fixture_Model::LEVEL_HIGH);
        $result = static::__submit($record, $loaded, ['name' => 'Mine', 'level_id' => Form_Edit_Fixture_Model::LEVEL_LOW]);

        static::__assert_instance_of(Error_Response::class, $result);
        static::__assert_equals(Ajax::ERROR_QUESTION, $result->get_error_code());

        $question = $result->get_metadata()['question'];
        static::__assert_equals('field_conflict', $question['kind']);
        static::__assert_count(1, $question['fields'], 'only the overlapping field');
        static::__assert_equals('level_id', $question['fields'][0]['field']);
        static::__assert_equals('enum', $question['fields'][0]['kind']);
        static::__assert_equals('High', $question['fields'][0]['current'], 'an enum reads as its label');
        static::__assert_equals('Low', $question['fields'][0]['yours']);
        static::__assert_equals('Low', $question['fields'][0]['loaded'], 'what the form was opened with');

        static::__assert_equals('Original', static::__stored($record->id)->name, 'nothing written before the answer');
    }

    /** FE-06 */
    public static function test_yes_writes_mine_and_no_keeps_theirs_and_writes_the_rest()
    {
        foreach ([true => 'Mine', 0 => 'Theirs'] as $answer => $expected) {
            $record = static::__record();
            $loaded = static::__version($record);

            static::__other_save($record->id, 'name', 'Theirs');
            $values = ['name' => 'Mine', 'phone' => '222'];
            $key = static::__submit($record, $loaded, $values)->get_metadata()['key'];

            $result = static::__submit($record, $loaded, $values, [$key => (bool) $answer]);

            static::__assert_true(is_array($result));
            static::__assert_equals($expected, static::__stored($record->id)->name);
            static::__assert_equals('222', static::__stored($record->id)->phone, 'the rest is written');
        }
    }

    /** FE-07 */
    public static function test_a_further_save_between_rounds_asks_again()
    {
        $record = static::__record();
        $loaded = static::__version($record);

        static::__other_save($record->id, 'name', 'Theirs');
        $key = static::__submit($record, $loaded, ['name' => 'Mine'])->get_metadata()['key'];

        static::__other_save($record->id, 'name', 'Theirs again');
        $result = static::__submit($record, $loaded, ['name' => 'Mine'], [$key => true]);

        static::__assert_instance_of(Error_Response::class, $result);
        static::__assert_equals('Theirs again', static::__stored($record->id)->name);
    }

    /** FE-08 */
    public static function test_a_write_with_no_revision_asks_a_plain_confirm()
    {
        $record = static::__record();
        $loaded = static::__version($record);

        usleep(3000);
        DB::table('form_edit_fixtures')->where('id', $record->id)->update([
            'email' => 'raw@example.com',
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        $asked = static::__submit($record, $loaded, ['name' => 'Mine']);
        static::__assert_equals('confirm', $asked->get_metadata()['question']['kind']);

        $key = $asked->get_metadata()['key'];
        static::__submit($record, $loaded, ['name' => 'Mine'], [$key => false]);
        static::__assert_equals('Original', static::__stored($record->id)->name, 'No writes nothing');

        static::__submit($record, $loaded, ['name' => 'Mine'], [$key => true]);
        static::__assert_equals('Mine', static::__stored($record->id)->name);
    }

    /** FE-09 */
    public static function test_a_form_is_not_in_conflict_with_its_own_save()
    {
        $record = static::__record();

        $first = static::__submit($record, static::__version($record), ['name' => 'Mine']);
        $second = static::__submit($record, Rsx_Time::to_database($first['updated_at']), ['name' => 'Mine again']);

        static::__assert_true(is_array($second), 'no question');
        static::__assert_equals('Mine again', static::__stored($record->id)->name);
    }

    /** FE-10 */
    public static function test_a_submission_without_its_edit_state_is_refused()
    {
        $record = static::__record();

        static::__assert_throws(\RuntimeException::class, fn () => Form_Edit::run($record, [], fn () => null), 'updated_at');
    }

    /** FE-11 */
    public static function test_a_record_deleted_meanwhile_answers_not_found()
    {
        $record = static::__record();
        Form_Edit_Fixture_Model::find($record->id)->delete();

        $result = static::__submit($record, static::__version($record), ['name' => 'Mine']);

        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $result->get_error_code());
    }

    /** FE-12 */
    public static function test_the_record_is_read_under_a_row_lock()
    {
        $record = static::__record();

        DB::enableQueryLog();
        static::__submit($record, static::__version($record), ['name' => 'Mine']);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $locked = array_filter($queries, fn ($query) => stripos($query, 'for update') !== false);

        static::__assert_true(count($locked) === 1, 'one locking read');
    }

    /** FE-14 */
    public static function test_the_question_hands_back_editors_as_references()
    {
        $record = static::__record();
        $loaded = static::__version($record);

        static::__acting_as_user(1);
        try {
            static::__other_save($record->id, 'name', 'Theirs');
        } finally {
            static::__reset_session();
        }

        $question = static::__submit($record, $loaded, ['name' => 'Mine'])->get_metadata()['question'];
        $revision = static::__latest_revision($record->id);
        $reference = ['type' => class_basename($revision->created_by), 'id' => (int) $revision->created_by->getKey()];

        static::__assert_equals([$reference], $question['editors'], 'one editor, as a reference');
        static::__assert_equals($reference, $question['fields'][0]['changes'][0]['by']);
        static::__assert_equals(Rsx_Time::to_iso($revision->created_at), $question['fields'][0]['changes'][0]['at'], 'the instant, unformatted');
        static::__assert_equals('Original', $question['fields'][0]['changes'][0]['from']);
        static::__assert_equals('Theirs', $question['fields'][0]['changes'][0]['to']);
        static::__assert_equals(['type' => 'Form_Edit_Fixture_Model', 'id' => (int) $record->id], $question['record']);
    }

    /** FE-15 */
    public static function test_two_editors_are_both_listed_and_nobody_is_null()
    {
        $record = static::__record();
        $loaded = static::__version($record);

        static::__acting_as_user(1);
        try {
            static::__other_save($record->id, 'name', 'First');
        } finally {
            static::__reset_session();
        }
        static::__other_save($record->id, 'name', 'Second');

        $question = static::__submit($record, $loaded, ['name' => 'Mine'])->get_metadata()['question'];

        static::__assert_count(2, $question['editors'], 'each editor once');
        static::__assert_equals(null, $question['editors'][1], 'a save nobody signed in made');
        static::__assert_equals(['First', 'Second'], array_column($question['fields'][0]['changes'], 'to'), 'every change, in order');
        static::__assert_equals('Second', $question['fields'][0]['current']);
    }

    /** FE-16 */
    public static function test_a_field_set_to_the_value_already_saved_is_not_asked_about()
    {
        $record = static::__record();
        $loaded = static::__version($record);

        static::__other_save($record->id, 'name', 'Same');
        $result = static::__submit($record, $loaded, ['name' => 'Same']);

        static::__assert_true(is_array($result), 'nothing would be overwritten, so no question');
        static::__assert_equals('Same', static::__stored($record->id)->name);
    }

    /** FE-17 */
    public static function test_a_revision_names_the_actor_the_record_stamp_names()
    {
        $record = static::__record();

        static::__acting_as_user(1);
        try {
            static::__other_save($record->id, 'name', 'Theirs');
        } finally {
            static::__reset_session();
        }

        $stored = DB::table('form_edit_fixtures')->where('id', $record->id)->first(['updated_by_type', 'updated_by_id']);
        $revision = DB::table('_revisions')->orderBy('id', 'desc')->first(['created_by_type', 'created_by_id']);

        static::__assert_equals((int) $stored->updated_by_type, (int) $revision->created_by_type, 'the same actor model');
        static::__assert_equals((int) $stored->updated_by_id, (int) $revision->created_by_id, 'the same actor');
    }

    /** FE-18 */
    public static function test_the_view_url_the_endpoint_passes_rides_the_question()
    {
        $record = static::__record();
        $loaded = static::__version($record);
        static::__other_save($record->id, 'name', 'Theirs');

        usleep(3000);
        $params = ['name' => 'Mine', '_changed' => ['name'], '_loaded_updated_at' => $loaded];
        $result = Form_Edit::run($record, $params, fn () => [], '/records/' . $record->id);

        static::__assert_equals('/records/' . $record->id, $result->get_metadata()['question']['view_url']);
    }

    private static function __latest_revision(int $record_id): Revision_Model
    {
        return Revision_Model::where('record_type', 'Form_Edit_Fixture_Model')
            ->where('record_id', $record_id)
            ->orderBy('id', 'desc')
            ->first();
    }

    /** FE-13 */
    public static function test_a_secret_column_says_only_that_it_changed()
    {
        $record = static::__record();
        $loaded = static::__version($record);

        static::__other_save($record->id, 'secret_token', 'their secret');
        $field = static::__submit($record, $loaded, ['secret_token' => 'my secret'])->get_metadata()['question']['fields'][0];

        static::__assert_equals('secret', $field['kind']);
        static::__assert_equals(null, $field['current']);
        static::__assert_equals(null, $field['yours']);
        static::__assert_equals(null, $field['changes'][0]['to'], 'no value anywhere in the row');
    }
}
