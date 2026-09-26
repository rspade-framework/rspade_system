<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * 2026_09_25_193921_reindex_identity_tables drops three indexes BY COLUMN rather than by
 * name, because the migrations that created them skip their whole step when the column
 * already exists: an application that site-scoped user_profiles (or soft-deleted an actor
 * table) in its own migration first indexed the column under its own name, and the
 * framework's name never existed there.
 *
 * The lookup must drop every index whose ONLY column is the one named, whatever it is
 * called, leave a composite that merely contains the column alone, and do nothing when no
 * such index exists. Runs the migration's own helper against a throwaway probe table.
 */
class Reindex_By_Column_Drop_Test extends Rsx_Test_Abstract
{
    // Real DDL, which auto-commits and cannot participate in the per-test transaction.
    protected static $use_database_transactions = false;

    private const PROBE = '_reindex_by_column_probe';

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::PROBE);
    }

    private static function __index_names(): array
    {
        $names = [];

        foreach (DB::select('SHOW INDEXES FROM `' . self::PROBE . '`') as $row) {
            $names[$row->Key_name] = true;
        }

        return array_keys($names);
    }

    private static function __drop_by_column(string $column): void
    {
        $migration = require base_path('database/migrations/2026_09_25_193921_reindex_identity_tables.php');

        $method = new \ReflectionMethod($migration, 'drop_single_column_indexes');
        $method->invoke($migration, self::PROBE, $column);
    }

    public static function test_an_application_named_index_on_the_column_is_dropped_and_a_composite_kept()
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::PROBE);
        DB::statement(
            'CREATE TABLE ' . self::PROBE . ' (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                site_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                KEY idx_app_named_site (site_id),
                KEY idx_site_user (site_id, user_id),
                KEY idx_user (user_id)
            )'
        );

        static::__drop_by_column('site_id');

        $names = static::__index_names();
        sort($names);

        static::__assert_equals(['PRIMARY', 'idx_site_user', 'idx_user'], $names);
    }

    public static function test_no_single_column_index_on_the_column_drops_nothing()
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::PROBE);
        DB::statement(
            'CREATE TABLE ' . self::PROBE . ' (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                deleted_at TIMESTAMP(3) NULL,
                user_id BIGINT NOT NULL,
                KEY idx_user_deleted (user_id, deleted_at)
            )'
        );

        static::__drop_by_column('deleted_at');

        $names = static::__index_names();
        sort($names);

        static::__assert_equals(['PRIMARY', 'idx_user_deleted'], $names);
    }
}
