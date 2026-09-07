<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Determinism units for the build file-hash seam (helpers.php).
 *
 * The prod/debug content hash is the foundation of the sealed-build determinism
 * contract: identical (project-relative path, content) inputs must produce an
 * identical hash regardless of the absolute checkout location, and must be blind
 * to disk mtime. These tests drive the extracted, pure helpers directly so the
 * PROD branch is exercised without flipping the process-global RSX_MODE.
 *
 * Pure logic, no DB.
 */
class File_Hash_Determinism_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function _stage_dir(): string
    {
        $dir = sys_get_temp_dir() . '/rsx_prod_mode_' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);

        return $dir;
    }

    private static function _rmtree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // _rsx_content_hash - the pure core
    // -------------------------------------------------------------------------

    public static function test_content_hash_is_stable_for_same_inputs()
    {
        $a = \_rsx_content_hash('rsx/models/foo_model.php', "<?php\nclass Foo {}\n");
        $b = \_rsx_content_hash('rsx/models/foo_model.php', "<?php\nclass Foo {}\n");
        static::__assert_equals($a, $b, 'identical relative path + content must hash identically');
    }

    public static function test_content_hash_changes_when_content_changes()
    {
        $a = \_rsx_content_hash('rsx/models/foo_model.php', "<?php\nclass Foo {}\n");
        $b = \_rsx_content_hash('rsx/models/foo_model.php', "<?php\nclass Foo { public \$x; }\n");
        static::__assert_not_equals($a, $b, 'a content change must change the hash');
    }

    public static function test_content_hash_changes_when_relative_path_changes()
    {
        $a = \_rsx_content_hash('rsx/models/foo_model.php', "<?php\nclass Foo {}\n");
        $b = \_rsx_content_hash('rsx/models/bar_model.php', "<?php\nclass Foo {}\n");
        static::__assert_not_equals($a, $b, 'a relative-path change must change the hash');
    }

    // -------------------------------------------------------------------------
    // Checkout-location independence: the same logical file staged at two
    // different absolute roots, read from disk, hashes identically. This is the
    // unit-level proof of the cluster determinism contract (the full two-checkout
    // proof is a separate acceptance harness).
    // -------------------------------------------------------------------------

    public static function test_same_content_at_two_absolute_roots_hashes_equal()
    {
        $root_a = self::_stage_dir();
        $root_b = self::_stage_dir();
        try {
            $rel = 'rsx/models/foo_model.php';
            $content = "<?php\nclass Foo_Model {}\n";

            mkdir(dirname($root_a . '/' . $rel), 0777, true);
            mkdir(dirname($root_b . '/' . $rel), 0777, true);
            file_put_contents($root_a . '/' . $rel, $content);
            file_put_contents($root_b . '/' . $rel, $content);

            $hash_a = \_rsx_content_hash($rel, file_get_contents($root_a . '/' . $rel));
            $hash_b = \_rsx_content_hash($rel, file_get_contents($root_b . '/' . $rel));

            static::__assert_equals(
                $hash_a,
                $hash_b,
                'same relative path + content read from two different absolute roots must hash identically'
            );
        } finally {
            self::_rmtree($root_a);
            self::_rmtree($root_b);
        }
    }

    // -------------------------------------------------------------------------
    // _rsx_file_hash_content_based - blind to mtime, sensitive to content
    // -------------------------------------------------------------------------

    public static function test_content_file_hash_unchanged_after_touch()
    {
        $dir = self::_stage_dir();
        try {
            $file = $dir . '/thing.js';
            file_put_contents($file, "const a = 1;\n");
            $before = \_rsx_file_hash_content_based($file);

            // Move mtime forward without changing content.
            touch($file, time() + 3600);
            clearstatcache(true, $file);
            $after = \_rsx_file_hash_content_based($file);

            static::__assert_equals($before, $after, 'content hash must ignore an mtime-only change');
        } finally {
            self::_rmtree($dir);
        }
    }

    public static function test_content_file_hash_changes_on_content_change()
    {
        $dir = self::_stage_dir();
        try {
            $file = $dir . '/thing.js';
            file_put_contents($file, "const a = 1;\n");
            $before = \_rsx_file_hash_content_based($file);

            file_put_contents($file, "const a = 2;\n");
            clearstatcache(true, $file);
            $after = \_rsx_file_hash_content_based($file);

            static::__assert_not_equals($before, $after, 'content hash must change when the bytes change');
        } finally {
            self::_rmtree($dir);
        }
    }

    // -------------------------------------------------------------------------
    // _rsx_file_hash_fast - dev metadata path IS mtime-sensitive (by design)
    // -------------------------------------------------------------------------

    public static function test_fast_hash_changes_after_touch()
    {
        $dir = self::_stage_dir();
        try {
            $file = $dir . '/thing.js';
            file_put_contents($file, "const a = 1;\n");
            $before = \_rsx_file_hash_fast($file);

            touch($file, time() + 3600);
            clearstatcache(true, $file);
            $after = \_rsx_file_hash_fast($file);

            static::__assert_not_equals($before, $after, 'dev fast hash is metadata-based and must notice an mtime change');
        } finally {
            self::_rmtree($dir);
        }
    }

    // -------------------------------------------------------------------------
    // _rsx_relative_build_path - the two-mount convergence
    // -------------------------------------------------------------------------

    public static function test_relative_build_path_strips_base_path()
    {
        static::__assert_equals(
            'app/RSpade/helpers.php',
            \_rsx_relative_build_path(base_path() . '/app/RSpade/helpers.php')
        );
    }

    public static function test_relative_build_path_symlink_route()
    {
        // /rsx reached via the base_path()/rsx symlink.
        static::__assert_equals(
            'rsx/models/foo_model.php',
            \_rsx_relative_build_path(base_path() . '/rsx/models/foo_model.php')
        );
    }

    public static function test_relative_build_path_real_mount_route_converges()
    {
        // /rsx reached via the real project-root mount must yield the SAME relative
        // string as the symlink route, so a file's build key does not depend on
        // which mount it was reached through.
        $symlink_route = \_rsx_relative_build_path(base_path() . '/rsx/models/foo_model.php');
        $real_route = \_rsx_relative_build_path(dirname(base_path()) . '/rsx/models/foo_model.php');
        static::__assert_equals($symlink_route, $real_route, 'symlink and real /rsx mounts must converge');
        static::__assert_equals('rsx/models/foo_model.php', $real_route);
    }

    public static function test_relative_build_path_leaves_external_path_unchanged()
    {
        $external = '/some/unrelated/absolute/path.php';
        static::__assert_equals($external, \_rsx_relative_build_path($external));
    }
}
