<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use LogicException;
use App\RSpade\CodeQuality\Rules\Models\ModelEnumColumns_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Forms\Form_Edit;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\Schema_Absent_Table_Fixture_Model;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Column_Fixture_Tables;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Missing_Column_Fixture_Model;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Plain_Fixture_Model;

/**
 * The first-use schema check (Rsx_Model_Abstract::_assert_columns_exist()): every declared
 * column and every `from` name is a column of the model's table, checked on the WRITE side -
 * save() of a model with derived columns, rederive(), Form_Edit::run() - memoized, and never
 * on an attribute read. MODEL-ENUM-01 reports the same comparison in rsx:check.
 */
class Derived_Column_Schema_Check_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Derived_Column_Fixture_Tables::create();
        Derived_Column_Fixture_Tables::create_missing_column_table();
    }

    public static function teardown()
    {
        Derived_Column_Fixture_Tables::drop();
    }

    // DERIVED-SCHEMA-PURE: the comparison, over a synthetic column map.
    public static function test_the_comparison_names_declared_and_from_columns_and_exempts_the_audit_columns()
    {
        static::__assert_equals(
            ['full_name' => 'declares', 'first_name' => "names in ['full_name']['from']"],
            Derived_Plain_Fixture_Model::_columns_missing_from(['id' => [], 'last_name' => []]),
            'a missing declared column and a missing from column, and no audit type column'
        );
        static::__assert_equals(
            [],
            Derived_Plain_Fixture_Model::_columns_missing_from(['first_name' => [], 'last_name' => [], 'full_name' => []])
        );
    }

    // DERIVED-SCHEMA-NO-TABLE
    public static function test_a_model_whose_table_is_absent_throws()
    {
        static::__assert_throws(
            LogicException::class,
            fn () => Schema_Absent_Table_Fixture_Model::_assert_columns_exist(),
            "Schema_Absent_Table_Fixture_Model::\$columns cannot be checked: the model's table is not in the connected database"
        );
    }

    // DERIVED-SCHEMA-SAVE: save() of a model with derived columns refuses a missing `from`.
    public static function test_save_refuses_a_from_column_the_table_lacks()
    {
        $record = new Derived_Missing_Column_Fixture_Model();

        static::__assert_throws(
            LogicException::class,
            fn () => $record->save(),
            "Derived_Missing_Column_Fixture_Model::\$columns names in ['shown']['from'] 'ghost', which is not a column of the model's table"
        );
        static::__assert_false($record->exists, 'nothing was written');
    }

    // DERIVED-SCHEMA-FORM-EDIT
    public static function test_form_edit_run_checks_the_declaration()
    {
        static::__assert_throws(
            LogicException::class,
            fn () => Form_Edit::run(new Derived_Missing_Column_Fixture_Model(), ['_changed' => []], fn () => null),
            "'ghost'"
        );
    }

    // DERIVED-SCHEMA-NOT-ON-READ: reading attributes never runs the check (a model read
    // during a migration that has not yet added a declared column must work).
    public static function test_an_attribute_read_does_not_check()
    {
        $record = new Derived_Missing_Column_Fixture_Model();
        $record->setRawAttributes(['id' => 1, 'shown' => 'value']);

        static::__assert_equals('value', $record->shown, 'the read works although the declaration is wrong');
        static::__assert_equals('value', $record->toArray()['shown']);
    }

    // DERIVED-SCHEMA-MEMO: a passing check is remembered for the schema's lifetime.
    public static function test_a_passing_check_is_memoized()
    {
        static::__assert_null(Rsx_Schema::derived('columns_checked|' . Derived_Plain_Fixture_Model::class));

        Derived_Plain_Fixture_Model::_assert_columns_exist();

        static::__assert_true(Rsx_Schema::derived('columns_checked|' . Derived_Plain_Fixture_Model::class));
    }

    // DERIVED-SCHEMA-RULE: MODEL-ENUM-01 reports the same missing column in rsx:check.
    public static function test_model_enum_01_reports_the_missing_from_column()
    {
        $path = (new \ReflectionClass(Derived_Missing_Column_Fixture_Model::class))->getFileName();
        $collector = new ViolationCollector();
        (new ModelEnumColumns_CodeQualityRule($collector))->check($path, '', ['class' => 'Derived_Missing_Column_Fixture_Model']);

        $violations = array_values($collector->get_by_rule('MODEL-ENUM-01'));
        static::__assert_count(1, $violations);
        static::__assert_contains("'ghost'", $violations[0]->message);
        static::__assert_contains("table 'derived_missing_fixtures'", $violations[0]->message);

        $collector = new ViolationCollector();
        $plain = (new \ReflectionClass(Derived_Plain_Fixture_Model::class))->getFileName();
        (new ModelEnumColumns_CodeQualityRule($collector))->check($plain, '', ['class' => 'Derived_Plain_Fixture_Model']);
        static::__assert_count(0, $collector->get_by_rule('MODEL-ENUM-01'), 'a model whose declarations match its table is clean');
    }
}
