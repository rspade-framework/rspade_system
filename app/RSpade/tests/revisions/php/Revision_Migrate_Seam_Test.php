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
 * dictionary build, and the cache clear at the end of a run.
 *
 * SOURCE-STRUCTURE ASSERTIONS, deliberately - the same technique the migrate concern
 * already uses for `migrate:normalize_schema`'s catch path. Actually running `migrate`
 * from a test means snapshotting and re-migrating the test database to observe two lines
 * of output; what is worth protecting here is not that RsxCache::clear() works (the cache
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
     * BOTH successful tails clear the cache - the snapshot-protected run and the bare
     * one. A schema that has just moved leaves the build-scoped cache describing
     * something that no longer exists.
     */
    public static function test_both_migrate_success_paths_clear_the_cache()
    {
        foreach (['run_with_snapshot', 'run_without_snapshot'] as $method) {
            $source = static::__method_source(Maint_Migrate::class, $method);

            static::__assert_contains('RsxCache::clear();', $source, $method . '() must clear the cache');
            static::__assert_contains('[OK] Cache cleared', $source, $method . '() must say so');
        }
    }

    /**
     * migrate:restore clears it too, and only after the migration mode is cleaned up -
     * the restore has just replaced the database the cache was populated against.
     */
    public static function test_restore_clears_the_cache_after_cleanup()
    {
        $source = static::__method_source(Migrate_Restore_Command::class, 'handle');

        $cleanup = strpos($source, 'cleanup_migration_mode();');
        $clear = strpos($source, 'RsxCache::clear();');

        static::__assert_true($cleanup !== false, 'the restore must clean up migration mode');
        static::__assert_true($clear !== false, 'the restore must clear the cache');
        static::__assert_greater_than($cleanup, $clear, 'the cache is cleared after the restore completes');
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
