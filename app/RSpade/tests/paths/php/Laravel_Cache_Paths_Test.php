<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Paths\Php;

use App\RSpade\Core\Laravel\Rsx_Application;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Laravel's cached artifacts - config, events, services, packages - are
 * BUILD OUTPUTS, and so is compiled Blade. They live in the build tree with everything
 * else the build produces, which is what lets that tree be read-only to the web user on
 * a production box.
 *
 * The four getters are overridden on Rsx_Application rather than bootstrapPath() itself,
 * because bootstrapPath() also resolves bootstrap/providers.php - a SOURCE file. So the
 * assertion worth making is that every one of the four moved and nothing else did.
 *
 * Pure logic, no DB.
 */
class Laravel_Cache_Paths_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The running application IS the subclass - a plain Illuminate Application would
     * put all of them back inside the framework checkout with nothing to report it.
     */
    public static function test_the_container_is_the_rsx_application()
    {
        static::__assert_instance_of(Rsx_Application::class, app(), 'the container is Rsx_Application');
    }

    /**
     * Every cached-artifact getter answers inside build/laravel. (RSX has no route cache.)
     */
    public static function test_every_laravel_cache_file_is_in_the_build_tree()
    {
        $app = app();

        $expected = [
            'config' => $app->getCachedConfigPath(),
            'events' => $app->getCachedEventsPath(),
            'services' => $app->getCachedServicesPath(),
            'packages' => $app->getCachedPackagesPath(),
        ];

        foreach ($expected as $name => $actual) {
            static::__assert_equals(
                Rsx_Project_Paths::laravel_cache_file($name),
                $actual,
                "the cached {$name} file is in build/laravel"
            );

            static::__assert_true(
                Rsx_Project_Paths::is_under_build($actual),
                "the cached {$name} file is a build output"
            );
        }
    }

    /**
     * bootstrapPath() itself is UNMOVED: bootstrap/providers.php is source and must stay
     * where the framework ships it.
     */
    public static function test_the_bootstrap_path_itself_did_not_move()
    {
        static::__assert_equals(
            base_path('bootstrap'),
            rtrim(app()->bootstrapPath(), '/'),
            'bootstrapPath() still points inside the framework tree'
        );
    }

    /**
     * Compiled Blade is a build output too. No realpath() behind it: the directory
     * legitimately does not exist yet on a box that has not built, and realpath() would
     * answer false and send Blade to the working directory.
     */
    public static function test_compiled_views_are_in_the_build_tree()
    {
        static::__assert_equals(
            Rsx_Project_Paths::views_compiled_dir(),
            config('view.compiled'),
            'view.compiled is build/views'
        );

        static::__assert_true(
            Rsx_Project_Paths::is_under_build((string) config('view.compiled')),
            'compiled Blade is a build output'
        );
    }

    /**
     * A DEVELOPMENT box makes build/laravel for itself.
     *
     * Laravel's PackageManifest WRITES packages.php during boot whenever that file is
     * missing, and throws "The .../build/laravel directory must be present and writable"
     * when the directory is absent - before any command runs, so a fresh checkout would
     * refuse the very build that creates the tree. bootstrap/app.php therefore creates
     * this one directory in development, and only in development: in a production-like
     * mode the whole build tree is the build's to produce and its absence stays loud.
     *
     * Proven the only way a boot-time behaviour can be: remove the directory, boot a
     * child, and see that the child succeeded and the directory is back.
     */
    public static function test_a_development_box_recreates_the_laravel_cache_directory_at_boot()
    {
        $dir = Rsx_Project_Paths::laravel_cache_dir();

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        static::__assert_false(is_dir($dir), 'the directory is gone before the child boots');

        $output = [];
        $exit = \App\RSpade\Core\Console\Rsx_Artisan::run('--version', [], $output);

        static::__assert_equals(0, $exit, 'a development box boots with no build/laravel: ' . implode("\n", $output));
        static::__assert_true(is_dir($dir), 'and has one afterwards');
    }

    /**
     * Laravel's file cache driver is a DERIVED cache, not a build output.
     */
    public static function test_the_laravel_file_cache_is_derived_not_built()
    {
        static::__assert_equals(
            Rsx_Project_Paths::laravel_file_cache_dir(),
            config('cache.stores.file.path'),
            'the file cache store is in the tmp tree'
        );

        static::__assert_true(
            Rsx_Project_Paths::is_under_tmp((string) config('cache.stores.file.path')),
            'the file cache is derived'
        );
    }
}
