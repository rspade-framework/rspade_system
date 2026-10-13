<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver;
use App\RSpade\Core\FPC\Rsx_FPC;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Manifest\Manifest;

/**
 * THE ONE READER OF LIVE COLUMN DATA.
 *
 * What columns a table has, and what type and length each is, is a fact about the DATABASE.
 * It is read from the database at run time, here, and nowhere else: the manifest holds code
 * facts only (which models exist and which table each declares), so a build needs no
 * database and a migration needs no rebuild.
 *
 * ONE QUERY describes the whole connected database - information_schema.COLUMNS - and every
 * question is answered from that one result:
 *
 *   tables()                    table => column => shape, every table
 *   table_columns($table)       one table's columns, or null when it does not exist
 *   model_columns($class)       a MODEL's columns: its table's, plus - for a Class-Table
 *                               Inheritance base model - its detail tables' merged in
 *
 * THE COLUMN SHAPE:
 *
 *   type          the simplified type (see simplify_type(); tinyint(1) is 'boolean')
 *   max_length    characters, for varchar/char; null for every other type
 *   nullable      bool
 *   key           MySQL's COLUMN_KEY ('PRI', 'UNI', 'MUL', '')
 *   default       MySQL's COLUMN_DEFAULT
 *   extra         MySQL's EXTRA
 *   source_table  the table the column physically lives in (a merged detail column names
 *                 its detail table)
 *
 * LOOKUP ORDER: this process's own copy, then the cache, then the query.
 *
 * WHERE THE CACHE ENTRY LIVES. It is keyed under Manifest::get_state_key() - the code key
 * combined with the applied migrations - so a migration moves the key and every process
 * misses the old entry with nothing having to be purged. It is stored through RsxCache's
 * reduced-volatility convention (the _RVC_ key prefix, Redis database 2) rather than in the
 * ordinary cache database, because RsxCache::clear() runs on EVERY transaction rollback and
 * a rollback cannot change a table's columns: DDL is not transactional in MySQL. Database 2
 * is emptied only by rsx:clean. The entry still carries RsxCache's (database, host) scope
 * prefix, so another database's schema is never read as this one's.
 *
 * DURING A MANIFEST BUILD there is no state key (the manifest is not ready), so the cache is
 * skipped and the query answers directly. Nothing in a build asks: the manifest holds code
 * facts only.
 *
 * AN EMPTY DATABASE IS NEVER CACHED. A database with no tables at all is a fresh install
 * about to be migrated; its answer is held for this process only.
 *
 * reset() drops this process's copy (and the state key memo with it); the next question
 * reads the cache or the database again. It is what a process calls after it changed the
 * schema itself or was pointed at another database.
 */
class Rsx_Schema
{
    /**
     * The cache entry: the whole database's column map. The _RVC_ prefix routes it to the
     * reduced-volatility database; the version suffix is bumped when the stored shape
     * changes in a way the code key would not already notice.
     */
    private const CACHE_KEY = RsxCache::RVC_PREFIX . 'rsx_schema_tables_v1';

    /**
     * This process's copy of the whole database's column map, or null before the first read.
     *
     * @var array<string, array<string, array>>|null
     */
    private static ?array $_tables = null;

    /**
     * Merged column maps by model class name (null = no such model, or its table is absent).
     *
     * @var array<string, array|null>
     */
    private static array $_model_columns = [];

    /**
     * Values other code derived FROM the schema and wants held for exactly as long as the
     * schema itself is - see derived().
     *
     * @var array<string, mixed>
     */
    private static array $_derived = [];

    /**
     * How many times this process ran the information_schema query. Tests assert on it.
     */
    public static int $_query_count = 0;

    /**
     * Every table in the connected database: table => column => shape.
     *
     * @return array<string, array<string, array>>
     */
    public static function tables(): array
    {
        if (self::$_tables !== null) {
            return self::$_tables;
        }

        // The cache is keyed under the state key, which exists only once the manifest is
        // ready. A read made during a build goes straight to the database.
        $cacheable = Manifest::$_has_manifest_ready;

        if ($cacheable) {
            $cached = RsxCache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return self::$_tables = $cached;
            }
        }

        self::$_tables = self::__read_tables();

        if ($cacheable && !empty(self::$_tables)) {
            RsxCache::set(self::CACHE_KEY, self::$_tables);
        }

        return self::$_tables;
    }

    /**
     * Does this table exist in the connected database?
     */
    public static function has_table(string $table): bool
    {
        return isset(self::tables()[$table]);
    }

    /**
     * One table's columns (column => shape), or null when the table does not exist.
     *
     * @return array<string, array>|null
     */
    public static function table_columns(string $table): ?array
    {
        return self::tables()[$table] ?? null;
    }

    /**
     * A model's column map, or null when the class is not a model in the registry or its
     * table does not exist in the connected database.
     *
     * For a Class-Table Inheritance base model the map spans base + detail tables as one
     * logical model: each detail table's own columns are merged in, tagged with the detail
     * table as their source_table. The structural columns every detail table repeats (id,
     * the parent key, the timestamps and the audit pairs) are not merged, and where a
     * detail column has the same name as a base column the base column wins.
     *
     * @param string $class_name Simple class name
     * @return array<string, array>|null
     */
    public static function model_columns(string $class_name): ?array
    {
        if (array_key_exists($class_name, self::$_model_columns)) {
            return self::$_model_columns[$class_name];
        }

        $record = Manifest::php_model_record($class_name);
        $columns = $record === null ? null : self::table_columns($record['table']);

        if ($columns !== null) {
            self::__merge_detail_columns($record['fqcn'], $class_name, $record['table'], $columns);
        }

        return self::$_model_columns[$class_name] = $columns;
    }

    /**
     * A value some other class derived from the schema, held until reset().
     *
     * A model's schema-derived cast map is the worked example: it is consulted on every
     * attribute read, so it must be memoized, and it is a function of the columns, so the
     * memo must die exactly when the column maps do. Holding it here is what makes that one
     * event instead of two. Returns null when nothing is held under the key.
     */
    public static function derived(string $key): mixed
    {
        return self::$_derived[$key] ?? null;
    }

    /**
     * Hold a schema-derived value until reset() (see derived()).
     */
    public static function remember_derived(string $key, mixed $value): void
    {
        self::$_derived[$key] = $value;
    }

    /**
     * Drop this process's view of the schema: the column maps, everything derived from them,
     * and the state key memo (the key the maps are cached under is recomputed with them).
     *
     * The cache entry is left alone - it is still the right answer for the state key it was
     * filed under. Called on every transaction rollback and after the test runner swaps
     * databases (Transaction_Rollback_Cache_Reset::reset()).
     */
    public static function reset(): void
    {
        self::$_tables = null;
        self::$_model_columns = [];
        self::$_derived = [];

        Manifest::reset_state_key();
    }

    /**
     * Delete the cache entry filed under the CURRENT state key, then reset().
     *
     * For a process that changed the schema without the applied migrations moving, where
     * the key alone would not tell the old entry from the new.
     */
    public static function forget(): void
    {
        if (Manifest::$_has_manifest_ready) {
            RsxCache::delete(self::CACHE_KEY);
        }

        self::reset();
    }

    /**
     * Discard everything cached that a schema change makes wrong - what `migrate` and every
     * other command that changes the schema calls BEFORE it starts and AFTER it ends,
     * whichever way it ends.
     *
     *   - the column map's cache entry, under the state key this process held AND under the
     *     one the database answers now (a migration moves the key between the two calls);
     *   - this process's own copy and its state key memo;
     *   - the build-scoped cache (RsxCache::clear());
     *   - the full page cache. Its namespace is the CODE key, which a migration does not
     *     move, so nothing but this clear discards a page cached against the old schema.
     *
     * AN OPTIMISATION, NOT THE MECHANISM. Correctness comes from the state key: once the
     * applied migrations move, every process computes a new key and misses the old entries
     * whether or not anything deleted them. What the purge adds is the cases the key cannot
     * see - an entry written under the new key while the run was still changing columns, and
     * the cached pages.
     *
     * UNDER MAINTENANCE MODE Redis is stopped and cache writes and clears are no-ops by
     * design, so only this process's copy is dropped. That is sufficient for the same
     * reason: the key has moved, and a maintenance window serves no pages.
     */
    public static function purge_schema_state(): void
    {
        if (Framework_Maintenance::is_active_on_disk()) {
            self::reset();

            return;
        }

        // Twice: forget() deletes under the key this process holds and then drops the memo,
        // so the second call asks the database and deletes under the key it answers now.
        self::forget();
        self::forget();

        RsxCache::clear();
        Rsx_FPC::clear();
    }

    /**
     * MySQL column type -> the simplified type.
     *
     * TINYINT(1) is 'boolean' (the MySQL boolean convention); every other tinyint is an
     * integer. A type with no simplification answers its own base name.
     *
     * @param string $type MySQL column type (e.g., "varchar(255)", "tinyint(1)")
     */
    public static function simplify_type(string $type): string
    {
        if (preg_match('/^tinyint\(1\)/i', $type)) {
            return 'boolean';
        }

        if (!preg_match('/^([a-z]+)/', $type, $matches)) {
            return $type;
        }

        $type_map = [
            'int' => 'integer',
            'bigint' => 'integer',
            'tinyint' => 'integer',
            'smallint' => 'integer',
            'mediumint' => 'integer',
            'varchar' => 'string',
            'char' => 'string',
            'text' => 'text',
            'mediumtext' => 'text',
            'longtext' => 'text',
            'datetime' => 'datetime',
            'timestamp' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'decimal' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'boolean' => 'boolean',
            'json' => 'json',
            'enum' => 'enum',
        ];

        return $type_map[$matches[1]] ?? $matches[1];
    }

    /**
     * Max characters of a varchar/char column type; null for every other type.
     *
     * @param string $type MySQL column type (e.g., "varchar(255)", "char(10)", "text")
     */
    public static function varchar_length(string $type): ?int
    {
        if (preg_match('/^(?:varchar|char)\((\d+)\)/i', $type, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * The query: every column of every table in the connected database, in table and
     * ordinal order.
     *
     * @return array<string, array<string, array>>
     */
    private static function __read_tables(): array
    {
        self::$_query_count++;

        // Aliased explicitly: MySQL 8 answers information_schema column names in upper case.
        $rows = DB::select(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type,'
            . ' IS_NULLABLE AS is_nullable, COLUMN_KEY AS column_key, COLUMN_DEFAULT AS column_default,'
            . ' EXTRA AS extra'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . ' ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );

        $tables = [];

        foreach ($rows as $row) {
            $tables[$row->table_name][$row->column_name] = [
                'type' => self::simplify_type($row->column_type),
                'max_length' => self::varchar_length($row->column_type),
                'nullable' => ($row->is_nullable === 'YES'),
                'key' => $row->column_key,
                'default' => $row->column_default,
                'extra' => $row->extra,
                'source_table' => $row->table_name,
            ];
        }

        return $tables;
    }

    /**
     * Merge a base model's detail-table columns into its column map (by reference).
     *
     * A detail table that does not exist yet contributes nothing. A same-name collision
     * between a base column and a detail column is usually unintended; the base column wins
     * and the collision is named on the SCHEMA debug channel.
     *
     * @param string $fqcn       The base model FQCN
     * @param string $class_name The base model simple name (for the message)
     * @param string $base_table The base table name
     * @param array  $columns    The base column map, merged in place
     */
    private static function __merge_detail_columns(string $fqcn, string $class_name, string $base_table, array &$columns): void
    {
        if (!$fqcn::has_detail_tables()) {
            return;
        }

        foreach (Detail_Tables_Resolver::detail_classes($fqcn::$detail_tables) as $detail_class) {
            $detail_table = $detail_class::get_table_static();
            $detail_columns = self::table_columns($detail_table);

            if ($detail_columns === null) {
                continue;
            }

            $structural = [
                'id', $detail_class::parent_key(),
                'created_at', 'updated_at', 'deleted_at',
                'created_by_id', 'created_by_type', 'updated_by_id', 'updated_by_type',
                'deleted_by_id', 'deleted_by_type',
            ];

            foreach ($detail_columns as $column_name => $shape) {
                if (in_array($column_name, $structural, true)) {
                    continue;
                }

                if (isset($columns[$column_name])) {
                    console_debug(
                        'SCHEMA',
                        "column '{$column_name}' exists on both base table '{$base_table}' and detail table "
                        . "'{$detail_table}' for model {$class_name} - the base column is used"
                    );

                    continue;
                }

                $columns[$column_name] = $shape;
            }
        }
    }
}
