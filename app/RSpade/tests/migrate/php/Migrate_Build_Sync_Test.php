<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Support\Rsx_Fingerprint;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Migrate\Php\Build_Sync_Probe_Migrate;

/**
 * MIGRATE SETTLES THE BUILD AGAINST THE DATABASE IT JUST CHANGED.
 *
 * A build's model column maps are read from the live schema, and nothing in the source tree
 * changes when a migration runs - so a build made before a migration went on describing the
 * database as it was, indefinitely: the tables the migration created had no model, the
 * columns it added were unknown. The build now records the applied migrations it was made
 * against, and migrate compares.
 *
 * The decision is driven through Build_Sync_Probe_Migrate (facts supplied, the rebuild
 * recorded); the fingerprint and the record are read for real.
 */
class Migrate_Build_Sync_Test extends Rsx_Test_Abstract
{
    private static function __probe(): Build_Sync_Probe_Migrate
    {
        return new Build_Sync_Probe_Migrate();
    }

    public static function test_a_build_that_matches_the_database_is_left_alone()
    {
        $command = static::__probe();
        $command->applied_now = 'after';
        $command->build_hash = 'after';

        static::__assert_equals(0, $command->sync('before'));
        static::__assert_equals([], $command->rebuilds, 'no rebuild');
        static::__assert_equals([], $command->lines, 'and nothing said');
    }

    public static function test_a_run_that_migrated_rebuilds_a_stale_build_and_says_why()
    {
        $command = static::__probe();

        static::__assert_equals(0, $command->sync('before'));
        static::__assert_equals(['development'], $command->rebuilds, 'one rebuild, the development one');
        static::__assert_contains('the schema changed', $command->output());
        static::__assert_contains('--no-rebuild', $command->output(), 'the opt-out is named');
    }

    public static function test_a_run_that_migrated_nothing_never_rebuilds()
    {
        $command = static::__probe();
        $command->applied_now = 'unchanged';
        $command->build_hash = 'something else';

        static::__assert_equals(0, $command->sync('unchanged'));
        static::__assert_equals([], $command->rebuilds, 'no rebuild when nothing was migrated');
        static::__assert_contains('does not describe this database', $command->output(), 'the mismatch is reported');
        static::__assert_contains('rsx:manifest:build --force', $command->output(), 'with the command');
    }

    public static function test_no_rebuild_skips_the_rebuild_and_reports_the_stale_build()
    {
        $command = static::__probe();
        $command->no_rebuild = true;

        static::__assert_equals(0, $command->sync('before'));
        static::__assert_equals([], $command->rebuilds);
        static::__assert_contains('--no-rebuild', $command->output());
        static::__assert_contains('rsx:manifest:build --force', $command->output());
    }

    public static function test_a_failed_rebuild_is_the_exit_code_and_says_the_database_moved()
    {
        $command = static::__probe();
        $command->rebuild_exit = 3;

        static::__assert_equals(3, $command->sync('before'));
        static::__assert_contains('were applied and are committed', $command->output());
    }

    public static function test_an_index_with_no_record_is_a_mismatch()
    {
        $command = static::__probe();
        $command->build_hash = null;

        static::__assert_equals(0, $command->sync('before'));
        static::__assert_equals(['development'], $command->rebuilds);
    }

    /**
     * The fingerprint is the applied NAMES and nothing else: two databases that have had the
     * same migrations applied answer the same value, whatever their ids, batches and order.
     */
    public static function test_the_fingerprint_is_the_sorted_applied_names()
    {
        $table = config('database.migrations', 'migrations');
        $names = DB::table($table)->orderByDesc('id')->pluck('migration')->all();

        static::__assert_true(count($names) > 0, 'the test database is migrated');

        sort($names, SORT_STRING);

        static::__assert_equals(md5(implode("\n", $names)), Rsx_Fingerprint::applied_migrations());
    }

    public static function test_the_build_records_the_database_it_was_made_against()
    {
        static::__assert_equals(
            Rsx_Fingerprint::applied_migrations(),
            Manifest::applied_migrations(),
            'the build this test runs under describes the database this test runs against'
        );
    }
}
