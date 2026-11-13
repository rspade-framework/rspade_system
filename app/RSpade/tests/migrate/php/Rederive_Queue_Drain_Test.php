<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionMethod;
use App\RSpade\Core\Database\Lifecycle\Model_Lifecycle_Emissions;
use App\RSpade\Core\Database\Rsx_Rederive;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Column_Fixture_Tables;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Unstable_Fixture_Model;
use App\RSpade\Tests\Migrate\Php\Hook_Counting_Migrate;

/**
 * The rederive queue and the last step of `migrate` that drains it.
 *
 * Rsx_Rederive::enqueue('<table>') queues a table once; Maint_Migrate::drain_rederive_queue()
 * - the real method, driven through Hook_Counting_Migrate with its output captured verbatim -
 * rederives every record of each queued table and removes the table's row only when the
 * table completes. The drain prints nothing for an empty queue or a table it skips, saves
 * only stale records (derived-only saves, so updated_at holds), sends no realtime frame,
 * walks every site and soft-deleted rows with each record's site declared, and stops after
 * N + 2 passes with a [WARNING] when values never settle. Its position as the last step of
 * execute_migrations() is pinned by source structure.
 *
 * Fixture tables are the derived_columns concern's (Derived_Column_Fixture_Tables), filled
 * with plain SQL behind the models' backs.
 */
class Rederive_Queue_Drain_Test extends Rsx_Test_Abstract
{
    private const OLD_STAMP = '2020-01-02 03:04:05.678';

    private const DONE = "Done rederiving cached values\n";

    public static function setup()
    {
        Derived_Column_Fixture_Tables::create();
    }

    public static function teardown()
    {
        config(['rsx.realtime.enabled' => false]);
        Realtime_Emissions::_testing_reset();
        Model_Lifecycle_Emissions::_testing_reset();
        Derived_Unstable_Fixture_Model::$fail = false;
        Derived_Column_Fixture_Tables::drop();
    }

    /** Run the real drain step and answer [succeeded, everything it printed]. */
    private static function __drain(): array
    {
        $command = new Hook_Counting_Migrate();
        $ok = $command->drive_rederive_step();

        return [$ok, $command->raw_output];
    }

    private static function __queued(): array
    {
        return DB::table(Rsx_Rederive::QUEUE_TABLE)->orderBy('id')->pluck('table_name')->all();
    }

    private static function __heading(string $table, int $dots): string
    {
        return "Rederiving cached values for {$table}..." . str_repeat('.', $dots) . "\n";
    }

    private static function __second_site_id(): int
    {
        $site = new Site_Model();
        $site->slug = 'rederive-drain-' . uniqid();
        $site->name = 'Rederive Drain Second Site';
        $site->save();

        return (int) $site->id;
    }

    // -------------------------------------------------------------------------
    // The queue
    // -------------------------------------------------------------------------

    // REDERIVE-ENQUEUE-IDEMPOTENT
    public static function test_enqueue_is_idempotent_per_table()
    {
        Rsx_Rederive::enqueue('derived_plain_fixtures');
        Rsx_Rederive::enqueue('derived_plain_fixtures');
        Rsx_Rederive::enqueue('derived_chain_fixtures');

        static::__assert_equals(['derived_plain_fixtures', 'derived_chain_fixtures'], static::__queued());
    }

    // REDERIVE-ENQUEUE-TABLE-NAME
    public static function test_enqueue_refuses_a_model_class_name()
    {
        static::__assert_throws(InvalidArgumentException::class, static function () {
            Rsx_Rederive::enqueue('Derived_Plain_Fixture_Model');
        }, 'pass the TABLE name');
    }

    // REDERIVE-DRAIN-EMPTY
    public static function test_an_empty_queue_prints_nothing()
    {
        [$ok, $output] = static::__drain();

        static::__assert_true($ok);
        static::__assert_equals('', $output, 'nothing queued, nothing printed');
    }

    // REDERIVE-DRAIN-SKIP: no model, no derived columns, a missing table, a missing column.
    public static function test_tables_it_cannot_rederive_are_dropped_silently()
    {
        foreach (['no_such_rederive_table', 'derived_category_fixtures', 'derived_missing_fixtures'] as $table) {
            Rsx_Rederive::enqueue($table);
        }

        [$ok, $output] = static::__drain();

        static::__assert_true($ok);
        static::__assert_equals('', $output, 'no model, no derived columns and no table are all silent');
        static::__assert_equals([], static::__queued(), 'and each queue row is removed');

        // The table exists but lacks a column its model declares.
        Derived_Column_Fixture_Tables::create_missing_column_table();
        Rsx_Rederive::enqueue('derived_missing_fixtures');

        [$ok, $output] = static::__drain();

        static::__assert_true($ok);
        static::__assert_equals('', $output, 'a missing declared column is silent too');
        static::__assert_equals([], static::__queued());
    }

    // -------------------------------------------------------------------------
    // Output and what is saved
    // -------------------------------------------------------------------------

    // REDERIVE-DRAIN-OUTPUT: a heading, one dot per batch of 100, a newline; a second pass
    // because the first changed records; the closing line.
    public static function test_output_is_one_dot_per_batch_and_a_closing_line()
    {
        $values = [];
        for ($index = 1; $index <= 150; $index++) {
            $full = ($index % 50 === 0) ? 'Stale' : "F{$index} L{$index}";
            $values[] = DB::getPdo()->quote("F{$index}") . ', ' . DB::getPdo()->quote("L{$index}") . ', '
                . DB::getPdo()->quote($full) . ", '" . self::OLD_STAMP . "'";
        }
        DB::statement('INSERT INTO derived_plain_fixtures (first_name, last_name, full_name, updated_at) VALUES ('
            . implode('), (', $values) . ')');

        Rsx_Rederive::enqueue('derived_plain_fixtures');

        [$ok, $output] = static::__drain();

        static::__assert_true($ok);
        static::__assert_equals(
            static::__heading('derived_plain_fixtures', 2) . static::__heading('derived_plain_fixtures', 2) . self::DONE,
            $output,
            'two batches per pass; the second pass finds nothing and ends the loop'
        );
        static::__assert_equals(0, (int) DB::selectOne("SELECT COUNT(*) AS c FROM derived_plain_fixtures WHERE full_name = 'Stale'")->c, 'every stale value corrected');
        static::__assert_equals(150, (int) DB::selectOne('SELECT COUNT(*) AS c FROM derived_plain_fixtures WHERE updated_at = ?', [self::OLD_STAMP])->c, 'and no updated_at moved');
        static::__assert_equals([], static::__queued(), 'the completed table left the queue');
    }

    // REDERIVE-DRAIN-STALE-ONLY + REDERIVE-DRAIN-NO-REALTIME + REDERIVE-DRAIN-SITES
    public static function test_only_stale_records_are_saved_on_every_site_with_no_realtime_frame()
    {
        static::__acting_as_user(1);
        config(['rsx.realtime.enabled' => true]);

        $other_site = static::__second_site_id();

        DB::insert('INSERT INTO derived_site_fixtures (site_id, name, label, updated_at) VALUES '
            . '(1, ?, ?, ?), (1, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)', [
                'alpha', 'ALPHA', self::OLD_STAMP,
                'beta', 'STALE', self::OLD_STAMP,
                $other_site, 'gamma', 'GAMMA', self::OLD_STAMP,
                $other_site, 'delta', 'STALE', self::OLD_STAMP,
            ]);

        Realtime_Emissions::_testing_reset();
        Model_Lifecycle_Emissions::_testing_reset();
        Rsx_Rederive::enqueue('derived_site_fixtures');

        [$ok, $output] = static::__drain();

        static::__assert_true($ok, 'the drain succeeded: ' . $output);

        $rows = DB::select('SELECT name, label, updated_at FROM derived_site_fixtures ORDER BY id');
        static::__assert_equals(['ALPHA', 'BETA', 'GAMMA', 'DELTA'], array_map(fn ($row) => $row->label, $rows), 'both sites corrected');
        foreach ($rows as $row) {
            static::__assert_equals(self::OLD_STAMP, substr((string) $row->updated_at, 0, 23), "updated_at of {$row->name} is untouched");
        }

        $saved = array_map(fn ($entry) => $entry['changed'], Model_Lifecycle_Emissions::_testing_pending());
        static::__assert_equals([['label'], ['label']], $saved, 'exactly the two stale records were saved');
        static::__assert_equals([], Realtime_Emissions::_testing_pending(), 'and no realtime frame was queued by the drain');
        static::__assert_equals(1, Session::get_site_id(), 'the caller\'s site is back');
    }

    // REDERIVE-DRAIN-TRASHED: soft-deleted rows, each record's site declared for its derive method.
    public static function test_soft_deleted_rows_and_each_records_site_are_covered()
    {
        static::__acting_as_user(1);

        $other_site = static::__second_site_id();

        DB::insert('INSERT INTO derived_site_fixtures (site_id, name, label) VALUES (1, ?, ?), (?, ?, ?), (?, ?, ?)', [
            'one', 'ONE', $other_site, 'two', 'TWO', $other_site, 'three', 'THREE',
        ]);
        DB::insert('INSERT INTO derived_trashable_fixtures (site_id, name, label, site_fixture_count, deleted_at) VALUES '
            . '(1, ?, NULL, NULL, NULL), (?, ?, NULL, NULL, NULL), (?, ?, NULL, NULL, ?)', [
                'alpha', $other_site, 'beta', $other_site, 'gamma', self::OLD_STAMP,
            ]);

        Rsx_Rederive::enqueue('derived_trashable_fixtures');

        [$ok, $output] = static::__drain();

        static::__assert_true($ok, 'the drain succeeded: ' . $output);

        $rows = DB::select('SELECT label, site_fixture_count, deleted_at FROM derived_trashable_fixtures ORDER BY id');
        static::__assert_equals(['ALPHA', 'BETA', 'GAMMA'], array_map(fn ($row) => $row->label, $rows), 'the soft-deleted row was rederived too');
        static::__assert_equals([1, 2, 2], array_map(fn ($row) => (int) $row->site_fixture_count, $rows), 'each derive read its own site');
        static::__assert_not_null($rows[2]->deleted_at, 'and stayed deleted');
    }

    // -------------------------------------------------------------------------
    // The pass cap, failure and the queue row
    // -------------------------------------------------------------------------

    // REDERIVE-DRAIN-CAP: N queued tables allow N + 2 passes; a table still changing gets the WARNING.
    public static function test_values_that_never_settle_stop_after_n_plus_two_passes_with_a_warning()
    {
        DB::statement('INSERT INTO derived_unstable_fixtures (tick) VALUES (NULL), (NULL)');

        // Two queued tables (one is skipped silently): four passes are allowed.
        Rsx_Rederive::enqueue('derived_unstable_fixtures');
        Rsx_Rederive::enqueue('no_such_rederive_table');

        [$ok, $output] = static::__drain();

        static::__assert_true($ok, 'never settling is not fatal');
        static::__assert_equals(4, substr_count($output, 'Rederiving cached values for derived_unstable_fixtures...'), 'N + 2 passes');
        static::__assert_contains('[WARNING] Cached values were still changing after 4 passes in: derived_unstable_fixtures.', $output);
        static::__assert_contains('in a cycle, or on something non-deterministic', $output, 'the warning says what it implies');
        static::__assert_contains('php artisan rsx:derived:check', $output, 'and how to see what drifts');
        static::__assert_true(str_ends_with($output, self::DONE), 'the closing line still follows');
        static::__assert_equals([], static::__queued(), 'the table is settled as far as the queue is concerned');
    }

    // REDERIVE-DRAIN-FAILURE: a derive method that throws fails the step; every queued table stays queued.
    public static function test_a_throwing_derive_fails_the_step_and_keeps_its_table_queued()
    {
        DB::statement("INSERT INTO derived_plain_fixtures (first_name, last_name, full_name) VALUES ('Ada', 'Lovelace', 'Ada Lovelace')");
        DB::statement('INSERT INTO derived_unstable_fixtures (tick) VALUES (NULL)');

        Rsx_Rederive::enqueue('derived_plain_fixtures');
        Rsx_Rederive::enqueue('derived_unstable_fixtures');
        Derived_Unstable_Fixture_Model::$fail = true;

        [$ok] = static::__drain();

        static::__assert_false($ok, 'the step reports failure, which execute_migrations() turns into a rollback');
        $queued = static::__queued();
        sort($queued);
        static::__assert_equals(['derived_plain_fixtures', 'derived_unstable_fixtures'], $queued, 'no table leaves the queue until the pass loop ends, so the next run walks them all again');
    }

    // REDERIVE-DRAIN-POSITION: the last step of execute_migrations(), on every run.
    public static function test_the_drain_is_the_last_step_of_every_run()
    {
        $method = new ReflectionMethod('App\\RSpade\\Commands\\Migrate\\Maint_Migrate', 'execute_migrations');
        $lines = file($method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $dictionary = strpos($source, 'build_revision_dictionary_if_stale(');
        $drain = strpos($source, '$this->drain_rederive_queue()');
        $return = strrpos($source, 'return $exitCode;');

        static::__assert_true($dictionary !== false && $drain !== false && $return !== false, 'all three are in execute_migrations()');
        static::__assert_equals(1, substr_count($source, 'drain_rederive_queue('), 'the drain is called once');
        static::__assert_true($drain > $dictionary, 'after the revision dictionary');
        static::__assert_true($drain < $return, 'before the return');

        $line_start = strrpos(substr($source, 0, $drain), "\n");
        $line = substr($source, $line_start, strpos($source, "\n", $drain) - $line_start);
        static::__assert_false(str_contains($line, 'framework-only'), 'and not skipped on --framework-only');
    }
}
