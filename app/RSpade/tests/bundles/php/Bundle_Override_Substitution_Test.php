<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\PHP\Rsx_Namespace;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * A BUNDLE COMPILES THE OVERRIDE, NEVER THE FILE IT SHADOWS (BundleCompiler::_add_file();
 * rsx:man class_override) - AND EVERY FRAMEWORK STYLESHEET, SCRIPT AND TEMPLATE IT INCLUDES,
 * ALWAYS.
 *
 * Only PHP classes are overridable. A framework PHP file an application overrides stays on
 * disk under its own name, and a bundle walks the disk. Every include - a directory, a
 * type-filtered pattern, an explicit file - passes through one seam, which substitutes the
 * overriding file in the shadowed file's place, so the bundle carries the OVERRIDE's model
 * stub even though none of its includes names the application directory. A JS class, a
 * template and a stylesheet are never overrides and are compiled as they are found: an
 * application stylesheet wrapped in the class of a framework component is compiled IN
 * ADDITION to the framework's, wherever its own include puts it.
 *
 * The end-to-end half is a CHILD BUILD of a fixture tree into a scratch build root (the
 * child-build seam, Core/Manifest/CLAUDE.md), followed by a child compile of a fixture bundle
 * in that same build. Three framework sets each have a SPLIT MODEL the application overrides
 * (declaring an enum only the override has, so the stub says whose it is), a JS class and a
 * template of their own, and a stylesheet the application styles further with a stylesheet of
 * its own wrapped in the same class. The bundle reaches one set by a directory include, one by
 * patterns and one by explicit files, and names no application path except its own
 * stylesheet directory. The compiled output carries the provenance of every input (the
 * `=== path ===` banner of a script, `Compiled from:` of a template, `START:` of a
 * stylesheet), so it answers both what was compiled and in which order.
 *
 * THE FIXTURE TREES LIVE UNDER `resource/` DIRECTORIES, which no build descends into: they
 * are scanned only by the child that names them as extra roots.
 */
class Bundle_Override_Substitution_Test extends Rsx_Test_Abstract
{
    // The subject is the filesystem and child processes.
    protected static $use_database_transactions = false;

    private const BUNDLE = 'Bo_Probe_Bundle';

    /** The three include kinds, each reaching its own framework set. */
    private const SETS = ['dir', 'pat', 'exp'];

    /** The framework-side fixture root, relative to base_path(). */
    private static string $framework_root = '';

    /** The application-side fixture root, relative to base_path(). */
    private static string $application_root = '';

    /** The child's scratch build root. */
    private static string $build_root = '';

    // =====================================================================
    // The fixture
    // =====================================================================

    private static function __roots(): void
    {
        $tag = 'bo_' . getmypid();

        static::$framework_root = 'app/RSpade/temp/resource/' . $tag . '/framework';
        static::$application_root = 'rsx/resource/' . $tag . '/application';
        static::$build_root = Rsx_Project_Paths::tmp_path('bundle-override-substitution/' . getmypid() . '/build');
    }

    private static function __pascal(string $set): string
    {
        return ucfirst($set);
    }

    /** The framework directory of one set, relative to base_path(). */
    private static function __framework_set_root(string $set): string
    {
        return static::$framework_root . '/' . $set;
    }

    /**
     * The framework files of one set, path => content. Per set: a split model (an abstract
     * base carrying the table, and the empty shell the application overrides); a JS class and
     * a template nothing overrides; and three stylesheets whose paths sort first, styled further
     * by the application, last.
     *
     * @return array<string,string>
     */
    private static function __framework_files(string $set): array
    {
        $root = static::__framework_set_root($set);
        $p = 'Bo_' . static::__pascal($set);
        $marker = 'BO_FRAMEWORK_' . strtoupper($set);
        $namespace = Rsx_Namespace::from_path("{$root}/{$p}_Model.php");

        return [
            "{$root}/{$p}_Model_Abstract.php" => "<?php\n\nnamespace {$namespace};\n\n"
                . "use App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract;\n\n"
                . "abstract class {$p}_Model_Abstract extends Rsx_Model_Abstract\n{\n    protected \$table = 'bo_{$set}_items';\n}\n",
            "{$root}/{$p}_Model.php" => "<?php\n\nnamespace {$namespace};\n\nclass {$p}_Model extends {$p}_Model_Abstract\n{\n}\n",
            "{$root}/{$p}_Widget.js" => "class {$p}_Widget {\n    static marker() {\n        return '{$marker}_WIDGET';\n    }\n}\n",
            "{$root}/{$p}_Panel.jqhtml" => "<Define:{$p}_Panel>\n    <div class=\"{$p}_Panel__body\">{$marker}_PANEL</div>\n</Define:{$p}_Panel>\n",
            "{$root}/{$p}_Aa_First.scss" => ".{$p}_Aa_First {\n    display: block;\n}\n",
            "{$root}/{$p}_Panel.scss" => ".{$p}_Panel {\n    &__framework_marker {\n        display: block;\n    }\n}\n",
            "{$root}/{$p}_Zz_Last.scss" => ".{$p}_Zz_Last {\n    display: block;\n}\n",
        ];
    }

    /** The framework path of one set that the application overrides: the model shell. */
    private static function __shadowed_path(string $set): string
    {
        return static::__framework_set_root($set) . '/Bo_' . static::__pascal($set) . '_Model.php';
    }

    /**
     * The application files of one set, path => content - the model override (extending the
     * framework base, declaring an enum only it has), then a stylesheet wrapped in the
     * framework stylesheet's class, in a directory of its own. Named after the application's
     * own file convention, never after the framework's files.
     *
     * @return array<string,string>
     */
    private static function __application_files(string $set): array
    {
        $root = static::$application_root;
        $p = 'Bo_' . static::__pascal($set);
        $f = 'bo_' . $set;
        $upper = strtoupper($set);
        $model = "{$root}/models/{$f}_model.php";
        $namespace = Rsx_Namespace::from_path($model);
        $base_namespace = Rsx_Namespace::from_path(static::__shadowed_path($set));

        return [
            $model => "<?php\n\nnamespace {$namespace};\n\n"
                . "use {$base_namespace}\\{$p}_Model_Abstract;\n\n"
                . "class {$p}_Model extends {$p}_Model_Abstract\n{\n    public static \$enums = [\n"
                . "        'kind_id' => [\n            1 => ['constant' => 'KIND_BO_APPLICATION_{$upper}', 'label' => 'Override'],\n        ],\n    ];\n}\n",
            "{$root}/styles/{$f}_panel.scss" => ".{$p}_Panel {\n    &__application_marker {\n        display: block;\n    }\n}\n",
        ];
    }

    /**
     * The fixture bundle: the 'dir' set by a directory include, the 'pat' set by four
     * type-filtered patterns, the 'exp' set by explicit files in authored order - and then the
     * application's STYLESHEET directory only. No include names the application's models.
     */
    private static function __bundle_file(): array
    {
        $path = static::$application_root . '/bo_probe_bundle.php';
        $namespace = Rsx_Namespace::from_path($path);
        $dir = static::__framework_set_root('dir');
        $pat = static::__framework_set_root('pat');
        $exp = static::__framework_set_root('exp');
        $styles = static::$application_root . '/styles';

        $php = <<<PHP
        <?php

        namespace {$namespace};

        use App\\RSpade\\Core\\Bundle\\Rsx_Module_Bundle_Abstract;

        class Bo_Probe_Bundle extends Rsx_Module_Bundle_Abstract
        {
            public static function define(): array
            {
                return [
                    'include' => [
                        '{$dir}',
                        '{$pat}/*.php',
                        '{$pat}/*.js',
                        '{$pat}/*.jqhtml',
                        '{$pat}/*.scss',
                        '{$exp}/Bo_Exp_Model_Abstract.php',
                        '{$exp}/Bo_Exp_Model.php',
                        '{$exp}/Bo_Exp_Widget.js',
                        '{$exp}/Bo_Exp_Panel.jqhtml',
                        '{$exp}/Bo_Exp_Aa_First.scss',
                        '{$exp}/Bo_Exp_Panel.scss',
                        '{$exp}/Bo_Exp_Zz_Last.scss',
                        '{$styles}',
                    ],
                ];
            }
        }
        PHP;

        return [$path => $php . "\n"];
    }

    /** @param array<string,string> $files */
    private static function __write(array $files): void
    {
        foreach ($files as $path => $content) {
            ensure_directory(dirname(base_path($path)));
            file_put_contents(base_path($path), $content);
        }

        clearstatcache();
    }

    private static function __write_fixture(): void
    {
        foreach (static::SETS as $set) {
            static::__write(static::__framework_files($set));
            static::__write(static::__application_files($set));
        }

        static::__write(static::__bundle_file());
    }

    private static function __remove_everything(): void
    {
        foreach ([dirname(static::$framework_root), dirname(static::$application_root)] as $directory) {
            if (is_dir(base_path($directory))) {
                rmdir_recursive(base_path($directory));
            }
        }

        if (is_dir(dirname(static::$build_root))) {
            rmdir_recursive(dirname(static::$build_root));
        }
    }

    // =====================================================================
    // The child build and compile
    // =====================================================================

    /** The flags that put a child on the fixture build. */
    private static function __child_flags(): array
    {
        ensure_directory(static::$build_root);
        ensure_directory(static::$build_root . '-tmp');

        return [
            '--_rsx-build-root=' . static::$build_root,
            '--_rsx-tmp-root=' . static::$build_root . '-tmp',
            '--_manifest-extra-scan-roots=' . static::$framework_root . ',' . static::$application_root,
        ];
    }

    /** Run one artisan command on the fixture build; it must succeed. Returns its output. */
    private static function __child_ok(string $command, array $args, string $what): string
    {
        $output = [];
        $exit = Rsx_Artisan::run($command, array_merge($args, static::__child_flags()), $output);
        $output = implode("\n", $output);

        static::__assert_equals(0, $exit, "{$what} failed:\n{$output}");

        return $output;
    }

    /**
     * Every compiled file in the fixture build's bundle directory, name => content.
     *
     * @return array<string,string>
     */
    private static function __compiled_files(): array
    {
        $compiled = [];

        foreach (glob(static::$build_root . '/bundles/*.{js,css}', GLOB_BRACE) ?: [] as $file) {
            $compiled[basename($file)] = file_get_contents($file);
        }

        return $compiled;
    }

    /**
     * The source files a compiled file was made from, in output order, as manifest keys.
     *
     * @return array<int,string>
     */
    private static function __provenance(string $content): array
    {
        preg_match_all(
            '#/\* === (?<script>\S+?)(?: \(babel\))? === \*/|/\* Compiled from: (?<template>\S+?) \*/|/\* =+ START: (?<sheet>\S+?) =+ \*/#',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        $sources = [];

        foreach ($matches as $match) {
            $source = ($match['script'] ?? '') . ($match['template'] ?? '') . ($match['sheet'] ?? '');

            // The banner of a generated input names it relative to base_path() with `..`.
            if (str_starts_with($source, '../')) {
                continue;
            }

            $sources[] = $source;
        }

        return $sources;
    }

    /** Position of $path in $sources; the source must appear exactly once. */
    private static function __position_of(string $path, array $sources, string $what): int
    {
        $positions = array_keys($sources, $path, true);

        static::__assert_equals(1, count($positions), "{$what}: {$path} is compiled exactly once");

        return $positions[0];
    }

    // =====================================================================
    // The tests
    // =====================================================================

    /**
     * A directory include, a pattern include and an explicit include of a shadowed framework
     * PHP file each compile the application class that overrides it - its model stub is in the
     * bundle exactly once, and it is the override's (it carries the enum only the override
     * declares) - although no include names the application's model directory. No shadowed
     * path appears anywhere in anything the fixture build compiled.
     *
     * The framework JS class and template of each set are compiled as found: nothing
     * overrides them. The framework stylesheet is compiled in its own place whatever the
     * application declares, and the application stylesheet wrapped in the same class is
     * compiled too, after it - the order of the include list.
     */
    public static function test_every_include_kind_compiles_the_php_override_in_the_shadowed_files_place()
    {
        static::__roots();
        static::__remove_everything();

        try {
            static::__write_fixture();

            static::__child_ok('rsx:manifest:build', [], 'the fixture build');

            $overrides = (include static::$build_root . '/manifest_index.php')['data']['overrides'];

            foreach (static::SETS as $set) {
                $application = array_keys(static::__application_files($set));
                $framework_sheet = static::__framework_set_root($set) . '/Bo_' . static::__pascal($set) . '_Panel.scss';

                static::__assert_false(isset($overrides[$framework_sheet]), "{$framework_sheet} is not shadowed by the application stylesheet wrapped in its class");
                static::__assert_equals(
                    $application[0],
                    $overrides[static::__shadowed_path($set)]['by'] ?? null,
                    'the fixture build records ' . static::__shadowed_path($set) . " as shadowed by {$application[0]}"
                );
            }

            static::__assert_equals(count(static::SETS), count($overrides), 'the model shells are the only shadowed files');

            static::__child_ok('rsx:bundle:compile', [static::BUNDLE], 'compiling the fixture bundle');

            $compiled = static::__compiled_files();
            $app_js = $app_css = null;

            foreach ($compiled as $name => $content) {
                if (str_starts_with($name, static::BUNDLE . '__app.')) {
                    if (str_ends_with($name, '.js')) {
                        $app_js = $content;
                    } else {
                        $app_css = $content;
                    }
                }
            }

            static::__assert_not_null($app_js, 'the fixture bundle compiled an app script');
            static::__assert_not_null($app_css, 'and an app stylesheet');

            $js_sources = static::__provenance($app_js);
            $css_sources = static::__provenance($app_css);

            foreach (static::SETS as $set) {
                $framework = static::__framework_set_root($set);
                $p = 'Bo_' . static::__pascal($set);
                $upper = strtoupper($set);
                $sheet = array_keys(static::__application_files($set))[1];
                $what = "the '{$set}' set";

                // ---- the model: the override's stub, once ---------------------------------
                static::__assert_equals(1, substr_count($app_js, "class Base_{$p}_Model "), "{$what}: the model's stub is in the bundle once");
                static::__assert_contains("KIND_BO_APPLICATION_{$upper}", $app_js, "{$what}: the stub is the override's - it carries the enum only the override declares");

                // ---- the script and the template: the framework's, as found ----------------
                static::__position_of("{$framework}/{$p}_Widget.js", $js_sources, $what);
                static::__position_of("{$framework}/{$p}_Panel.jqhtml", $js_sources, $what);
                static::__assert_contains("BO_FRAMEWORK_{$upper}_WIDGET", $app_js, "{$what}: the framework JS class is compiled");
                static::__assert_contains("BO_FRAMEWORK_{$upper}_PANEL", $app_js, "{$what}: and so is the framework template");

                // ---- the stylesheets: the framework's in its place, the application's after it
                $at = static::__position_of("{$framework}/{$p}_Panel.scss", $css_sources, $what);
                static::__assert_greater_than(static::__position_of("{$framework}/{$p}_Aa_First.scss", $css_sources, $what), $at, "{$what}: the framework stylesheet follows the one before it");
                static::__assert_less_than(static::__position_of("{$framework}/{$p}_Zz_Last.scss", $css_sources, $what), $at, "{$what}: and precedes the one after it");
                static::__assert_greater_than($at, static::__position_of($sheet, $css_sources, $what), "{$what}: the application stylesheet wrapped in the same class is compiled too, after the framework's");
                static::__assert_contains(".{$p}_Panel__framework_marker", $app_css, "{$what}: the framework stylesheet's rules are in the bundle");
                static::__assert_contains(".{$p}_Panel__application_marker", $app_css, "{$what}: and so are the application stylesheet's");
            }

            // ---- COMPLETENESS: nothing the fixture build compiled came from a shadowed file
            static::__assert_not_empty($compiled, 'the fixture build compiled bundles');

            foreach ($compiled as $name => $content) {
                $shadowed = array_values(array_intersect(static::__provenance($content), array_keys($overrides)));
                static::__assert_equals([], $shadowed, "{$name} was compiled from no shadowed file");
            }
        } finally {
            static::__remove_everything();
        }
    }

    /**
     * AN OVERRIDDEN MODEL REACHES A BUNDLE WHOLE: PHP STUB AND JS CLASS TOGETHER.
     *
     * A split framework model (`Bo_Gadget_Model_Abstract` + shell `Bo_Gadget_Model`), included
     * by a framework-side bundle that names only the framework model directory - the shape of
     * Core_Bundle, and of a /_sys bundle that may name no application path. The application
     * overrides the model (`class Bo_Gadget_Model extends Bo_Gadget_Model_Abstract`, declaring
     * an enum the stub carries) and gives it a client-side class
     * (`class Bo_Gadget_Model extends Base_Bo_Gadget_Model`). The bundle compiles - the model
     * check would refuse it if the substitution brought the PHP file alone - and carries the
     * override's stub and the application's JS class exactly once, the JS class after the stub
     * it extends, and no generated stand-in for it. A second bundle that also includes the
     * application's model directory gets the same, each once.
     */
    public static function test_an_overridden_model_brings_its_js_class_into_a_bundle_naming_no_application_path()
    {
        static::__roots();
        static::__remove_everything();

        $framework_models = static::$framework_root . '/model';
        $application_models = static::$application_root . '/models';
        $application_js = "{$application_models}/bo_gadget_model.js";
        $bundles = [
            'Bo_Framework_Model_Bundle' => static::$framework_root . '/Bo_Framework_Model_Bundle.php',
            'Bo_Application_Model_Bundle' => static::$application_root . '/bo_application_model_bundle.php',
        ];

        $base_namespace = Rsx_Namespace::from_path("{$framework_models}/Bo_Gadget_Model_Abstract.php");
        $override_namespace = Rsx_Namespace::from_path("{$application_models}/bo_gadget_model.php");
        $bundle_php = static function (string $path, string $class, array $includes): string {
            $namespace = Rsx_Namespace::from_path($path);
            $lines = implode('', array_map(static fn ($include) => "                    '{$include}',\n", $includes));

            return "<?php\n\nnamespace {$namespace};\n\nuse App\\RSpade\\Core\\Bundle\\Rsx_Module_Bundle_Abstract;\n\n"
                . "class {$class} extends Rsx_Module_Bundle_Abstract\n{\n    public static function define(): array\n    {\n"
                . "        return [\n            'include' => [\n{$lines}            ],\n        ];\n    }\n}\n";
        };

        try {
            static::__write([
                "{$framework_models}/Bo_Gadget_Model_Abstract.php" => "<?php\n\nnamespace {$base_namespace};\n\n"
                    . "use App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract;\n\n"
                    . "abstract class Bo_Gadget_Model_Abstract extends Rsx_Model_Abstract\n{\n    protected \$table = 'bo_gadgets';\n}\n",
                "{$framework_models}/Bo_Gadget_Model.php" => "<?php\n\nnamespace {$base_namespace};\n\n"
                    . "class Bo_Gadget_Model extends Bo_Gadget_Model_Abstract\n{\n}\n",
                "{$application_models}/bo_gadget_model.php" => "<?php\n\nnamespace {$override_namespace};\n\n"
                    . "use {$base_namespace}\\Bo_Gadget_Model_Abstract;\n\n"
                    . "class Bo_Gadget_Model extends Bo_Gadget_Model_Abstract\n{\n    public static \$enums = [\n"
                    . "        'kind_id' => [\n            1 => ['constant' => 'KIND_BO_APPLICATION_OVERRIDE', 'label' => 'Override'],\n        ],\n    ];\n}\n",
                $application_js => "class Bo_Gadget_Model extends Base_Bo_Gadget_Model {\n    static marker() {\n        return 'BO_APPLICATION_GADGET_JS';\n    }\n}\n",
                $bundles['Bo_Framework_Model_Bundle'] => $bundle_php($bundles['Bo_Framework_Model_Bundle'], 'Bo_Framework_Model_Bundle', [$framework_models]),
                $bundles['Bo_Application_Model_Bundle'] => $bundle_php($bundles['Bo_Application_Model_Bundle'], 'Bo_Application_Model_Bundle', [$framework_models, $application_models]),
            ]);

            static::__child_ok('rsx:manifest:build', [], 'the fixture build');

            $overrides = (include static::$build_root . '/manifest_index.php')['data']['overrides'];
            static::__assert_equals(
                "{$application_models}/bo_gadget_model.php",
                $overrides["{$framework_models}/Bo_Gadget_Model.php"]['by'] ?? null,
                'the fixture build records the framework model shell as shadowed by the application model'
            );

            foreach (array_keys($bundles) as $bundle) {
                static::__child_ok('rsx:bundle:compile', [$bundle], "compiling {$bundle}");

                $app_js = null;
                foreach (static::__compiled_files() as $name => $content) {
                    if (str_starts_with($name, $bundle . '__app.') && str_ends_with($name, '.js')) {
                        $app_js = $content;
                    }
                }

                static::__assert_not_null($app_js, "{$bundle} compiled an app script");

                static::__position_of($application_js, static::__provenance($app_js), $bundle);
                static::__assert_equals(1, substr_count($app_js, 'BO_APPLICATION_GADGET_JS'), "{$bundle}: the application's JS class is in the bundle once");
                static::__assert_equals(1, substr_count($app_js, 'class Base_Bo_Gadget_Model '), "{$bundle}: the model's stub is in the bundle once");
                static::__assert_contains('KIND_BO_APPLICATION_OVERRIDE', $app_js, "{$bundle}: the stub is the override's - it carries the enum only the override declares");
                static::__assert_false(
                    str_contains($app_js, 'class Bo_Gadget_Model extends Base_Bo_Gadget_Model {}'),
                    "{$bundle}: no generated class stands in for the application's JS class"
                );
                static::__assert_less_than(
                    strpos($app_js, 'BO_APPLICATION_GADGET_JS'),
                    strpos($app_js, 'class Base_Bo_Gadget_Model '),
                    "{$bundle}: the stub is compiled before the JS class that extends it"
                );
                static::__assert_false(
                    in_array("{$framework_models}/Bo_Gadget_Model.php", static::__provenance($app_js), true),
                    "{$bundle}: the shadowed framework shell contributes nothing"
                );
            }
        } finally {
            static::__remove_everything();
        }
    }

    /**
     * Every module bundle of the build this process runs on (outside the test trees) resolves
     * to a file list with no shadowed path in it. (A tree with no overrides passes trivially; an installed application
     * that overrides framework classes is what this audits.)
     */
    public static function test_no_bundle_of_this_build_resolves_a_shadowed_file()
    {
        $overrides = Manifest::overrides();
        $bundles = 0;

        foreach (Manifest::php_class_records_extending('Rsx_Module_Bundle_Abstract') as $class => $record) {
            // The test trees' bundles are fixtures of other tests, not bundles a page serves.
            if (Rsx_Paths::is_test_tree($record['file'])) {
                continue;
            }

            $bundles++;
            $files = (new BundleCompiler())->resolve_for_shake($record['fqcn'])['files'];
            $shadowed = array_values(array_filter($files, static fn ($file) => isset($overrides[Rsx_Paths::relative($file)])));

            static::__assert_equals([], $shadowed, "{$class} resolves no shadowed file");

            foreach ($overrides as $framework_path => $override) {
                static::__assert_false(in_array(rsxrealpath(base_path($framework_path)), $files, true), "{$class} does not include {$framework_path}");
            }
        }

        static::__assert_greater_than(0, $bundles, 'the build declares module bundles');
    }
}
