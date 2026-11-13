<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use LogicException;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\Model_Columns_Fixture_Base_Model_Abstract;
use App\RSpade\Tests\Database\Php\Model_Columns_Fixture_Child_Model_Abstract;
use App\RSpade\Tests\Database\Php\Model_Columns_Fixture_Grandchild_Model;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Wrapped_Text;

/**
 * The $columns declaration read through Rsx_Model_Abstract's accessors: the base-first
 * overlay where a class's entry replaces its parent's for the same column, each kind view,
 * enum_values() refusing a column that is not an enum, the audit type-ref columns on every
 * model, and per-class memoization that never answers one class with another's columns.
 *
 * The fixtures are a three-class chain declared through $columns only, with no table: every
 * answer here is a fact about the code.
 */
class Model_Columns_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const AUDIT_TYPE_REFS = ['created_by_type', 'updated_by_type', 'deleted_by_type'];

    public static function test_the_base_declaration_is_overlaid_on_the_audit_entries()
    {
        static::__assert_equals(
            [
                'created_by_type' => ['type_ref' => true],
                'updated_by_type' => ['type_ref' => true],
                'deleted_by_type' => ['type_ref' => true],
                'status_id' => ['enum' => [1 => ['label' => 'Open'], 2 => ['label' => 'Closed']]],
                'body' => ['text_type' => Text_Fixture_Wrapped_Text::class],
                'subject_type' => ['type_ref' => true],
                'api_secret' => ['never_export' => true],
                'view_count' => ['revision_exclude' => true],
            ],
            Model_Columns_Fixture_Base_Model_Abstract::columns()
        );
    }

    public static function test_a_child_entry_replaces_the_parent_entry_wholesale()
    {
        $columns = Model_Columns_Fixture_Child_Model_Abstract::columns();

        static::__assert_equals(
            ['enum' => [3 => ['label' => 'Archived']]],
            $columns['status_id'],
            'the replaced value map is the child\'s alone - the parent\'s values are not merged in'
        );
        static::__assert_equals([], $columns['view_count'], 'an empty entry declares the column plain');
        static::__assert_equals(
            ['text_type' => Text_Fixture_Wrapped_Text::class],
            $columns['body'],
            'an entry the child does not name is inherited'
        );
        static::__assert_equals(
            [
                'created_by_type', 'updated_by_type', 'deleted_by_type',
                'status_id', 'body', 'subject_type', 'api_secret', 'view_count',
                'priority_id',
            ],
            array_keys($columns),
            'base first: a replaced entry keeps its place, a new one follows'
        );
    }

    public static function test_the_overlay_walks_every_class_of_the_chain()
    {
        $columns = Model_Columns_Fixture_Grandchild_Model::columns();

        static::__assert_equals([], $columns['body'], 'the grandchild declares body plain');
        static::__assert_equals(['never_export' => true, 'revision_exclude' => true], $columns['api_secret']);
        static::__assert_equals(['type_ref' => true], $columns['owner_type']);
        static::__assert_equals(
            ['enum' => [3 => ['label' => 'Archived']]],
            $columns['status_id'],
            'the middle class\'s replacement reaches the leaf'
        );
        static::__assert_equals([], $columns['view_count'], 'the middle class\'s plain entry reaches the leaf');
        static::__assert_equals(['type_ref' => true], $columns['subject_type'], 'the base\'s entry reaches the leaf');
    }

    public static function test_the_enum_view()
    {
        static::__assert_equals(
            ['status_id' => [1 => ['label' => 'Open'], 2 => ['label' => 'Closed']]],
            Model_Columns_Fixture_Base_Model_Abstract::enum_columns()
        );
        static::__assert_equals(
            [
                'status_id' => [3 => ['label' => 'Archived']],
                'priority_id' => [1 => ['label' => 'Low'], 2 => ['label' => 'High']],
            ],
            Model_Columns_Fixture_Grandchild_Model::enum_columns()
        );
        static::__assert_equals(
            [1 => ['label' => 'Low'], 2 => ['label' => 'High']],
            Model_Columns_Fixture_Child_Model_Abstract::enum_values('priority_id')
        );
    }

    public static function test_enum_values_throws_on_a_column_that_is_not_an_enum()
    {
        static::__assert_throws(
            LogicException::class,
            fn () => Model_Columns_Fixture_Child_Model_Abstract::enum_values('body'),
            'is not an enum column'
        );
        static::__assert_throws(
            LogicException::class,
            fn () => Model_Columns_Fixture_Child_Model_Abstract::enum_values('no_such_column'),
            'is not an enum column'
        );
        static::__assert_throws(
            LogicException::class,
            fn () => Model_Columns_Fixture_Base_Model_Abstract::enum_values('priority_id'),
            'is not an enum column'
        );
    }

    public static function test_the_text_type_view()
    {
        static::__assert_equals(
            ['body' => Text_Fixture_Wrapped_Text::class],
            Model_Columns_Fixture_Child_Model_Abstract::text_type_columns()
        );
        static::__assert_equals(Text_Fixture_Wrapped_Text::class, Model_Columns_Fixture_Child_Model_Abstract::text_type_or_null('body'));
        static::__assert_equals([], Model_Columns_Fixture_Grandchild_Model::text_type_columns(), 'body declared plain below');
        static::__assert_equals(null, Model_Columns_Fixture_Grandchild_Model::text_type_or_null('body'));
    }

    public static function test_the_type_ref_view()
    {
        static::__assert_equals(
            [...self::AUDIT_TYPE_REFS, 'subject_type'],
            Model_Columns_Fixture_Child_Model_Abstract::type_ref_columns()
        );
        static::__assert_equals(
            [...self::AUDIT_TYPE_REFS, 'subject_type', 'owner_type'],
            Model_Columns_Fixture_Grandchild_Model::type_ref_columns()
        );
    }

    public static function test_the_never_export_and_revision_exclude_views()
    {
        static::__assert_equals(['api_secret'], Model_Columns_Fixture_Child_Model_Abstract::never_exported_columns());
        static::__assert_equals(['api_secret'], Model_Columns_Fixture_Grandchild_Model::never_exported_columns());

        static::__assert_equals(['view_count'], Model_Columns_Fixture_Base_Model_Abstract::revision_excluded_columns());
        static::__assert_equals([], Model_Columns_Fixture_Child_Model_Abstract::revision_excluded_columns(), 'view_count declared plain');
        static::__assert_equals(['api_secret'], Model_Columns_Fixture_Grandchild_Model::revision_excluded_columns());
    }

    /**
     * The three audit type columns are declared on the base, so every model answers them -
     * the fixtures, and every concrete model this application has.
     */
    public static function test_every_model_carries_the_audit_type_refs()
    {
        $classes = [
            Model_Columns_Fixture_Base_Model_Abstract::class,
            Model_Columns_Fixture_Child_Model_Abstract::class,
            Model_Columns_Fixture_Grandchild_Model::class,
        ];

        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $entry) {
            $classes[] = $entry['fqcn'];
        }

        static::__assert_greater_than(3, count($classes), 'the manifest must name the application\'s models');

        foreach ($classes as $fqcn) {
            foreach (self::AUDIT_TYPE_REFS as $column) {
                static::__assert_true(
                    in_array($column, $fqcn::type_ref_columns(), true),
                    "{$fqcn}::type_ref_columns() must carry {$column}"
                );
                static::__assert_equals(true, $fqcn::columns()[$column]['type_ref'] ?? null, "{$fqcn}: {$column} entry");
            }
        }
    }

    /**
     * Memoized per class: asking the leaf first must not answer its ancestors with the
     * leaf's columns, and asking again answers the same.
     */
    public static function test_the_memo_is_per_class()
    {
        $grandchild = Model_Columns_Fixture_Grandchild_Model::columns();
        $base = Model_Columns_Fixture_Base_Model_Abstract::columns();
        $child = Model_Columns_Fixture_Child_Model_Abstract::columns();

        static::__assert_false(array_key_exists('owner_type', $base), 'base does not see the leaf\'s column');
        static::__assert_false(array_key_exists('owner_type', $child), 'child does not see the leaf\'s column');
        static::__assert_false(array_key_exists('priority_id', $base), 'base does not see the child\'s column');
        static::__assert_equals(['enum' => [1 => ['label' => 'Open'], 2 => ['label' => 'Closed']]], $base['status_id']);
        static::__assert_equals(['revision_exclude' => true], $base['view_count']);
        static::__assert_equals(['text_type' => Text_Fixture_Wrapped_Text::class], $child['body']);

        static::__assert_equals($grandchild, Model_Columns_Fixture_Grandchild_Model::columns(), 'a second ask answers the same');
        static::__assert_equals(['api_secret'], Model_Columns_Fixture_Base_Model_Abstract::never_exported_columns());
        static::__assert_equals([], Model_Columns_Fixture_Child_Model_Abstract::revision_excluded_columns());
    }
}
