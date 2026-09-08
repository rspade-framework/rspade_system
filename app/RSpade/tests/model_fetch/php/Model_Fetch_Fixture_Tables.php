<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ModelFetch\Php;

use Illuminate\Support\Facades\DB;

/**
 * The DDL behind this concern's fixture models.
 *
 * The tables live here rather than in a migration because a framework migration would ship
 * them to every installed application; a test class creates them in setup() and drops them
 * in teardown(). setup()/teardown() run OUTSIDE the per-test transaction, so the DDL is
 * safe there and the rows each test inserts still roll back.
 *
 * Every column the model layer stamps by itself is present (the audit pairs, the
 * timestamps, deleted_at), because a fixture that omits them would be proving something
 * about a table shape RSpade never produces.
 */
class Model_Fetch_Fixture_Tables
{
    public static function create(): void
    {
        static::drop();

        DB::statement('CREATE TABLE model_fetch_parent_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT NULL,
            updated_at TIMESTAMP(3) NULL DEFAULT NULL,
            deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
            created_by_id BIGINT NULL,
            created_by_type BIGINT NULL,
            updated_by_id BIGINT NULL,
            updated_by_type BIGINT NULL,
            deleted_by_id BIGINT NULL,
            deleted_by_type BIGINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        DB::statement('CREATE TABLE model_fetch_child_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_fixture_id BIGINT NOT NULL,
            title VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT NULL,
            updated_at TIMESTAMP(3) NULL DEFAULT NULL,
            deleted_at TIMESTAMP(3) NULL DEFAULT NULL,
            created_by_id BIGINT NULL,
            created_by_type BIGINT NULL,
            updated_by_id BIGINT NULL,
            updated_by_type BIGINT NULL,
            deleted_by_id BIGINT NULL,
            deleted_by_type BIGINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // The marker fixture's fetch() never reads a row, but the endpoint preloads the
        // requested ids before calling it, so the table has to exist.
        DB::statement('CREATE TABLE model_fetch_marker_fixtures (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NULL,
            created_at TIMESTAMP(3) NULL DEFAULT NULL,
            updated_at TIMESTAMP(3) NULL DEFAULT NULL,
            created_by_id BIGINT NULL,
            created_by_type BIGINT NULL,
            updated_by_id BIGINT NULL,
            updated_by_type BIGINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public static function drop(): void
    {
        DB::statement('DROP TABLE IF EXISTS model_fetch_child_fixtures');
        DB::statement('DROP TABLE IF EXISTS model_fetch_parent_fixtures');
        DB::statement('DROP TABLE IF EXISTS model_fetch_marker_fixtures');
    }
}
