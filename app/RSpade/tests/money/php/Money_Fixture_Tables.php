<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;

/**
 * The runtime table behind this concern's fixture models, created in a test class's setup()
 * and dropped in its teardown().
 *
 * money_fixtures carries a money column of each nullability (amount NULL, price NOT NULL),
 * a never-exported money column (hidden), a derived money column (doubled, from amount), two
 * DECIMAL columns of the wrong shape for MODEL-MONEY-01 (wide DECIMAL(19,2), rate
 * DECIMAL(8,3)), two floating-point columns (score FLOAT, ratio DOUBLE) and a plain DECIMAL
 * (hours) that is not money. Rsx_Schema is forgotten after the DDL both ways, so the
 * schema-derived casts and the column check read the table as it is.
 */
class Money_Fixture_Tables
{
    public const TABLE = 'money_fixtures';

    /**
     * Create the table and register the fixture models' type refs outside any test
     * transaction, so a rollback can never strand an id the registry still remembers.
     */
    public static function create(): void
    {
        static::drop();

        DB::statement('CREATE TABLE ' . self::TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            amount DECIMAL(15,2) NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            hidden DECIMAL(15,2) NULL,
            doubled DECIMAL(15,2) NULL,
            wide DECIMAL(19,2) NULL,
            rate DECIMAL(8,3) NULL,
            score FLOAT NULL,
            ratio DOUBLE NULL,
            hours DECIMAL(8,2) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        Rsx_Schema::forget();

        foreach (['Money_Fixture_Model', 'Money_Wide_Fixture_Model'] as $model) {
            Type_Ref_Registry::class_to_id($model);
        }
    }

    public static function drop(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::TABLE);

        Rsx_Schema::forget();
    }

    /**
     * One row's stored values, read with plain SQL - the bytes in the table, untouched by any
     * cast.
     *
     * @return array<string, mixed>
     */
    public static function raw_row(int $id): array
    {
        return (array) DB::selectOne('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
    }
}
