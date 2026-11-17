<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ColumnEquivalence\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\ColumnEquivalence\Php\Recorded_Changes_Fixture_Model;

/**
 * recorded_changes() answers for the save the CALLER made. A model whose save() override
 * rederives after parent::save() writes the instance twice for one call; the rederive's
 * derived-only write adds nothing to the list and takes nothing from it, so the caller still
 * learns which recorded columns its edit changed - and Form_Edit::written_fields(), which reads
 * the same list, still names the field. A rederive() a caller makes on its own records nothing,
 * and a save that throws in its nested write answers empty.
 */
class Recorded_Changes_Nested_Save_Test extends Rsx_Test_Abstract
{
    private const TABLE = 'recorded_changes_fixtures';

    /** Set by a test to make the derived-only write of the fixture throw. */
    private static bool $fail_derived_write = false;

    /** Whether the updating listener is registered in this process. */
    private static bool $listener_registered = false;

    public static function setup()
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::TABLE);
        DB::statement('CREATE TABLE ' . self::TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            note VARCHAR(255) NULL,
            label VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        Rsx_Schema::forget();
        Type_Ref_Registry::class_to_id('Recorded_Changes_Fixture_Model');
    }

    public static function teardown()
    {
        Recorded_Changes_Fixture_Model::$label_suffix = '-a';
        Recorded_Changes_Fixture_Model::$rederive_after_save = true;
        static::$fail_derived_write = false;
        DB::statement('DROP TABLE IF EXISTS ' . self::TABLE);
        Rsx_Schema::forget();
    }

    /** A stored record whose label is current under the suffix '-a', reloaded. */
    private static function __stored(): Recorded_Changes_Fixture_Model
    {
        static::__acting_as_user(1);
        Recorded_Changes_Fixture_Model::$label_suffix = '-a';

        $record = new Recorded_Changes_Fixture_Model();
        $record->name = 'original';
        $record->note = 'n';
        $record->save();

        return Recorded_Changes_Fixture_Model::find($record->id);
    }

    private static function __revision_count(Recorded_Changes_Fixture_Model $record): int
    {
        return count(iterator_to_array($record->revisions(), false));
    }

    private static function __stored_label(int $id): ?string
    {
        return DB::selectOne('SELECT label FROM ' . self::TABLE . ' WHERE id = ?', [$id])->label;
    }

    // RC-NESTED-REDERIVE
    public static function test_a_rederive_inside_the_save_keeps_the_callers_recorded_columns()
    {
        $record = static::__stored();
        $revisions = static::__revision_count($record);

        Recorded_Changes_Fixture_Model::$label_suffix = '-b';
        $record->name = 'renamed';
        $record->save();

        static::__assert_equals('n-b', static::__stored_label((int) $record->id), 'the override rederived: the stale label was rewritten');
        static::__assert_equals(['name'], $record->recorded_changes(), "the caller's edit, not the rederive's derived-only write");
        static::__assert_equals($revisions + 1, static::__revision_count($record), 'one revision, for the edit');
    }

    // RC-NOT-STALE
    public static function test_a_rederive_with_nothing_stale_leaves_the_answer_as_before()
    {
        $record = static::__stored();

        $record->name = 'renamed';
        $record->save();

        static::__assert_equals('n-a', static::__stored_label((int) $record->id));
        static::__assert_equals(['name'], $record->recorded_changes());

        $record->save();
        static::__assert_equals([], $record->recorded_changes(), 'the next save of the instance starts the list over');
    }

    // RC-STANDALONE-REDERIVE
    public static function test_a_callers_own_rederive_records_nothing()
    {
        $record = static::__stored();
        $record->name = 'renamed';
        $record->save();
        static::__assert_equals(['name'], $record->recorded_changes());

        Recorded_Changes_Fixture_Model::$label_suffix = '-c';
        static::__assert_true($record->rederive(), 'the stale label is rewritten');
        static::__assert_equals('n-c', static::__stored_label((int) $record->id));
        static::__assert_equals([], $record->recorded_changes(), 'a rederive() on its own is a save of its own, and records nothing');
    }

    // RC-NESTED-THROWS
    public static function test_a_save_whose_nested_write_throws_answers_empty()
    {
        if (!static::$listener_registered) {
            Recorded_Changes_Fixture_Model::updating(function (Recorded_Changes_Fixture_Model $record) {
                if (static::$fail_derived_write && array_keys($record->getDirty()) === ['label']) {
                    throw new RuntimeException('derived-only write refused by the test');
                }
            });
            static::$listener_registered = true;
        }

        $record = static::__stored();
        Recorded_Changes_Fixture_Model::$label_suffix = '-d';
        $record->name = 'renamed';

        static::$fail_derived_write = true;
        $thrown = null;
        try {
            $record->save();
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
        } finally {
            static::$fail_derived_write = false;
        }

        static::__assert_equals('derived-only write refused by the test', $thrown, 'the nested write threw out of the caller\'s save');
        static::__assert_equals([], $record->recorded_changes(), 'a save that threw records nothing');
    }

    // RC-WRITTEN-FIELDS
    public static function test_written_fields_names_the_field_across_the_rederive()
    {
        $record = static::__stored();
        Recorded_Changes_Fixture_Model::$label_suffix = '-e';
        $written = null;
        $params = ['name' => 'edited', '_changed' => ['name'], '_loaded_updated_at' => Rsx_Time::to_database($record->updated_at)];

        usleep(3000);
        Form_Edit::run($record, $params, function (Form_Edit $edit) use ($record, &$written) {
            $edit->set('name', 'edited');
            $record->save();
            $written = $edit->written_fields();

            return ['id' => $record->id];
        });

        static::__assert_equals('n-e', static::__stored_label((int) $record->id), 'the override rederived inside the edit');
        static::__assert_equals(['name'], $written);
    }
}
