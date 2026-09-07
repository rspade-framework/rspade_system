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
 * `migrate:normalize_schema` applies ALL of a table's normalizations in ONE ALTER TABLE.
 *
 * THE COST IT REMOVES. The pass used to emit one `ALTER TABLE t <clause>` per change, so a
 * table needing the audit pairs, the timestamp columns and their indexes, the `order`
 * normalization, a precision bump and a charset conversion took roughly a dozen statements -
 * and every MODIFY, ADD INDEX and CONVERT is its own full pass over the rows. On a populated
 * downstream table that is the whole cost of `migrate`. One statement, one pass.
 *
 * THE HARD PART IS INTRA-STATEMENT DEPENDENCY. With an ADD COLUMN merely QUEUED,
 * Schema::hasColumn() still says false, so the index on the column this pass just added would
 * never be planned - the command tracks pending columns instead. That also closes a LATENT
 * GAP: before batching, such an index only landed on the NEXT pass, and a table created by the
 * LAST migration of a run has no next pass.
 *
 * The probe table is shaped like the framework's own `user_profiles` before normalization
 * (pre-pair `created_by`/`updated_by`, precision-less TIMESTAMPs, no indexes, a bare DATETIME,
 * an `order` column, latin1). The real table is not touched; this runs on a throwaway.
 */
class Normalize_Schema_Single_Alter_Test extends Rsx_Test_Abstract
{
    // Real DDL, which auto-commits and cannot participate in the per-test transaction.
    protected static $use_database_transactions = false;

    private const TABLE = '_normalize_batch_probe';

    /** @var string[] Every statement seen while $capturing is true. */
    private static array $captured = [];

    private static bool $capturing = false;

    private static bool $listening = false;

    private static function __listen(): void
    {
        if (self::$listening) {
            return;
        }

        self::$listening = true;

        DB::listen(static function ($query) {
            if (self::$capturing) {
                self::$captured[] = $query->sql;
            }
        });
    }

    /**
     * Run a full normalize pass and return every statement it issued against the probe table.
     *
     * @return string[]
     */
    private static function __normalize_pass(): array
    {
        self::__listen();

        self::$captured = [];
        self::$capturing = true;

        try {
            Artisan::call('migrate:normalize_schema', ['--production' => true]);
        } finally {
            self::$capturing = false;
        }

        return array_values(array_filter(
            self::$captured,
            static fn (string $sql) => stripos($sql, 'ALTER TABLE') !== false
                && strpos($sql, self::TABLE) !== false
        ));
    }

    private static function __drop(): void
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    private static function __create_unnormalized(): void
    {
        self::__drop();

        DB::statement('CREATE TABLE ' . self::TABLE . ' ('
            . ' id BIGINT PRIMARY KEY,'
            . ' user_id BIGINT NULL,'
            . ' created_by BIGINT NULL,'
            . ' updated_by BIGINT NULL,'
            . ' created_at TIMESTAMP NULL,'
            . ' updated_at TIMESTAMP NULL,'
            . ' last_seen_at DATETIME NULL,'
            . ' `order` INT NOT NULL DEFAULT 0,'
            . ' display_name VARCHAR(50) NULL'
            . ' ) CHARACTER SET latin1 COLLATE latin1_swedish_ci');
    }

    private static function __create_table_sql(): string
    {
        return DB::select('SHOW CREATE TABLE `' . self::TABLE . '`')[0]->{'Create Table'};
    }

    private static function __columns(): array
    {
        return array_map(static fn ($c) => $c->Field, DB::select('SHOW COLUMNS FROM ' . self::TABLE));
    }

    private static function __index_names(): array
    {
        return array_map(static fn ($i) => $i->Key_name, DB::select('SHOW INDEXES FROM ' . self::TABLE));
    }

    private static function __trigger_exists(string $name): bool
    {
        return count(DB::select('SHOW TRIGGERS WHERE `Trigger` = ?', [$name])) > 0;
    }

    /**
     * ONE statement carries every clause, the schema afterwards is fully normalized, and a
     * second pass issues no DDL at all.
     */
    public static function test_a_table_is_normalized_in_a_single_alter_statement()
    {
        self::__create_unnormalized();

        try {
            $altered = self::__normalize_pass();

            static::__assert_count(
                1,
                $altered,
                'a table must receive exactly ONE ALTER TABLE per pass, not one per change - got: '
                . implode(' | ', $altered)
            );

            $sql = $altered[0];

            // Every normalization this table needs, in that one statement.
            $expected_clauses = [
                'RENAME COLUMN created_by TO created_by_id',
                'ADD COLUMN created_by_type BIGINT NULL AFTER created_by_id',
                'RENAME COLUMN updated_by TO updated_by_id',
                'ADD COLUMN updated_by_type BIGINT NULL AFTER updated_by_id',
                'MODIFY COLUMN created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3)',
                'MODIFY COLUMN updated_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)',
                'ADD INDEX created_at(created_at)',
                'ADD INDEX updated_at(updated_at)',
                'MODIFY COLUMN `order` BIGINT DEFAULT NULL',
                'ADD INDEX order_idx(`order`)',
                'MODIFY COLUMN last_seen_at DATETIME(3)',
                'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            ];

            foreach ($expected_clauses as $clause) {
                static::__assert_contains($clause, $sql, 'the single statement must carry: ' . $clause);
            }

            // ...and the resulting schema is actually correct.
            $columns = self::__columns();
            foreach (['created_by_id', 'created_by_type', 'updated_by_id', 'updated_by_type'] as $column) {
                static::__assert_true(in_array($column, $columns, true), $column . ' must exist');
            }
            static::__assert_false(in_array('created_by', $columns, true), 'the pre-pair created_by must be gone');
            static::__assert_false(in_array('updated_by', $columns, true), 'the pre-pair updated_by must be gone');

            $create = self::__create_table_sql();
            static::__assert_contains('`created_at` timestamp(3) NULL DEFAULT CURRENT_TIMESTAMP(3)', $create, 'created_at must carry precision and default');
            static::__assert_contains('ON UPDATE CURRENT_TIMESTAMP(3)', $create, 'updated_at must carry the on-update trigger');
            static::__assert_contains('`last_seen_at` datetime(3)', $create, 'a plain DATETIME must be bumped to precision 3');
            static::__assert_contains('`order` bigint DEFAULT NULL', $create, 'order must be BIGINT DEFAULT NULL');
            static::__assert_contains('utf8mb4', $create, 'the table must be converted to utf8mb4');

            $indexes = self::__index_names();
            foreach (['created_at', 'updated_at', 'order_idx'] as $index) {
                static::__assert_true(
                    in_array($index, $indexes, true),
                    'index ' . $index . ' must exist - an index on a column added in the SAME statement is'
                    . ' exactly the case pending-column tracking exists to catch'
                );
            }

            static::__assert_true(self::__trigger_exists(self::TABLE . '_order_insert'), 'the order INSERT trigger must be created after the flush');
            static::__assert_true(self::__trigger_exists(self::TABLE . '_order_update'), 'the order UPDATE trigger must be created after the flush');

            // Idempotence: nothing left to do means no statement at all.
            $second = self::__normalize_pass();
            static::__assert_count(
                0,
                $second,
                'a normalized table must receive ZERO ALTER statements on the next pass - got: ' . implode(' | ', $second)
            );
        } finally {
            self::__drop();
        }
    }

    /**
     * The refusal path still changes NOTHING. Batching makes this stricter than before: the
     * clauses are queued and the throw happens before any flush, so not even a partial
     * statement reaches the database.
     */
    public static function test_a_refusing_table_emits_no_ddl()
    {
        self::__drop();
        DB::statement('CREATE TABLE ' . self::TABLE
            . ' (id BIGINT PRIMARY KEY, created_by BIGINT NULL, created_by_id BIGINT NULL)');
        DB::statement('INSERT INTO ' . self::TABLE . ' (id, created_by, created_by_id) VALUES (?, ?, ?)', [1, 3, 7]);

        $before = self::__create_table_sql();

        try {
            $message = '';
            $altered = [];

            try {
                $altered = self::__normalize_pass();
                static::__fail('a genuine two-column authorship collision must abort the pass');
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                $altered = array_values(array_filter(
                    self::$captured,
                    static fn (string $sql) => stripos($sql, 'ALTER TABLE') !== false
                        && strpos($sql, self::TABLE) !== false
                ));
            }

            static::__assert_contains('No schema change has been made', $message, 'must say the database is untouched');
            static::__assert_count(0, $altered, 'a refusal must issue no DDL whatsoever - got: ' . implode(' | ', $altered));
            static::__assert_equals($before, self::__create_table_sql(), 'the schema must be byte-identical after a refusal');
        } finally {
            self::$capturing = false;
            self::__drop();
        }
    }
}
