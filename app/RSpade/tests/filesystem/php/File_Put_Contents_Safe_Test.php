<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Filesystem\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Framework test for the file_put_contents_safe() atomic writer and its
 * _paths_on_same_filesystem() helper (see app/RSpade/helpers.php).
 */
class File_Put_Contents_Safe_Test extends Rsx_Test_Abstract
{
    // Filesystem behavior - no database needed.
    protected static $use_database_transactions = false;

    public static function test_writes_full_content_and_returns_byte_count()
    {
        $f = storage_path('rsx-tmp/fpcs_fw_' . random_hash(8) . '.txt');
        @unlink($f);

        $bytes = file_put_contents_safe($f, 'hello-atomic');

        static::__assert_equals(12, $bytes);
        static::__assert_equals('hello-atomic', file_get_contents($f));

        @unlink($f);
    }

    public static function test_overwrite_preserves_permissions()
    {
        $f = storage_path('rsx-tmp/fpcs_fw_perm_' . random_hash(8) . '.txt');
        file_put_contents($f, 'old');
        chmod($f, 0600);

        file_put_contents_safe($f, 'replacement');

        static::__assert_equals('replacement', file_get_contents($f));
        static::__assert_equals(0600, fileperms($f) & 0777);

        @unlink($f);
    }

    public static function test_same_filesystem_detection()
    {
        static::__assert_true(_paths_on_same_filesystem(storage_path('rsx-tmp'), base_path()));
    }

    public static function test_cross_filesystem_write_leaves_no_staging_dir()
    {
        // /dev/shm is tmpfs - a distinct filesystem from the app tree - which
        // exercises the .tmp_<n> staging branch. Skip if it isn't available.
        if (!is_dir('/dev/shm') || _paths_on_same_filesystem('/dev/shm', storage_path('rsx-tmp'))) {
            static::__skip('/dev/shm not available as a distinct filesystem');

            return;
        }

        $f = '/dev/shm/fpcs_fw_xfs_' . random_hash(8) . '.txt';
        @unlink($f);

        $bytes = file_put_contents_safe($f, 'cross-fs');

        static::__assert_equals(8, $bytes);
        static::__assert_equals('cross-fs', file_get_contents($f));
        static::__assert_count(0, glob('/dev/shm/.tmp_*'));

        @unlink($f);
    }
}
