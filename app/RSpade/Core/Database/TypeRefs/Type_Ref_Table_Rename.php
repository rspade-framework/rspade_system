<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database\TypeRefs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Type_Ref_Table_Rename - a table rename is followed into `_type_refs.table_name`.
 *
 * `_type_refs` stores a class name AND the table that class lives in. A migration that
 * renames a table therefore invalidates the registry row unless something updates it, and
 * a migration cannot do it itself: MIGRATION-MODEL-01 forbids a migration from naming a
 * model class or the registry, and it would have to be remembered in every rename anyway.
 *
 * So the MIGRATE PIPELINE does it. `Maint_Migrate` already routes every executed statement
 * through one macro (the SqlQueryTransformer seam); this class watches that stream for the
 * two spellings MySQL accepts, and applies the accumulated renames to `_type_refs` in the
 * SAME migrate run, printing one line per updated ref.
 *
 * Renames are kept as an ORDERED LIST, not a map, and replayed in order - which is what
 * makes a chain (a -> b in one migration, b -> c in a later one) land on the final name
 * with no special case.
 *
 * This is bookkeeping about a table's NAME. It never touches class_name, and it never
 * deletes a row.
 */
class Type_Ref_Table_Rename
{
    /**
     * Renames observed this run, in execution order.
     * @var array<int, array{from: string, to: string}>
     */
    protected static array $renames = [];

    /**
     * Forget everything observed. Called when the migrate run begins.
     */
    public static function reset(): void
    {
        static::$renames = [];
    }

    /**
     * The renames observed so far, in order.
     *
     * @return array<int, array{from: string, to: string}>
     */
    public static function observed(): array
    {
        return static::$renames;
    }

    /**
     * Watch one executed statement for a table rename.
     *
     * Recognised:
     *     RENAME TABLE a TO b
     *     RENAME TABLE a TO b, c TO d           (multi-rename list)
     *     ALTER TABLE a RENAME b
     *     ALTER TABLE a RENAME TO b
     *     ALTER TABLE a RENAME AS b
     *
     * Explicitly NOT a table rename (and not matched): ALTER TABLE a RENAME COLUMN x TO y,
     * ALTER TABLE a RENAME INDEX i TO j, ALTER TABLE a RENAME KEY i TO j.
     *
     * Backticks and a database qualifier (`db`.`table`) are stripped - `_type_refs` stores
     * a bare table name.
     */
    public static function observe(string $sql): void
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql));

        if (preg_match('/^RENAME\s+TABLES?\s+(.+)$/is', $normalized, $matches)) {
            foreach (explode(',', rtrim(trim($matches[1]), ';')) as $clause) {
                if (preg_match('/^\s*(\S+)\s+TO\s+(\S+)\s*$/i', $clause, $pair)) {
                    static::_record($pair[1], $pair[2]);
                }
            }

            return;
        }

        if (preg_match(
            '/^ALTER\s+(?:ONLINE\s+|OFFLINE\s+|IGNORE\s+)*TABLE\s+(\S+)\s+RENAME\s+(?:TO\s+|AS\s+)?(\S+?);?$/is',
            $normalized,
            $matches
        )) {
            // RENAME COLUMN / INDEX / KEY are a different statement that happens to share
            // the keyword; the target token would be the literal word.
            if (preg_match('/^(COLUMN|INDEX|KEY)$/i', $matches[2])) {
                return;
            }

            static::_record($matches[1], $matches[2]);
        }
    }

    /**
     * Apply every observed rename to `_type_refs.table_name`, in order.
     *
     * @return array<int, array{class_name: string, from: string, to: string}> one entry per
     *         registry row actually updated, in the order the updates happened
     */
    public static function apply(): array
    {
        if (empty(static::$renames) || !Schema::hasTable('_type_refs')) {
            return [];
        }

        $updated = [];

        foreach (static::$renames as $rename) {
            $rows = DB::select(
                'SELECT class_name FROM _type_refs WHERE table_name = ?',
                [$rename['from']]
            );

            if (empty($rows)) {
                continue;
            }

            DB::update(
                'UPDATE _type_refs SET table_name = ? WHERE table_name = ?',
                [$rename['to'], $rename['from']]
            );

            foreach ($rows as $row) {
                $updated[] = [
                    'class_name' => $row->class_name,
                    'from' => $rename['from'],
                    'to' => $rename['to'],
                ];
            }
        }

        return $updated;
    }

    /**
     * Record one from -> to pair, with identifier quoting and the database qualifier
     * stripped.
     */
    protected static function _record(string $from, string $to): void
    {
        $from = static::_bare_table_name($from);
        $to = static::_bare_table_name($to);

        if ($from === '' || $to === '' || $from === $to) {
            return;
        }

        static::$renames[] = ['from' => $from, 'to' => $to];
    }

    /**
     * `db`.`table` / db.table / `table` / table -> table
     */
    protected static function _bare_table_name(string $identifier): string
    {
        $identifier = trim($identifier, " \t\n\r;");
        $identifier = str_replace(['`', '"'], '', $identifier);

        $dot = strrpos($identifier, '.');
        if ($dot !== false) {
            $identifier = substr($identifier, $dot + 1);
        }

        return trim($identifier);
    }
}
