<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Illuminate\Support\Facades\DB;
use ReflectionClass;
use App\RSpade\Core\Manifest\Manifest;

/**
 * File_Blob_References - every table that holds a reference to a _file_storage blob.
 *
 * A blob is deduplicated across the whole install and may be pointed at by more than one kind
 * of row: an attachment, a queued email's part, a task's attachment, anything an application
 * stores in the central blob store. File_Disposal_Service may release a blob's bytes only when
 * NO such row exists, so it must know every table that can hold one. That knowledge is
 * DECLARED, on the model that owns the reference:
 *
 *     #[Blob_Reference('file_storage_id')]
 *     #[Blob_Reference(column: 'file_storage_id', where_null: 'destroyed_at')]
 *
 * `column` names the column holding _file_storage.id. `where_null`, when given, names a column
 * that must be NULL for the row to count - an attachment's destroyed tombstone keeps its row
 * but no longer pins the bytes. Arguments are literals (attribute arguments are read by
 * reflection before the autoloader is ready).
 *
 * The model also uses the Blob_Referencing trait, whose save() holds the blob's read lock while
 * the reference is written; the declaration is what the disposal side reads, the trait is the
 * write side.
 *
 * A table that references _file_storage and carries no declaration would let the disposal
 * sweeps free bytes it still needs. undeclared_referencing_columns() finds one from the live
 * schema's foreign keys; the "Blob References" health row FAILs on it.
 */
class File_Blob_References
{
    /** @var array<int, array{table: string, column: string, where_null: ?string, model: string}>|null model is the concrete model's FQCN */
    private static ?array $declarations = null;

    /**
     * Every declared reference, one entry per #[Blob_Reference] instance.
     *
     * @return array<int, array{table: string, column: string, where_null: ?string, model: string}>
     */
    public static function declarations(): array
    {
        if (static::$declarations !== null) {
            return static::$declarations;
        }

        $found = [];

        foreach (Manifest::by_attribute('Blob_Reference') as $row) {
            if ($row['class'] === null || $row['member'] !== null) {
                continue;
            }

            $fqcn = Manifest::php_class_metadata($row['class'])['fqcn'] ?? null;
            if ($fqcn === null) {
                shouldnt_happen("#[Blob_Reference] on {$row['class']}, which the manifest does not index");
            }

            $table = (new ReflectionClass($fqcn))->getDefaultProperties()['table'] ?? null;
            if (!is_string($table) || $table === '') {
                throw new \RuntimeException("#[Blob_Reference] on {$row['class']}: the class declares no \$table.");
            }

            // The attribute usually sits on a core model's abstract base; queries go through the
            // concrete model the manifest serves for the table (the shell, or an application's
            // override of it).
            $concrete = Manifest::model_for_table($table);
            $concrete_fqcn = $concrete !== null ? (Manifest::php_class_metadata($concrete)['fqcn'] ?? null) : null;
            if ($concrete_fqcn === null) {
                // An ordinary state, not an impossible one: the build maps a model to its
                // table only once the table exists, so this is a table that has not been
                // migrated yet, or a build made before the migration that created it.
                throw new \RuntimeException(
                    "#[Blob_Reference] on {$row['class']}: this build has no model for table {$table}. "
                    . "The class exists; the build has not seen its table. Either the table has not been "
                    . "migrated (php artisan migrate), or the build predates the migration that created it "
                    . "(rsx:health names this in its 'Build Schema' row) - rebuild with "
                    . (\App\RSpade\Core\Rsx::is_production() ? 'php artisan rsx:build --force' : 'php artisan rsx:manifest:build --force')
                    . '.'
                );
            }

            foreach ($row['instances'] as $args) {
                $column = $args['column'] ?? $args[0] ?? null;
                if (!is_string($column) || $column === '') {
                    throw new \RuntimeException("#[Blob_Reference] on {$row['class']} names no column.");
                }

                $where_null = $args['where_null'] ?? $args[1] ?? null;

                $found[] = [
                    'table' => $table,
                    'column' => $column,
                    'where_null' => is_string($where_null) && $where_null !== '' ? $where_null : null,
                    'model' => $concrete_fqcn,
                ];
            }
        }

        usort($found, fn ($a, $b) => [$a['table'], $a['column']] <=> [$b['table'], $b['column']]);

        return static::$declarations = $found;
    }

    /**
     * Is the blob referenced by any declared row?
     */
    public static function is_referenced(int $storage_id): bool
    {
        foreach (static::declarations() as $declaration) {
            // Every row counts, from every site and soft-deleted or not: the model's own
            // query with its global scopes removed.
            $query = $declaration['model']::query()->withoutGlobalScopes()->where($declaration['column'], $storage_id);
            if ($declaration['where_null'] !== null) {
                $query->whereNull($declaration['where_null']);
            }
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Narrow a query over `_file_storage as {$alias}` to the blobs NO declared row references.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $alias The alias _file_storage carries in $query.
     * @return \Illuminate\Database\Query\Builder
     */
    public static function where_unreferenced($query, string $alias)
    {
        foreach (static::declarations() as $i => $declaration) {
            $ref = "blob_ref_{$i}";
            $query->whereNotExists(function ($q) use ($declaration, $ref, $alias) {
                $q->select(DB::raw(1))
                    ->from("{$declaration['table']} as {$ref}")
                    ->whereColumn("{$ref}.{$declaration['column']}", "{$alias}.id");
                if ($declaration['where_null'] !== null) {
                    $q->whereNull("{$ref}.{$declaration['where_null']}");
                }
            });
        }

        return $query;
    }

    /**
     * Columns in the live schema with a foreign key to _file_storage.id that no #[Blob_Reference]
     * declares, as "table.column".
     *
     * @return string[]
     */
    public static function undeclared_referencing_columns(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?',
            ['_file_storage', 'id']
        );

        $declared = [];
        foreach (static::declarations() as $declaration) {
            $declared["{$declaration['table']}.{$declaration['column']}"] = true;
        }

        $missing = [];
        foreach ($rows as $row) {
            $key = "{$row->t}.{$row->c}";
            if (!isset($declared[$key])) {
                $missing[] = $key;
            }
        }
        sort($missing);

        return array_values(array_unique($missing));
    }

    /**
     * Every column that references _file_storage is declared, so no disposal sweep can free
     * bytes a row still needs.
     *
     * @return array
     */
    #[Health_Check('Blob References')]
    public static function health_check(): array
    {
        $missing = static::undeclared_referencing_columns();
        if ($missing === []) {
            return ['status' => 'OK', 'detail' => count(static::declarations()) . ' declared reference column(s)'];
        }

        return [
            'status' => 'FAIL',
            'detail' => 'references to _file_storage with no #[Blob_Reference]: ' . implode(', ', $missing),
            'remediation' => "declare #[Blob_Reference('<column>')] and use Blob_Referencing on the model of each listed table (rsx:man file_disposal)",
        ];
    }

    /** Test seam: forget the memoized declarations. */
    public static function _reset(): void
    {
        static::$declarations = null;
    }
}
