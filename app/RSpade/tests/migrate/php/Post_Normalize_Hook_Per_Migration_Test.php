<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Migrate\Php\Hook_Counting_Migrate;

/**
 * migrate.normalize_schema.complete fires after EVERY normalize pass, so a run with N
 * pending migrations fires it N+1 times.
 *
 * WHY THE COUNT IS THE CONTRACT. A migration may read a column an application's handler
 * computes. On an incremental box each invocation runs one or two migrations and then
 * fires, so by the time the next migration is authored the handler's columns are on every
 * table. A from-zero replay runs the whole chain in ONE invocation: fired only at the end,
 * every mid-history migration would see a schema no developer has ever seen, and the one
 * that reads a handler-computed column dies with an unknown-column error hundreds of
 * migrations in (a downstream field report, 2026-09-15). Firing per pass is what makes the
 * two histories produce the same schema at every step.
 *
 * HOW THIS IS MEASURED. The per-migration firings are driven for REAL:
 * run_migrations_with_normalization() is run against a SCRATCH migration directory holding
 * throwaway migration files, with a fake migrator standing in for Laravel's (so no DDL is
 * issued and no migrations table is touched) and the normalize call stubbed to succeed.
 * The handler is registered through the Event_Registry test seam and counts its own
 * invocations, so what is counted is the real dispatcher on the real call site.
 *
 * The other two firings - after the PRE-migration pass and after the POST-migration pass -
 * live in execute_migrations(), which cannot be driven without migrating a real database.
 * Their presence and their position are pinned structurally by
 * Migrate_Normalize_Complete_Event_Test; this class carries that count as
 * EXECUTE_MIGRATIONS_FIRINGS and adds it to what it measured, so the N+1 total is asserted
 * and the sibling test fails the moment the constant stops being true.
 *
 * No database access - skip the per-test transaction.
 */
class Post_Normalize_Hook_Per_Migration_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    const EVENT_NAME = 'migrate.normalize_schema.complete';

    /**
     * Firings that happen outside the migration loop: one after the pre-migration
     * normalize pass, one after the post-migration pass. Pinned by
     * Migrate_Normalize_Complete_Event_Test::test_the_dispatcher_is_called_after_every_normalize_pass().
     */
    const EXECUTE_MIGRATIONS_FIRINGS = 2;

    /** @var string|null The scratch migration directory for the current test. */
    private static $scratch = null;

    /**
     * A scratch migration directory holding $count throwaway migration files.
     *
     * The files are never executed - the fake migrator's runPending() does nothing - but
     * they must exist and be named like migrations, because the loop discovers its work
     * with the same scanner the real runner uses.
     */
    private static function __stage_migrations(int $count): string
    {
        $dir = sys_get_temp_dir() . '/rsx-hook-migrations-' . random_hash(12);
        ensure_directory($dir);
        self::$scratch = $dir;

        for ($index = 1; $index <= $count; $index++) {
            $name = '2026_09_15_00000' . $index . '_scratch_migration_' . $index . '.php';
            file_put_contents_safe($dir . '/' . $name, "<?php\n// Never executed - see Post_Normalize_Hook_Per_Migration_Test.\n");
        }

        return $dir;
    }

    private static function __teardown(): void
    {
        Event_Registry::_clear_test_handlers();

        if (self::$scratch !== null && is_dir(self::$scratch)) {
            exec_safe('rm -rf ' . escapeshellarg(self::$scratch));
        }

        self::$scratch = null;
    }

    /**
     * Run the real migration loop over $count staged migrations and return how many times
     * the hook fired.
     */
    private static function __loop_firings(int $count): int
    {
        $fired = 0;

        Event_Registry::_set_test_handlers(self::EVENT_NAME, [
            static function () use (&$fired) {
                $fired++;
            },
        ]);

        $directory = self::__stage_migrations($count);

        $command = new Hook_Counting_Migrate();
        $command->drive_migration_loop([$directory]);

        return $fired;
    }

    // -------------------------------------------------------------------------
    // The loop fires once per per-migration normalize pass
    // -------------------------------------------------------------------------

    public static function test_two_pending_migrations_fire_the_hook_inside_the_loop()
    {
        try {
            // The loop normalizes BETWEEN migrations - after the last one the
            // post-migration pass covers it - so two migrations mean one mid-loop pass
            // and one mid-loop firing.
            static::__assert_equals(
                1,
                self::__loop_firings(2),
                'two pending migrations produce one per-migration normalize pass, and one firing'
            );
        } finally {
            self::__teardown();
        }
    }

    public static function test_four_pending_migrations_fire_the_hook_three_times_inside_the_loop()
    {
        try {
            static::__assert_equals(
                3,
                self::__loop_firings(4),
                'N pending migrations produce N-1 per-migration normalize passes, and N-1 firings'
            );
        } finally {
            self::__teardown();
        }
    }

    public static function test_a_single_pending_migration_fires_nothing_inside_the_loop()
    {
        try {
            static::__assert_equals(
                0,
                self::__loop_firings(1),
                'one pending migration has no BETWEEN, so the only firings are the pre and post passes'
            );
        } finally {
            self::__teardown();
        }
    }

    public static function test_nothing_to_migrate_fires_nothing_inside_the_loop()
    {
        try {
            static::__assert_equals(
                0,
                self::__loop_firings(0),
                'a run with nothing to migrate never enters the loop'
            );
        } finally {
            self::__teardown();
        }
    }

    // -------------------------------------------------------------------------
    // The whole-run total
    // -------------------------------------------------------------------------

    public static function test_a_run_of_n_migrations_fires_the_hook_n_plus_one_times()
    {
        try {
            foreach ([0, 1, 2, 4, 7] as $pending) {
                $total = self::__loop_firings($pending) + self::EXECUTE_MIGRATIONS_FIRINGS;
                self::__teardown();

                $expected = $pending === 0 ? 2 : $pending + 1;

                static::__assert_equals(
                    $expected,
                    $total,
                    "a run of {$pending} pending migration(s) fires the hook {$expected} times"
                );
            }
        } finally {
            self::__teardown();
        }
    }

    /**
     * The mid-loop firing follows a SUCCESSFUL normalize pass. A pass that exits non-zero
     * throws, so the hook never runs against a schema the framework could not normalize.
     */
    public static function test_a_failed_normalize_pass_fires_nothing()
    {
        $fired = 0;

        Event_Registry::_set_test_handlers(self::EVENT_NAME, [
            static function () use (&$fired) {
                $fired++;
            },
        ]);

        try {
            $directory = self::__stage_migrations(2);

            $command = new Hook_Counting_Migrate();
            $command->normalize_exit_code = 1;

            static::__assert_throws(
                \Exception::class,
                static function () use ($command, $directory) {
                    $command->drive_migration_loop([$directory]);
                },
                'Normalization failed after migration'
            );

            static::__assert_equals(0, $fired, 'a failed normalize pass fires no hook');
        } finally {
            self::__teardown();
        }
    }
}
