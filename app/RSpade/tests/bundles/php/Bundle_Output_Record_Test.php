<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * EVERY COMPILE OF A BUNDLE TYPE RECORDS THE FILES IT WROTE - build/bundle_outputs/
 * <Bundle>__<type>.json - and the compiler's cache is that record: a compiled output that
 * is deleted from disk is compiled again in development, and is a loud failure in a sealed
 * build, never a page served without its stylesheet.
 *
 * The bundle compiled here is the framework's own control-panel bundle, resolved from the
 * manifest.
 */
class Bundle_Output_Record_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const BUNDLE = '_Sys_Bundle';

    private static function __compile(): array
    {
        $record = Manifest::php_class_metadata(self::BUNDLE);
        static::__assert_not_null($record, 'the framework ships its control-panel bundle');

        return (new BundleCompiler())->compile($record['fqcn'], []);
    }

    private static function __record(string $type): array
    {
        $path = Rsx_Project_Paths::bundle_outputs_file(self::BUNDLE, $type);
        static::__assert_true(is_file($path), "the {$type} compile left an output record");

        return json_decode((string) file_get_contents($path), true);
    }

    public static function test_the_record_names_exactly_the_files_the_compile_wrote()
    {
        $result = static::__compile();

        foreach (['vendor', 'app'] as $type) {
            $record = static::__record($type);
            static::__assert_equals(self::BUNDLE, $record['bundle']);
            static::__assert_equals($type, $record['type']);

            $expected = array_values(array_filter([$result["{$type}_js_bundle_path"] ?? null, $result["{$type}_css_bundle_path"] ?? null]));
            sort($expected);
            $recorded = $record['files'];
            sort($recorded);
            static::__assert_equals($expected, $recorded, "the {$type} record names the files the page is given");

            foreach ($record['files'] as $file) {
                static::__assert_true(str_contains($file, ".{$record['hash']}."), "{$file} carries the recorded hash");
            }
        }
    }

    public static function test_a_deleted_stylesheet_is_compiled_again()
    {
        $result = static::__compile();
        $css = $result['app_css_bundle_path'] ?? null;
        static::__assert_not_null($css, 'sanity: the bundle compiles an app stylesheet');

        $path = Rsx_Project_Paths::bundles_dir() . '/' . $css;
        unlink($path);
        static::__assert_false(is_file($path), 'sanity: the stylesheet is gone');

        $again = static::__compile();
        static::__assert_equals($css, $again['app_css_bundle_path'] ?? null, 'the page is given the stylesheet again');
        static::__assert_true(is_file($path), 'the stylesheet is back on disk');
    }

    public static function test_a_record_for_another_hash_is_no_cache()
    {
        static::__compile();
        $path = Rsx_Project_Paths::bundle_outputs_file(self::BUNDLE, 'app');
        $record = static::__record('app');

        file_put_contents($path, json_encode(['bundle' => self::BUNDLE, 'type' => 'app', 'hash' => '00000000', 'files' => []]));
        static::__compile();

        static::__assert_equals($record, static::__record('app'), 'the compile wrote the current record again');
    }

    public static function test_a_sealed_build_missing_a_recorded_output_fails_loud()
    {
        $result = static::__compile();
        $css = $result['app_css_bundle_path'];
        $path = Rsx_Project_Paths::bundles_dir() . '/' . $css;
        $bytes = file_get_contents($path);

        $sealed = new class extends BundleCompiler {
            public function check_as_sealed(string $bundle_name): ?array
            {
                $this->is_production = true;
                $this->bundle_name = $bundle_name;

                return $this->_check_production_cache();
            }
        };

        unlink($path);
        try {
            static::__assert_throws(\RuntimeException::class, function () use ($sealed) {
                $sealed->check_as_sealed(self::BUNDLE);
            }, 'rsx:build --force');
        } finally {
            file_put_contents($path, $bytes);
        }
    }
}
