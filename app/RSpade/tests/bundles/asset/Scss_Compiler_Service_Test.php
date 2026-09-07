<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Bundles\Asset;

use RuntimeException;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Integrations\Scss\Scss_Compiler;

/**
 * The SCSS compiler is an RPC service, and it is the framework's only sass invocation.
 *
 * It used to generate a `compile.js` into a temp directory on every call, shell out to
 * `bash -c 'cd <base> && node <script>'`, recover the exit code by parsing it off the last
 * line of captured output, and delete the script again - the last pre-RPC call shape in the
 * framework. These tests pin the behavior that had to survive that move: expanded output
 * with an embedded sourcemap in development, compressed-and-postcss'd output in production,
 * `@import` resolution through the master-file mechanism the bundler relies on, and a
 * failure that arrives as an exception naming the file rather than as a parsed exit code.
 *
 * Fixtures live in a scratch directory under this concern's framework-ignored `resource/`
 * directory and are removed in teardown(); the live `rsx/` tree is never touched.
 */
class Scss_Compiler_Service_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    protected const FIXTURE_RELATIVE = 'app/RSpade/tests/bundles/asset/resource/scss_fixture-temp';

    protected static function __fixture_root(): string
    {
        return base_path(static::FIXTURE_RELATIVE);
    }

    protected static function __write_fixture(string $relative_path, string $contents): string
    {
        $absolute = static::__fixture_root() . '/' . $relative_path;

        ensure_directory(dirname($absolute));
        file_put_contents_safe($absolute, $contents);

        return $absolute;
    }

    protected static function __output_path(string $name): string
    {
        return static::__fixture_root() . '/' . $name;
    }

    public static function setup()
    {
        ensure_directory(static::__fixture_root());
    }

    public static function teardown()
    {
        $root = static::__fixture_root();

        if (is_dir($root)) {
            exec_safe('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * SCSS-RPC-01 - Development shape: nesting and variables resolve, the output is
     * expanded, and a decodable sourcemap is embedded.
     */
    public static function test_development_compile_expands_and_embeds_a_sourcemap()
    {
        $input = static::__write_fixture(
            'dev.scss',
            "\$scss_fixture_color: #ff0000;\n.scss_fixture { color: \$scss_fixture_color; .nested { display: block; } }\n"
        );
        $output = static::__output_path('dev.css');

        $result = Scss_Compiler::compile($input, $output, false, true);

        static::__assert_true($result['bytes'] > 0, 'the compiler reported an empty stylesheet');
        static::__assert_true(file_exists($output), 'no stylesheet was written');

        $css = file_get_contents($output);

        // The variable resolved and the nesting flattened.
        static::__assert_contains('#ff0000', $css);
        static::__assert_contains('.scss_fixture .nested', $css);

        // Expanded, not compressed: real newlines between rules.
        static::__assert_true(substr_count($css, "\n") > 3, 'the development output is not expanded');

        // The sourcemap is real and decodes.
        static::__assert_true(
            preg_match(
                '~/\*# sourceMappingURL=data:application/json;charset=utf-8;base64,([A-Za-z0-9+/=]+) \*/~',
                $css,
                $matches
            ) === 1,
            'the stylesheet carries no embedded sourcemap'
        );

        $map = json_decode(base64_decode($matches[1]), true);

        static::__assert_equals(3, $map['version'], 'the sourcemap is not a version 3 map');
        static::__assert_true(count($map['sources']) > 0, 'the sourcemap names no sources');

        // Paths are project-relative: no file:// protocol survives.
        static::__assert_true(
            strpos(implode(' ', $map['sources']), 'file://') === false,
            'a file:// protocol survived into the sourcemap sources'
        );
    }

    /**
     * SCSS-RPC-02 - Production shape: compressed output, and the postcss pass (autoprefixer
     * + cssnano) actually ran.
     */
    public static function test_production_compile_is_compressed_and_optimized()
    {
        $input = static::__write_fixture(
            'prod.scss',
            "/* a comment cssnano removes */\n.scss_fixture_prod { color: #ff0000; }\n.scss_fixture_prod_two { color: #ff0000; }\n"
        );
        $output = static::__output_path('prod.css');

        Scss_Compiler::compile($input, $output, true, false);

        $css = file_get_contents($output);

        static::__assert_contains('.scss_fixture_prod', $css);

        // cssnano ran: the comment is gone and the color is shortened.
        static::__assert_true(
            strpos($css, 'a comment cssnano removes') === false,
            'the postcss pass did not run - a comment survived a production build'
        );
        static::__assert_contains('red', $css);

        // Compressed: essentially one line.
        static::__assert_true(substr_count(trim($css), "\n") <= 1, 'the production output is not compressed');
    }

    /**
     * SCSS-RPC-03 - `@import` resolves, which is the mechanism the bundler's master file is
     * built on: the whole bundle is one entry file importing every stylesheet by absolute
     * path.
     */
    public static function test_imports_resolve_the_way_the_bundle_master_file_needs()
    {
        $partial = static::__write_fixture('partial.scss', ".scss_fixture_partial { color: blue; }\n");
        $entry = static::__write_fixture('entry.scss', '@import ' . json_encode($partial) . ";\n");
        $output = static::__output_path('entry.css');

        Scss_Compiler::compile($entry, $output, false, false);

        static::__assert_contains('.scss_fixture_partial', file_get_contents($output));
    }

    /**
     * SCSS-RPC-04 - Invalid SCSS fails loudly, names the input file, and carries sass's own
     * message. It must not leave a half-written stylesheet behind: the compiler writes the
     * CSS BEFORE it optimizes it, so presence of the file proves nothing.
     */
    public static function test_invalid_scss_fails_loudly_and_leaves_no_artifact()
    {
        $input = static::__write_fixture('broken.scss', ".scss_fixture_broken { color: \$never_defined; }\n");
        $output = static::__output_path('broken.css');

        $threw = false;
        $message = '';

        try {
            Scss_Compiler::compile($input, $output, false, false);
        } catch (RuntimeException $e) {
            $threw = true;
            $message = $e->getMessage();
        }

        static::__assert_true($threw, 'invalid SCSS compiled without complaint');
        static::__assert_contains('SCSS compilation failed', $message);
        static::__assert_contains('broken.scss', $message);
        static::__assert_true(!file_exists($output), 'a failed compile left an artifact behind');
    }

    /**
     * SCSS-RPC-05 - The node service answers its own health check after a compile - i.e.
     * the SCSS compile spawned it on demand and left it serving.
     */
    public static function test_the_daemon_is_reachable_after_a_compile()
    {
        $input = static::__write_fixture('ping.scss', ".scss_fixture_ping { color: green; }\n");

        Scss_Compiler::compile($input, static::__output_path('ping.css'), false, false);

        static::__assert_true(Rsx_Node_Service::ping(), 'the node service did not answer a ping');
    }

    /**
     * SCSS-RPC-06 - The generated-script idiom is gone: no `compile.js` is written beside
     * the input, and nothing in the processor shells out any more.
     */
    public static function test_no_compile_script_is_written_beside_the_input()
    {
        $input = static::__write_fixture('noscript.scss', ".scss_fixture_noscript { color: teal; }\n");

        Scss_Compiler::compile($input, static::__output_path('noscript.css'), false, false);

        static::__assert_true(
            !file_exists(dirname($input) . '/compile.js'),
            'a generated compile.js was left beside the input file'
        );

        $processor_source = file_get_contents(base_path('app/RSpade/Integrations/Scss/Scss_BundleProcessor.php'));

        static::__assert_true(
            strpos($processor_source, 'shell_exec') === false,
            'the SCSS processor still shells out'
        );
    }
}
