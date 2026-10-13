<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use ReflectionClass;
use ReflectionMethod;
use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Commands\Migrate\Migrate_Restore_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins the two seams `migrate` owns on behalf of this subsystem: the post-migrate
 * dictionary build, and the schema-state purge around a run.
 *
 * SOURCE-STRUCTURE ASSERTIONS, deliberately - the same technique the migrate concern
 * already uses for `migrate:normalize_schema`'s catch path. Actually running `migrate`
 * from a test means snapshotting and re-migrating the test database to observe two lines
 * of output; what is worth protecting here is not that the purge works (the database
 * concern owns that) but that the CALL SITES have not silently disappeared from a command
 * nobody re-reads. A deleted call is exactly the regression these assertions catch and
 * nothing else would.
 *
 * The dictionary build's own behavior - stale vs fresh, the id ceiling - is driven
 * directly in Revision_Dictionary_Test.
 */
class Revision_Migrate_Seam_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * execute_migrations() reaches the dictionary step - the one path both the
     * snapshot-protected and the bare run share.
     */
    public static function test_execute_migrations_calls_the_dictionary_step()
    {
        $source = static::__method_source(Maint_Migrate::class, 'execute_migrations');

        static::__assert_contains('build_revision_dictionary_if_stale()', $source, 'execute_migrations() must reach the dictionary step');
    }

    /**
     * The step sits AFTER the initial user, which is what puts it after the final
     * normalize pass and so after the schema reaches its tip.
     */
    public static function test_the_dictionary_step_runs_after_the_initial_user()
    {
        $source = static::__method_source(Maint_Migrate::class, 'execute_migrations');

        $initial_user = strpos($source, 'create_initial_user_if_needed()');
        $dictionary = strpos($source, 'build_revision_dictionary_if_stale()');

        static::__assert_true($initial_user !== false, 'the initial-user step must be present');
        static::__assert_true($dictionary !== false, 'the dictionary step must be present');
        static::__assert_greater_than($initial_user, $dictionary, 'the dictionary is built after the initial user');
    }

    /**
     * A framework-only run migrates a schema-only subset, and a dictionary derived from
     * that would describe half a database - so the step is skipped there, exactly as the
     * initial-user step above it is.
     */
    public static function test_the_dictionary_step_is_skipped_for_framework_only()
    {
        $source = static::__method_source(Maint_Migrate::class, 'execute_migrations');

        static::__assert_contains("!\$this->option('framework-only') && !\$this->build_revision_dictionary_if_stale()", $source);
    }

    /**
     * The step delegates to Revision_Dictionary and reports only when it built something.
     */
    public static function test_the_dictionary_step_delegates_and_reports()
    {
        $source = static::__method_source(Maint_Migrate::class, 'build_revision_dictionary_if_stale');

        static::__assert_contains('Revision_Dictionary::regenerate_if_stale()', $source);
        static::__assert_contains('[OK] Revision dictionary ', $source);
        static::__assert_contains('tokens)', $source);
        static::__assert_contains('if ($id !== null)', $source, 'silent when nothing was built');
    }

    /**
     * EVERY migrate run purges the schema-dependent caches before it starts and after it
     * ends, whichever runner it takes and however that runner ends: the purge sits in
     * handle(), ahead of the run and in a finally around it. A schema that has just moved
     * leaves everything cached about it describing something that no longer exists.
     */
    public static function test_migrate_purges_before_the_run_and_after_it_on_every_exit()
    {
        $source = static::__method_source(Maint_Migrate::class, 'handle');

        $before = strpos($source, '$this->purge_schema_state();');
        $run = strpos($source, 'return $this->run_migrate();');
        $finally = strpos($source, '} finally {');
        $after = strrpos($source, '$this->purge_schema_state();');

        static::__assert_true($before !== false && $run !== false && $finally !== false, 'handle() purges, runs, and has a finally');
        static::__assert_greater_than($before, $run, 'the first purge is ahead of the run');
        static::__assert_greater_than($run, $finally, 'the run is inside the try');
        static::__assert_greater_than($finally, $after, 'the second purge is in the finally');

        static::__assert_contains(
            'Rsx_Schema::purge_schema_state();',
            static::__method_source(Maint_Migrate::class, 'purge_schema_state'),
            'and the purge is the one definition'
        );
    }

    /**
     * The model code a run executes after its migrations - the hook's handlers, the initial
     * user, the dictionary - reads the MIGRATED columns: this process's copy of the schema
     * is dropped after the schema reaches the tip and before any of them runs.
     */
    public static function test_the_process_schema_copy_is_dropped_before_the_model_steps()
    {
        $source = static::__method_source(Maint_Migrate::class, 'execute_migrations');

        $reset = strpos($source, 'Rsx_Schema::reset();');
        $last_reset = strrpos($source, 'Rsx_Schema::reset();');
        $renames = strpos($source, '$this->apply_type_ref_table_renames();');
        $hook = strrpos($source, '$this->fire_post_normalize_hook();');
        $initial_user = strpos($source, 'create_initial_user_if_needed()');

        static::__assert_true($reset !== false, 'execute_migrations() resets the schema copy');
        static::__assert_greater_than($renames, $reset, 'after the schema work is done');
        static::__assert_greater_than($reset, $hook, 'before the final hook fires');
        static::__assert_greater_than($hook, $last_reset, 'again after the handlers, which may add columns');
        static::__assert_greater_than($last_reset, $initial_user, 'and before the initial user');
    }

    /**
     * migrate:restore purges too, and only after the migration mode is cleaned up - the
     * restore has just replaced the database everything cached was read from.
     */
    public static function test_restore_purges_after_cleanup()
    {
        $source = static::__method_source(Migrate_Restore_Command::class, 'handle');

        $cleanup = strpos($source, 'cleanup_migration_mode();');
        $purge = strpos($source, '$this->purge_schema_state();');

        static::__assert_true($cleanup !== false, 'the restore must clean up migration mode');
        static::__assert_true($purge !== false, 'the restore must purge the schema state');
        static::__assert_greater_than($cleanup, $purge, 'the purge follows the completed restore');
    }

    /**
     * The body of one method, comments included.
     */
    private static function __method_source(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = (new ReflectionClass($class))->getFileName();
        $lines = file($file);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }
}
