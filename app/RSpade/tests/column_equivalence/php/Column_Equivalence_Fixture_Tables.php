<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ColumnEquivalence\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;

/**
 * The runtime table behind Column_Equivalence_Fixture_Model, created in a test class's setup()
 * and dropped in its teardown(). One column of each kind the dirty check and the revision
 * writer judge: a nullable VARCHAR (name), a blank_distinct VARCHAR (code), a plain
 * DECIMAL(8,2) (hours), a money DECIMAL(15,2) (budget), a TINYINT(1) (is_flag), a DATETIME(3)
 * (happened_at), a JSON document (doc), a revision_exclude DATETIME (last_seen_at), a
 * never_export VARCHAR (token) and a type-ref pair (owner_type / owner_id). Rsx_Schema is
 * forgotten after the DDL both ways, so the descriptors read the table as it is.
 */
class Column_Equivalence_Fixture_Tables
{
    public const TABLE = 'column_equivalence_fixtures';

    /**
     * Create the table and register the fixture model's type ref outside any test transaction,
     * so a rollback can never strand an id the registry still remembers.
     */
    public static function create(): void
    {
        static::drop();

        DB::statement('CREATE TABLE ' . self::TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            code VARCHAR(20) NULL,
            hours DECIMAL(8,2) NULL,
            budget DECIMAL(15,2) NULL,
            is_flag TINYINT(1) NULL,
            happened_at DATETIME(3) NULL,
            doc JSON NULL,
            last_seen_at DATETIME NULL,
            token VARCHAR(64) NULL,
            owner_type BIGINT NULL,
            owner_id BIGINT NULL,
            created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            created_by_id BIGINT NULL DEFAULT NULL,
            created_by_type BIGINT NULL DEFAULT NULL,
            updated_by_id BIGINT NULL DEFAULT NULL,
            updated_by_type BIGINT NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        Rsx_Schema::forget();
        Type_Ref_Registry::class_to_id('Column_Equivalence_Fixture_Model');
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
