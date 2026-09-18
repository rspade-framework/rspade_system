<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Schema_Contract;
use App\RSpade\Core\Models\User_Model;

/**
 * Schema_Contract_Health_Checks - the rsx:health rows over Schema_Contract.
 *
 * ONE check, a LIST of rows: one per contracted table, plus `schema: foreign keys`,
 * `schema: rows` and `schema: semantics`. It runs in every mode, because the question
 * it asks - does this database still carry what the framework code reads by name - has
 * the same answer and the same consequence on a laptop and on a deployed box, and
 * nothing else asks it anywhere (rsx:migrate:check_consistency compares the manifest
 * with the database, in production modes only, and the manifest is DERIVED from the
 * database, so it can never see a column the framework needs and the schema never had).
 *
 * FAIL is structural: a missing table, a missing required column, a missing unique
 * index, a missing foreign key, a missing required row. Each of those is something the
 * framework will throw on, and the detail names the column or index AND the framework
 * consumer from the contract, so an operator can tell at a glance that it is the
 * framework and not their own feature that is about to stop working.
 *
 * WARN is semantic: a value the framework coerces or tolerates but that is silently
 * wrong (a NULL is_grant is a permission lost, a NULL is_api_access_enabled is an API
 * opened). Never FAIL - rsx:health's exit code gates deploys and none of these stops
 * the application running.
 *
 * Every row builder is PUBLIC and takes the introspected shape as parameters. The
 * failure branches are states no healthy database is in, so the only way they are ever
 * seen is by handing a builder a shape with the column removed - which is what the
 * tests do, without altering any database.
 *
 * The three introspection queries read information_schema through DB::select. That is
 * the house shape for schema-level questions (Revision_Dictionary and Rsx_Data_Wipe do
 * the same): information_schema has no model, no site scope and no audit columns, and a
 * query builder over it would be a query builder over a catalog, not over data.
 *
 * @see rsx:man health
 * @see rsx:man database_schema_architecture
 */
class Schema_Contract_Health_Checks
{
    /**
     * The framework's contract on application-owned tables, checked against this
     * database.
     *
     * @return array
     */
    #[Health_Check('Schema Contract')]
    public static function schema_contract(): array
    {
        $database = DB::connection()->getDatabaseName();

        $columns = static::_introspect_columns($database);
        $unique_indexes = static::_introspect_unique_indexes($database);
        $foreign_keys = static::_introspect_foreign_keys($database);

        $rows = [];

        foreach (Schema_Contract::tables() as $table => $contract) {
            $rows[] = static::_table_row(
                $table,
                $contract,
                $columns[$table] ?? null,
                $unique_indexes[$table] ?? []
            );
        }

        $rows[] = static::_foreign_keys_row(Schema_Contract::foreign_keys(), $foreign_keys);
        $rows[] = static::_rows_row(Schema_Contract::rows(), static::_introspect_required_rows());
        $rows[] = static::_semantics_row(static::_run_probes());

        return $rows;
    }

    // =========================================================================
    // Row builders - every one takes the introspected shape as a parameter
    // =========================================================================

    /**
     * One contracted table: does it exist, does it carry every required column, does it
     * carry every unique index the framework relies on.
     *
     * @param string $table The table name
     * @param array $contract Its Schema_Contract::tables() entry
     * @param array<int, string>|null $present_columns The columns this database has, or null when the table is absent
     * @param array<int, array<int, string>> $present_unique The column lists of this table's unique indexes
     * @return array
     */
    public static function _table_row(string $table, array $contract, ?array $present_columns, array $present_unique): array
    {
        $label = 'schema: ' . $table;
        $required = $contract['columns'] ?? [];
        $unique = $contract['unique'] ?? [];

        if ($present_columns === null) {
            return [
                'label' => $label,
                'status' => 'FAIL',
                'detail' => 'the table does not exist - the framework models it and reads '
                    . count($required) . ' column(s) on it by name',
                'remediation' => static::_restore_hint($contract['migration'] ?? null),
            ];
        }

        $missing_columns = [];
        foreach ($required as $column => $spec) {
            if (!in_array($column, $present_columns, true)) {
                $missing_columns[] = $table . '.' . $column . ' (' . $spec['where'] . ')';
            }
        }

        $missing_indexes = [];
        foreach ($unique as $index) {
            if (!static::_has_unique_over($present_unique, $index['columns'])) {
                $missing_indexes[] = 'UNIQUE(' . implode(', ', $index['columns']) . ') [' . $index['name'] . ']'
                    . ' - ' . $index['why'];
            }
        }

        if (empty($missing_columns) && empty($missing_indexes)) {
            return [
                'label' => $label,
                'status' => 'OK',
                'detail' => count($required) . ' columns, ' . count($unique) . ' unique index'
                    . (count($unique) === 1 ? '' : 'es'),
            ];
        }

        $findings = [];
        if (!empty($missing_columns)) {
            $findings[] = 'missing column(s): ' . implode('; ', $missing_columns);
        }
        if (!empty($missing_indexes)) {
            $findings[] = 'missing unique index(es): ' . implode('; ', $missing_indexes);
        }

        // Name the migration of the first missing column when the contract records one;
        // otherwise the table's own creating migration.
        $migration = null;
        foreach ($required as $column => $spec) {
            if (!in_array($column, $present_columns, true)) {
                $migration = $spec['migration'] ?? ($contract['migration'] ?? null);
                break;
            }
        }
        $migration = $migration ?? ($contract['migration'] ?? null);

        return [
            'label' => $label,
            'status' => 'FAIL',
            'detail' => implode(' | ', $findings),
            'remediation' => static::_restore_hint($migration),
        ];
    }

    /**
     * The foreign keys the framework relies on, including the four that point from
     * FRAMEWORK-owned tables into application-owned ones.
     *
     * @param array<int, array> $contract Schema_Contract::foreign_keys()
     * @param array<int, string> $present Keys of the form "table.column->referenced_table.referenced_column"
     * @return array
     */
    public static function _foreign_keys_row(array $contract, array $present): array
    {
        $missing = [];

        foreach ($contract as $fk) {
            $key = static::_fk_key($fk['table'], $fk['column'], $fk['references'], $fk['referenced_column']);
            if (!in_array($key, $present, true)) {
                $missing[] = $key . ' - ' . $fk['why'];
            }
        }

        if (empty($missing)) {
            return [
                'label' => 'schema: foreign keys',
                'status' => 'OK',
                'detail' => count($contract) . ' foreign keys intact',
            ];
        }

        return [
            'label' => 'schema: foreign keys',
            'status' => 'FAIL',
            'detail' => 'missing: ' . implode('; ', $missing),
            'remediation' => 'each of these is a framework requirement, not an application one -'
                . ' restore it with a migration (ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY)',
        ];
    }

    /**
     * The rows the framework assumes exist.
     *
     * @param array<int, array> $contract Schema_Contract::rows()
     * @param array<string, bool> $found label => whether the row was found (a label the
     *   contract declares `only_if_any` for is absent from this map when the guard table is empty)
     * @return array
     */
    public static function _rows_row(array $contract, array $found): array
    {
        $missing = [];
        $checked = 0;

        foreach ($contract as $row) {
            if (!array_key_exists($row['label'], $found)) {
                continue;
            }
            $checked++;
            if ($found[$row['label']] !== true) {
                $missing[] = $row['label'] . ' - ' . $row['why'] . ' [' . $row['remediation'] . ']';
            }
        }

        if (empty($missing)) {
            return [
                'label' => 'schema: rows',
                'status' => 'OK',
                'detail' => $checked . ' required row(s) present',
            ];
        }

        return [
            'label' => 'schema: rows',
            'status' => 'FAIL',
            'detail' => 'missing: ' . implode(' | ', $missing),
            'remediation' => 'these rows are a framework requirement - restore them with a migration',
        ];
    }

    /**
     * The semantic probes: present, and still silently wrong.
     *
     * @param array<int, array{label: string, finding: ?string, remediation: string}> $findings
     *   One entry per probe that ran; `finding` null means the probe passed.
     * @return array
     */
    public static function _semantics_row(array $findings): array
    {
        $problems = [];

        foreach ($findings as $finding) {
            if (($finding['finding'] ?? null) !== null) {
                $problems[] = $finding['label'] . ': ' . $finding['finding'];
            }
        }

        if (empty($problems)) {
            return [
                'label' => 'schema: semantics',
                'status' => 'OK',
                'detail' => count($findings) . ' semantic probe(s) clean',
            ];
        }

        $remediations = [];
        foreach ($findings as $finding) {
            if (($finding['finding'] ?? null) !== null) {
                $remediations[] = $finding['remediation'];
            }
        }

        return [
            'label' => 'schema: semantics',
            'status' => 'WARN',
            'detail' => implode(' | ', $problems),
            'remediation' => implode(' | ', $remediations),
        ];
    }

    // =========================================================================
    // Probes
    // =========================================================================

    /**
     * Run every declared semantic probe and return one finding entry per probe.
     *
     * @return array<int, array{label: string, finding: ?string, remediation: string}>
     */
    public static function _run_probes(): array
    {
        $results = [];

        foreach (Schema_Contract::probes() as $probe) {
            $results[] = [
                'label' => $probe['label'],
                'finding' => $probe['kind'] === 'enum'
                    ? static::_role_enum_finding()
                    : static::_count_probe_finding($probe),
                'remediation' => $probe['remediation'],
            ];
        }

        return $results;
    }

    /**
     * The one PHP-side probe: the role enum the whole permission layer reads.
     *
     * @return string|null The finding, or null when the declaration is sound
     */
    public static function _role_enum_finding(): ?string
    {
        $roles = User_Model::$enums['role_id'] ?? [];

        if (empty($roles)) {
            return "User_Model::\$enums['role_id'] is empty - has_permission() grants nothing and"
                . ' the test suite cannot pick a privileged role';
        }

        $incomplete = [];
        foreach ($roles as $id => $definition) {
            if (!is_array($definition)
                || !array_key_exists('permissions', $definition)
                || !array_key_exists('can_admin_roles', $definition)) {
                $incomplete[] = (string) $id;
            }
        }

        if (!empty($incomplete)) {
            return 'role(s) ' . implode(', ', $incomplete)
                . ' declare no permissions and/or no can_admin_roles';
        }

        return null;
    }

    /**
     * A COUNT(*) probe: a non-zero count is the finding.
     *
     * The predicate is a framework-authored literal from Schema_Contract::probes() -
     * nothing user-supplied reaches this query.
     *
     * @param array $probe A 'count'-kind entry of Schema_Contract::probes()
     * @return string|null
     */
    public static function _count_probe_finding(array $probe): ?string
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS offending FROM `' . $probe['table'] . '` WHERE ' . $probe['predicate']
        );

        $count = (int) ($rows[0]->offending ?? 0);

        if ($count === 0) {
            return null;
        }

        return $count . ' row(s) where ' . $probe['predicate'] . ' - ' . $probe['why'];
    }

    // =========================================================================
    // Introspection
    // =========================================================================

    /**
     * Every column of every contracted table, as table => [column, ...]. A table absent
     * from the result does not exist in this database.
     *
     * @return array<string, array<int, string>>
     */
    public static function _introspect_columns(string $database): array
    {
        $tables = array_keys(Schema_Contract::tables());

        $rows = DB::select(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (' . static::_placeholders(count($tables)) . ')',
            array_merge([$database], $tables)
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->table_name][] = (string) $row->column_name;
        }

        return $map;
    }

    /**
     * The column lists of every UNIQUE index on every contracted table, as
     * table => [[col, col], [col]]. The index NAME is not part of the contract.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    public static function _introspect_unique_indexes(string $database): array
    {
        $tables = array_keys(Schema_Contract::tables());

        $rows = DB::select(
            'SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name,'
            . ' GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0'
            . ' AND TABLE_NAME IN (' . static::_placeholders(count($tables)) . ')'
            . ' GROUP BY TABLE_NAME, INDEX_NAME',
            array_merge([$database], $tables)
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->table_name][] = explode(',', (string) $row->columns);
        }

        return $map;
    }

    /**
     * Every foreign key in this database, as "table.column->referenced_table.referenced_column".
     *
     * @return array<int, string>
     */
    public static function _introspect_foreign_keys(string $database): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name,'
            . ' REFERENCED_TABLE_NAME AS referenced_table, REFERENCED_COLUMN_NAME AS referenced_column'
            . ' FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database]
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[] = static::_fk_key(
                (string) $row->table_name,
                (string) $row->column_name,
                (string) $row->referenced_table,
                (string) $row->referenced_column
            );
        }

        return $keys;
    }

    /**
     * Whether each contracted row is present, keyed by its label.
     *
     * A row declaring `only_if_any` is asked only when that table holds any row at all:
     * a database with no login_users is a fresh install waiting to be seeded, not a
     * broken one. Such a row is absent from the map entirely, and _rows_row() skips it.
     *
     * @return array<string, bool>
     */
    public static function _introspect_required_rows(): array
    {
        $found = [];
        $guard_has_rows = [];

        foreach (Schema_Contract::rows() as $row) {
            $guard = $row['only_if_any'];

            if ($guard !== null) {
                if (!array_key_exists($guard, $guard_has_rows)) {
                    $guard_has_rows[$guard] = static::_table_has_any_row($guard);
                }
                if (!$guard_has_rows[$guard]) {
                    continue;
                }
            }

            $found[$row['label']] = static::_row_exists($row['table'], $row['where']);
        }

        return $found;
    }

    // =========================================================================
    // internals
    // =========================================================================

    /**
     * Does any of these unique indexes cover exactly this column set (in any order)?
     *
     * @param array<int, array<int, string>> $present
     * @param array<int, string> $wanted
     */
    private static function _has_unique_over(array $present, array $wanted): bool
    {
        sort($wanted);

        foreach ($present as $columns) {
            sort($columns);
            if ($columns === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * The canonical spelling of a foreign key, used on both sides of the comparison.
     */
    private static function _fk_key(string $table, string $column, string $references, string $referenced_column): string
    {
        return $table . '.' . $column . '->' . $references . '.' . $referenced_column;
    }

    /**
     * Does a row matching this equality predicate exist? The columns come from the
     * contract, never from input.
     *
     * @param array<string, int> $where
     */
    private static function _row_exists(string $table, array $where): bool
    {
        $clauses = [];
        $bindings = [];

        foreach ($where as $column => $value) {
            $clauses[] = '`' . $column . '` = ?';
            $bindings[] = $value;
        }

        $rows = DB::select(
            'SELECT 1 AS present FROM `' . $table . '` WHERE ' . implode(' AND ', $clauses) . ' LIMIT 1',
            $bindings
        );

        return !empty($rows);
    }

    /**
     * Does this table hold any row at all? (The `only_if_any` guard - a LIMIT 1 probe,
     * never a count.)
     */
    private static function _table_has_any_row(string $table): bool
    {
        $rows = DB::select('SELECT 1 AS present FROM `' . $table . '` LIMIT 1');

        return !empty($rows);
    }

    /** A comma-separated run of `?` placeholders. */
    private static function _placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }

    /** The remediation sentence, naming the framework migration when the contract records one. */
    private static function _restore_hint(?string $migration): string
    {
        $hint = 'this is a framework requirement, not an application column: ';

        return $migration === null
            ? $hint . 'restore it with a migration'
            : $hint . 'the framework migration ' . $migration . ' created it - restore it with a migration';
    }
}
