<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Migrate\Php\Dump_Rollback_Probe_Migrate;

/**
 * EVERY `migrate` RUN PURGES THE SCHEMA-DEPENDENT CACHES TWICE: once before anything is
 * touched, and once after the run has ended - whichever of the three runners it took, and
 * whether that runner succeeded, failed, restored, refused or threw.
 *
 * Driven through Dump_Rollback_Probe_Migrate, which runs Maint_Migrate::handle() for real
 * against a scratch database and records the purge instead of performing it. Each recorded
 * purge carries how many events (window raised, migrations run, a runner entered, window
 * lowered) had happened by then, so "before" is a purge at 0 and "after" is a purge at the
 * final event count.
 *
 * What the purge DOES is Rsx_Schema::purge_schema_state()'s, asserted in
 * tests/database/php/Rsx_Schema_Test.
 */
class Migrate_Purge_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const SCRATCH = 'rspade_migrate_purge';

    private const CONNECTION = 'migrate_purge';

    public static function setup()
    {
        config(['database.connections.' . self::CONNECTION => array_merge((array) config('database.connections.mysql'), ['database' => self::SCRATCH])]);
    }

    public static function teardown()
    {
        DB::disconnect(self::CONNECTION);
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `' . self::SCRATCH . '`');
        rmdir_recursive(static::__dir());
    }

    private static function __dir(): string
    {
        return Rsx_Project_Paths::tmp_path('test-migrate-purge');
    }

    private static function __command(array $options, int $pending = 1, int $migrations_exit = 0): Dump_Rollback_Probe_Migrate
    {
        DB::disconnect(self::CONNECTION);
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `' . self::SCRATCH . '`');
        DB::connection('mysql')->statement('CREATE DATABASE `' . self::SCRATCH . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        if (is_dir(static::__dir())) {
            rmdir_recursive(static::__dir());
        }
        ensure_directory(static::__dir());

        DB::connection(self::CONNECTION)->statement('CREATE TABLE things (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL)');

        $command = new Dump_Rollback_Probe_Migrate(self::CONNECTION, static::__dir());
        $command->options = $options;
        $command->pending = $pending;
        $command->migrations = fn (): int => $migrations_exit;

        return $command;
    }

    /** One purge before anything happened, one after everything did. */
    private static function __assert_purged_around(Dump_Rollback_Probe_Migrate $command, string $path): void
    {
        static::__assert_equals(
            [0, count($command->events)],
            $command->purges,
            $path . ': one purge before the run and one after it (events: ' . implode(', ', $command->events) . ')'
        );
    }

    // -----------------------------------------------------------------------------------
    // The datadir-snapshot runner
    // -----------------------------------------------------------------------------------

    public static function test_the_snapshot_runner_is_purged_around_on_success()
    {
        $command = static::__command([]);
        $command->snapshot_engaged = true;

        static::__assert_equals(0, $command->handle());
        static::__assert_equals(['snapshot_run'], $command->events);
        static::__assert_purged_around($command, 'snapshot run, success');
    }

    public static function test_the_snapshot_runner_is_purged_around_on_failure_and_rollback()
    {
        // A failed migration or a schema-quality violation: the runner rolls the datadir
        // back and answers 1.
        $command = static::__command([]);
        $command->snapshot_engaged = true;
        $command->runner_result = 1;

        static::__assert_equals(1, $command->handle());
        static::__assert_purged_around($command, 'snapshot run, rolled back');
    }

    public static function test_a_runner_that_throws_is_still_purged_after()
    {
        $command = static::__command([]);
        $command->snapshot_engaged = true;
        $command->runner_result = new \RuntimeException('the runner died');

        static::__assert_throws(\RuntimeException::class, fn () => $command->handle(), 'the runner died');
        static::__assert_purged_around($command, 'snapshot run, threw');
    }

    // -----------------------------------------------------------------------------------
    // The bare runner
    // -----------------------------------------------------------------------------------

    public static function test_the_bare_runner_is_purged_around_on_success()
    {
        $command = static::__command([]);

        static::__assert_equals(0, $command->handle());
        static::__assert_equals(['bare_run'], $command->events);
        static::__assert_purged_around($command, 'bare run, success');
    }

    public static function test_the_bare_runner_is_purged_around_on_failure()
    {
        $command = static::__command([]);
        $command->runner_result = 1;

        static::__assert_equals(1, $command->handle());
        static::__assert_purged_around($command, 'bare run, failed');
    }

    // -----------------------------------------------------------------------------------
    // The dump-rollback runner
    // -----------------------------------------------------------------------------------

    public static function test_dump_rollback_with_nothing_pending_is_purged_around()
    {
        $command = static::__command(['dump-rollback' => true], 0);

        static::__assert_equals(0, $command->handle());
        static::__assert_equals([], $command->events, 'sanity: nothing ran');
        static::__assert_equals([0, 0], $command->purges, 'purged before and after all the same');
    }

    public static function test_dump_rollback_success_is_purged_after_the_window_is_lowered()
    {
        $command = static::__command(['dump-rollback' => true], 1, 0);

        static::__assert_equals(0, $command->handle());
        static::__assert_equals(['maintenance_enable', 'migrations', 'maintenance_disable'], $command->events);
        static::__assert_purged_around($command, 'dump-rollback, success');
    }

    public static function test_dump_rollback_failure_and_restore_is_purged_around()
    {
        $command = static::__command(['dump-rollback' => true], 1, 1);

        static::__assert_equals(1, $command->handle());
        static::__assert_equals(['maintenance_enable', 'migrations', 'maintenance_disable'], $command->events);
        static::__assert_purged_around($command, 'dump-rollback, restored');
    }

    public static function test_a_refused_dump_rollback_is_purged_around()
    {
        $command = static::__command(['dump-rollback' => true, 'framework-only' => true]);

        static::__assert_equals(1, $command->handle());
        static::__assert_equals([0, 0], $command->purges);
    }

    public static function test_abandoning_a_dump_rollback_is_purged_around()
    {
        $command = static::__command(['abandon-dump-rollback' => true]);

        static::__assert_equals(0, $command->handle());
        static::__assert_equals([0, 0], $command->purges);
    }
}
