<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Support\Facades\DB;
use LogicException;
use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Table;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Geo\Places\Country_Model;
use App\RSpade\Tests\Database\Php\Missing_Property_Fixture_Model;
use App\RSpade\Tests\Database\Php\Missing_Property_Fixture_Note_Detail_Model;

/**
 * Reading a property a model does not have THROWS (Rsx_Model_Abstract::
 * throwMissingAttributeExceptionIfApplicable()), so a typo such as
 * `$record->role_id_can_admin_roles` - one underscore where the enum property
 * `role_id__can_admin_roles` has two - fails loud instead of reading as null.
 *
 * Every magic read RSpade answers must keep answering: enum properties (custom ones and an
 * undefined-for-this-value one), the static enum lookups, a system column, a never_export
 * column, an appended property, a relationship, a detail accessor. Presence tests (isset(),
 * `??`, data_get()) never throw. A record loaded by a partial select throws for a column it
 * did not select. A new record reads an unassigned COLUMN as null but still throws for a key
 * that is no column.
 *
 * The fixture tables are created with plain DDL after the schema reader was filled, so the
 * "is this a column" half of the rule is exercised against a migrated framework table
 * (countries) and the magic half against the fixtures.
 */
class Model_Missing_Property_Test extends Rsx_Test_Abstract
{
    public static function setup(): void
    {
        static::__drop_fixture_tables();

        DB::statement('CREATE TABLE missing_property_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            role_id BIGINT NULL,
            kind_id BIGINT NOT NULL DEFAULT 2,
            parent_id BIGINT NULL,
            secret_token VARCHAR(64) NULL,
            _sys_note VARCHAR(64) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        Rsx_Detail_Table::create('mp_fixture_notes', 'missing_property_fixtures', [
            ['name' => 'body', 'type' => 'VARCHAR(255)'],
        ]);
    }

    public static function teardown(): void
    {
        static::__drop_fixture_tables();
    }

    private static function __drop_fixture_tables(): void
    {
        DB::statement('DROP TABLE IF EXISTS mp_fixture_notes');
        DB::statement('DROP TABLE IF EXISTS missing_property_fixtures');
    }

    /**
     * A committed fixture row, read back from the database (exists, not recently created).
     */
    private static function __loaded(array $fields): Missing_Property_Fixture_Model
    {
        $record = new Missing_Property_Fixture_Model();
        foreach ($fields as $field => $value) {
            $record->$field = $value;
        }
        $record->save();

        return Missing_Property_Fixture_Model::find($record->id);
    }

    public static function test_a_degraded_enum_property_throws_naming_the_right_one()
    {
        $record = static::__loaded(['name' => 'A', 'role_id' => Missing_Property_Fixture_Model::ROLE_ADMIN]);

        $e = static::__assert_throws(LogicException::class, function () use ($record) {
            return $record->role_id_can_admin_roles;
        }, "has no property 'role_id_can_admin_roles'");

        static::__assert_contains('Missing_Property_Fixture_Model', $e->getMessage(), 'names the model');
        static::__assert_contains("Did you mean 'role_id__can_admin_roles'?", $e->getMessage(), 'suggests the enum property');
    }

    public static function test_a_near_miss_of_a_column_suggests_it()
    {
        $record = static::__loaded(['name' => 'A']);

        $e = static::__assert_throws(LogicException::class, function () use ($record) {
            return $record->nmae;
        }, "has no property 'nmae'");

        static::__assert_contains("Did you mean 'name'?", $e->getMessage(), 'suggests the column');
    }

    public static function test_enum_properties_and_lookups_still_read()
    {
        $admin = static::__loaded(['name' => 'A', 'role_id' => Missing_Property_Fixture_Model::ROLE_ADMIN]);
        $member = static::__loaded(['name' => 'B', 'role_id' => Missing_Property_Fixture_Model::ROLE_MEMBER]);
        $nobody = static::__loaded(['name' => 'C', 'role_id' => null]);

        static::__assert_equals('Admin', $admin->role_id__label);
        static::__assert_true($admin->role_id__can_admin_roles === true, 'custom enum property');
        static::__assert_null($member->role_id__can_admin_roles, 'a declared property this value omits reads as null');
        static::__assert_null($nobody->role_id__label, 'an empty enum column has no label, and that is no error');
        static::__assert_equals([1, 2], $admin->role_id__enum_ids, 'static lookup through an instance');
        static::__assert_equals([1, 2], Missing_Property_Fixture_Model::role_id__enum_ids());
    }

    public static function test_system_and_never_export_columns_still_read()
    {
        $record = static::__loaded(['name' => 'A', '_sys_note' => 'internal', 'secret_token' => 'abc']);

        static::__assert_equals('internal', $record->_sys_note);
        static::__assert_equals('abc', $record->secret_token);
    }

    public static function test_appended_property_and_relationship_still_read()
    {
        $parent = static::__loaded(['name' => 'Parent']);
        $child = static::__loaded(['name' => 'Child', 'parent_id' => $parent->id]);

        static::__assert_equals('Fixture Child', $child->display_name);
        static::__assert_equals($parent->id, $child->parent->id, 'relationship');
        static::__assert_null(static::__loaded(['name' => 'Orphan'])->parent, 'an empty relationship is null, not an error');
    }

    public static function test_detail_accessor_still_reads()
    {
        $record = static::__loaded(['name' => 'A', 'kind_id' => Missing_Property_Fixture_Model::KIND_NOTE]);

        $detail = $record->missing_property_fixture_note;
        static::__assert_instance_of(Missing_Property_Fixture_Note_Detail_Model::class, $detail);
        $detail->body = 'hello';
        $detail->save();

        $fresh = Missing_Property_Fixture_Model::find($record->id);
        static::__assert_equals('hello', $fresh->missing_property_fixture_note->body);
    }

    public static function test_presence_tests_never_throw()
    {
        $record = static::__loaded(['name' => 'A', 'role_id' => Missing_Property_Fixture_Model::ROLE_MEMBER]);

        static::__assert_false(isset($record->role_id_can_admin_roles), 'isset() on a missing key is false');
        static::__assert_equals('fallback', $record->no_such_property ?? 'fallback', '?? takes the default');
        static::__assert_false(isset($record['no_such_property']), 'ArrayAccess presence');
        static::__assert_null(data_get($record, 'no_such_property'), 'data_get() answers null');
        static::__assert_true(isset($record->name), 'a present column is set');

        // ...and the refusal is live again once the presence test returns.
        static::__assert_throws(LogicException::class, function () use ($record) {
            return $record->no_such_property;
        }, "has no property 'no_such_property'");
    }

    public static function test_a_partial_select_throws_for_an_unselected_column()
    {
        $id = DB::table('countries')->insertGetId([
            'alpha2' => 'XQ', 'alpha3' => 'XQX', 'numeric' => '999', 'name' => 'Fixture Country',
        ]);

        $partial = Country_Model::query()->where('id', $id)->select(['id', 'alpha2'])->first();

        static::__assert_equals('XQ', $partial->alpha2, 'a selected column reads');
        static::__assert_throws(LogicException::class, function () use ($partial) {
            return $partial->name;
        }, 'Country_Model::name was not loaded on this record');
        static::__assert_false(isset($partial->name), 'isset() on the unselected column is false');
    }

    public static function test_a_new_record_reads_unassigned_columns_as_null_and_refuses_typos()
    {
        $country = new Country_Model();

        static::__assert_null($country->id, 'an unsaved record has no id');
        static::__assert_null($country->name, 'an unassigned column reads as null');

        static::__assert_throws(LogicException::class, function () use ($country) {
            return $country->nmae;
        }, "Country_Model has no property 'nmae'");
    }

    public static function test_a_typo_on_a_loaded_framework_model_names_the_column()
    {
        $country = Country_Model::query()->first();

        $e = static::__assert_throws(LogicException::class, function () use ($country) {
            return $country->common_nam;
        }, "Country_Model has no property 'common_nam': it is not a column of 'countries'");

        static::__assert_contains("Did you mean 'common_name'?", $e->getMessage(), 'suggests the column');
    }
}
