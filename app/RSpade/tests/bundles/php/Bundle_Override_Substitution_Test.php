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
 * rsx:man class_override).
 *
 * A framework file an application overrides stays on disk under its own name, and a bundle
 * walks the disk. Every include - a directory, a type-filtered pattern, an explicit file -
 * passes through one seam, which substitutes the overriding file in the shadowed file's place.
 *
 * The end-to-end half is a CHILD BUILD of a fixture tree into a scratch build root (the
 * child-build seam, Core/Manifest/CLAUDE.md), followed by a child compile of a fixture bundle
 * in that same build. Three framework sets are each overridden by the application - a JS class,
 * a jqhtml template and a stylesheet per set - and the bundle reaches one set by a directory
 * include, one by patterns and one by explicit files, then includes the application directory
 * as well. The compiled output carries the provenance of every input (the `=== path ===`
 * banner of a script, `Compiled from:` of a template, `START:` of a stylesheet), so it answers
 * both what was compiled and in which order.
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
     * The framework files of one set, path => content. Per set: a base class, the class the
     * application overrides (extending the base) and a class extending THAT one, named so a
     * path sort puts it first; the template the application overrides; and three stylesheets
     * whose paths sort first, overridden, last.
     *
     * @return array<string,string>
     */
    private static function __framework_files(string $set): array
    {
        $root = static::__framework_set_root($set);
        $p = 'Bo_' . static::__pascal($set);
        $marker = 'BO_FRAMEWORK_' . strtoupper($set);

        return [
            "{$root}/{$p}_Achild.js" => "class {$p}_Achild extends {$p}_Widget {\n    static child_marker() {\n        return '{$marker}_ACHILD';\n    }\n}\n",
            "{$root}/{$p}_Base.js" => "class {$p}_Base {\n    static base_marker() {\n        return '{$marker}_BASE';\n    }\n}\n",
            "{$root}/{$p}_Widget.js" => "class {$p}_Widget extends {$p}_Base {\n    static marker() {\n        return '{$marker}_WIDGET';\n    }\n}\n",
            "{$root}/{$p}_Panel.jqhtml" => "<Define:{$p}_Panel>\n    <div class=\"{$p}_Panel__body\">{$marker}_PANEL</div>\n</Define:{$p}_Panel>\n",
            "{$root}/{$p}_Aa_First.scss" => ".{$p}_Aa_First {\n    display: block;\n}\n",
            "{$root}/{$p}_Panel.scss" => ".{$p}_Panel {\n    &__framework_marker {\n        display: block;\n    }\n}\n",
            "{$root}/{$p}_Zz_Last.scss" => ".{$p}_Zz_Last {\n    display: block;\n}\n",
        ];
    }

    /** The three framework paths of one set that the application overrides. */
    private static function __shadowed_paths(string $set): array
    {
        $root = static::__framework_set_root($set);
        $p = 'Bo_' . static::__pascal($set);

        return ["{$root}/{$p}_Widget.js", "{$root}/{$p}_Panel.jqhtml", "{$root}/{$p}_Panel.scss"];
    }

    /**
     * The application files overriding one set, path => content - named after the
     * application's own file convention, never after the framework's files.
     *
     * @return array<string,string>
     */
    private static function __application_files(string $set): array
    {
        $root = static::$application_root;
        $p = 'Bo_' . static::__pascal($set);
        $f = 'bo_' . $set;
        $marker = 'BO_APPLICATION_' . strtoupper($set);

        return [
            "{$root}/{$f}_widget.js" => "class {$p}_Widget extends {$p}_Base {\n    static marker() {\n        return '{$marker}_WIDGET';\n    }\n}\n",
            "{$root}/{$f}_panel.jqhtml" => "<Define:{$p}_Panel>\n    <div class=\"{$p}_Panel__body\">{$marker}_PANEL</div>\n</Define:{$p}_Panel>\n",
            "{$root}/{$f}_panel.scss" => ".{$p}_Panel {\n    &__application_marker {\n        display: block;\n    }\n}\n",
        ];
    }

    /**
     * The fixture bundle: the 'dir' set by a directory include, the 'pat' set by three
     * type-filtered patterns, the 'exp' set by explicit files in authored order - and then the
     * application directory, which holds every override a second time.
     */
    private static function __bundle_file(): array
    {
        $path = static::$application_root . '/bo_probe_bundle.php';
        $namespace = Rsx_Namespace::from_path($path);
        $dir = static::__framework_set_root('dir');
        $pat = static::__framework_set_root('pat');
        $exp = static::__framework_set_root('exp');

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
                        '{$pat}/*.js',
                        '{$pat}/*.jqhtml',
                        '{$pat}/*.scss',
                        '{$exp}/Bo_Exp_Base.js',
                        '{$exp}/Bo_Exp_Widget.js',
                        '{$exp}/Bo_Exp_Achild.js',
                        '{$exp}/Bo_Exp_Panel.jqhtml',
                        '{$exp}/Bo_Exp_Aa_First.scss',
                        '{$exp}/Bo_Exp_Panel.scss',
                        '{$exp}/Bo_Exp_Zz_Last.scss',
                        __DIR__,
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
     * file each compile the application file that overrides it - exactly once, though the
     * bundle includes the application directory too - and never the framework file. The
     * override takes the framework file's place: a stylesheet between the stylesheets that
     * surround the framework one, a class after the base it extends and before the class that
     * extends it. No shadowed path appears anywhere in anything the fixture build compiled.
     */
    public static function test_every_include_kind_compiles_the_override_in_the_shadowed_files_place()
    {
        static::__roots();
        static::__remove_everything();

        try {
            static::__write_fixture();

            static::__child_ok('rsx:manifest:build', [], 'the fixture build');

            $overrides = (include static::$build_root . '/manifest_index.php')['data']['overrides'];

            foreach (static::SETS as $set) {
                $application = array_keys(static::__application_files($set));

                foreach (static::__shadowed_paths($set) as $i => $framework_path) {
                    static::__assert_equals(
                        $application[$i],
                        $overrides[$framework_path]['by'] ?? null,
                        "the fixture build records {$framework_path} as shadowed by {$application[$i]}"
                    );
                }
            }

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
                [$widget, $panel, $sheet] = array_keys(static::__application_files($set));
                $what = "the '{$set}' set";

                // ---- the script: after its base, before the class extending it ----------
                $at = static::__position_of($widget, $js_sources, $what);
                static::__assert_greater_than(static::__position_of("{$framework}/{$p}_Base.js", $js_sources, $what), $at, "{$what}: the override is compiled after the base it extends");
                static::__assert_less_than(static::__position_of("{$framework}/{$p}_Achild.js", $js_sources, $what), $at, "{$what}: and before the class that extends it");

                // ---- the template ------------------------------------------------------
                static::__position_of($panel, $js_sources, $what);

                // ---- the stylesheet: in the framework stylesheet's place ---------------
                $at = static::__position_of($sheet, $css_sources, $what);
                static::__assert_greater_than(static::__position_of("{$framework}/{$p}_Aa_First.scss", $css_sources, $what), $at, "{$what}: the override stylesheet follows the one before the shadowed file");
                static::__assert_less_than(static::__position_of("{$framework}/{$p}_Zz_Last.scss", $css_sources, $what), $at, "{$what}: and precedes the one after it, though the application directory is included last");

                // ---- the content is the application's ---------------------------------
                $upper = strtoupper($set);
                static::__assert_contains("BO_APPLICATION_{$upper}_WIDGET", $app_js, "{$what}: the override's script is in the bundle");
                static::__assert_contains("BO_APPLICATION_{$upper}_PANEL", $app_js, "{$what}: the override's template is in the bundle");
                static::__assert_contains(".{$p}_Panel__application_marker", $app_css, "{$what}: the override's stylesheet is in the bundle");
            }

            // ---- COMPLETENESS: nothing the fixture build compiled came from a shadowed file
            static::__assert_not_empty($compiled, 'the fixture build compiled bundles');

            foreach ($compiled as $name => $content) {
                foreach (static::SETS as $set) {
                    $upper = strtoupper($set);
                    static::__assert_false(str_contains($content, "BO_FRAMEWORK_{$upper}_WIDGET"), "{$name} carries no shadowed script of the '{$set}' set");
                    static::__assert_false(str_contains($content, "BO_FRAMEWORK_{$upper}_PANEL"), "{$name} carries no shadowed template of the '{$set}' set");
                }

                static::__assert_false(str_contains($content, '__framework_marker'), "{$name} carries no shadowed stylesheet");

                $shadowed = array_values(array_intersect(static::__provenance($content), array_keys($overrides)));
                static::__assert_equals([], $shadowed, "{$name} was compiled from no shadowed file");
            }
        } finally {
            static::__remove_everything();
        }
    }

    /**
     * Every module bundle of the build this process runs on (outside the test trees) resolves
     * to a file list with no shadowed path in it. (A tree with no overrides passes trivially; an installed application
     * that overrides framework components is what this audits.)
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
