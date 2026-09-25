<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * `migrate:normalize_schema` gives every table an index LEADING with created_at and one
 * leading with updated_at - and decides "already has one" by the LEADING COLUMN, never by
 * the index name.
 *
 * Name-based detection added a second, identical `created_at(created_at)` beside every
 * migration that had already declared `idx_x_created_at (created_at)`: pure write cost on
 * every insert. A composite that merely CONTAINS the column - `(site_id, created_at)` -
 * does not lead with it, cannot serve a filter or sort on it alone, and must not count.
 *
 * Runs real passes against throwaway probe tables; the framework's own tables are only
 * read.
 */
class Normalize_Schema_Timestamp_Index_Test extends Rsx_Test_Abstract
{
    // Real DDL, which auto-commits and cannot participate in the per-test transaction.
    protected static $use_database_transactions = false;

    private const COVERED = '_normalize_ts_covered_probe';
    private const CONTAINED = '_normalize_ts_contained_probe';

    private static function __drop(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::COVERED);
        DB::statement('DROP TABLE IF EXISTS ' . self::CONTAINED);
    }

    public static function teardown()
    {
        static::__drop();
    }

    /**
     * Every index on $table as name => [columns in order].
     *
     * @return array<string, string[]>
     */
    private static function __indexes(string $table): array
    {
        $indexes = [];

        foreach (DB::select("SHOW INDEXES FROM `{$table}`") as $row) {
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
        }

        foreach ($indexes as $name => $columns) {
            ksort($columns);
            $indexes[$name] = array_values($columns);
        }

        return $indexes;
    }

    private static function __leading(string $table, string $column): array
    {
        return array_keys(array_filter(
            static::__indexes($table),
            static fn (array $columns) => $columns[0] === $column
        ));
    }

    private static function __normalize(): void
    {
        Artisan::call('migrate:normalize_schema', ['--production' => true]);
    }

    public static function test_an_index_already_leading_with_the_column_is_enough_whatever_its_name()
    {
        static::__drop();

        DB::statement('CREATE TABLE ' . self::COVERED . ' ('
            . ' id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . ' created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),'
            . ' updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),'
            . ' INDEX idx_probe_created (created_at),'
            . ' INDEX idx_probe_updated_then_id (updated_at, id)'
            . ' ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        static::__normalize();

        static::__assert_equals(
            ['idx_probe_created'],
            static::__leading(self::COVERED, 'created_at'),
            'the migration-named created_at index satisfies the pass; no second copy is added'
        );
        static::__assert_equals(
            ['idx_probe_updated_then_id'],
            static::__leading(self::COVERED, 'updated_at'),
            'a composite LEADING with updated_at satisfies it too'
        );
    }

    public static function test_a_composite_that_only_contains_the_column_does_not_count()
    {
        static::__drop();

        DB::statement('CREATE TABLE ' . self::CONTAINED . ' ('
            . ' id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . ' site_id BIGINT NOT NULL,'
            . ' created_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),'
            . ' updated_at TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),'
            . ' INDEX idx_probe_site_created (site_id, created_at)'
            . ' ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        static::__normalize();

        static::__assert_equals(
            ['created_at'],
            static::__leading(self::CONTAINED, 'created_at'),
            '(site_id, created_at) does not lead with created_at, so the pass adds created_at(created_at)'
        );
        static::__assert_equals(['updated_at'], static::__leading(self::CONTAINED, 'updated_at'), 'and updated_at(updated_at)');
        static::__assert_array_has_key('idx_probe_site_created', static::__indexes(self::CONTAINED), 'the composite itself is untouched');
    }

    public static function test_every_table_leads_an_index_with_each_timestamp_after_a_pass()
    {
        static::__drop();
        static::__normalize();

        $offenders = DB::select("
            SELECT t.table_name AS table_name, c.column_name AS column_name, COUNT(s.index_name) AS leading_count
            FROM information_schema.tables t
            JOIN (SELECT 'created_at' AS column_name UNION ALL SELECT 'updated_at') c
            LEFT JOIN information_schema.statistics s
                ON s.table_schema = t.table_schema
                AND s.table_name = t.table_name
                AND s.seq_in_index = 1
                AND s.column_name = c.column_name
            WHERE t.table_schema = DATABASE()
                AND t.table_type = 'BASE TABLE'
                AND t.table_name <> '_migrations'
            GROUP BY t.table_name, c.column_name
            HAVING COUNT(s.index_name) = 0
        ");

        static::__assert_empty(
            array_map(static fn ($row) => "{$row->table_name}.{$row->column_name}", $offenders),
            'every table carries an index leading with created_at and one leading with updated_at'
        );
    }
}
