<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Codegen\Php\Column_Order_Fixture_Detail_Model;
use App\RSpade\Tests\Codegen\Php\Column_Order_Fixture_Model;

/**
 * The generated model docblock is a function of the schema's CONTENT, never of its history.
 *
 * MySQL appends a column added by ALTER TABLE at the end of the table, while the same column
 * arriving through CREATE TABLE (a restored schema snapshot) sits where it was declared. Two
 * databases with identical schemas therefore disagree on ORDINAL_POSITION, and a docblock
 * emitted in physical order churns between every pair of machines that built them differently.
 * The generator lists columns by name, base group first, then each detail table's group.
 *
 * Both histories are built here from the same column set - one CREATE TABLE in declared order,
 * one CREATE TABLE plus ALTER TABLE ADD COLUMN - and build_metadata() must answer the same
 * bytes for both.
 */
class Constants_Regenerate_Order_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const BASE_TABLE = 'codegen_column_order_fixtures';
    private const DETAIL_TABLE = 'codegen_column_order_fixture_details';

    public static function teardown()
    {
        static::__drop_tables();
    }

    // CODEGEN-ORDER-01: @property lines are sorted by name within each group.
    public static function test_property_lines_are_sorted_by_name_within_each_group()
    {
        static::__build_by_create();
        [$doc_block] = (new \App\RSpade\Commands\Rsx\Constants_Regenerate_Command())->build_metadata(Column_Order_Fixture_Model::class);

        $base = [];
        $detail = [];

        foreach (explode("\n", $doc_block) as $line) {
            if (!preg_match('/^ \* @property \S+ \$(\w+)( \(detail: (\w+)\))?$/', $line, $m)) {
                continue;
            }

            if (isset($m[3])) {
                static::__assert_equals(self::DETAIL_TABLE, $m[3]);
                $detail[] = $m[1];
            } else {
                static::__assert_true($detail === [], "Base column {$m[1]} emitted after the detail group");
                $base[] = $m[1];
            }
        }

        static::__assert_true(in_array('zeta_code', $base, true) && in_array('alpha_note', $base, true), 'Base columns missing from the docblock');
        static::__assert_empty(array_intersect(['aardvark_label', 'mid_size', 'omega_flag'], $base), 'A detail column leaked into the base group');

        $sorted_base = $base;
        sort($sorted_base, SORT_STRING);
        static::__assert_equals($sorted_base, $base, 'Base @property lines are not in name order');

        static::__assert_equals(['aardvark_label', 'mid_size', 'omega_flag'], $detail, 'Detail @property lines are not in name order');
    }

    // CODEGEN-ORDER-02: the same schema reached by two histories generates byte-identical output.
    public static function test_two_histories_of_one_schema_generate_identical_docblocks()
    {
        static::__build_by_create();
        $by_create = (new \App\RSpade\Commands\Rsx\Constants_Regenerate_Command())->build_metadata(Column_Order_Fixture_Model::class);

        static::__build_by_alter();

        // Precondition: the two histories really do disagree on physical order.
        static::__assert_not_equals(
            static::__physical_order(self::BASE_TABLE),
            static::__physical_order_after_create(),
            'Fixture did not produce a different physical column order'
        );

        $by_alter = (new \App\RSpade\Commands\Rsx\Constants_Regenerate_Command())->build_metadata(Column_Order_Fixture_Model::class);

        static::__assert_equals($by_create[0], $by_alter[0], 'Docblock depends on physical column order');
        static::__assert_equals($by_create[1], $by_alter[1], 'Constants block depends on physical column order');
    }

    /** @var string[]|null ORDINAL_POSITION order of the base table as the CREATE history left it. */
    private static $__create_order = null;

    private static function __physical_order_after_create(): array
    {
        return static::$__create_order;
    }

    private static function __physical_order(string $table): array
    {
        return array_map(
            fn ($row) => $row->COLUMN_NAME,
            DB::select('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table])
        );
    }

    /**
     * History one: every column declared in CREATE TABLE, in the order a snapshot restores.
     */
    private static function __build_by_create(): void
    {
        static::__drop_tables();

        DB::statement('CREATE TABLE ' . self::BASE_TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            zeta_code VARCHAR(32) NULL,
            type_id BIGINT NOT NULL,
            alpha_note VARCHAR(64) NULL,
            created_at TIMESTAMP(3) NULL,
            updated_at TIMESTAMP(3) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE ' . self::DETAIL_TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ' . Column_Order_Fixture_Detail_Model::parent_key() . ' BIGINT NOT NULL,
            omega_flag TINYINT NULL,
            aardvark_label VARCHAR(32) NULL,
            mid_size INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        static::$__create_order = static::__physical_order(self::BASE_TABLE);
    }

    /**
     * History two: the same columns, two of them added later by ALTER TABLE (appended last).
     */
    private static function __build_by_alter(): void
    {
        static::__drop_tables();

        DB::statement('CREATE TABLE ' . self::BASE_TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type_id BIGINT NOT NULL,
            created_at TIMESTAMP(3) NULL,
            updated_at TIMESTAMP(3) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE ' . self::BASE_TABLE . ' ADD COLUMN alpha_note VARCHAR(64) NULL');
        DB::statement('ALTER TABLE ' . self::BASE_TABLE . ' ADD COLUMN zeta_code VARCHAR(32) NULL');

        DB::statement('CREATE TABLE ' . self::DETAIL_TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ' . Column_Order_Fixture_Detail_Model::parent_key() . ' BIGINT NOT NULL,
            mid_size INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE ' . self::DETAIL_TABLE . ' ADD COLUMN omega_flag TINYINT NULL');
        DB::statement('ALTER TABLE ' . self::DETAIL_TABLE . ' ADD COLUMN aardvark_label VARCHAR(32) NULL');
    }

    private static function __drop_tables(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::DETAIL_TABLE);
        DB::statement('DROP TABLE IF EXISTS ' . self::BASE_TABLE);
    }
}
