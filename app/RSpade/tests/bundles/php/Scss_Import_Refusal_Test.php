<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Integrations\Scss\Scss_BundleProcessor;

/**
 * An `@import` in an application stylesheet is refused BEFORE Sass runs, with an actionable
 * error - the file, each import with its line, why, and what to do instead - rather than the
 * raw Sass deprecation failure it used to surface as. A stylesheet under a `vendor/`
 * directory (Bootstrap's sources) manages its own imports and is exempt, and an `@import`
 * inside a comment is not an import.
 *
 * Bundles hand every stylesheet through Scss_BundleProcessor::process_batch(), which
 * validates each one first; process_batch() is driven here with a single offending file, so
 * the refusal is observed exactly where a bundle compile would meet it.
 */
class Scss_Import_Refusal_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __write(string $relative, string $contents): array
    {
        $root = Rsx_Project_Paths::tmp_path('scss_import_refusal_' . random_hash(8));
        $file = $root . '/' . $relative;
        ensure_directory(dirname($file));
        file_put_contents($file, $contents);

        return [$root, $file];
    }

    public static function test_an_application_import_is_refused_naming_each_line()
    {
        [$root, $file] = static::__write('rsx/app/fixture/fixture_widget.scss',
            "// @import 'commented_out';\n"
            . ".Fixture_Widget {\n"
            . "    color: red;\n"
            . "}\n"
            . "@import 'variables';\n"
            . "@import \"mixins\";\n");

        try {
            $files = [$file];
            $message = '';
            try {
                Scss_BundleProcessor::process_batch($files);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }

            static::__assert_contains('contains @import directives', $message, 'the refusal is the actionable one, not a Sass failure');
            static::__assert_contains('fixture_widget.scss', $message, 'it names the file');
            static::__assert_contains("line 5: @import 'variables'", $message, 'each import with its line');
            static::__assert_contains('line 6: @import "mixins"', $message);
            static::__assert_false(str_contains($message, 'commented_out'), 'an import inside a comment is not reported');
            static::__assert_contains('How to fix', $message, 'and it says what to do instead');
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    public static function test_a_commented_import_alone_is_not_refused()
    {
        [$root, $file] = static::__write('rsx/app/fixture/fixture_quiet.scss',
            "/* @import 'old_partial'; */\n.Fixture_Quiet {\n    color: blue;\n}\n");

        try {
            $method = new \ReflectionMethod(Scss_BundleProcessor::class, '__validate_scss_file');
            $method->invoke(null, $file);
            static::__pass('no refusal');
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    public static function test_a_vendor_stylesheet_may_import()
    {
        [$root, $file] = static::__write('rsx/resource/vendor/fixture_lib/fixture_lib.scss',
            "@import 'functions';\n@import 'variables';\n");

        try {
            $method = new \ReflectionMethod(Scss_BundleProcessor::class, '__validate_scss_file');
            $method->invoke(null, $file);
            static::__pass('vendor stylesheets manage their own imports');
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }
}
