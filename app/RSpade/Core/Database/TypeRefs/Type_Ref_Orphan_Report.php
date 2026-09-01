<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\TypeRefs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Type_Ref_Orphan_Report - which polymorphic ROWS point at a model that no longer exists.
 *
 * THE QUESTION THIS ANSWERS, AND THE ONE IT DOES NOT
 * -------------------------------------------------
 * A `_type_refs` row whose class_name names a model that has been deleted or renamed is
 * INERT and PERMANENT. It is not a defect, it is not reported anywhere, and it must never
 * be deleted: the row is the only thing that still gives meaning to the integer sitting in
 * however many data columns. Dropping it is what turns readable history into a naked
 * number.
 *
 * What IS worth knowing is the opposite direction: which DATA still points at a type id
 * that can no longer be resolved to a class. Those rows throw at the point of use, and
 * they are the operator's to repoint or delete. That is the whole of this class, and the
 * whole of `php artisan rsx:type_refs:orphans`.
 *
 * WHAT COUNTS AS AN ORPHAN
 * ------------------------
 * A non-null value in a type-ref column that is not one of the RESOLVABLE registry ids.
 * That single definition covers both shapes at once:
 *
 *   - the id has a `_type_refs` row naming a class the manifest no longer knows;
 *   - the id has no `_type_refs` row at all (a dangling integer - hand-written SQL, a
 *     restore from another environment, a deleted registry row).
 *
 * THE COLUMN INVENTORY is the manifest's, not a schema guess: every model's
 * _type_ref_columns() - its own $type_ref_columns declaration UNIONED with the framework
 * audit pairs (created_by_type / updated_by_type / deleted_by_type), which are type refs on
 * every table in the system - intersected with the columns each table actually has.
 *
 * NO ROWS ARE EVER LOADED. Everything is COUNT(*) ... GROUP BY, and the SELECT the report
 * prints is TEXT for the operator to paste into `db:query` - this class never runs it.
 */
class Type_Ref_Orphan_Report
{
    /**
     * The registry ids whose class still resolves in the manifest - the ids that data is
     * allowed to hold.
     *
     * @return array<int, int> list of ids
     */
    public static function resolvable_ids(): array
    {
        if (!Schema::hasTable('_type_refs')) {
            return [];
        }

        $ids = [];
        foreach (DB::select('SELECT id, class_name FROM _type_refs ORDER BY id') as $row) {
            if (Type_Ref_Registry::class_resolves($row->class_name)) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /**
     * Every registry id, mapped to the class name it names - resolvable or not. Used to
     * label an orphan: an id that IS registered gets the retired class name, an id that is
     * not registered gets null.
     *
     * @return array<int, string>
     */
    public static function registry_map(): array
    {
        if (!Schema::hasTable('_type_refs')) {
            return [];
        }

        $map = [];
        foreach (DB::select('SELECT id, class_name FROM _type_refs ORDER BY id') as $row) {
            $map[(int) $row->id] = $row->class_name;
        }

        return $map;
    }

    /**
     * Every table with type-ref columns, as table_name => list of column names.
     *
     * Built from the manifest's model inventory (a table with no model is not a table any
     * type ref can be written through) and intersected with the columns the table actually
     * has - deleted_by_type exists only where rows soft-delete.
     *
     * @return array<string, array<int, string>>
     */
    public static function type_ref_columns_by_table(): array
    {
        $by_table = [];

        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $entry) {
            // php_get_extending() returns concrete Rsx_Model_Abstract subclasses only.
            $fqcn = $entry['fqcn'] ?? null;
            if (!$fqcn) {
                continue;
            }

            $model = new $fqcn();
            $table = $model->getTable();
            if (!$table) {
                continue;
            }

            foreach ($fqcn::_type_ref_columns() as $column) {
                $by_table[$table][$column] = true;
            }
        }

        $result = [];
        foreach ($by_table as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $existing = Schema::getColumnListing($table);
            $present = array_values(array_intersect(array_keys($columns), $existing));
            if (!empty($present)) {
                sort($present);
                $result[$table] = $present;
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * The report: one entry per (table, column) that holds at least one orphaned type id.
     *
     * @return array<int, array{
     *     table: string,
     *     column: string,
     *     count: int,
     *     type_ids: array<int, string|null>,
     *     select: string
     * }> ordered by table then column
     */
    public static function scan(): array
    {
        $resolvable = static::resolvable_ids();
        $registry = static::registry_map();

        $findings = [];

        foreach (static::type_ref_columns_by_table() as $table => $columns) {
            foreach ($columns as $column) {
                // "Not one of the resolvable ids" IS the definition of an orphan - it
                // catches a retired-class id and a dangling id in the same predicate. An
                // empty resolvable set (a brand-new database) makes every non-null value an
                // orphan, and `NOT IN ()` is not valid SQL, so the clause degrades to the
                // NULL test alone.
                $where = "`{$column}` IS NOT NULL";
                if (!empty($resolvable)) {
                    $where .= ' AND `' . $column . '` NOT IN (' . implode(', ', $resolvable) . ')';
                }

                $rows = DB::select(
                    "SELECT `{$column}` AS type_ref_id, COUNT(*) AS row_count"
                    . " FROM `{$table}` WHERE {$where} GROUP BY `{$column}` ORDER BY `{$column}`"
                );

                if (empty($rows)) {
                    continue;
                }

                $type_ids = [];
                $total = 0;
                foreach ($rows as $row) {
                    $id = (int) $row->type_ref_id;
                    $type_ids[$id] = $registry[$id] ?? null;
                    $total += (int) $row->row_count;
                }

                $findings[] = [
                    'table' => $table,
                    'column' => $column,
                    'count' => $total,
                    'type_ids' => $type_ids,
                    'select' => static::format_select($table, $column, $type_ids),
                ];
            }
        }

        return $findings;
    }

    /**
     * The copy-pasteable statement that returns exactly the offending rows, with the
     * vanished class names as a trailing comment:
     *
     *     SELECT * FROM shared_items WHERE item_type IN (12, 19)  -- Event_Model, Forum_Thread_Model
     *
     * An id with no registry row at all has no name to print, so it prints as
     * `<id> (no _type_refs row)`.
     *
     * @param array<int, string|null> $type_ids id => class name (or null)
     */
    public static function format_select(string $table, string $column, array $type_ids): string
    {
        $ids = array_keys($type_ids);
        sort($ids);

        $names = [];
        foreach ($ids as $id) {
            $names[] = $type_ids[$id] === null
                ? $id . ' (no _type_refs row)'
                : $type_ids[$id];
        }

        return 'SELECT * FROM ' . $table . ' WHERE ' . $column . ' IN (' . implode(', ', $ids) . ')'
            . '  -- ' . implode(', ', $names);
    }
}
