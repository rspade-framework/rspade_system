<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Migrate_Dump_Rollback;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Migrate\Php\Dump_Rollback_Probe_Migrate;

/**
 * `migrate --dump-rollback` as the command runs it (Maint_Migrate::handle()), against a
 * scratch database through Dump_Rollback_Probe_Migrate: the real dump, restore and decision,
 * with the migrations, the maintenance window and the bare run stood in for.
 *
 * What is pinned is the FLOW: nothing pending takes no dump and raises nothing; a success
 * commits and lowers the window it raised; a failure restores and lowers it; a crash left
 * behind is recovered by the next run - with or without the flag - before anything migrates;
 * a refusal migrates nothing and raises no window.
 */
class Dump_Rollback_Command_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const SCRATCH = 'rspade_dump_rollback_command';

    private const CONNECTION = 'dump_rollback_command';

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
        return Rsx_Project_Paths::tmp_path('test-dump-rollback-command');
    }

    private static function __fresh(): void
    {
        DB::disconnect(self::CONNECTION);
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `' . self::SCRATCH . '`');
        DB::connection('mysql')->statement('CREATE DATABASE `' . self::SCRATCH . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        if (is_dir(static::__dir())) {
            rmdir_recursive(static::__dir());
        }
        ensure_directory(static::__dir());

        static::__db()->statement('CREATE TABLE things (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL)');
        static::__db()->table('things')->insert([['name' => 'original']]);
    }

    private static function __db(): \Illuminate\Database\Connection
    {
        return DB::connection(self::CONNECTION);
    }

    private static function __command(array $options, int $pending, callable $migrations): Dump_Rollback_Probe_Migrate
    {
        $command = new Dump_Rollback_Probe_Migrate(self::CONNECTION, static::__dir());
        $command->options = $options;
        $command->pending = $pending;
        $command->migrations = $migrations;

        return $command;
    }

    /** A migration that changes the row and reports $exit. */
    private static function __migrating(int $exit): callable
    {
        return function () use ($exit): int {
            DB::connection(self::CONNECTION)->table('things')->update(['name' => 'migrated']);

            return $exit;
        };
    }

    private static function __name(): string
    {
        DB::disconnect(self::CONNECTION);

        return (string) static::__db()->table('things')->value('name');
    }

    private static function __files(): array
    {
        return glob(static::__dir() . '/*') ?: [];
    }

    public static function test_nothing_pending_takes_no_dump_and_raises_nothing()
    {
        static::__fresh();
        $command = static::__command(['dump-rollback' => true], 0, static::__migrating(0));

        static::__assert_equals(0, $command->handle());
        static::__assert_equals([], $command->events, 'no maintenance, no migrations');
        static::__assert_equals([], static::__files(), 'no dump');
    }

    public static function test_a_successful_run_commits_and_lowers_the_window_it_raised()
    {
        static::__fresh();
        $command = static::__command(['dump-rollback' => true], 2, static::__migrating(0));

        static::__assert_equals(0, $command->handle());
        static::__assert_equals(['maintenance_enable', 'migrations', 'maintenance_disable'], $command->events);
        static::__assert_equals('migrated', static::__name());
        static::__assert_equals([], static::__files(), 'the dump is removed');
    }

    public static function test_a_failed_run_restores_and_lowers_the_window()
    {
        static::__fresh();
        $command = static::__command(['dump-rollback' => true], 1, static::__migrating(1));

        static::__assert_equals(1, $command->handle());
        static::__assert_equals('original', static::__name(), 'the dump was restored');
        static::__assert_equals(['maintenance_enable', 'migrations', 'maintenance_disable'], $command->events);
        static::__assert_equals([], static::__files());
    }

    public static function test_a_window_somebody_else_raised_is_left_up()
    {
        static::__fresh();
        $command = static::__command(['dump-rollback' => true], 1, static::__migrating(0));
        $command->maintenance_up = true;

        static::__assert_equals(0, $command->handle());
        static::__assert_equals(['migrations'], $command->events, 'neither raised nor lowered');
        static::__assert_true($command->maintenance_up);
    }

    public static function test_a_crashed_run_is_recovered_before_the_next_migrates()
    {
        static::__fresh();
        // The earlier run: dumped, half-migrated, died.
        (new Migrate_Dump_Rollback(self::CONNECTION, static::__dir()))->begin(1);
        static::__db()->table('things')->update(['name' => 'half migrated']);

        $seen = [];
        $command = static::__command(['dump-rollback' => true], 1, function () use (&$seen): int {
            $seen[] = (string) DB::connection(self::CONNECTION)->table('things')->value('name');

            return 0;
        });

        static::__assert_equals(0, $command->handle());
        static::__assert_equals(['original'], $seen, 'restored first, then migrated from the restored state');
        static::__assert_equals(['maintenance_enable', 'migrations', 'maintenance_disable'], $command->events);
        static::__assert_equals([], static::__files());
    }

    public static function test_a_plain_migrate_recovers_a_crashed_run_too()
    {
        static::__fresh();
        (new Migrate_Dump_Rollback(self::CONNECTION, static::__dir()))->begin(1);
        static::__db()->table('things')->update(['name' => 'half migrated']);

        $command = static::__command([], 1, static::__migrating(0));

        static::__assert_equals(0, $command->handle());
        static::__assert_equals('original', static::__name(), 'restored before the bare run');
        static::__assert_equals(['maintenance_enable', 'maintenance_disable', 'bare_run'], $command->events);
    }

    public static function test_a_refusal_migrates_nothing_and_raises_no_window()
    {
        static::__fresh();
        (new Migrate_Dump_Rollback(self::CONNECTION, static::__dir()))->begin(1);
        static::__db()->table('things')->update(['name' => 'half migrated']);
        foreach (static::__files() as $file) {
            unlink($file);
        }

        $command = static::__command(['dump-rollback' => true], 1, static::__migrating(0));

        static::__assert_equals(1, $command->handle());
        static::__assert_equals([], $command->events, 'no window raised for a refusal, nothing migrated');
        static::__assert_equals('half migrated', static::__name(), 'nothing was touched');
        static::__assert_contains('--abandon-dump-rollback', implode("\n", $command->lines), 'the refusal names the way out');
    }

    public static function test_abandon_settles_a_refusal()
    {
        static::__fresh();
        (new Migrate_Dump_Rollback(self::CONNECTION, static::__dir()))->begin(1);
        foreach (static::__files() as $file) {
            unlink($file);
        }

        $abandon = static::__command(['abandon-dump-rollback' => true], 1, static::__migrating(0));
        static::__assert_equals(0, $abandon->handle());

        $next = static::__command([], 1, static::__migrating(0));
        static::__assert_equals(0, $next->handle());
        static::__assert_equals(['bare_run'], $next->events, 'nothing left to settle');
    }

    public static function test_an_unusable_client_program_is_fatal_before_anything_happens()
    {
        static::__fresh();
        $command = static::__command(['dump-rollback' => true], 1, static::__migrating(0));
        $command->binaries_unusable = 'mysqldump: not installed (not on PATH)';

        static::__assert_equals(1, $command->handle());
        static::__assert_equals([], $command->events, 'no window, no dump, no migrations');
        static::__assert_equals([], static::__files());
        static::__assert_contains('mysqldump: not installed', implode("\n", $command->lines));
    }

    public static function test_framework_only_is_refused_with_the_flag()
    {
        static::__fresh();
        $command = static::__command(['dump-rollback' => true, 'framework-only' => true], 1, static::__migrating(0));

        static::__assert_equals(1, $command->handle());
        static::__assert_equals([], $command->events);
    }
}
