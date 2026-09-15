<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Prod\Rsx_Build_Context;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The write guard as the WRITERS see it.
 *
 * Prod_Guard_Test pins the predicate - which mode and which context may write. This class
 * pins the plumbing: that the three functions everything else writes the build tree
 * through actually consult it, that a refused write leaves the disk untouched rather than
 * half-written, and that the same call succeeds in development and inside a build.
 *
 * The distinction matters because the guard is one line at the top of each writer: it is
 * removable by accident, and nothing else would notice. A test on the predicate alone
 * cannot see that.
 *
 * Scratch build root via Rsx_Project_Paths::_override(['build' => ...]); mode via
 * Rsx::_testing_set_mode(). The real build tree is never named. Pure logic, no DB.
 */
class Write_Guard_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run $fn against a scratch build root with the process mode forced, then restore
     * every seam and remove the tree.
     */
    private static function _with(string $mode, callable $fn): void
    {
        $root = sys_get_temp_dir() . '/rsx_write_guard_' . bin2hex(random_bytes(8));
        mkdir($root . '/bundles', 0775, true);

        Rsx_Project_Paths::_override(['build' => $root]);
        Rsx::_testing_set_mode($mode);
        Rsx_Build_Context::_testing_reset();

        try {
            $fn($root);
        } finally {
            Rsx_Build_Context::_testing_reset();
            Rsx::clear_mode_cache();
            Rsx_Project_Paths::_clear_overrides();
            self::__remove_tree($root);
        }
    }

    /** Remove a scratch tree without going through the guarded helper. */
    private static function __remove_tree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::__remove_tree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // file_put_contents_safe
    // -------------------------------------------------------------------------

    public static function test_a_content_write_into_the_build_tree_is_refused_in_production()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            $target = $root . '/bundles/App__app.deadbeef.js';

            static::__assert_throws(
                \RuntimeException::class,
                fn () => file_put_contents_safe($target, 'console.log(1);'),
                'file_put_contents_safe'
            );

            static::__assert_false(is_file($target), 'the refusal happens BEFORE the staging write, so nothing lands');
        });
    }

    public static function test_the_same_write_succeeds_in_development()
    {
        self::_with(Rsx::MODE_DEVELOPMENT, function ($root) {
            $target = $root . '/bundles/App__app.deadbeef.js';

            file_put_contents_safe($target, 'console.log(1);');

            static::__assert_equals('console.log(1);', file_get_contents($target), 'development rebuilds on demand');
        });
    }

    public static function test_the_same_write_succeeds_inside_a_build()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            Rsx_Build_Context::begin();

            $target = $root . '/bundles/App__app.deadbeef.js';
            file_put_contents_safe($target, 'console.log(1);');

            static::__assert_equals('console.log(1);', file_get_contents($target), 'the build is what produces the tree');
        });
    }

    public static function test_a_write_outside_the_build_tree_is_untouched_by_the_guard()
    {
        self::_with(Rsx::MODE_PRODUCTION, function () {
            $target = sys_get_temp_dir() . '/rsx_write_guard_outside_' . bin2hex(random_bytes(6)) . '.txt';

            try {
                file_put_contents_safe($target, 'unrelated');
                static::__assert_equals('unrelated', file_get_contents($target), 'a production box writes logs, uploads and temp files all day');
            } finally {
                @unlink($target);
            }
        });
    }

    // -------------------------------------------------------------------------
    // rmdir_recursive
    // -------------------------------------------------------------------------

    public static function test_a_recursive_delete_of_the_build_tree_is_refused_in_production()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            file_put_contents($root . '/bundles/stale.js', 'x');

            static::__assert_throws(
                \RuntimeException::class,
                fn () => rmdir_recursive($root . '/bundles'),
                'rmdir_recursive'
            );

            static::__assert_true(is_file($root . '/bundles/stale.js'), 'a refused wipe deletes nothing at all');
        });
    }

    public static function test_a_build_may_discard_what_it_is_about_to_rebuild()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            Rsx_Build_Context::begin();
            file_put_contents($root . '/bundles/stale.js', 'x');

            rmdir_recursive($root . '/bundles');

            static::__assert_false(is_dir($root . '/bundles'), 'rsx:clean runs inside the build for exactly this');
        });
    }

    // -------------------------------------------------------------------------
    // ensure_build_tree
    // -------------------------------------------------------------------------

    public static function test_a_production_box_never_grows_an_empty_build_tree()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            self::__remove_tree($root);

            static::__assert_throws(
                \RuntimeException::class,
                fn () => Rsx_Project_Paths::ensure_build_tree(),
                'ensure_build_tree'
            );

            static::__assert_false(
                is_dir($root),
                'the missing tree must stay missing: an empty one serves nothing while claiming to be a build'
            );
        });
    }

    public static function test_the_build_creates_the_tree_it_needs()
    {
        self::_with(Rsx::MODE_PRODUCTION, function ($root) {
            self::__remove_tree($root);
            Rsx_Build_Context::begin();

            Rsx_Project_Paths::ensure_build_tree();

            static::__assert_true(is_dir($root), 'the build root');
            static::__assert_true(is_dir(Rsx_Project_Paths::bundles_dir()), 'the bundle directory');
            static::__assert_true(is_dir(Rsx_Project_Paths::laravel_cache_dir()), 'the Laravel cache directory');
            static::__assert_true(is_dir(Rsx_Project_Paths::views_compiled_dir()), 'the compiled-view directory');
        });
    }

    public static function test_development_creates_the_tree_without_ceremony()
    {
        self::_with(Rsx::MODE_DEVELOPMENT, function ($root) {
            self::__remove_tree($root);

            Rsx_Project_Paths::ensure_build_tree();

            static::__assert_true(is_dir(Rsx_Project_Paths::bundles_dir()), 'development makes what it is missing');
        });
    }
}
