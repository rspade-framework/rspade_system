<?php

namespace App\RSpade\Core\Manifest\Modules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Database\Model_Lineage_Fingerprint;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;
use App\RSpade\Core\Support\Rsx_Fingerprint;

/**
 * Support module for extracting database metadata for Rsx_Model_Abstract classes
 * This runs after the primary manifest is built to add model metadata
 */
class Model_ManifestSupport extends ManifestSupport_Abstract
{
    /**
     * Rebuild the model registry, introspecting the DATABASE only for models whose answer
     * could have moved.
     *
     * THE FINGERPRINT IS THE WHOLE MECHANISM: the columns a model reports are a function of
     * its own file (the table name, the detail-table declaration) and of the SCHEMA, and the
     * schema is defined by the migration files. So the key is
     *
     *     <model file hash>__<hash of every migration file's content>
     *
     * and it is checked in two places, cheapest first: the row carried forward from the
     * previous build (no round trip at all), then the persistent cache (Redis, survives a
     * `rsx:clean`). Only a miss reaches MySQL - which is why two consecutive builds with no
     * model change issue ZERO `SHOW COLUMNS`. The old code read the persistent cache and
     * then fell through and re-queried anyway, ~110 round trips per rebuild with the answer
     * already in hand.
     *
     * A model whose table does not exist is a LOUD SKIP, never a silent one: an unmigrated
     * development database must not fail the build, but it must not disappear either -
     * field_length() names this cause when it later finds no column.
     *
     * @param array &$manifest_data Reference to the manifest data array
     * @return void
     */
    public static function process(array &$manifest_data, array $changed_files, array $removed_files): void
    {
        $previous = $manifest_data['data']['models'] ?? [];
        $models = [];

        // Models whose table is missing, reported ONCE at the end of the pass. Per-model
        // error_log() lines are the same information a hundred times over in a fresh test
        // database, where every fixture model legitimately has no table yet.
        $skipped = [];

        // The schema identity every model row is keyed on, computed once per build.
        $schema_fingerprint = Rsx_Fingerprint::migration_files();

        // All PHP files should already be loaded in Phase 3 of manifest processing
        // Get all classes extending Rsx_Model_Abstract
        $model_entries = Manifest::php_get_extending('Rsx_Model_Abstract');

        foreach ($model_entries as $model_entry) {
            if (!isset($model_entry['fqcn'])) {
                continue;
            }

            $fqcn = $model_entry['fqcn'];
            $class_name = $model_entry['class'] ?? '';
            // THE LINEAGE, NOT THE FILE. A core model carries its $table, $enums and
            // $detail_tables on an abstract base and ships a three-line concrete an
            // application replaces, so a row derived from the concrete's own hash cannot
            // notice the base moving underneath it.
            $lineage_hash = Model_Lineage_Fingerprint::lineage_hash($class_name, $manifest_data);
            $fingerprint = $lineage_hash . '__' . $schema_fingerprint;

            // 1. The row this build inherited, if its fingerprint still holds.
            if (($previous[$class_name]['fingerprint'] ?? null) === $fingerprint) {
                $models[$class_name] = $previous[$class_name];

                continue;
            }

            // 2. The persistent cache. Keyed on explicit content hashes, so it is valid
            //    across builds and across a cache clear of the build-scoped family.
            $cachekey = 'Model_ManifestSupport_v3_' . $model_entry['file'] . '__' . $fingerprint;
            $cache = RsxCache::get_persistent($cachekey);

            if (!empty($cache)) {
                $models[$class_name] = $cache;

                continue;
            }

            // 3. Introspect.
            include_once($model_entry['file']);

            // Check if class is abstract
            if (Manifest::php_is_abstract($fqcn)) {
                // Skip abstract classes
                continue;
            }

            // Instantiate the model to get table name
            $model_instance = new $fqcn();
            $table_name = $model_instance->getTable();

            // Check if table exists
            if (!Schema::hasTable($table_name)) {
                // ACCEPTABLE (migrations have not run, or are broken) but never SILENT: the
                // model simply carries no column metadata for the rest of this build, and
                // Rsx_Model_Abstract::field_length() names this line when it then finds no
                // column for a field it was asked about.
                console_debug(
                    'MANIFEST',
                    "model {$class_name} skipped: table '{$table_name}' does not exist"
                );
                $skipped[] = "{$class_name} ({$table_name})";

                continue;
            }

            // Get column information
            $columns = [];

            // Use SHOW COLUMNS to get column information
            $column_results = DB::select("SHOW COLUMNS FROM `{$table_name}`");

            foreach ($column_results as $column) {
                $columns[$column->Field] = [
                    'type' => static::__parse_column_type($column->Type),
                    'max_length' => static::__parse_varchar_length($column->Type),
                    'nullable' => ($column->Null === 'YES'),
                    'key' => $column->Key,
                    'default' => $column->Default,
                    'extra' => $column->Extra,
                    'source_table' => $table_name,
                ];
            }

            // Class-Table Inheritance: merge detail-table columns into the base model's column
            // map so field_length(), JS stubs, and model codegen span base + detail as one
            // logical model. The detail introspection is cached with the base model's row -
            // same fingerprint, same reasoning: a detail schema change is a migration change.
            static::__merge_detail_columns($fqcn, $class_name, $table_name, $columns);

            // The row REFERENCES the model's file; it does not copy the file's method map
            // into itself. That copy was 167 KB of the index - a verbatim second edition of
            // data `files` already held - and nothing read it: Orm_Controller and
            // get_relationships() both read the FILE record (php_get_metadata_by_class()).
            $full_data = [
                'fqcn' => $fqcn,
                'file' => $model_entry['file'] ?? '',
                'table' => $table_name,
                'columns' => $columns,
                'class' => $class_name,  // Ajax_Endpoint_Controller expects this
                'fingerprint' => $fingerprint,
            ];

            $models[$class_name] = $full_data;

            RsxCache::set_persistent($cachekey, $full_data);
        }

        if (!empty($skipped)) {
            // LOUD, and exactly once: an unmigrated database must not fail the build, but a
            // model silently losing its columns is how field_length() ends up throwing a
            // mystery at runtime. That error message names this line.
            // THE APPLICATION LOG, not error_log(). A model losing its columns must be
            // written down somewhere durable, but error_log() on the CLI is STDERR, and
            // stderr is a task command's NARRATION channel - a build that happens inside
            // `rsx:task:run -q` would corrupt output the command contract says is empty.
            // The MANIFEST console_debug channel above names every model; this is the one
            // line that survives.
            \Illuminate\Support\Facades\Log::warning(
                '[manifest] ' . count($skipped) . ' model(s) skipped - their tables do not exist '
                . '(run `php artisan migrate`), so field_length() and their generated JS stubs '
                . 'do not know their columns: ' . implode(', ', $skipped)
            );
        }

        ksort($models);

        $manifest_data['data']['models'] = $models;
    }

    /**
     * Merge a base model's detail-table columns into its column map (by reference), tagging
     * each with its source table. Shared structural/audit columns (id, the parent FK, the
     * created/updated/deleted columns) are skipped. A genuine same-name collision between a
     * base column and a detail column is a smell - it emits a build warning and the base
     * column wins.
     *
     * @param string $fqcn       The base model FQCN (already loaded)
     * @param string $class_name The base model simple name (for messages)
     * @param string $base_table The base table name
     * @param array  $columns    The base column map, merged in place
     */
    private static function __merge_detail_columns(string $fqcn, string $class_name, string $base_table, array &$columns): void
    {
        if (!$fqcn::has_detail_tables()) {
            return;
        }

        foreach (\App\RSpade\Core\Database\DetailTables\Detail_Tables_Resolver::detail_classes($fqcn::$detail_tables) as $detail_class) {
            if (!class_exists($detail_class)) {
                continue;
            }

            $detail_table = (new $detail_class())->getTable();
            if (!Schema::hasTable($detail_table)) {
                continue;
            }

            $structural = [
                'id', $detail_class::parent_key(),
                'created_at', 'updated_at', 'deleted_at',
                'created_by_id', 'created_by_type', 'updated_by_id', 'updated_by_type',
                'deleted_by_id', 'deleted_by_type',
            ];

            foreach (DB::select("SHOW COLUMNS FROM `{$detail_table}`") as $column) {
                if (in_array($column->Field, $structural, true)) {
                    continue;
                }

                if (isset($columns[$column->Field])) {
                    error_log(
                        "[detail_tables] WARNING: column '{$column->Field}' exists on both base table " .
                        "'{$base_table}' and detail table '{$detail_table}' for model {$class_name} - " .
                        'same-name base/detail columns are usually unintended.'
                    );
                    continue;
                }

                $columns[$column->Field] = [
                    'type' => static::__parse_column_type($column->Type),
                    'max_length' => static::__parse_varchar_length($column->Type),
                    'nullable' => ($column->Null === 'YES'),
                    'key' => $column->Key,
                    'default' => $column->Default,
                    'extra' => $column->Extra,
                    'source_table' => $detail_table,
                ];
            }
        }
    }

    /**
     * Parse MySQL column type to a simpler format
     *
     * CRITICAL: TINYINT(1) is treated as boolean (common MySQL boolean convention)
     * All other TINYINT sizes are treated as integers
     *
     * @param string $type MySQL column type (e.g., "varchar(255)", "tinyint(1)")
     * @return string Simplified type
     */
    protected static function __parse_column_type(string $type): string
    {
        // Special case: tinyint(1) is boolean
        if (preg_match('/^tinyint\(1\)/i', $type)) {
            return 'boolean';
        }

        // Extract base type without parameters
        if (preg_match('/^([a-z]+)/', $type, $matches)) {
            $base_type = $matches[1];

            // Map MySQL types to simplified types
            $type_map = [
                'int' => 'integer',
                'bigint' => 'integer',
                'tinyint' => 'integer',  // tinyint(1) handled above, others are integers
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

            return $type_map[$base_type] ?? $base_type;
        }

        return $type;
    }

    /**
     * Extract varchar length from MySQL column type
     *
     * Returns the max length for varchar/char fields, null for all other types.
     *
     * @param string $type MySQL column type (e.g., "varchar(255)", "char(10)", "text")
     * @return int|null Max length for varchar/char, null otherwise
     */
    protected static function __parse_varchar_length(string $type): ?int
    {
        // Match varchar(N) or char(N)
        if (preg_match('/^(?:varchar|char)\((\d+)\)/i', $type, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get the name of this support module
     *
     * @return string
     */
    public static function get_name(): string
    {
        return 'Model Database Metadata';
    }
}
