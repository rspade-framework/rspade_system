<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Bundles\Asset;

use RuntimeException;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Bundle_Include_Pattern;
use App\RSpade\Core\Bundle\Rsx_Asset_Bundle_Abstract;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * The type-filtered directory include: one directory, one file type, in two forms.
 *
 * The fixture is a scratch directory under this concern's `resource/` directory
 * (framework-ignored, so the manifest never indexes it) holding stylesheets at three depths,
 * one file of another type, and a subdirectory whose NAME a directory include skips:
 *
 *     a_first-temp.scss
 *     b_second-temp.scss
 *     notes-temp.txt
 *     sub/c_nested-temp.scss
 *     sub/deeper/d_deep-temp.scss
 *     sub/node_modules/e_skipped-temp.scss
 *     only_nested/sub/f_only-temp.scss
 *
 * Every stylesheet declares one rule named after itself, so "is this file in the bundle" is
 * a question the compiled CSS answers. The live `rsx/` tree is never touched and the scratch
 * directory is removed in teardown().
 */
class Bundle_Include_Pattern_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    protected const FIXTURE_RELATIVE = 'app/RSpade/tests/bundles/asset/resource/pattern_fixture-temp';

    protected const FIXTURE_NAMESPACE = 'Rsx_Pattern_Fixture_Temp';

    /**
     * The fixture stylesheets, fixture-relative path => the rule name each declares.
     */
    protected static function __fixture_stylesheets(): array
    {
        return [
            'a_first-temp.scss' => 'rsx_pattern_fixture_a_first',
            'b_second-temp.scss' => 'rsx_pattern_fixture_b_second',
            'sub/c_nested-temp.scss' => 'rsx_pattern_fixture_c_nested',
            'sub/deeper/d_deep-temp.scss' => 'rsx_pattern_fixture_d_deep',
            'sub/node_modules/e_skipped-temp.scss' => 'rsx_pattern_fixture_e_skipped',
            'only_nested/sub/f_only-temp.scss' => 'rsx_pattern_fixture_f_only',
        ];
    }

    protected static function __fixture_path(string $relative): string
    {
        return base_path(static::FIXTURE_RELATIVE . '/' . $relative);
    }

    /**
     * Write the fixture in its original state (idempotent - every test starts here).
     */
    protected static function __write_fixture(): void
    {
        static::__remove_fixture_files();

        foreach (static::__fixture_stylesheets() as $relative => $rule) {
            $path = static::__fixture_path($relative);
            ensure_directory(dirname($path));
            file_put_contents($path, ".{$rule} {\n    display: block;\n}\n");
        }

        file_put_contents(static::__fixture_path('notes-temp.txt'), "not a stylesheet\n");

        static::__write_fixture_bundles();
    }

    /**
     * Write and load the fixture bundle definitions (never indexed by the manifest; the
     * compiler resolves them by class_exists()).
     */
    protected static function __write_fixture_bundles(): void
    {
        $base = static::FIXTURE_RELATIVE;
        $namespace = static::FIXTURE_NAMESPACE;

        $php = <<<PHP
        <?php

        namespace {$namespace};

        use App\\RSpade\\Core\\Bundle\\Rsx_Asset_Bundle_Abstract;
        use App\\RSpade\\Core\\Bundle\\Rsx_Module_Bundle_Abstract;

        class Pattern_Fixture_Shallow_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                return ['include' => ['{$base}/*.scss']];
            }
        }

        class Pattern_Fixture_Recursive_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                return ['include' => ['{$base}/**/*.scss']];
            }
        }

        class Pattern_Fixture_Ordered_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                return [
                    'include' => [
                        // Named first, so it stays first: the pattern below matches it too.
                        '{$base}/b_second-temp.scss',
                        '{$base}/sub/*.scss',
                        '{$base}/*.scss',
                    ],
                ];
            }
        }

        class Pattern_Fixture_No_Match_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                // only_nested/ holds a stylesheet one directory down and none of its own.
                return ['include' => ['{$base}/only_nested/*.scss']];
            }
        }

        class Pattern_Fixture_Malformed_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                return ['include' => ['{$base}/*']];
            }
        }

        class Pattern_Fixture_Scanning_Asset_Bundle extends Rsx_Asset_Bundle_Abstract
        {
            public static function define(): array
            {
                return ['include' => ['{$base}/*.scss']];
            }
        }
        PHP;

        $path = static::__fixture_path('bundles-temp.php');
        ensure_directory(dirname($path));
        file_put_contents($path, $php . "\n");

        // Idempotent per resolved path: every test rewrites these byte-identically.
        require_once $path;
    }

    protected static function __bundle(string $name): string
    {
        return '\\' . static::FIXTURE_NAMESPACE . '\\' . $name;
    }

    /**
     * The fixture stylesheets a bundle resolves to, fixture-relative, in bundle order.
     */
    protected static function __resolved_fixture_files(string $bundle_name): array
    {
        $resolved = (new BundleCompiler())->resolve_for_shake(static::__bundle($bundle_name));
        $root = rsxrealpath(base_path(static::FIXTURE_RELATIVE)) . '/';
        $files = [];

        foreach ($resolved['files'] as $file) {
            if (str_starts_with($file, $root)) {
                $files[] = substr($file, strlen($root));
            }
        }

        return $files;
    }

    /**
     * Compile a fixture bundle and return [app css filename, its contents].
     */
    protected static function __compile_css(string $bundle_name): array
    {
        $result = (new BundleCompiler())->compile(static::__bundle($bundle_name), []);
        $filename = $result['app_css_bundle_path'] ?? null;

        static::__assert_not_null($filename, "{$bundle_name} produces an app CSS artifact");

        return [$filename, file_get_contents(Rsx_Project_Paths::bundles_dir() . '/' . $filename)];
    }

    protected static function __remove_fixture_files(): void
    {
        $root = base_path(static::FIXTURE_RELATIVE);

        if (!is_dir($root)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($root);
    }

    protected static function __remove_fixture(): void
    {
        static::__remove_fixture_files();

        foreach (['/Pattern_Fixture_*', '/npm_Pattern_Fixture_*'] as $pattern) {
            foreach (glob(Rsx_Project_Paths::bundles_dir() . $pattern) as $artifact) {
                if (is_file($artifact)) {
                    unlink($artifact);
                }
            }
        }
    }

    public static function setup()
    {
        parent::setup();

        static::__remove_fixture();
        static::__write_fixture();
    }

    public static function teardown()
    {
        parent::teardown();

        static::__remove_fixture();
    }

    /**
     * '<dir>/*.<ext>' is the directory's own files of that type: nothing from a
     * subdirectory, nothing of another type.
     */
    public static function test_shallow_form_takes_only_the_directorys_own_files()
    {
        static::__write_fixture();

        static::__assert_equals(
            ['a_first-temp.scss', 'b_second-temp.scss'],
            static::__resolved_fixture_files('Pattern_Fixture_Shallow_Bundle'),
            "'*.scss' resolves to the directory's own stylesheets, sorted, and to no subdirectory's"
        );

        [, $css] = static::__compile_css('Pattern_Fixture_Shallow_Bundle');

        static::__assert_contains('rsx_pattern_fixture_a_first', $css, 'a matched stylesheet is compiled');
        static::__assert_contains('rsx_pattern_fixture_b_second', $css, 'a matched stylesheet is compiled');
        static::__assert_false(
            str_contains($css, 'rsx_pattern_fixture_c_nested'),
            "'*.scss' over a directory does NOT take a subdirectory's stylesheet"
        );
        static::__assert_false(
            str_contains($css, 'rsx_pattern_fixture_d_deep'),
            "'*.scss' over a directory does NOT take a stylesheet two directories down"
        );
    }

    /**
     * '<dir>/**' + '/*.<ext>' is the directory's files of that type at every depth, sorted
     * by path, skipping the directory names a directory include skips.
     */
    public static function test_recursive_form_takes_every_depth_in_path_order()
    {
        static::__write_fixture();

        static::__assert_equals(
            [
                'a_first-temp.scss',
                'b_second-temp.scss',
                'only_nested/sub/f_only-temp.scss',
                'sub/c_nested-temp.scss',
                'sub/deeper/d_deep-temp.scss',
            ],
            static::__resolved_fixture_files('Pattern_Fixture_Recursive_Bundle'),
            'the recursive form resolves every depth, sorted by path, and passes over an excluded directory name'
        );

        [, $css] = static::__compile_css('Pattern_Fixture_Recursive_Bundle');

        static::__assert_contains('rsx_pattern_fixture_d_deep', $css, 'a stylesheet two directories down is compiled');
        static::__assert_false(
            str_contains($css, 'rsx_pattern_fixture_e_skipped'),
            'a directory named node_modules is not descended into, exactly as a directory include would not'
        );
    }

    /**
     * A pattern expands at its own position in the include list, and a file an earlier
     * entry already named keeps its earlier position.
     */
    public static function test_include_order_is_preserved_around_a_pattern()
    {
        static::__write_fixture();

        static::__assert_equals(
            ['b_second-temp.scss', 'sub/c_nested-temp.scss', 'a_first-temp.scss'],
            static::__resolved_fixture_files('Pattern_Fixture_Ordered_Bundle'),
            'entries resolve in authored order: the named file, then the first pattern, then what the second adds'
        );
    }

    /**
     * A pattern matching no file fails the build naming the pattern and the bundle - both
     * when the directory has no file of that type and when the directory does not exist.
     */
    public static function test_pattern_matching_nothing_fails_the_build()
    {
        static::__write_fixture();

        $exception = static::__assert_throws(
            RuntimeException::class,
            function () {
                (new BundleCompiler())->compile(static::__bundle('Pattern_Fixture_No_Match_Bundle'), []);
            },
            'matches no files'
        );

        static::__assert_contains(
            static::FIXTURE_RELATIVE . '/only_nested/*.scss',
            $exception->getMessage(),
            'the failure names the pattern'
        );
        static::__assert_contains(
            'Pattern_Fixture_No_Match_Bundle',
            $exception->getMessage(),
            'the failure names the bundle that declared it'
        );

        static::__assert_throws(
            RuntimeException::class,
            function () {
                Bundle_Include_Pattern::expand(static::FIXTURE_RELATIVE . '/no_such_directory-temp/*.scss');
            },
            'does not exist'
        );
    }

    /**
     * Any use of '*' outside the two forms is refused, naming the accepted forms.
     */
    public static function test_malformed_pattern_fails_the_build_naming_the_forms()
    {
        static::__write_fixture();

        $exception = static::__assert_throws(
            RuntimeException::class,
            function () {
                (new BundleCompiler())->compile(static::__bundle('Pattern_Fixture_Malformed_Bundle'), []);
            },
            'not one of the accepted forms'
        );

        static::__assert_contains("'<directory>/*.<ext>'", $exception->getMessage(), 'the refusal prints the shallow form');
        static::__assert_contains("'<directory>/**/*.<ext>'", $exception->getMessage(), 'the refusal prints the recursive form');

        $base = static::FIXTURE_RELATIVE;
        $malformed = [
            "{$base}/*",                 // no extension
            "{$base}/**",                // no file part
            "{$base}/**/*",              // no extension
            "{$base}/*.{scss,css}",      // a brace set
            "{$base}/*/a_first-temp.scss", // a wildcard directory
            "{$base}/a_*.scss",          // a partial file name
            "{$base}/**/**/*.scss",      // recursion twice
            "{$base}/*.scss.map",        // two extensions
            "{$base}/[ab]*.scss",        // a character class
            '*.scss',                    // no directory
        ];

        foreach ($malformed as $pattern) {
            static::__assert_throws(
                RuntimeException::class,
                function () use ($pattern) {
                    Bundle_Include_Pattern::parse($pattern);
                },
                'not one of the accepted forms'
            );
        }

        static::__assert_equals(
            ['directory' => 'rsx/theme', 'recursive' => false, 'extension' => 'scss'],
            Bundle_Include_Pattern::parse('rsx/theme/*.scss'),
            'the shallow form parses'
        );
        static::__assert_equals(
            ['directory' => 'rsx/theme', 'recursive' => true, 'extension' => 'scss'],
            Bundle_Include_Pattern::parse('rsx/theme/**/*.SCSS'),
            'the recursive form parses, and the extension is compared lowercased'
        );
    }

    /**
     * A file ADDED to a matched directory reaches the next compile with nothing else done:
     * the pattern is expanded on every resolve, exactly as a directory include is scanned.
     */
    public static function test_a_new_matching_file_is_picked_up_by_the_next_compile()
    {
        static::__write_fixture();

        [$before_filename, $before_css] = static::__compile_css('Pattern_Fixture_Shallow_Bundle');

        static::__assert_false(
            str_contains($before_css, 'rsx_pattern_fixture_added'),
            'the added rule is not in the bundle before its file exists'
        );

        file_put_contents(
            static::__fixture_path('c_added-temp.scss'),
            ".rsx_pattern_fixture_added {\n    display: block;\n}\n"
        );
        clearstatcache();

        [$after_filename, $after_css] = static::__compile_css('Pattern_Fixture_Shallow_Bundle');

        static::__assert_not_equals(
            $before_filename,
            $after_filename,
            'a new file matching the pattern changes the artifact (its content hash, and so its filename)'
        );
        static::__assert_contains(
            'rsx_pattern_fixture_added',
            $after_css,
            'the new file is compiled into the bundle by the next compile'
        );

        // And a new file of ANOTHER type, or one directory down, changes nothing.
        file_put_contents(static::__fixture_path('more_notes-temp.txt'), "still not a stylesheet\n");
        file_put_contents(
            static::__fixture_path('sub/g_new_nested-temp.scss'),
            ".rsx_pattern_fixture_new_nested {\n    display: block;\n}\n"
        );
        clearstatcache();

        [$unchanged_filename] = static::__compile_css('Pattern_Fixture_Shallow_Bundle');

        static::__assert_equals(
            $after_filename,
            $unchanged_filename,
            'a new file the pattern does not match leaves the artifact as it was'
        );
    }

    /**
     * An asset bundle found by a scan may not scan a directory itself, and a pattern is a
     * directory scan.
     */
    public static function test_scanned_asset_bundle_may_not_declare_a_pattern()
    {
        static::__write_fixture();

        static::__assert_throws(
            RuntimeException::class,
            function () {
                Rsx_Asset_Bundle_Abstract::validate_no_directory_scanning(
                    static::__bundle('Pattern_Fixture_Scanning_Asset_Bundle'),
                    static::__bundle('Pattern_Fixture_Shallow_Bundle')
                );
            },
            'cannot have directory paths or patterns'
        );
    }
}
