<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * `make:migration:safe` records its whitelist entry BESIDE THE FILE IT AUTHORIZES.
 *
 * Every migration directory carries its own `.migration_whitelist`, and the migrator reads the
 * one next to each file. So the entry has to follow `--path`: an entry written into a different
 * tree authorizes nothing, and dirties a tree the migration does not live in - measured in a
 * downstream field report, where minting a framework migration wrote the file under `system/`
 * and the entry into the application tree.
 *
 * The command is exercised for real, into a scratch directory under app/RSpade/temp.
 */
class Make_Migration_Whitelist_Location_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var string Path relative to base_path(), which is how --path is resolved. */
    private static $relative_dir = 'app/RSpade/temp/make_migration_whitelist_test';

    public static function teardown()
    {
        $dir = base_path(static::$relative_dir);
        if (is_dir($dir)) {
            foreach (glob($dir . '/{,.}*', GLOB_BRACE) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($dir);
        }
    }

    // MIGRATE-WHITELIST-PATH: the entry lands in the directory --path names, and the application
    // whitelist is not touched.
    public static function test_the_entry_lands_beside_the_migration_it_authorizes()
    {
        $app_whitelist = base_path('rsx/resource/migrations/.migration_whitelist');
        $app_before = file_exists($app_whitelist) ? file_get_contents($app_whitelist) : null;

        $name = 'whitelist_location_probe_' . bin2hex(random_bytes(4));

        $code = Artisan::call('make:migration:safe', [
            'name' => $name,
            '--path' => static::$relative_dir,
        ]);
        static::__assert_equals(0, $code, 'the command succeeds');

        $dir = base_path(static::$relative_dir);

        $migrations = glob($dir . '/*_' . $name . '.php');
        static::__assert_count(1, $migrations, 'the migration file lands in the directory --path named');

        $whitelist_path = $dir . '/.migration_whitelist';
        static::__assert_true(
            file_exists($whitelist_path),
            'and the whitelist that authorizes it is created in that SAME directory'
        );

        $whitelist = json_decode(file_get_contents($whitelist_path), true);
        static::__assert_array_has_key(
            basename($migrations[0]),
            $whitelist['migrations'],
            'the entry names the file that was just written'
        );

        $app_after = file_exists($app_whitelist) ? file_get_contents($app_whitelist) : null;
        static::__assert_equals(
            $app_before,
            $app_after,
            'the application whitelist is untouched - a --path mint never dirties another tree'
        );
    }
}
