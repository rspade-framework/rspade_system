<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;

/**
 * The runtime tables behind this concern's fixture models, created in a test class's
 * setup() and dropped in its teardown().
 *
 * Every table is shaped like a normalized one where it matters: updated_at is TIMESTAMP(3)
 * ON UPDATE CURRENT_TIMESTAMP(3) - so a test can see MySQL bump it - and each carries the
 * audit authorship pairs. Rsx_Schema is forgotten after the DDL both ways, so the
 * first-use column check and the schema-derived casts read these tables as they are.
 */
class Derived_Column_Fixture_Tables
{
    private const TABLES = [
        'derived_plain_fixtures',
        'derived_chain_fixtures',
        'derived_site_fixtures',
        'derived_category_fixtures',
        'derived_lookup_fixtures',
        'derived_missing_fixtures',
        'derived_trashable_fixtures',
        'derived_unstable_fixtures',
    ];

    private const AUDIT_AND_TIMESTAMPS = '
        created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        created_by_id BIGINT NULL DEFAULT NULL,
        created_by_type BIGINT NULL DEFAULT NULL,
        updated_by_id BIGINT NULL DEFAULT NULL,
        updated_by_type BIGINT NULL DEFAULT NULL';

    private const OPTIONS = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Create every fixture table except derived_missing_fixtures (whose absence is itself a
     * case), and register the fixture models' type refs outside any test transaction so a
     * rollback can never strand an id the registry still remembers.
     */
    public static function create(): void
    {
        static::drop();

        DB::statement('CREATE TABLE derived_plain_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NULL,
            first_name VARCHAR(255) NULL,
            last_name VARCHAR(255) NULL,
            full_name VARCHAR(255) NULL,
            note VARCHAR(255) NULL,' . self::AUDIT_AND_TIMESTAMPS . ',
            UNIQUE KEY uniq_derived_plain_code (code)
        )' . self::OPTIONS);

        DB::statement('CREATE TABLE derived_chain_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            base_value BIGINT NULL,
            doubled BIGINT NULL,
            quadrupled BIGINT NULL,' . self::AUDIT_AND_TIMESTAMPS . '
        )' . self::OPTIONS);

        DB::statement('CREATE TABLE derived_site_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL,
            name VARCHAR(255) NULL,
            label VARCHAR(255) NULL,
            note VARCHAR(255) NULL,' . self::AUDIT_AND_TIMESTAMPS . '
        )' . self::OPTIONS);

        DB::statement('CREATE TABLE derived_category_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,' . self::AUDIT_AND_TIMESTAMPS . '
        )' . self::OPTIONS);

        DB::statement('CREATE TABLE derived_lookup_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category_id BIGINT NULL,
            category_name VARCHAR(255) NULL,' . self::AUDIT_AND_TIMESTAMPS . '
        )' . self::OPTIONS);

        DB::statement('CREATE TABLE derived_trashable_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            site_id BIGINT NOT NULL,
            name VARCHAR(255) NULL,
            label VARCHAR(255) NULL,
            site_fixture_count BIGINT NULL,
            deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
            deleted_by_id BIGINT NULL DEFAULT NULL,
            deleted_by_type BIGINT NULL DEFAULT NULL,' . self::AUDIT_AND_TIMESTAMPS . '
        )' . self::OPTIONS);

        DB::statement('CREATE TABLE derived_unstable_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tick BIGINT NULL,' . self::AUDIT_AND_TIMESTAMPS . '
        )' . self::OPTIONS);

        Rsx_Schema::forget();

        foreach (['Derived_Plain_Fixture_Model', 'Derived_Chain_Fixture_Model', 'Derived_Site_Fixture_Model',
            'Derived_Category_Fixture_Model', 'Derived_Lookup_Fixture_Model', 'Derived_Missing_Column_Fixture_Model',
            'Derived_Trashable_Site_Fixture_Model', 'Derived_Unstable_Fixture_Model'] as $model) {
            Type_Ref_Registry::class_to_id($model);
        }
    }

    /**
     * Create derived_missing_fixtures: every column the model's table would need except the
     * `ghost` its declaration names.
     */
    public static function create_missing_column_table(): void
    {
        DB::statement('DROP TABLE IF EXISTS derived_missing_fixtures');
        DB::statement('CREATE TABLE derived_missing_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            shown VARCHAR(255) NULL,' . self::AUDIT_AND_TIMESTAMPS . '
        )' . self::OPTIONS);

        Rsx_Schema::forget();
    }

    public static function drop(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }

        Rsx_Schema::forget();
    }

    /**
     * One row's stored values, read with plain SQL - the bytes in the table, untouched by any
     * cast.
     *
     * @return array<string, mixed>
     */
    public static function raw_row(string $table, int $id): array
    {
        return (array) DB::selectOne("SELECT * FROM {$table} WHERE id = ?", [$id]);
    }
}
