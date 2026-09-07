<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DbReset\Cli;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

// @ARTISAN-SPAWN-01-EXCEPTION - one test below needs artisan's STDOUT and STDERR kept apart,
// which is the property under test; exec_safe() (and therefore Rsx_Artisan::run) merges them
// into one pipe by design. Every other spawn here goes through Rsx_Artisan. Nothing holds a
// lock at that point, so there is nothing for the child to inherit.

/**
 * THE REFUSAL, DRIVEN FOR REAL - the only part of rsx:database_and_storage_reset that a test
 * may execute.
 *
 * The command drops every table on the DEFAULT connection, deletes every file under the three
 * configured roots, and enters the REAL maintenance window (php-fpm, realtime and redis go
 * down). None of that is a thing a test does, on any database. This is the same doctrine
 * ../db_cache/README.md records for rsx:db:rebuild_provision_cache_snapshot, and it holds verbatim here.
 *
 * WHAT MAKES THAT SAFE RATHER THAN A GAP. Every invocation below OMITS --yes, and the
 * decision that --yes is required is the pure function Database_And_Storage_Reset_Command::
 * refusal_reason() - proved for all six flag/seal combinations in
 * ../php/Db_Reset_Refusal_Test, and called as the FIRST statement of handle() before a path
 * is resolved or a connection is opened. The sealed variant is asserted there too, for the
 * same reason: a seal cannot be faked across a process boundary, and faking one to watch a
 * wipe refuse is a worse test than proving the rule that decides it.
 *
 * What these tests add is the WIRING: that the refusal really is reached first, that it exits
 * non-zero, that it lands on stdout, and that the database is untouched when it does.
 */
class Db_Reset_Refusal_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const COMMAND = 'rsx:database_and_storage_reset';

    /**
     * EVERY spawn below points the child at the TEST database.
     *
     * A subprocess does not inherit the runner's in-process connection switch, so without
     * this it would resolve the DEFAULT connection - this box's DEVELOPMENT database. The
     * refusal means it never opens it either way, but a test whose safety rests on one
     * `if` is one regression away from being the thing that destroys a developer's data.
     * Naming the database is an ENVIRONMENT fact, which is exactly what $env is for.
     *
     * @return array<string, string>
     */
    private static function __child_env(): array
    {
        return ['DB_DATABASE' => (string) config('database.connections.test.database')];
    }

    /**
     * No flags: the accidental invocation. It must refuse, explain, name the flag, and
     * leave the database exactly as it found it.
     */
    public static function test_no_flags_refuses_explains_and_destroys_nothing()
    {
        static::__assert_false(
            Framework_Maintenance::is_active_on_disk(),
            'maintenance must not already be up when this test starts'
        );

        $before = static::__table_count();

        $output = [];
        $exit_code = Rsx_Artisan::run(self::COMMAND, [], $output, static::__child_env());
        $text = implode("\n", $output);

        static::__assert_equals(1, $exit_code, 'a missing confirmation must exit non-zero: ' . $text);

        // Every consequence the change request requires the operator to have read.
        static::__assert_contains('every table', $text);
        static::__assert_contains('every business record and every user account and login', $text);
        static::__assert_contains('every file under these roots is DELETED', $text);
        static::__assert_contains('ACTUAL UPLOADED BYTES', $text);
        static::__assert_contains('THERE IS NO UNDO', $text);
        static::__assert_contains('BEFORE running this command', $text);

        // The flag, spelled exactly as it must be typed.
        static::__assert_contains('php artisan ' . self::COMMAND . ' --yes', $text);

        // All three configured roots are named, so the operator sees the blob store AND
        // the two caches that go with it. Matched by suffix: the child resolves them from
        // its own storage root, which is not this process's test-scoped one.
        static::__assert_contains('/uploads', $text);
        static::__assert_contains('/rsx-thumbnails', $text);
        static::__assert_contains('/rsx-renditions', $text);

        // Nothing moved. The refusal returns before the window is raised, before the
        // snapshot decision, before a single table is counted.
        static::__assert_false(Framework_Maintenance::is_active_on_disk(), 'the refusal must not raise maintenance mode');
        static::__assert_equals($before, static::__table_count(), 'the database must be untouched');
    }

    /**
     * --force alone is the wrong flag, and it is NOT a confirmation. An operator who
     * reaches for the production escape hatch without confirming still gets the full text
     * and still loses nothing.
     */
    public static function test_force_alone_is_not_a_confirmation()
    {
        $before = static::__table_count();

        $output = [];
        $exit_code = Rsx_Artisan::run(self::COMMAND, ['--force'], $output, static::__child_env());
        $text = implode("\n", $output);

        static::__assert_equals(1, $exit_code, '--force without --yes must refuse: ' . $text);
        static::__assert_contains('php artisan ' . self::COMMAND . ' --yes', $text);
        static::__assert_false(Framework_Maintenance::is_active_on_disk(), 'the refusal must not raise maintenance mode');
        static::__assert_equals($before, static::__table_count(), 'the database must be untouched');
    }

    /**
     * The refusal goes to STDOUT and nothing goes to stderr.
     *
     * House style: a refusal is printed the way every other refusal in this codebase is
     * printed - an error() headline and line() body, on ordinary output. An operator
     * piping the run to a file gets the refusal in the same place the run would have
     * been. Proving that needs the two streams kept apart, which exec_safe() merges by
     * design - hence the file-level spawn exception above.
     */
    public static function test_the_refusal_is_written_to_stdout_and_stderr_stays_empty()
    {
        $stdout_file = tempnam(sys_get_temp_dir(), 'rsx-db-reset-out-');
        $stderr_file = tempnam(sys_get_temp_dir(), 'rsx-db-reset-err-');

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(base_path('artisan'))
            . ' ' . escapeshellarg(self::COMMAND)
            . ' > ' . escapeshellarg($stdout_file)
            . ' 2> ' . escapeshellarg($stderr_file);

        // Same environment fact as __child_env(), applied to this process for the
        // duration of the call so the child inherits it.
        $previous_database = getenv('DB_DATABASE');
        putenv('DB_DATABASE=' . (string) config('database.connections.test.database'));

        $exit_code = 0;
        try {
            \passthru('bash -c ' . escapeshellarg($command), $exit_code);
        } finally {
            if ($previous_database === false) {
                putenv('DB_DATABASE');
            } else {
                putenv('DB_DATABASE=' . $previous_database);
            }
        }

        $stdout = (string) file_get_contents($stdout_file);
        $stderr = (string) file_get_contents($stderr_file);

        unlink($stdout_file);
        unlink($stderr_file);

        static::__assert_equals(1, $exit_code, 'the refusal must exit 1');
        static::__assert_equals('', trim($stderr), 'the refusal is ordinary output - stderr must stay empty');
        static::__assert_contains('DESTROYS THIS APPLICATION', $stdout);
        static::__assert_contains('--yes', $stdout);
    }

    /**
     * Tables in the database the CHILD was pointed at - the one a regressed gate would
     * have dropped. Counted by name rather than DATABASE(), because the connection this
     * process holds and the connection the child opens are not the same one.
     */
    private static function __table_count(): int
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = ?',
            [(string) config('database.connections.test.database')]
        );

        return (int) ($rows[0]->table_count ?? 0);
    }
}
