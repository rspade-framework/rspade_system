<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\FPC\Rsx_FPC;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Database\Php\Schema_Absent_Table_Fixture_Model;

/**
 * Rsx_Schema: the one reader of live column data.
 *
 * WHAT IS BEING PROVED:
 *
 *   - THE SHAPE. One information_schema query must describe a column exactly as the
 *     per-table SHOW COLUMNS it replaced did - the same simplified type (tinyint(1) is a
 *     boolean), the same varchar length, the same nullable/key/default/extra - because
 *     every schema-derived cast and every field_length() answer is read out of it.
 *   - THE LOOKUP ORDER. This process's copy, then the cache, then the query: a process that
 *     finds the cache entry asks the database nothing about columns.
 *   - WHAT MOVES IT. The entry is filed under the state key, so a schema change is seen when
 *     the applied migrations move - and, deliberately, not before.
 *
 * The schema change is performed for real - a column added to a framework table and a row
 * in the migrations table, both removed in a finally - so this class runs outside the
 * per-test transaction (DDL commits whatever the transaction says).
 */
class Rsx_Schema_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const PROBE_TABLE = '_email_blocked_addresses';

    private const PROBE_MODEL = 'Email_Blocked_Address_Model';

    private const PROBE_COLUMN = 'zz_rsx_schema_probe';

    private const PROBE_MIGRATION = '9999_12_31_235959_rsx_schema_probe';

    /**
     * Run $fn and return every SQL statement issued while it ran.
     *
     * @return array<int, string>
     */
    private static function __queries_during(callable $fn): array
    {
        $queries = [];
        $listening = true;

        // Laravel has no unlisten; the flag is what stops the closure collecting.
        DB::listen(function ($query) use (&$queries, &$listening) {
            if ($listening) {
                $queries[] = $query->sql;
            }
        });

        try {
            $fn();
        } finally {
            $listening = false;
        }

        return $queries;
    }

    /**
     * @param array<int, string> $queries
     * @return array<int, string> The statements that read column metadata
     */
    private static function __schema_reads(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            fn ($sql) => stripos($sql, 'information_schema') !== false || stripos($sql, 'show columns') !== false
        ));
    }

    // -------------------------------------------------------------------------
    // The shape
    // -------------------------------------------------------------------------

    /**
     * The type simplification, pinned as literals: these are the names every cast decision
     * and every db_get_columns_by_type() caller compares against.
     */
    public static function test_the_type_simplification()
    {
        $expected = [
            'tinyint(1)' => 'boolean',
            'tinyint(4)' => 'integer',
            'tinyint' => 'integer',
            'int' => 'integer',
            'bigint' => 'integer',
            'bigint unsigned' => 'integer',
            'smallint' => 'integer',
            'mediumint' => 'integer',
            'varchar(255)' => 'string',
            'char(2)' => 'string',
            'text' => 'text',
            'mediumtext' => 'text',
            'longtext' => 'text',
            'datetime' => 'datetime',
            'datetime(3)' => 'datetime',
            'timestamp(3)' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'decimal(10,2)' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'json' => 'json',
            "enum('a','b')" => 'enum',
            'blob' => 'blob',
        ];

        foreach ($expected as $mysql_type => $simplified) {
            static::__assert_equals($simplified, Rsx_Schema::simplify_type($mysql_type), "{$mysql_type} simplifies to {$simplified}");
        }

        static::__assert_equals(255, Rsx_Schema::varchar_length('varchar(255)'));
        static::__assert_equals(2, Rsx_Schema::varchar_length('char(2)'));
        static::__assert_null(Rsx_Schema::varchar_length('text'), 'only varchar/char carry a length');
        static::__assert_null(Rsx_Schema::varchar_length('bigint'));
    }

    /**
     * Column for column, what the one information_schema query says about a framework
     * model's table is what SHOW COLUMNS says about it.
     */
    public static function test_a_table_is_described_exactly_as_show_columns_describes_it()
    {
        $table = Login_User_Model::get_table_static();

        $expected = [];
        foreach (DB::select("SHOW COLUMNS FROM `{$table}`") as $column) {
            $expected[$column->Field] = [
                'type' => Rsx_Schema::simplify_type($column->Type),
                'max_length' => Rsx_Schema::varchar_length($column->Type),
                'nullable' => ($column->Null === 'YES'),
                'key' => $column->Key,
                'default' => $column->Default,
                'extra' => $column->Extra,
                'source_table' => $table,
            ];
        }

        Rsx_Schema::forget();

        static::__assert_equals($expected, Rsx_Schema::table_columns($table), "the column map of {$table}");
        static::__assert_equals(
            array_keys($expected),
            array_keys(Rsx_Schema::table_columns($table)),
            'in the table\'s own column order'
        );

        // The same map is the MODEL's, and the three kinds every cast decision turns on are
        // all present in it - otherwise the comparison above proved nothing about them.
        $model_columns = Manifest::php_model_columns('Login_User_Model');
        static::__assert_equals($expected, $model_columns, 'a model with no detail tables has exactly its table\'s columns');

        $types = array_column($model_columns, 'type');
        foreach (['boolean', 'datetime', 'string'] as $type) {
            static::__assert_true(in_array($type, $types, true), "{$table} carries a {$type} column");
        }

        static::__assert_equals('boolean', $model_columns['is_developer']['type'], 'tinyint(1) reads as boolean');
        static::__assert_equals('datetime', $model_columns['created_at']['type'], 'timestamp reads as datetime');
        static::__assert_equals('string', $model_columns['email']['type']);
        static::__assert_equals(
            $model_columns['email']['max_length'],
            Login_User_Model::field_length('email'),
            'field_length() is the max_length of the same map'
        );
        static::__assert_null($model_columns['is_developer']['max_length'], 'a non-character column has no length');
    }

    public static function test_the_accessors_agree_with_each_other()
    {
        $table = Login_User_Model::get_table_static();
        $columns = Rsx_Schema::table_columns($table);

        static::__assert_true(Rsx_Schema::has_table($table));
        static::__assert_false(Rsx_Schema::has_table('zz_rsx_schema_no_such_table'));
        static::__assert_null(Rsx_Schema::table_columns('zz_rsx_schema_no_such_table'), 'a table that does not exist has no map');
        static::__assert_null(Rsx_Schema::model_columns('Rsx_Test_Abstract'), 'a class that is not a model has no map');

        $flattened = array_map(fn ($shape) => $shape['type'], $columns);
        static::__assert_equals($flattened, Manifest::db_get_table_columns($table), 'db_get_table_columns() is the same map, flattened to types');

        $booleans = array_keys(array_filter($flattened, fn ($type) => $type === 'boolean'));
        static::__assert_equals($booleans, Manifest::db_get_columns_by_type($table, 'boolean'));

        static::__assert_true(in_array($table, Manifest::db_get_tables(), true), 'db_get_tables() lists a model table that exists');
    }

    // -------------------------------------------------------------------------
    // A model whose table does not exist
    // -------------------------------------------------------------------------

    public static function test_a_model_with_no_table_has_no_columns_and_field_length_says_why()
    {
        $class = 'Schema_Absent_Table_Fixture_Model';
        $table = Schema_Absent_Table_Fixture_Model::get_table_static();

        static::__assert_not_null(Manifest::php_model_record($class), 'the model is registered');
        static::__assert_false(Rsx_Schema::has_table($table), 'and its table is in no database');
        static::__assert_null(Rsx_Schema::model_columns($class));
        static::__assert_null(Manifest::php_model_columns($class));
        static::__assert_equals([], Manifest::db_get_table_columns($table));
        static::__assert_false(in_array($table, Manifest::db_get_tables(), true), 'db_get_tables() lists only tables that exist');

        $exception = static::__assert_throws(
            RuntimeException::class,
            fn () => Schema_Absent_Table_Fixture_Model::field_length('anything'),
            $table
        );

        static::__assert_contains('php artisan migrate', $exception->getMessage(), 'the message names the remedy');
    }

    // -------------------------------------------------------------------------
    // The lookup order
    // -------------------------------------------------------------------------

    public static function test_a_cache_hit_reads_nothing_from_the_schema()
    {
        // Miss: the entry is gone and this process holds nothing.
        Rsx_Schema::forget();
        $queries_before = Rsx_Schema::$_query_count;

        $miss = static::__schema_reads(static::__queries_during(fn () => Rsx_Schema::tables()));

        static::__assert_count(1, $miss, 'a miss is ONE query for the whole database');
        static::__assert_equals($queries_before + 1, Rsx_Schema::$_query_count);

        // Same process: its own copy answers.
        $held = static::__queries_during(function () {
            Rsx_Schema::tables();
            Rsx_Schema::model_columns('Login_User_Model');
            Login_User_Model::field_length('email');
        });

        static::__assert_equals([], $held, 'this process\'s copy answers with no statement at all');

        // A process holding nothing (reset() is exactly that state) finds the entry.
        Rsx_Schema::reset();

        $tables = null;
        $hit = static::__schema_reads(static::__queries_during(function () use (&$tables) {
            $tables = Rsx_Schema::tables();
        }));

        static::__assert_equals([], $hit, 'a cache hit issues no schema query');
        static::__assert_equals($queries_before + 1, Rsx_Schema::$_query_count, 'the query ran once in all');
        static::__assert_true(isset($tables[Login_User_Model::get_table_static()]), 'and the cached answer is the schema');
    }

    public static function test_reset_drops_everything_this_process_derived()
    {
        Rsx_Schema::remember_derived('rsx_schema_test|memo', ['held' => true]);
        static::__assert_equals(['held' => true], Rsx_Schema::derived('rsx_schema_test|memo'));

        Manifest::get_state_key();
        $computations = Manifest::$_state_key_computations;

        Rsx_Schema::reset();

        static::__assert_null(Rsx_Schema::derived('rsx_schema_test|memo'), 'a derived value dies with the column maps');

        Manifest::get_state_key();
        static::__assert_equals(
            $computations + 1,
            Manifest::$_state_key_computations,
            'and so does the state key memo - the key the maps are cached under is recomputed with them'
        );
    }

    // -------------------------------------------------------------------------
    // What moves it
    // -------------------------------------------------------------------------

    /**
     * A real ALTER TABLE is NOT seen while the applied migrations stand still - the entry is
     * filed under the state key and that key has not moved - and IS seen the moment a
     * migrations row moves it. No purge is involved in either direction.
     */
    public static function test_a_schema_change_is_seen_once_the_state_key_moves()
    {
        $table = self::PROBE_TABLE;
        $column = self::PROBE_COLUMN;
        $migrations_table = config('database.migrations', 'migrations');

        Rsx_Schema::forget();

        static::__assert_true(Rsx_Schema::has_table($table), "{$table} exists");
        static::__assert_false(isset(Rsx_Schema::model_columns(self::PROBE_MODEL)[$column]), 'the probe column does not exist yet');

        $key_before = Manifest::get_state_key();
        $altered = false;
        $migrated = false;

        try {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(40) NULL");
            $altered = true;

            Rsx_Schema::reset();

            static::__assert_equals($key_before, Manifest::get_state_key(), 'DDL alone does not move the state key');
            static::__assert_false(
                isset(Rsx_Schema::model_columns(self::PROBE_MODEL)[$column]),
                'so the entry filed under it is still the answer'
            );

            DB::table($migrations_table)->insert(['migration' => self::PROBE_MIGRATION, 'batch' => 999999]);
            $migrated = true;

            Rsx_Schema::reset();

            static::__assert_not_equals($key_before, Manifest::get_state_key(), 'the migrations row moved the state key');

            $columns = Rsx_Schema::model_columns(self::PROBE_MODEL);

            static::__assert_true(isset($columns[$column]), 'and the column the migration added is in the column map');
            static::__assert_equals('string', $columns[$column]['type']);
            static::__assert_equals(40, $columns[$column]['max_length']);
            static::__assert_equals(
                40,
                Manifest::php_model_record(self::PROBE_MODEL)['fqcn']::field_length($column),
                'field_length() answers for it with no rebuild'
            );
        } finally {
            // The entry filed under the MOVED key describes a column about to be dropped, and
            // a later run would produce that key again: delete it while it is still current.
            if ($migrated) {
                Rsx_Schema::forget();
                DB::table($migrations_table)->where('migration', self::PROBE_MIGRATION)->delete();
            }

            if ($altered) {
                DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }

            Rsx_Schema::reset();
        }

        static::__assert_equals($key_before, Manifest::get_state_key(), 'the applied set is back where it was');
        static::__assert_false(
            isset(Rsx_Schema::model_columns(self::PROBE_MODEL)[$column]),
            'and so is the schema this process sees'
        );
    }

    // -------------------------------------------------------------------------
    // The purge a schema-changing command runs before and after
    // -------------------------------------------------------------------------

    private static function __fpc_redis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect((string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);

        $password = env('REDIS_PASSWORD');
        if ($password && $password !== 'null') {
            $redis->auth($password);
        }

        // The database the full page cache lives on (Rsx_FPC, and the proxy beside it).
        $redis->select(2);

        return $redis;
    }

    /**
     * The case the state key cannot see: columns that changed while the applied migrations
     * stood still (an entry written mid-run, before the run's last column was added). The
     * purge deletes the entry under the current key, so the next read is the schema.
     */
    public static function test_the_purge_discards_an_entry_the_key_alone_would_keep()
    {
        $table = self::PROBE_TABLE;
        $column = self::PROBE_COLUMN;

        Rsx_Schema::forget();
        Rsx_Schema::tables();
        $key = Manifest::get_state_key();

        try {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(40) NULL");

            Rsx_Schema::reset();
            static::__assert_false(
                isset(Rsx_Schema::model_columns(self::PROBE_MODEL)[$column]),
                'sanity: a process holding nothing still reads the entry written before the change'
            );

            Rsx_Schema::purge_schema_state();

            static::__assert_equals($key, Manifest::get_state_key(), 'the key did not move');
            static::__assert_true(
                isset(Rsx_Schema::model_columns(self::PROBE_MODEL)[$column]),
                'and the column is seen all the same: the purge deleted the entry'
            );
        } finally {
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            Rsx_Schema::purge_schema_state();
        }

        static::__assert_false(isset(Rsx_Schema::model_columns(self::PROBE_MODEL)[$column]));
    }

    public static function test_the_purge_clears_the_build_scoped_cache_and_the_page_cache()
    {
        $redis = static::__fpc_redis();
        $page_key = Rsx_FPC::cache_key('purge-probe.test', '/rsx-schema-purge-probe', Manifest::get_build_key());

        RsxCache::set('rsx_schema_purge_probe', 'held');
        $redis->set($page_key, 'a cached page');
        Rsx_Schema::remember_derived('rsx_schema_test|purge', true);

        try {
            Rsx_Schema::purge_schema_state();

            static::__assert_null(RsxCache::get('rsx_schema_purge_probe'), 'the build-scoped cache is cleared');
            static::__assert_false((bool) $redis->exists($page_key), 'a page cached against the old schema is gone - its key (the code key) did not move');
            static::__assert_null(Rsx_Schema::derived('rsx_schema_test|purge'), 'and so is this process\'s own copy');
        } finally {
            $redis->del($page_key);
        }
    }

    /**
     * Inside a maintenance window Redis is stopped and cache clears are no-ops by design, so
     * the purge touches no cache at all - and never tries to reach one. It still drops this
     * process's copy. Sufficient, because correctness is the state key's.
     */
    public static function test_under_maintenance_the_purge_drops_this_process_copy_only()
    {
        $redis = static::__fpc_redis();
        $page_key = Rsx_FPC::cache_key('purge-probe.test', '/rsx-schema-purge-probe', Manifest::get_build_key());

        RsxCache::set('rsx_schema_purge_probe', 'held');
        $redis->set($page_key, 'a cached page');
        Rsx_Schema::remember_derived('rsx_schema_test|purge', true);

        try {
            Framework_Maintenance::$force_active_for_tests = true;

            try {
                Rsx_Schema::purge_schema_state();
            } finally {
                Framework_Maintenance::$force_active_for_tests = null;
            }

            static::__assert_null(Rsx_Schema::derived('rsx_schema_test|purge'), 'this process\'s copy is dropped');
            static::__assert_equals('held', RsxCache::get('rsx_schema_purge_probe'), 'the cache was not touched');
            static::__assert_true((bool) $redis->exists($page_key), 'nor was the page cache');
        } finally {
            RsxCache::delete('rsx_schema_purge_probe');
            $redis->del($page_key);
        }
    }
}
