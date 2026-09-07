<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use App\RSpade\Commands\Migrate\Migrate_Normalize_Schema_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The audit-pair normalizer must never manufacture the collision it refuses.
 *
 * THE DEFECT (field report, 2026-08-10). normalizeAuditColumns() renamed first and complained
 * later. On a table carrying BOTH a framework's own pre-pair `created_by` and an app
 * `created_by_user_id`, the rename produced exactly the `created_by` + `created_by_id` pair
 * the next invocation rejects - the pass's own first action created the state its second
 * action refuses.
 *
 * That was worse than a bad error for two reasons. DDL AUTO-COMMITS in MySQL, so a failed
 * `migrate` rolled back the data but not the ALTER and the table kept both columns forever.
 * And this pass runs BEFORE any migration, so once it threw, no repair migration could ever
 * run - only hand-written SQL could recover the database.
 *
 * Contract now: decide before mutating. An all-NULL `{base}` is vestigial and is dropped
 * (lossless, and it self-heals a database already dead-ended by the old order); real data in
 * both refuses HAVING CHANGED NOTHING, so the database stays migratable.
 *
 * The private method is exercised directly by reflection: running the whole command would
 * walk every table in the schema, which tests the wrong thing and couples this to unrelated
 * tables.
 */
class Audit_Pair_Convergence_Test extends Rsx_Test_Abstract
{
    // Real DDL, which auto-commits and cannot participate in the per-test transaction.
    protected static $use_database_transactions = false;

    private const TABLE = '_audit_pair_probe';

    /**
     * normalizeAuditColumns() QUEUES clauses onto the table's pending ALTER TABLE rather than
     * issuing one statement per change, so the flush is part of invoking it - on ONE instance,
     * since the pending state lives there. A refusal throws before the flush, which is exactly
     * the property test_real_data_in_both_refuses_without_mutating() pins.
     */
    private static function __normalize(string $table, string $base): void
    {
        $command = new Migrate_Normalize_Schema_Command();

        $normalize = new ReflectionMethod(Migrate_Normalize_Schema_Command::class, 'normalizeAuditColumns');
        $normalize->setAccessible(true);
        $normalize->invoke($command, $table, $base);

        $flush = new ReflectionMethod(Migrate_Normalize_Schema_Command::class, '__flush_alter');
        $flush->setAccessible(true);
        $flush->invoke($command, $table);
    }

    private static function __drop(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    private static function __value(string $column): int
    {
        $rows = DB::select('SELECT ' . $column . ' AS v FROM ' . self::TABLE . ' WHERE id = ?', [1]);

        return (int) ($rows[0]->v ?? 0);
    }

    private static function __columns(): array
    {
        return array_map(
            static fn ($c) => $c->Field,
            DB::select('SHOW COLUMNS FROM ' . self::TABLE)
        );
    }

    /**
     * The exact incident shape: pre-pair all-NULL `created_by` beside an app `created_by_user_id`.
     * Converges in ONE step, keeps the app's data, and never produces the forbidden pair.
     */
    public static function test_all_null_pre_pair_column_is_dropped_and_data_survives()
    {
        self::__drop();
        DB::statement('CREATE TABLE ' . self::TABLE
            . ' (id BIGINT PRIMARY KEY, created_by BIGINT NULL, created_by_user_id BIGINT NULL)');
        DB::statement('INSERT INTO ' . self::TABLE . ' (id, created_by, created_by_user_id) VALUES (?, ?, ?)', [1, null, 7]);

        try {
            self::__normalize(self::TABLE, 'created_by');

            $columns = self::__columns();
            static::__assert_true(in_array('created_by_id', $columns, true), 'created_by_id must exist');
            static::__assert_true(in_array('created_by_type', $columns, true), 'created_by_type must exist');
            static::__assert_false(
                in_array('created_by', $columns, true),
                'the all-NULL pre-pair column must be gone, not left to collide on the next pass'
            );
            static::__assert_false(in_array('created_by_user_id', $columns, true), 'the old spelling must be renamed away');
            static::__assert_equals(7, self::__value('created_by_id'), 'authorship data must survive');

            // Idempotent: a second pass is a no-op, which is what makes migrate safe to re-run.
            self::__normalize(self::TABLE, 'created_by');
            static::__assert_equals(7, self::__value('created_by_id'), 'a second pass must not disturb it');
        } finally {
            self::__drop();
        }
    }

    /**
     * A database ALREADY dead-ended by the old rename-first order (both `{base}` and
     * `{base}_id` present) heals itself when the pre-pair column holds nothing.
     */
    public static function test_already_dead_ended_database_self_heals()
    {
        self::__drop();
        DB::statement('CREATE TABLE ' . self::TABLE
            . ' (id BIGINT PRIMARY KEY, created_by BIGINT NULL, created_by_id BIGINT NULL)');
        DB::statement('INSERT INTO ' . self::TABLE . ' (id, created_by, created_by_id) VALUES (?, ?, ?)', [1, null, 9]);

        try {
            self::__normalize(self::TABLE, 'created_by');

            static::__assert_false(
                in_array('created_by', self::__columns(), true),
                'the state the old code dead-ended on must now resolve without hand SQL'
            );
            static::__assert_equals(9, self::__value('created_by_id'), 'data must be untouched');
        } finally {
            self::__drop();
        }
    }

    /**
     * THE CRITICAL PROPERTY: real data in both columns refuses, and the schema is UNCHANGED
     * afterwards - so the database is still migratable and a repair migration can still run.
     */
    public static function test_real_data_in_both_refuses_without_mutating()
    {
        self::__drop();
        DB::statement('CREATE TABLE ' . self::TABLE
            . ' (id BIGINT PRIMARY KEY, created_by BIGINT NULL, created_by_user_id BIGINT NULL)');
        DB::statement('INSERT INTO ' . self::TABLE . ' (id, created_by, created_by_user_id) VALUES (?, ?, ?)', [1, 3, 7]);
        $before = self::__columns();

        try {
            $message = '';
            try {
                self::__normalize(self::TABLE, 'created_by');
                static::__fail('normalizing a genuine two-column collision must throw');
            } catch (\Throwable $e) {
                $message = $e->getMessage();
            }

            static::__assert_equals($before, self::__columns(), 'a refusal must change NOTHING - DDL auto-commits');

            // The message has to be actionable, and has to stop blaming the wrong column.
            static::__assert_contains('No schema change has been made', $message, 'must say the database is untouched');
            static::__assert_contains("FRAMEWORK'S OWN PRE-PAIR", $message, 'must identify created_by as the framework column');
            static::__assert_contains('DROP COLUMN created_by;', $message, 'must name the exact unblocking statement');
            static::__assert_contains('1 non-NULL rows', $message, 'must quantify what is at risk');
        } finally {
            self::__drop();
        }
    }

    /**
     * The plain case still works: nothing present at all -> the pair is created NULL.
     */
    public static function test_absent_columns_are_created()
    {
        self::__drop();
        DB::statement('CREATE TABLE ' . self::TABLE . ' (id BIGINT PRIMARY KEY)');

        try {
            self::__normalize(self::TABLE, 'updated_by');
            $columns = self::__columns();
            static::__assert_true(in_array('updated_by_id', $columns, true), 'updated_by_id must be created');
            static::__assert_true(in_array('updated_by_type', $columns, true), 'updated_by_type must be created');
        } finally {
            self::__drop();
        }
    }
}
