<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use App\RSpade\Core\Database\Migration_Guard;
use App\RSpade\Core\Database\Rsx_Rederive;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Migrate\Php\Guard_Probing_Migrator;
use App\RSpade\Tests\Migrate\Php\Hook_Counting_Migrate;

/**
 * While a migration's up() executes, a model save() / delete() and the builder's bulk
 * update() / delete() THROW (Migration_Guard): a migration is a historical record that
 * replays forever, so it writes with direct SQL, never through a model, which is current
 * code. The refusal is a RuntimeException - Maint_Migrate::execute_migrations() catches
 * \Exception and turns it into a rollback; an \Error would escape it.
 *
 * Every refusal fires before the model touches the database, so the writes below need no
 * table of their own. The window itself is measured on the real migration loop
 * (run_migrations_with_normalization()) against a scratch directory and a probing migrator.
 */
class Migration_Guard_Test extends Rsx_Test_Abstract
{
    /** @var string|null The scratch migration directory for the current test. */
    private static ?string $scratch = null;

    public static function teardown()
    {
        if (self::$scratch !== null && is_dir(self::$scratch)) {
            exec_safe('rm -rf ' . escapeshellarg(self::$scratch));
        }

        self::$scratch = null;
    }

    private static function __assert_refusal(callable $write, string $operation): void
    {
        $e = static::__assert_throws(RuntimeException::class, static function () use ($write) {
            Migration_Guard::run($write);
        }, 'refused: a migration is executing');

        static::__assert_equals(RuntimeException::class, get_class($e), 'a RuntimeException, which execute_migrations() catches');
        static::__assert_contains("Site_Model::{$operation} refused", $e->getMessage(), 'the message names the model and the call');
        static::__assert_contains('DB::statement() or the DB::table query builder', $e->getMessage(), 'and says how a migration writes');
        static::__assert_contains('historical record', $e->getMessage(), 'and why');
    }

    private static function __stage_migrations(int $count): string
    {
        $dir = sys_get_temp_dir() . '/rsx-guard-migrations-' . random_hash(12);
        ensure_directory($dir);
        self::$scratch = $dir;

        for ($index = 1; $index <= $count; $index++) {
            $name = '2026_11_13_00000' . $index . '_guard_probe_' . $index . '.php';
            file_put_contents_safe($dir . '/' . $name, "<?php\n// Never executed - see Migration_Guard_Test.\n");
        }

        return $dir;
    }

    // MIG-GUARD-SAVE
    public static function test_save_throws_inside_the_window()
    {
        static::__assert_refusal(static function () {
            $site = new Site_Model();
            $site->name = 'never written';
            $site->save();
        }, 'save()');
    }

    // MIG-GUARD-DELETE
    public static function test_delete_throws_inside_the_window()
    {
        static::__assert_refusal(static function () {
            (new Site_Model())->delete();
        }, 'delete()');
    }

    // MIG-GUARD-BULK
    public static function test_bulk_update_and_delete_throw_inside_the_window()
    {
        static::__assert_refusal(static function () {
            Site_Model::where('id', -1)->update(['name' => 'never written']);
        }, 'where(...)->update()');

        static::__assert_refusal(static function () {
            Site_Model::where('id', -1)->delete();
        }, 'where(...)->delete()');
    }

    // MIG-GUARD-OUTSIDE: nothing is refused outside the window, and reads are never refused.
    public static function test_writes_outside_the_window_and_reads_inside_it_are_not_refused()
    {
        static::__assert_false(Migration_Guard::is_active());
        static::__assert_equals(0, Site_Model::where('id', -1)->update(['name' => 'nobody']));

        $count = Migration_Guard::run(static fn () => Site_Model::where('id', -1)->count());
        static::__assert_equals(0, $count, 'a read inside the window runs');
    }

    // MIG-GUARD-ALLOW
    public static function test_allow_permits_writes_inside_the_window()
    {
        $updated = Migration_Guard::run(static function () {
            return Migration_Guard::allow(static fn () => Site_Model::where('id', -1)->update(['name' => 'nobody']));
        });

        static::__assert_equals(0, $updated, 'the allowed bulk update ran');
    }

    // MIG-GUARD-NESTING: each scope puts back exactly what it found.
    public static function test_nested_scopes_restore_their_state()
    {
        Migration_Guard::run(static function () {
            Migration_Guard::allow(static function () {
                // A run() inside an allowance is a fresh window with no allowance.
                Migration_Guard::run(static function () {
                    static::__assert_throws(RuntimeException::class, static function () {
                        Site_Model::where('id', -1)->update(['name' => 'x']);
                    });
                });

                static::__assert_true(Migration_Guard::is_active(), 'the outer window is still open');
                static::__assert_equals(0, Site_Model::where('id', -1)->update(['name' => 'x']), 'and the allowance is back');
            });

            static::__assert_throws(RuntimeException::class, static function () {
                Site_Model::where('id', -1)->update(['name' => 'x']);
            }, 'refused');
        });

        static::__assert_false(Migration_Guard::is_active(), 'closed after the outermost window');
    }

    // MIG-GUARD-THROW: a throwing body still closes the window.
    public static function test_the_window_closes_when_the_body_throws()
    {
        static::__assert_throws(RuntimeException::class, static function () {
            Migration_Guard::run(static function () {
                throw new RuntimeException('up() failed');
            });
        }, 'up() failed');

        static::__assert_false(Migration_Guard::is_active());
    }

    // MIG-GUARD-ENQUEUE: the rederive queue is a DB::table() write and works inside the window.
    public static function test_enqueue_works_inside_the_window()
    {
        Migration_Guard::run(static function () {
            Rsx_Rederive::enqueue('guard_probe_table');
        });

        static::__assert_equals(1, DB::table(Rsx_Rederive::QUEUE_TABLE)->where('table_name', 'guard_probe_table')->count());
    }

    // MIG-GUARD-LOOP: the real loop opens the window around each runPending() call only.
    public static function test_the_migration_loop_opens_the_window_around_each_migration()
    {
        $migrator = new Guard_Probing_Migrator();
        $command = new Hook_Counting_Migrate();
        $command->drive_migration_loop([static::__stage_migrations(3)], $migrator);

        static::__assert_equals([true, true, true], $migrator->seen_active, 'every up() ran inside the window');
        static::__assert_false(Migration_Guard::is_active(), 'and the window is closed after the loop');
    }

    // MIG-GUARD-LOOP-THROW
    public static function test_a_failing_migration_leaves_the_window_closed()
    {
        $migrator = new Guard_Probing_Migrator();
        $migrator->throw_message = 'probe up() failed';
        $command = new Hook_Counting_Migrate();

        static::__assert_throws(RuntimeException::class, static function () use ($command, $migrator) {
            $command->drive_migration_loop([static::__stage_migrations(2)], $migrator);
        }, 'probe up() failed');

        static::__assert_equals([true], $migrator->seen_active);
        static::__assert_false(Migration_Guard::is_active(), 'the window closed as the failure propagated');
    }

    // MIG-GUARD-SCOPE: the window wraps the runPending() call and nothing in execute_migrations().
    public static function test_the_window_is_opened_only_around_run_pending()
    {
        $loop = static::__method_source('run_migrations_with_normalization');
        $window = strpos($loop, 'Migration_Guard::run(');
        $run_pending = strpos($loop, '->runPending(');
        $normalize = strpos($loop, "'migrate:normalize_schema'");

        static::__assert_true($window !== false && $run_pending !== false, 'the loop opens the window and runs the migration');
        static::__assert_equals(1, substr_count($loop, 'Migration_Guard::run('), 'once per migration');
        static::__assert_true($window < $run_pending, 'runPending() runs inside it');
        static::__assert_true($normalize > $run_pending, 'normalization follows, outside the closure');

        static::__assert_equals(
            0,
            substr_count(static::__method_source('execute_migrations'), 'Migration_Guard'),
            'no post-migration step runs inside the window'
        );
    }

    // MIG-GUARD-ATTACHMENTS: the file-attachment repair a migration calls runs under the allowance.
    public static function test_the_mime_repair_runs_under_the_allowance()
    {
        $run = static::__method_source('run', 'App\\RSpade\\Core\\Files\\File_Mime_Repair');

        static::__assert_contains('Migration_Guard::allow(', $run);

        $counts = Migration_Guard::run(static fn () => \App\RSpade\Core\Files\File_Mime_Repair::run(true));
        static::__assert_true(is_array($counts), 'a dry run inside the window completes');
    }

    private static function __method_source(string $method_name, string $class = 'App\\RSpade\\Commands\\Migrate\\Maint_Migrate'): string
    {
        $method = new ReflectionMethod($class, $method_name);
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}
