<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Core\Database\MigrationPaths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * WHAT the pending-migration notice counts.
 *
 * It counts MigrationPaths::get_all_migration_files() (the framework's documented source of
 * truth: database/migrations + rsx/resource/migrations, recursive) diffed against the
 * repository's ran list. Laravel's Migrator::paths() is NOT the right set - it returns only the
 * directories packages register via loadMigrationsFrom(), which on a stock install is exactly
 * one vendor directory RSX never migrates, making the old count a permanent false positive AND
 * blind to every migration that matters.
 *
 * Runs inside the per-test transaction, so the deleted ran-rows are rolled back.
 */
class Migrate_Status_Notice_Count_Test extends Rsx_Test_Abstract
{
    /** Invoke the command's protected counter. */
    protected static function __count(): int
    {
        $command = new \App\RSpade\Commands\Migrate\Migrate_Status_Notice_Command();
        $method = new \ReflectionMethod($command, 'get_pending_migrations_count');
        $method->setAccessible(true);

        return (int) $method->invoke($command);
    }

    /** Migration names (basenames without .php) the notice considers. */
    protected static function __names(): array
    {
        $names = [];
        foreach (MigrationPaths::get_all_migration_files() as $file) {
            $names[] = str_replace('.php', '', basename($file));
        }

        return $names;
    }

    public static function test_vendor_migrations_are_never_counted()
    {
        foreach (static::__names() as $name) {
            static::__assert_true(
                !str_contains($name, 'personal_access_tokens'),
                'a vendor migration RSX never runs leaked into the counted set: ' . $name
            );
        }
    }

    public static function test_counts_zero_when_the_schema_is_current()
    {
        static::__assert_equals(0, static::__count(), 'the test database is migrated, so nothing may be pending');
    }

    /**
     * Un-record one framework migration and one app migration: the notice must see BOTH (the
     * old implementation saw neither, and reported 1 regardless).
     */
    public static function test_counts_unapplied_framework_and_app_migrations()
    {
        $repository = app('migrator')->getRepository();
        $ran = $repository->getRan();

        $framework = static::__first_ran_name_under(database_path('migrations'), $ran);
        $app = static::__first_ran_name_under(base_path('rsx/resource/migrations'), $ran);

        if ($framework === null || $app === null) {
            static::__skip('needs at least one ran framework migration and one ran app migration');

            return;
        }

        // Un-record them through the repository (it owns the table name).
        $repository->delete((object) ['migration' => $framework]);
        $repository->delete((object) ['migration' => $app]);

        static::__assert_equals(2, static::__count(), 'both the framework and the app migration must be counted');
    }

    /** First RAN migration name discovered under a base path, or null. */
    protected static function __first_ran_name_under(string $path, array $ran): ?string
    {
        foreach (MigrationPaths::_scan_migration_files($path) as $file) {
            $name = str_replace('.php', '', basename($file));
            if (in_array($name, $ran, true)) {
                return $name;
            }
        }

        return null;
    }
}
