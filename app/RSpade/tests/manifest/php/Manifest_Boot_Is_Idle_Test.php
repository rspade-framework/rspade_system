<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Store;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A boot on a tree unchanged since the last build does not rebuild the manifest.
 *
 * The index records the generated stub files (under the tmp tree) as entries the source
 * scan never returns. Cache validation must recognise them as generated through the path
 * owner rather than by a directory-name literal: when it does not, every entry reads as a
 * deleted file, every boot rebuilds, and the rebuild writes the same entries back.
 *
 * The proof is a child boot: inside the suite the manifest carries the test trees the
 * loaded index does not, so in-process validation is not a measure of freshness. The rule
 * itself, over synthetic data, is Manifest_Freshness_Decision_Test.
 */
class Manifest_Boot_Is_Idle_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The exemption is exercised: the index this box runs on records at least one generated
     * entry, and the path owner classifies that entry as generated.
     */
    public static function test_the_index_records_generated_entries_the_owner_recognises()
    {
        $generated = [];

        foreach (array_keys(Manifest::$data['data']['file_index'] ?? []) as $key) {
            if (Rsx_Project_Paths::is_generated_key($key)) {
                $generated[] = $key;
            }
        }

        static::__assert_true(count($generated) > 0, 'the index records generated entries');

        foreach ($generated as $key) {
            static::__assert_false(is_file(base_path($key)), "a generated key is not a source path: {$key}");
        }
    }

    /**
     * A second child boot, on the tree the first one left, leaves the index file untouched.
     *
     * The index on disk is SHARED state: any class that ran before this one may have added or
     * removed a file in a scanned tree, and the next boot then rebuilds - correctly. So the
     * first boot SETTLES the index (it may rebuild, absorbing whatever changed), and only the
     * second is measured: nothing changed between the two, so it must not write.
     *
     * The index is saved by writing a temp file and renaming it over the old one, so a save
     * always moves the inode - which catches a rewrite inside the same mtime second.
     */
    public static function test_a_child_boot_does_not_rewrite_the_index()
    {
        $index = Rsx_Project_Paths::manifest_index_file();

        $output = [];
        $exit = Rsx_Artisan::run('--version', [], $output);
        static::__assert_equals(0, $exit, 'the settling child booted: ' . implode("\n", $output));

        clearstatcache(true, $index);
        $before = [fileinode($index), filemtime($index)];

        $output = [];
        $exit = Rsx_Artisan::run('--version', [], $output);
        static::__assert_equals(0, $exit, 'the measured child booted: ' . implode("\n", $output));

        clearstatcache(true, $index);
        static::__assert_equals($before, [fileinode($index), filemtime($index)], 'the index was not rewritten by a boot on a tree unchanged since the last build');
    }
}
