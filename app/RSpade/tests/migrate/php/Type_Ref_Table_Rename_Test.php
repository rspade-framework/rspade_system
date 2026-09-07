<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Table_Rename;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * `_type_refs` stores a class name AND the table that class lives in, so a migration that
 * renames a table would leave the registry row naming a table that is gone. The migrate
 * pipeline follows the rename: every statement it executes passes through one macro, which
 * hands it to Type_Ref_Table_Rename::observe(), and execute_migrations() applies the
 * accumulated renames to `_type_refs.table_name` in the same run.
 *
 * Two halves are pinned here:
 *   - the PARSER, over every spelling MySQL accepts and the three RENAME statements that
 *     are NOT table renames;
 *   - the APPLY, against planted `_type_refs` rows inside the per-test transaction
 *     (apply() reads and writes `_type_refs` only - no DDL, so nothing implicitly commits).
 *
 * The WIRING - that the pipeline actually calls both - is pinned by source structure, the
 * way Migrate_Normalize_Complete_Event_Test pins the normalize hook's position.
 */
class Type_Ref_Table_Rename_Test extends Rsx_Test_Abstract
{
    const COMMAND_CLASS = 'App\\RSpade\\Commands\\Migrate\\Maint_Migrate';

    public static function setup(): void
    {
        Type_Ref_Table_Rename::reset();
    }

    public static function teardown(): void
    {
        Type_Ref_Table_Rename::reset();
    }

    /** Observe one statement in isolation and return the recorded pairs. */
    private static function __observe(string $sql): array
    {
        Type_Ref_Table_Rename::reset();
        Type_Ref_Table_Rename::observe($sql);

        return Type_Ref_Table_Rename::observed();
    }

    private static function __plant_type_ref(string $class_name, string $table_name): int
    {
        DB::insert(
            'INSERT INTO _type_refs (class_name, table_name, created_at, updated_at)'
            . ' VALUES (?, ?, NOW(3), NOW(3))',
            [$class_name, $table_name]
        );

        return (int) DB::select('SELECT id FROM _type_refs WHERE class_name = ?', [$class_name])[0]->id;
    }

    private static function __table_name_of(int $id): ?string
    {
        return DB::select('SELECT table_name FROM _type_refs WHERE id = ?', [$id])[0]->table_name;
    }

    // =====================================================================
    // The parser
    // =====================================================================

    public static function test_rename_table_is_recognised()
    {
        static::__assert_equals(
            [['from' => 'old_widgets', 'to' => 'new_widgets']],
            static::__observe('RENAME TABLE old_widgets TO new_widgets'),
            'the plain form'
        );
    }

    public static function test_backticks_and_a_database_qualifier_are_stripped()
    {
        static::__assert_equals(
            [['from' => 'old_widgets', 'to' => 'new_widgets']],
            static::__observe('RENAME TABLE `rspade`.`old_widgets` TO `rspade`.`new_widgets`'),
            '_type_refs stores a bare table name'
        );
    }

    public static function test_a_multi_rename_list_records_every_pair()
    {
        static::__assert_equals(
            [
                ['from' => 'a_table', 'to' => 'b_table'],
                ['from' => 'c_table', 'to' => 'd_table'],
            ],
            static::__observe('RENAME TABLE `a_table` TO `b_table`, `c_table` TO `d_table`;'),
            'every clause of a multi-rename list'
        );
    }

    public static function test_alter_table_rename_spellings_are_recognised()
    {
        foreach (['RENAME TO', 'RENAME AS', 'RENAME'] as $spelling) {
            static::__assert_equals(
                [['from' => 'old_widgets', 'to' => 'new_widgets']],
                static::__observe('ALTER TABLE old_widgets ' . $spelling . ' new_widgets'),
                'ALTER TABLE ... ' . $spelling
            );
        }
    }

    public static function test_multiline_whitespace_does_not_defeat_the_parser()
    {
        static::__assert_equals(
            [['from' => 'old_widgets', 'to' => 'new_widgets']],
            static::__observe("RENAME TABLE\n    old_widgets\n    TO new_widgets\n"),
            'migrations are written as heredoc SQL'
        );
    }

    public static function test_renaming_a_column_index_or_key_is_not_a_table_rename()
    {
        foreach (
            [
                'ALTER TABLE widgets RENAME COLUMN old_name TO new_name',
                'ALTER TABLE widgets RENAME INDEX old_index TO new_index',
                'ALTER TABLE widgets RENAME KEY old_key TO new_key',
            ] as $sql
        ) {
            static::__assert_empty(static::__observe($sql), 'not a table rename: ' . $sql);
        }
    }

    public static function test_ordinary_ddl_is_ignored()
    {
        static::__assert_empty(
            static::__observe('CREATE TABLE widgets (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY)'),
            'a CREATE TABLE records nothing'
        );
        static::__assert_empty(
            static::__observe('ALTER TABLE widgets ADD COLUMN label VARCHAR(255) NULL'),
            'an ordinary ALTER records nothing'
        );
    }

    // =====================================================================
    // The apply
    // =====================================================================

    public static function test_a_renamed_table_is_followed_into_the_registry_row()
    {
        $id = static::__plant_type_ref('Rename_Followed_Test_Model', 'rename_test_before');

        Type_Ref_Table_Rename::reset();
        Type_Ref_Table_Rename::observe('RENAME TABLE `rename_test_before` TO `rename_test_after`');

        $updated = Type_Ref_Table_Rename::apply();

        static::__assert_equals(
            [['class_name' => 'Rename_Followed_Test_Model', 'from' => 'rename_test_before', 'to' => 'rename_test_after']],
            $updated,
            'the narrative names the class and both table names'
        );
        static::__assert_equals(
            'rename_test_after',
            static::__table_name_of($id),
            'the registry row followed the rename'
        );
    }

    public static function test_a_chain_of_renames_lands_on_the_final_name()
    {
        $id = static::__plant_type_ref('Rename_Chain_Test_Model', 'chain_one');

        Type_Ref_Table_Rename::reset();
        Type_Ref_Table_Rename::observe('RENAME TABLE chain_one TO chain_two');
        Type_Ref_Table_Rename::observe('ALTER TABLE chain_two RENAME TO chain_three');

        $updated = Type_Ref_Table_Rename::apply();

        static::__assert_count(2, $updated, 'both hops are narrated');
        static::__assert_equals('chain_three', static::__table_name_of($id), 'the row lands on the final name');
    }

    public static function test_a_rename_of_a_table_no_type_ref_names_changes_nothing()
    {
        Type_Ref_Table_Rename::reset();
        Type_Ref_Table_Rename::observe('RENAME TABLE no_type_ref_names_this TO nor_this');

        static::__assert_empty(Type_Ref_Table_Rename::apply(), 'nothing to update, nothing narrated');
    }

    public static function test_apply_is_a_no_op_when_nothing_was_renamed()
    {
        Type_Ref_Table_Rename::reset();

        static::__assert_empty(Type_Ref_Table_Rename::apply(), 'a run with no renames touches nothing');
    }

    // =====================================================================
    // The wiring
    // =====================================================================

    public static function test_the_statement_macro_observes_every_migration_statement()
    {
        $method = new ReflectionMethod(self::COMMAND_CLASS, 'register_query_transformer');
        $lines = file($method->getFileName());
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        static::__assert_contains(
            'Type_Ref_Table_Rename::observe(',
            $source,
            'the one macro every migration statement passes through hands it to the observer'
        );
    }

    public static function test_execute_migrations_applies_the_renames()
    {
        $method = new ReflectionMethod(self::COMMAND_CLASS, 'execute_migrations');
        $lines = file($method->getFileName());
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        static::__assert_contains(
            'Type_Ref_Table_Rename::reset()',
            $source,
            'the run starts from a clean slate'
        );
        static::__assert_contains(
            'apply_type_ref_table_renames()',
            $source,
            'the renames are applied in the same migrate run'
        );
    }
}
