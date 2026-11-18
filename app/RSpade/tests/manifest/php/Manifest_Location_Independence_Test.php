<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Manifest\Manifest_Store;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The manifest cache files name nothing about where the checkout sits.
 *
 * Every path the manifest stores - file keys, class records, the `file` a trait-supplied
 * method carries - is project-relative (`app/RSpade/...`, `vendor/...`, `rsx/...`, or the
 * logical `build/` / `tmp/` / `storage/` key). So the index and the cold file built from
 * the same tree are location-independent artifacts, and the build key can hash the derived
 * sections verbatim.
 *
 * Asserted against the live cache files on disk: every key and string value in both is
 * walked, and one that begins with an absolute root of this checkout (the project root,
 * base_path(), or a volatile tree's root) and names a file on disk is an absolute path the
 * manifest should have stored relative. The existence check keeps a route that happens to
 * begin with the same characters as a root from reading as a path.
 */
class Manifest_Location_Independence_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_the_index_holds_no_absolute_path()
    {
        static::__assert_no_absolute_path(Manifest_Store::_get_cache_file_path());
    }

    public static function test_the_cold_file_holds_no_absolute_path()
    {
        static::__assert_no_absolute_path(Manifest_Store::_get_cold_file_path());
    }

    private static function __assert_no_absolute_path(string $cache_file): void
    {
        static::__assert_true(is_file($cache_file), "the cache file {$cache_file} exists");

        $contents = include $cache_file;
        static::__assert_true(is_array($contents), "{$cache_file} returns an array");

        $roots = array_unique([
            dirname(base_path()),
            base_path(),
            Rsx_Project_Paths::build_root(),
            Rsx_Project_Paths::tmp_root(),
            Rsx_Project_Paths::storage_root(),
        ]);

        $found = [];
        static::__walk($contents, '', $roots, $found);

        static::__assert_equals(
            [],
            array_slice($found, 0, 10),
            basename($cache_file) . ' stores ' . count($found) . ' absolute path(s); every manifest path is project-relative (Rsx_Paths::relative())'
        );
    }

    private static function __walk(mixed $value, string $at, array $roots, array &$found): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $here = $at . '[' . $key . ']';

                if (is_string($key)) {
                    static::__check($key, $here . ' (key)', $roots, $found);
                }

                static::__walk($item, $here, $roots, $found);
            }

            return;
        }

        if (is_string($value)) {
            static::__check($value, $at, $roots, $found);
        }
    }

    private static function __check(string $value, string $at, array $roots, array &$found): void
    {
        foreach ($roots as $root) {
            if (str_starts_with($value, $root . '/') && file_exists($value)) {
                $found[] = "{$at} = {$value}";

                return;
            }
        }
    }
}
