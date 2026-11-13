<?php

namespace App\RSpade\Core\Database;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Session\Session;

/**
 * Recomputing a table's derived columns (Rsx_Model_Abstract::$columns, the `derived` kind)
 * for every record it holds - the rederive queue `migrate` drains, and the engine behind
 * `rsx:derived:check` and `rsx:derived:rebuild`.
 *
 * A derived column is computed by its model's save() whenever a source column of the same
 * record changes. What save() cannot see is a change made beside the model: a migration
 * that adds or rewrites a source column with SQL, or a change to what a derive method
 * reads from another table. For those a migration calls enqueue('<table>'), and the final
 * step of that `migrate` run (drain()) rederives every record of the table with current
 * model code - after the schema has reached the tip, so the derive methods run against
 * the schema they were written for.
 *
 * THE WALK. Every record - all sites, soft-deleted included - in keyset batches of
 * BATCH_SIZE by primary key. Each record is loaded through its model and rederive()d, which
 * saves only when a value differs (a derived-only save: no updated_at, no updated_by, no
 * revision). A site-scoped model's batch is split by site: each site's records are re-read
 * and rederived inside one Site_Lock::write() section on that site, with that site declared
 * (Session::set_temporary_site_id()) so a derive method that reads another site-scoped
 * model reads that site's rows. The caller's session context is put back afterwards.
 *
 * THE PASSES. A derive method may read another record's derived value - in the same table or
 * in another one - so one pass is not always enough: while a pass changes any record, every
 * table gets another pass, up to N + 2 passes for N tables. Tables still changing on the last allowed
 * pass are named in a [WARNING] (a cycle among derived values, or a derive method that is
 * not deterministic) and the pass loop ends; nothing is fatal. A derive method that THROWS
 * is never caught: in `migrate` it fails the run, which rolls back.
 *
 * Output is written through the caller's $write callable, verbatim:
 *
 *     Rederiving cached values for tasks.....
 *     Done rederiving cached values
 *
 * one heading per table per pass (no newline), one dot per batch examined, a newline when
 * the table's pass ends, and the closing line after the last pass.
 */
class Rsx_Rederive
{
    /** The queue `migrate` drains: one row per table, removed when its table completes. */
    public const QUEUE_TABLE = '_rederive_queue';

    /** Records per keyset batch, and per progress dot. */
    public const BATCH_SIZE = 100;

    /** Drifting records shown per table by check(). */
    public const DRIFT_EXAMPLES = 5;

    /** Passes allowed beyond one per table. */
    public const EXTRA_PASSES = 2;

    /**
     * Queue $table's derived columns to be recomputed by the last step of the running (or
     * next) `migrate`. Idempotent: a table already queued stays queued once.
     *
     * Called from a migration's up() with the table's NAME, never a model class (a
     * migration names no model - MIGRATION-MODEL-01). A plain DB::table() write, so it works
     * inside the migration window (Migration_Guard). A table with no model, or whose model
     * has no derived columns, is dropped from the queue by the drain without a word: a
     * migration replays forever, long after the model it was written for has changed.
     *
     * @param string $table The table name, e.g. 'tasks'
     * @throws InvalidArgumentException for a blank name or a model class name
     */
    public static function enqueue(string $table): void
    {
        if (trim($table) === '' || str_contains($table, '\\') || str_ends_with($table, '_Model')) {
            throw new InvalidArgumentException(
                "Rsx_Rederive::enqueue('{$table}'): pass the TABLE name ('tasks'), never a model class - "
                . 'a migration names no model (MIGRATION-MODEL-01).'
            );
        }

        DB::table('_rederive_queue')->insertOrIgnore(['table_name' => $table]);
    }

    /**
     * Rederive every queued table, with no realtime frames (Realtime::without_model_frames()),
     * and remove each queue row when its table completes - a run that fails part way leaves
     * the rest queued for the next `migrate`. Prints nothing at all when nothing is queued.
     *
     * A queued table with no model, a model with no derived columns, or a table or column the
     * model declares that is not in the database is removed from the queue silently.
     *
     * FRAMEWORK INTERNAL - the last step of Maint_Migrate::execute_migrations().
     *
     * @param callable(string): void $write Writes text verbatim (no newline added)
     */
    public static function drain(callable $write): void
    {
        $queued = [];
        foreach (DB::table('_rederive_queue')->lazyById(self::BATCH_SIZE) as $row) {
            $queued[] = $row->table_name;
        }

        if ($queued === []) {
            return;
        }

        $tables = [];
        foreach ($queued as $table) {
            $fqcn = static::model_for($table);

            if ($fqcn === null || !static::__columns_exist($fqcn)) {
                static::__dequeue($table);

                continue;
            }

            $tables[$table] = $fqcn;
        }

        if ($tables === []) {
            return;
        }

        Realtime::without_model_frames(function () use ($tables, $queued, $write) {
            static::__run_passes($tables, count($queued) + self::EXTRA_PASSES, $write, function (string $table): void {
                static::__dequeue($table);
            });
        });
    }

    /**
     * Rederive every record of $tables (table => model class, as model_for() answers), with
     * the drain's pass loop and output. Realtime frames are sent as for any save.
     *
     * @param array<string, class-string<Rsx_Model_Abstract>> $tables
     * @param callable(string): void $write Writes text verbatim (no newline added)
     * @return array<int, string> The tables still changing on the last allowed pass (empty
     *                            when every table settled)
     */
    public static function rebuild(array $tables, callable $write): array
    {
        if ($tables === []) {
            return [];
        }

        return static::__run_passes($tables, count($tables) + self::EXTRA_PASSES, $write, function (string $table): void {
        });
    }

    /**
     * Report $fqcn's derived-column drift without changing anything: how many records were
     * examined, how many differ from a fresh computation, and the first DRIFT_EXAMPLES of
     * them as [id, [column => [stored, computed]]].
     *
     * @param class-string<Rsx_Model_Abstract> $fqcn
     * @return array{examined: int, drifting: int, examples: array<int, array{0: mixed, 1: array<string, array{0: mixed, 1: mixed}>}>}
     */
    public static function check(string $fqcn): array
    {
        $fqcn::_assert_columns_exist();

        $report = ['examined' => 0, 'drifting' => 0, 'examples' => []];

        static::__walk($fqcn, false, function (Rsx_Model_Abstract $record) use (&$report): void {
            $report['examined']++;

            $drift = $record->_derived_drift();

            if ($drift === []) {
                return;
            }

            $report['drifting']++;

            if (count($report['examples']) < self::DRIFT_EXAMPLES) {
                $report['examples'][] = [$record->getKey(), $drift];
            }
        });

        return $report;
    }

    /**
     * Rederive every record of $fqcn's table once, saving only those whose values differ.
     * Answers how many were saved.
     *
     * @param class-string<Rsx_Model_Abstract> $fqcn
     * @param callable|null $on_batch Called once per batch examined
     */
    public static function rederive_table(string $fqcn, ?callable $on_batch = null): int
    {
        $fqcn::_assert_columns_exist();

        $changed = 0;

        static::__walk($fqcn, true, function (Rsx_Model_Abstract $record) use (&$changed): void {
            if ($record->rederive()) {
                $changed++;
            }
        }, $on_batch);

        return $changed;
    }

    /**
     * The model class owning $table when that model declares derived columns, else null.
     *
     * @return class-string<Rsx_Model_Abstract>|null
     */
    public static function model_for(string $table): ?string
    {
        $class = Manifest::model_for_table($table);

        if ($class === null) {
            return null;
        }

        $fqcn = Manifest::php_model_record($class)['fqcn'];

        return $fqcn::derived_columns() === [] ? null : $fqcn;
    }

    /**
     * Every table in the connected database whose model declares derived columns, as
     * table => model class, ordered by table name.
     *
     * @return array<string, class-string<Rsx_Model_Abstract>>
     */
    public static function tables_with_derived_columns(): array
    {
        $tables = [];

        foreach (Manifest::db_get_tables() as $table) {
            $fqcn = static::model_for($table);

            if ($fqcn !== null) {
                $tables[$table] = $fqcn;
            }
        }

        ksort($tables);

        return $tables;
    }

    // =============================================================================================
    // INTERNALS
    // =============================================================================================

    /**
     * The pass loop shared by drain() and rebuild(). Every table is walked again while any
     * pass changes a record; $on_settled is told each table once the loop ends - a pass
     * changed nothing, or the passes ran out.
     *
     * @param array<string, class-string<Rsx_Model_Abstract>> $tables
     * @return array<int, string> The tables still changing on the last allowed pass
     */
    private static function __run_passes(array $tables, int $allowed_passes, callable $write, callable $on_settled): array
    {
        $unsettled = [];

        for ($pass = 1; $tables !== []; $pass++) {
            $changed = [];

            foreach ($tables as $table => $fqcn) {
                $write("Rederiving cached values for {$table}...");

                $count = static::rederive_table($fqcn, function () use ($write): void {
                    $write('.');
                });

                $write("\n");

                if ($count > 0) {
                    $changed[] = $table;
                }
            }

            if ($changed === []) {
                break;
            }

            if ($pass >= $allowed_passes) {
                $unsettled = $changed;

                $write(
                    '[WARNING] Cached values were still changing after ' . $pass . ' passes in: '
                    . implode(', ', $unsettled) . '. Their derive methods read values that keep changing - most '
                    . "likely a derived value that depends on another record's derived value in a cycle, or on "
                    . 'something non-deterministic. The stored values are what the last pass computed; '
                    . 'php artisan rsx:derived:check <table> shows what is still drifting.' . "\n"
                );

                break;
            }
        }

        foreach (array_keys($tables) as $table) {
            $on_settled($table);
        }

        $write("Done rederiving cached values\n");

        return $unsettled;
    }

    /**
     * Visit every record of $fqcn's table - all sites, soft-deleted included - in keyset
     * batches of BATCH_SIZE by primary key. For a site-scoped model each batch is split by
     * site, that site is declared, and the site's records are re-read (and, when $writes,
     * visited inside one Site_Lock::write() section on the site). The caller's session
     * context is restored afterwards, however the walk ends.
     */
    private static function __walk(string $fqcn, bool $writes, callable $visit, ?callable $on_batch = null): void
    {
        $primary_key = (new $fqcn())->getKeyName();
        $site_scoped = Site_Lock::_uses_site_scope($fqcn);
        $context = Session::_snapshot_context();

        try {
            $last = null;

            do {
                $rows = static::__batch_keys($fqcn, $primary_key, $last, $site_scoped);

                if ($rows === []) {
                    break;
                }

                if ($site_scoped) {
                    $by_site = [];
                    foreach ($rows as $row) {
                        $by_site[(int) $row->site_id][] = $row->{$primary_key};
                    }

                    foreach ($by_site as $site_id => $ids) {
                        Session::set_temporary_site_id($site_id);

                        $section = function () use ($fqcn, $primary_key, $ids, $visit): void {
                            foreach (static::__load($fqcn, $primary_key, $ids) as $record) {
                                $visit($record);
                            }
                        };

                        if ($writes) {
                            Site_Lock::write($site_id, $section, 'Rederive cached values');
                        } else {
                            $section();
                        }
                    }
                } else {
                    $ids = array_map(fn ($row) => $row->{$primary_key}, $rows);

                    foreach (static::__load($fqcn, $primary_key, $ids) as $record) {
                        $visit($record);
                    }
                }

                $last = $rows[count($rows) - 1]->{$primary_key};

                if ($on_batch !== null) {
                    $on_batch();
                }
            } while (count($rows) === self::BATCH_SIZE);
        } finally {
            Session::_restore_context($context);
        }
    }

    /**
     * The next batch's primary keys (and site ids), past $last, read across every site.
     *
     * @return array<int, object>
     */
    private static function __batch_keys(string $fqcn, string $primary_key, mixed $last, bool $site_scoped): array
    {
        $read = function () use ($fqcn, $primary_key, $last, $site_scoped): array {
            $query = static::__query($fqcn)
                ->select($site_scoped ? [$primary_key, 'site_id'] : [$primary_key])
                ->orderBy($primary_key)
                ->limit(self::BATCH_SIZE);

            if ($last !== null) {
                $query->where($primary_key, '>', $last);
            }

            return $query->toBase()->get()->all();
        };

        return $site_scoped ? $fqcn::without_site_scope($read) : $read();
    }

    /**
     * The records $ids name, loaded through the model (under the declared site's scope for a
     * site-scoped model). A record deleted since its key was read is simply absent.
     *
     * @return iterable<Rsx_Model_Abstract>
     */
    private static function __load(string $fqcn, string $primary_key, array $ids): iterable
    {
        return static::__query($fqcn)
            ->whereIn($primary_key, $ids)
            ->orderBy($primary_key)
            ->limit(self::BATCH_SIZE)
            ->get();
    }

    /**
     * A query over every record of $fqcn's table, soft-deleted ones included.
     */
    private static function __query(string $fqcn)
    {
        $query = $fqcn::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($fqcn), true)) {
            $query->withTrashed();
        }

        return $query;
    }

    /**
     * Whether every column $fqcn declares is in the database (its first-use schema check).
     * The drain's one sanctioned quiet path: a historical replay must never break because a
     * table or a declared column is not there yet.
     */
    private static function __columns_exist(string $fqcn): bool
    {
        try {
            $fqcn::_assert_columns_exist();
        } catch (LogicException $e) {
            return false;
        }

        return true;
    }

    private static function __dequeue(string $table): void
    {
        DB::table('_rederive_queue')->where('table_name', $table)->delete();
    }
}
