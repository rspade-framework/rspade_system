<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DbCache\Cli;

use Illuminate\Support\Facades\DB;
use App\RSpade\Commands\Database\Db_Rebuild_Provision_Cache_Snapshot_Command;
use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE INITIAL PROVISION, end to end.
 *
 * Feature 2 of the schema cache: `php artisan migrate` against a database with NO TABLES
 * AT ALL restores rsx/resource/db/schema_cache.sql.gz (plus the uploads archive) and then
 * continues with the ordinary migration run.
 *
 * IT RUNS AGAINST ITS OWN SCRATCH DATABASE, not the suite's test database. The subject is
 * a database being dropped, recreated and migrated from zero; doing that to the database
 * every other test class shares would make this class's failures somebody else's. The
 * scratch database is created here and dropped in teardown().
 *
 * The cache ARTIFACTS are written into a sandbox directory named by the internal
 * --_cache-dir flag, so nothing is ever written into the shipped rsx/resource/db.
 *
 * WHAT THIS DOES NOT DO: it never runs rsx:db:rebuild_provision_cache_snapshot itself. That command enters the
 * REAL maintenance window (php-fpm, realtime and redis go down) and operates on the
 * DEFAULT connection's database, which during a suite run is this box's development
 * database. Neither is a thing a test may do, so the build command's decision logic is
 * covered as pure logic by the two tests in ../php/ - the recovery state machine, and the
 * no-overwrite rule.
 */
class Db_Cache_Restore_Cli_Test extends Rsx_Test_Abstract
{
    // No transactions, and no test-database reset: this class never touches the test
    // database except to READ a dump of it as cache material.
    protected static $use_database_transactions = false;

    /** The scratch database. Never the developer database, never the test database. */
    const SCRATCH_DATABASE = 'rspade_db_cache_scratch';

    protected static function __sandbox(): string
    {
        $dir = storage_path('rsx-tmp/test-db-cache-sandbox');
        ensure_directory($dir);

        return $dir;
    }

    protected static function __schema_cache_path(): string
    {
        return static::__sandbox() . '/' . Db_Rebuild_Provision_Cache_Snapshot_Command::SCHEMA_CACHE_FILE;
    }

    /** `-h -P -u` for the test connection's mysql/mysqldump client. */
    protected static function __client_flags(): string
    {
        $conn = config('database.connections.test');

        return '-h' . escapeshellarg((string) $conn['host'])
            . ' -P' . escapeshellarg((string) $conn['port'])
            . ' -u' . escapeshellarg((string) $conn['username']);
    }

    /** @return array<string, string> */
    protected static function __mysql_env(): array
    {
        $password = (string) config('database.connections.test.password');

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    protected static function __shell(string $pipeline, string $context): void
    {
        $output = [];
        $exit_code = 0;
        \exec_safe('bash -c ' . escapeshellarg($pipeline), $output, $exit_code, static::__mysql_env());

        static::__assert_equals(0, $exit_code, $context . ' failed: ' . implode("\n", $output));
    }

    /** DROP + CREATE the scratch database, leaving it with no tables at all. */
    protected static function __recreate_scratch(): void
    {
        $admin = DB::connection('mysql');
        $admin->statement('DROP DATABASE IF EXISTS `' . self::SCRATCH_DATABASE . '`');
        $admin->statement('CREATE DATABASE `' . self::SCRATCH_DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /** Table / row counts for an arbitrary database, read through the admin connection. */
    protected static function __counts(string $database): array
    {
        $admin = DB::connection('mysql');
        $migrations_table = (string) config('database.migrations', 'migrations');

        $tables = (int) $admin->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?',
            [$database]
        )->c;

        if ($tables === 0) {
            return ['tables' => 0, 'migrations' => 0, 'users' => 0];
        }

        return [
            'tables' => $tables,
            'migrations' => (int) $admin->selectOne('SELECT COUNT(*) AS c FROM `' . $database . '`.`' . $migrations_table . '`')->c,
            'users' => (int) $admin->selectOne('SELECT COUNT(*) AS c FROM `' . $database . '`.`users`')->c,
        ];
    }

    /**
     * Produce the cache artifact from the (already migrated) test database. This is the
     * same mysqldump invocation rsx:db:rebuild_provision_cache_snapshot uses.
     */
    protected static function __write_cache_from_test_database(): void
    {
        $source = (string) config('database.connections.test.database');

        static::__shell(
            'set -o pipefail; mysqldump ' . static::__client_flags()
            . ' --no-tablespaces --single-transaction --quick --lock-tables=false '
            . escapeshellarg($source)
            . ' | gzip > ' . escapeshellarg(static::__schema_cache_path()),
            'dumping ' . $source
        );

        static::__assert_true(
            is_file(static::__schema_cache_path()) && filesize(static::__schema_cache_path()) > 0,
            'the cache artifact must exist'
        );
    }

    /**
     * `migrate` against the scratch database.
     *
     * --_no-snapshot is not an optimization here, it is a requirement: the development
     * snapshot path STOPS THE WHOLE MYSQL INSTANCE and copies its data directory, which a
     * test must never do to the box it is running on. RSX_MODE=debug runs the child as a
     * sealed deployment would run it - the mode the cache restore actually ships to. That
     * child inherits the test-run flag Rsx_Artisan forwards, so it boots on an http box
     * too (Rsx_App_Url's test-run allowance). --_no-initial-user keeps the account out,
     * the way the real cache build does.
     *
     * @return array{0: int, 1: string} exit code, combined output
     */
    protected static function __migrate_scratch(?string $cache_dir): array
    {
        $args = ['--force', '--_no-initial-user', Maint_Migrate::NO_SNAPSHOT_FLAG];

        if ($cache_dir !== null) {
            $args[] = Db_Rebuild_Provision_Cache_Snapshot_Command::CACHE_DIR_FLAG . '=' . $cache_dir;
        }

        $output = [];
        $exit_code = Rsx_Artisan::run('migrate', $args, $output, [
            'DB_DATABASE' => self::SCRATCH_DATABASE,
            'RSX_MODE' => 'debug',
        ]);

        return [$exit_code, implode("\n", $output)];
    }

    public static function teardown()
    {
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `' . self::SCRATCH_DATABASE . '`');

        $sandbox = storage_path('rsx-tmp/test-db-cache-sandbox');
        if (is_dir($sandbox)) {
            rmdir_recursive($sandbox);
        }
    }

    /**
     * THE FEATURE. An empty database plus a present cache restores it, and the run applies
     * nothing on top of a cache that is already at the tip.
     */
    public static function test_an_empty_database_restores_the_cache_and_applies_nothing_further()
    {
        $expected = static::__counts((string) config('database.connections.test.database'));
        static::__assert_greater_than(0, $expected['tables'], 'the test database must be migrated');
        static::__assert_greater_than(0, $expected['migrations'], 'the baseline carries migration rows');

        static::__write_cache_from_test_database();
        static::__recreate_scratch();
        static::__assert_equals(0, static::__counts(self::SCRATCH_DATABASE)['tables'], 'the scratch database must start empty');

        [$exit_code, $text] = static::__migrate_scratch(static::__sandbox());

        static::__assert_equals(0, $exit_code, 'the migrate run must succeed: ' . $text);
        static::__assert_contains('Restoring cached schema', $text, 'the restore must have fired: ' . $text);
        static::__assert_contains('Cached schema restored', $text);

        $actual = static::__counts(self::SCRATCH_DATABASE);
        static::__assert_equals($expected['tables'], $actual['tables'], 'every table must be present');
        static::__assert_equals($expected['migrations'], $actual['migrations'], 'every migration row must be present');
        static::__assert_equals($expected['users'], $actual['users'], 'user rows must round-trip exactly');

        // The cache is already at the tip, so nothing was applied on top of it.
        static::__assert_true(
            !str_contains($text, 'Migrating:'),
            'nothing should be applied on top of a cache already at the tip: ' . $text
        );

        // THE RESTORE IS A PRE-MIGRATE STEP, NOT A REPLACEMENT FOR THE MIGRATION RUN.
        // Both normalization passes bracket the migration loop, so their presence AFTER
        // the restore lines is the proof that the ordinary flow continued underneath -
        // pending migrations would have applied here had the cache been behind the tip.
        static::__assert_contains('Pre-migration normalization', $text, 'the normal run must continue: ' . $text);
        static::__assert_contains('Post-migration normalization', $text, 'the normal run must continue: ' . $text);

        $restored_at = strpos($text, 'Cached schema restored');
        static::__assert_true($restored_at !== false, 'the restore line must be present');
        static::__assert_true(
            strpos($text, 'Post-migration normalization') > $restored_at,
            'the migration run must proceed AFTER the restore, not instead of it'
        );
    }

    /**
     * THE REFUSAL (DBC-15). rsx:db:rebuild_provision_cache_snapshot runs in DEVELOPMENT mode only, and the
     * refusal is the FIRST statement of handle() - before the paths are resolved, before
     * maintenance is raised, before anything is backed up. So a run outside development
     * exits 1 having touched nothing at all.
     *
     * The mode is flipped for the SUBPROCESS only (RSX_MODE=debug on the child's
     * environment). This box stays in development: flipping its actual mode would seal
     * the build under a test.
     */
    public static function test_the_build_refuses_outside_development_and_touches_nothing()
    {
        $marker_path = storage_path(
            Db_Rebuild_Provision_Cache_Snapshot_Command::WORK_DIR_RELATIVE . '/' . Db_Rebuild_Provision_Cache_Snapshot_Command::MARKER_FILE
        );

        static::__assert_false(
            Framework_Maintenance::is_active_on_disk(),
            'maintenance must not already be up when this test starts'
        );
        static::__assert_false(is_file($marker_path), 'no in-progress marker may exist when this test starts');

        $before = static::__counts((string) config('database.connections.test.database'));

        $output = [];
        $exit_code = Rsx_Artisan::run('rsx:db:rebuild_provision_cache_snapshot', [], $output, ['RSX_MODE' => 'debug']);
        $text = implode("\n", $output);

        static::__assert_equals(1, $exit_code, 'the command must refuse with exit 1: ' . $text);
        static::__assert_contains('DEVELOPMENT mode only', $text, 'the refusal must name the required mode: ' . $text);
        static::__assert_contains('Debug', $text, 'the refusal must name the mode it actually ran in: ' . $text);

        // Nothing was touched: no maintenance window, no in-progress marker, no database
        // change. The refusal returns before __resolve_paths(), so none of these can move.
        static::__assert_false(Framework_Maintenance::is_active_on_disk(), 'the refusal must not raise maintenance mode');
        static::__assert_false(is_file($marker_path), 'the refusal must not write an in-progress marker');
        static::__assert_false(
            is_dir(Rsx_File_Paths::blob_root() . Db_Rebuild_Provision_Cache_Snapshot_Command::BLOB_BACKUP_SUFFIX),
            'the refusal must not move the blob store aside'
        );

        $after = static::__counts((string) config('database.connections.test.database'));
        static::__assert_equals($before['tables'], $after['tables'], 'the database must be untouched');
        static::__assert_equals($before['migrations'], $after['migrations'], 'the database must be untouched');
    }

    /**
     * bin/mysqlpv is a convenience on the dump/restore pipelines, never a participant. On
     * a box with no python3 the segment is empty and the plain pipeline moves identical
     * bytes; here python3 is present (the container ships it), so the segment is built.
     */
    public static function test_the_mysqlpv_segment_is_optional_and_well_formed()
    {
        $segment = Db_Rebuild_Provision_Cache_Snapshot_Command::mysqlpv_pipe_segment();

        if ($segment === '') {
            // No python3 on this box - the pipelines run without progress reporting.
            return;
        }

        static::__assert_true(str_starts_with($segment, ' | python3 '), 'the segment must be a pipe stage: ' . $segment);
        static::__assert_contains('mysqlpv', $segment);
        static::__assert_true(str_ends_with($segment, ' -l'), 'line-log mode, never the overwriting status line: ' . $segment);
    }

    /**
     * A NON-EMPTY database is left completely alone. This is the assertion that keeps the
     * feature from ever overwriting a working database.
     */
    public static function test_a_non_empty_database_never_restores_the_cache()
    {
        static::__write_cache_from_test_database();
        static::__recreate_scratch();

        // Populate it by hand, so the migrate below meets a database with tables in it.
        static::__shell(
            'set -o pipefail; cat ' . escapeshellarg(static::__schema_cache_path())
            . ' | gunzip | mysql ' . static::__client_flags() . ' ' . escapeshellarg(self::SCRATCH_DATABASE),
            'seeding the scratch database'
        );
        static::__assert_greater_than(0, static::__counts(self::SCRATCH_DATABASE)['tables'], 'the scratch database must be populated');

        [$exit_code, $text] = static::__migrate_scratch(static::__sandbox());

        static::__assert_equals(0, $exit_code, 'the migrate run must succeed: ' . $text);
        static::__assert_true(
            !str_contains($text, 'Restoring cached schema'),
            'a populated database must never be restored over: ' . $text
        );
    }

    /**
     * No cache shipped is not an error - it is every application that has never run
     * rsx:db:rebuild_provision_cache_snapshot. An empty database with no cache migrates from zero, exactly as it
     * always did, and says nothing about a cache.
     */
    public static function test_a_missing_cache_migrates_from_zero_in_silence()
    {
        $empty_cache_dir = static::__sandbox() . '/no-cache-here';
        ensure_directory($empty_cache_dir);

        static::__recreate_scratch();

        [$exit_code, $text] = static::__migrate_scratch($empty_cache_dir);

        static::__assert_equals(0, $exit_code, 'the migrate run must succeed: ' . $text);
        static::__assert_true(
            !str_contains($text, 'Restoring cached schema'),
            'no cache means no restore, and no complaint: ' . $text
        );

        // It really did migrate: the schema is there, built the long way.
        static::__assert_greater_than(
            0,
            static::__counts(self::SCRATCH_DATABASE)['tables'],
            'a from-zero migration must still produce the schema: ' . $text
        );
    }
}
