<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DbReset\Php;

use App\RSpade\Core\Database\Rsx_Data_Wipe;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Data_Wipe::clear_directory_contents() - the half of the reset that deletes files,
 * driven for real against a sandbox directory this class creates and destroys.
 *
 * The contract has three parts and all three matter:
 *   - the CONTENTS go, recursively, including dotfiles;
 *   - the DIRECTORY ITSELF survives, with its mode intact, because the blob store's
 *     permissions are part of a working installation and re-creating it would not
 *     reproduce them;
 *   - it RETURNS what it removed, because a command that destroys files owes the operator
 *     a number and there is no way to size a file after unlinking it.
 *
 * The sandbox lives under the system temp directory, never under storage/: this class
 * deletes everything it points at, and it must never be pointed at anything real.
 */
class Db_Reset_Directory_Wipe_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** A populated store is emptied, and the counts describe exactly what went. */
    public static function test_it_empties_the_directory_and_reports_what_it_removed()
    {
        $root = static::__populated_sandbox();

        $removed = Rsx_Data_Wipe::clear_directory_contents($root);

        static::__assert_true(is_dir($root), 'the directory itself must survive the wipe');
        static::__assert_count(0, static::__entries($root), 'nothing may be left inside it');

        // 3 files at the top, 2 nested, 1 dotfile.
        static::__assert_equals(6, $removed[Rsx_Data_Wipe::REMOVED_FILES]);
        static::__assert_equals(21, $removed[Rsx_Data_Wipe::REMOVED_BYTES]);

        static::__destroy_sandbox($root);
    }

    /**
     * The MODE survives. A blob store recreated with the default umask is not the same
     * blob store, and the failure would show up much later as a write the web user cannot
     * perform.
     */
    public static function test_the_directory_mode_survives()
    {
        $root = static::__populated_sandbox();
        chmod($root, 0770);
        clearstatcache(true, $root);
        $before = fileperms($root);

        Rsx_Data_Wipe::clear_directory_contents($root);

        clearstatcache(true, $root);
        static::__assert_equals($before, fileperms($root), 'the mode of the root must be untouched');

        static::__destroy_sandbox($root);
    }

    /**
     * A root that was never written to is not an error: the post-condition is "an empty
     * directory is there", which is as true of a store nobody has used as of one that has
     * just been cleared.
     */
    public static function test_a_missing_root_is_created_empty_and_counts_nothing()
    {
        $root = static::__sandbox_path();
        static::__assert_false(is_dir($root), 'the sandbox must not exist yet');

        $removed = Rsx_Data_Wipe::clear_directory_contents($root);

        static::__assert_true(is_dir($root), 'a missing root is created rather than refused');
        static::__assert_equals(0, $removed[Rsx_Data_Wipe::REMOVED_FILES]);
        static::__assert_equals(0, $removed[Rsx_Data_Wipe::REMOVED_BYTES]);

        static::__destroy_sandbox($root);
    }

    /** Measuring is separable from destroying - the reset counts before it drops. */
    public static function test_measuring_reports_the_same_totals_without_removing_anything()
    {
        $root = static::__populated_sandbox();

        $measured = Rsx_Data_Wipe::measure_directory_contents($root);

        static::__assert_equals(6, $measured[Rsx_Data_Wipe::REMOVED_FILES]);
        static::__assert_equals(21, $measured[Rsx_Data_Wipe::REMOVED_BYTES]);
        static::__assert_greater_than(0, count(static::__entries($root)), 'measuring must not delete');

        static::__destroy_sandbox($root);
    }

    // ---------------------------------------------------------------------------------
    // SANDBOX
    // ---------------------------------------------------------------------------------

    /** A unique path under the system temp directory. NEVER under storage/. */
    private static function __sandbox_path(): string
    {
        return rtrim(sys_get_temp_dir(), '/') . '/rsx-db-reset-sandbox-' . getmypid() . '-' . random_hash(8);
    }

    /**
     * Six files totalling 21 bytes: three at the top level, two nested two deep, and one
     * dotfile - the dotfile because a recursive delete that misses them leaves a store
     * that reads as empty and is not.
     */
    private static function __populated_sandbox(): string
    {
        $root = static::__sandbox_path();

        ensure_directory($root . '/ab/cd');

        file_put_contents($root . '/one.bin', 'aaaa');
        file_put_contents($root . '/two.bin', 'bbbb');
        file_put_contents($root . '/three.bin', 'cccc');
        file_put_contents($root . '/ab/four.bin', 'dddd');
        file_put_contents($root . '/ab/cd/five.bin', 'eeee');
        file_put_contents($root . '/.hidden', 'f');

        return $root;
    }

    /** @return string[] */
    private static function __entries(string $root): array
    {
        return array_values(array_diff(scandir($root), ['.', '..']));
    }

    private static function __destroy_sandbox(string $root): void
    {
        rmdir_recursive($root);
    }
}
