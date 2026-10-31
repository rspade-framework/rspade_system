<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Filesystem\Php;

use App\RSpade\Commands\Rsx\Clean_Command;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Clean_Command::clear_directory() against a process writing into the tree while it runs:
 * a directory written to between the walk and its rmdir() is emptied again and removed, a
 * directory that keeps being written to is left in place and returned, and neither fails
 * the clean. The writer is stood in for by the $before_rmdir_for_tests seam.
 */
class Clean_Directory_Concurrent_Writer_Test extends Rsx_Test_Abstract
{
    // Filesystem behavior - no database needed.
    protected static $use_database_transactions = false;

    /** A scratch tree: <root>/a/b/file.txt and <root>/c/file.txt */
    private static function __tree(): string
    {
        $root = Rsx_Project_Paths::tmp_path('clean_race_' . random_hash(8));
        ensure_directory($root . '/a/b');
        ensure_directory($root . '/c');
        file_put_contents($root . '/a/b/file.txt', 'x');
        file_put_contents($root . '/c/file.txt', 'x');

        return $root;
    }

    private static function __remove(string $root): void
    {
        Clean_Command::$before_rmdir_for_tests = null;
        if (is_dir($root)) {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    public static function test_an_undisturbed_tree_is_emptied_and_the_root_kept()
    {
        $root = static::__tree();

        try {
            $left = Clean_Command::clear_directory($root);

            static::__assert_equals([], $left, 'nothing left in place');
            static::__assert_true(is_dir($root), 'the root itself is kept');
            static::__assert_equals(['.', '..'], scandir($root), 'and is empty');
        } finally {
            static::__remove($root);
        }
    }

    public static function test_a_directory_written_once_mid_clean_is_still_removed()
    {
        $root = static::__tree();
        $written = false;

        Clean_Command::$before_rmdir_for_tests = function (string $path) use ($root, &$written) {
            if ($path === $root . '/a/b' && !$written) {
                $written = true;
                file_put_contents($path . '/late_cache_entry.txt', 'y');
            }
        };

        try {
            $left = Clean_Command::clear_directory($root);

            static::__assert_true($written, 'the writer ran');
            static::__assert_equals([], $left, 'nothing left in place');
            static::__assert_equals(['.', '..'], scandir($root), 'the root is empty');
        } finally {
            static::__remove($root);
        }
    }

    public static function test_a_directory_written_on_every_attempt_is_left_and_reported()
    {
        $root = static::__tree();

        Clean_Command::$before_rmdir_for_tests = function (string $path) use ($root) {
            if ($path === $root . '/a/b') {
                file_put_contents($path . '/late_' . random_hash(6) . '.txt', 'y');
            }
        };

        try {
            $left = Clean_Command::clear_directory($root);

            static::__assert_equals([$root . '/a/b'], $left, 'the contended directory is reported, once');
            static::__assert_true(is_dir($root . '/a/b'), 'and left in place');
            static::__assert_true(!is_dir($root . '/c'), 'the rest of the tree is still cleared');
        } finally {
            static::__remove($root);
        }
    }

    public static function test_an_entry_another_process_removed_first_is_not_an_error()
    {
        $root = static::__tree();

        Clean_Command::$before_rmdir_for_tests = function (string $path) use ($root) {
            if ($path === $root . '/c') {
                rmdir($path);
            }
        };

        try {
            $left = Clean_Command::clear_directory($root);

            static::__assert_equals([], $left, 'nothing left in place');
            static::__assert_equals(['.', '..'], scandir($root), 'the root is empty');
        } finally {
            static::__remove($root);
        }
    }
}
