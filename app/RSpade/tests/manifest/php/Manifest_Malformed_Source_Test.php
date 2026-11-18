<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\PHP\Rsx_Namespace;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MALFORMED SOURCE FAILS THE BUILD LOUDLY, AND NEVER HALF-INDEXES.
 *
 * One application file with a syntax error takes the whole build down, so the failure
 * message is the developer's only clue: it must name the file and the problem. Pinned here,
 * over a child build of a fixture tree into scratch roots (the house fixture-build seam,
 * Manifest_Fixture_Build_Test):
 *
 *   - a PHP parse error fails the build naming the file and the parser's complaint;
 *   - a syntactically malformed attribute is a parse error the same way;
 *   - an attribute whose argument cannot be evaluated (an undefined constant) fails the
 *     build loudly naming the constant, the file, the attribute and the declaration;
 *   - a failed build writes no index, and once the file is fixed the next build indexes it.
 *
 * The fixture tree sits under a `resource/` directory, so no ordinary build - including the
 * one this test process runs on - ever scans the broken files; only the children that name
 * it as an extra root do.
 */
class Manifest_Malformed_Source_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $tree = '';

    private static string $build_root = '';

    private static function __make_tree(): void
    {
        static::$tree = 'app/RSpade/temp/resource/manifest_malformed' . getmypid();
        static::$build_root = Rsx_Project_Paths::tmp_path('manifest-malformed-test/' . getmypid() . '/build');

        static::__remove_tree();

        ensure_directory(base_path(static::$tree));
        ensure_directory(static::$build_root);
        ensure_directory(static::$build_root . '-tmp');
    }

    private static function __remove_tree(): void
    {
        if (static::$tree !== '' && is_dir(base_path(static::$tree))) {
            rmdir_recursive(base_path(static::$tree));
        }

        if (static::$build_root !== '' && is_dir(dirname(static::$build_root))) {
            rmdir_recursive(dirname(static::$build_root));
        }
    }

    /** Write a PHP class file into the fixture tree whose class body is $body. Returns its path. */
    private static function __write_class(string $file, string $class, string $body): string
    {
        $relative = static::$tree . '/' . $file;
        $namespace = Rsx_Namespace::from_path($relative);

        file_put_contents(base_path($relative), "<?php\n\nnamespace {$namespace};\n\nclass {$class}\n{\n{$body}\n}\n");

        return $relative;
    }

    /** Build the fixture tree in a child. Returns [exit code, output]. */
    private static function __build(): array
    {
        $output = [];

        $exit = Rsx_Artisan::run('rsx:manifest:build', [
            '--_rsx-build-root=' . static::$build_root,
            '--_rsx-tmp-root=' . static::$build_root . '-tmp',
            '--_manifest-extra-scan-roots=' . static::$tree,
        ], $output);

        return [$exit, implode("\n", $output)];
    }

    /**
     * The output with every whitespace run removed. The console wraps a long error line at
     * its width, splitting a file or constant name across lines, so a name is looked for here.
     */
    private static function __compact(string $output): string
    {
        return (string) preg_replace('/\s+/', '', $output);
    }

    private static function __has_index(): bool
    {
        return file_exists(static::$build_root . '/manifest_index.php');
    }

    public static function test_a_parse_error_fails_the_build_naming_the_file()
    {
        static::__make_tree();

        try {
            static::__write_class(
                'malformed_parse_probe.php',
                'Malformed_Parse_Probe',
                "    public static function broken(\n    {\n    }"
            );

            [$exit, $output] = static::__build();

            static::__assert_not_equals(0, $exit, "a file that does not parse fails the build:\n{$output}");
            static::__assert_contains('malformed_parse_probe.php', static::__compact($output), 'the failure names the file');
            static::__assert_contains('PHPsyntaxerror', static::__compact($output), 'and says it is a syntax error');
            static::__assert_false(static::__has_index(), 'a failed build writes no index');
        } finally {
            static::__remove_tree();
        }
    }

    public static function test_a_syntactically_malformed_attribute_fails_naming_the_file()
    {
        static::__make_tree();

        try {
            static::__write_class(
                'malformed_attribute_probe.php',
                'Malformed_Attribute_Probe',
                "    #[Probe_Marker('unclosed'\n    public static function marked()\n    {\n    }"
            );

            [$exit, $output] = static::__build();

            static::__assert_not_equals(0, $exit, "an unclosed attribute fails the build:\n{$output}");
            static::__assert_contains('malformed_attribute_probe.php', static::__compact($output), 'the failure names the file');
            static::__assert_contains('PHPsyntaxerror', static::__compact($output));
            static::__assert_false(static::__has_index(), 'a failed build writes no index');
        } finally {
            static::__remove_tree();
        }
    }

    /**
     * The argument is parsed but cannot be evaluated when the scanner reads the attribute. The
     * build fails loudly, naming the constant, the file, the attribute and the declaration
     * carrying it.
     */
    public static function test_an_attribute_argument_that_cannot_be_evaluated_fails_the_build()
    {
        static::__make_tree();

        try {
            static::__write_class(
                'unevaluable_attribute_probe.php',
                'Unevaluable_Attribute_Probe',
                "    #[Probe_Marker(MANIFEST_MALFORMED_NO_SUCH_CONSTANT)]\n    public static function marked()\n    {\n    }"
            );

            [$exit, $output] = static::__build();

            static::__assert_not_equals(0, $exit, "an attribute naming an undefined constant fails the build:\n{$output}");
            $compact = static::__compact($output);
            static::__assert_contains('MANIFEST_MALFORMED_NO_SUCH_CONSTANT', $compact, 'the failure names the constant');
            static::__assert_contains('unevaluable_attribute_probe.php', $compact, 'the failure names the file');
            static::__assert_contains('#[Probe_Marker]', $compact, 'the failure names the attribute');
            static::__assert_contains('methodmarked()', $compact, 'the failure names the declaration');
            static::__assert_false(static::__has_index(), 'a failed build writes no index');
        } finally {
            static::__remove_tree();
        }
    }

    public static function test_fixing_the_file_lets_the_next_build_index_it()
    {
        static::__make_tree();

        try {
            $relative = static::__write_class('malformed_recovery_probe.php', 'Malformed_Recovery_Probe', '    public static function broken(');

            [$exit] = static::__build();
            static::__assert_not_equals(0, $exit, 'the broken file fails the build');

            static::__write_class('malformed_recovery_probe.php', 'Malformed_Recovery_Probe', "    public static function fixed()\n    {\n    }");

            [$exit, $output] = static::__build();
            static::__assert_equals(0, $exit, "the fixed file builds:\n{$output}");

            $index = include static::$build_root . '/manifest_index.php';

            static::__assert_true(isset($index['data']['php_classes']['Malformed_Recovery_Probe']), 'the fixed class is in the class map');
            static::__assert_equals($relative, $index['data']['php_classes']['Malformed_Recovery_Probe']['file'] ?? null);
        } finally {
            static::__remove_tree();
        }
    }
}
