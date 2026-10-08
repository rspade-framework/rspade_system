<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Bundle;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Bundle_Component_Shaker - decides which jqhtml COMPONENTS a sealed bundle can do without.
 *
 * WHY IT EXISTS. A bundle includes directories, and a directory brings everything in it. A
 * login bundle that includes the theme for its variables and its form inputs also receives
 * every other component the theme holds - the data grids, the editors, the viewers - none of
 * which a login page can render. In development that costs nothing worth saving. In a sealed
 * build (debug, production) it is most of the bundle, and it is also a catalogue of the
 * application's component names handed to a visitor who has not signed in.
 *
 * WHY ONLY COMPONENTS. Components are where the weight is, and a component is the one kind
 * of file whose use can be decided from text: it is used only when something NAMES it. Every
 * other file - plain JS classes, helper functions, model and controller stubs, stylesheets
 * that are not one component's own - is always kept. That is what keeps this analysis small
 * enough to trust: no parser, no call graph, no rule about method names.
 *
 * WHAT A COMPONENT IS HERE. A name that the bundle's files define as a component - a .jqhtml
 * template (its Define id), or a JS class descended from Component - together with every
 * file in the bundle carrying that name: the template, the class, and the stylesheet whose
 * single wrapper class is that name. The three are one unit: kept together or dropped
 * together.
 *
 * WHAT A REFERENCE IS. The component's name appearing in a file as a whole word - letters,
 * digits and underscores, any other character being a boundary. Comments and strings count:
 * a name mentioned is a name kept, and keeping too much is the safe direction. ONE shape is
 * not a reference: the name directly after a single period that does not itself follow a
 * word character - `'.My_Component'`, `$(".My_Component")` - which is a CSS class selector,
 * not a use of the component. (`Foo.My_Component` and `...My_Component.DEFAULTS` are uses.)
 *
 * THE KEEP SET starts from everything that can put a component on a page of this bundle
 * without another component naming it, and grows to a fixpoint:
 *
 *   1. the bundle's own `keep` list (the escape hatch - see below);
 *   2. Blade files served with this bundle (Manifest::blade_bundles(): a view prints the
 *      bundle, extends a layout that does, or is included by one that does), and Blade files
 *      served with NO bundle - a partial that could be included anywhere;
 *   3. every JS file in the bundle that is not a component: page scripts, libraries, stubs;
 *   4. a component whose class defines a static method - it may be reached by a sweep that
 *      calls a method on every class that has it (the boot phases), with nothing naming it;
 *   5. a component carrying a @route - an SPA action is reached by URL;
 *   6. a name that appears as a quoted string in PHP or config - a component chosen by the
 *      server (a viewer registry, a config value) is named nowhere in the client's source;
 *   then, repeatedly: every component named by the template or class of a kept component.
 *
 * WHAT IT CANNOT SEE. A name assembled at run time (a prefix joined to a variable) is
 * mentioned nowhere. Such a component is listed, by name, in the `keep` array of the
 * bundle's define() - the one escape hatch.
 *
 * A component dropped by mistake fails ONLY in a sealed build, so a debug build carries the
 * names it dropped and renders each as a component that throws, naming this cause
 * (BundleCompiler::_create_javascript_shaken_components()). A production build carries no
 * such list: the names are the thing being withheld.
 *
 * The result is a function of the bundle's file list and the manifest, so it is the same on
 * every box that builds the same tree.
 */
class Bundle_Component_Shaker
{
    /** Every quoted identifier in the PHP and config trees; built once per process. */
    private static ?array $php_literals = null;

    /**
     * Decide what a bundle keeps.
     *
     * @param string $bundle_name The bundle's simple class name
     * @param array<int,string> $files The bundle's resolved file list (absolute paths), in order
     * @param array<int,string> $keep Component names the bundle declares it keeps
     * @return array{
     *     files: array<int,string>,
     *     dropped_files: array<int,string>,
     *     kept: array<string,string>,
     *     dropped: array<int,string>
     * } `files` is the list to build from, in the order it was given; `kept` maps each kept
     *   component to the reason it was kept; `dropped` is the names removed, sorted.
     */
    public static function plan(string $bundle_name, array $files, array $keep = []): array
    {
        $manifest_files = Manifest::get_all();
        $components = static::__components($files, $manifest_files);

        if ($components === []) {
            return ['files' => $files, 'dropped_files' => [], 'kept' => [], 'dropped' => []];
        }

        $names = array_fill_keys(array_keys($components), true);
        $kept = [];
        $queue = [];

        $keep_component = function (string $name, string $reason) use (&$kept, &$queue): void {
            if (!isset($kept[$name])) {
                $kept[$name] = $reason;
                $queue[] = $name;
            }
        };

        // 1. The bundle's own keep list. A name it lists that the bundle does not hold is a
        //    mistake worth hearing about: it protects nothing.
        foreach ($keep as $name) {
            if (!isset($names[$name])) {
                throw new \RuntimeException(
                    "Bundle {$bundle_name} lists '{$name}' in 'keep', but no component of that name is in the bundle."
                );
            }
            $keep_component($name, "listed in {$bundle_name}'s keep");
        }

        // 2. Blade files served with this bundle, or with none.
        $blade_bundles = Manifest::blade_bundles();
        foreach (Manifest::get_full_manifest()['data']['blade_views'] ?? [] as $view_id => $path) {
            $served_with = $blade_bundles[$view_id] ?? null;
            if ($served_with !== null && !in_array($bundle_name, $served_with, true)) {
                continue;
            }
            foreach (static::references(file_get_contents(base_path($path)), $names) as $name) {
                $keep_component($name, "named in {$path}");
            }
        }

        // 3. Every JS file in the bundle that is not part of a component.
        $component_files = [];
        foreach ($components as $component) {
            foreach ($component['files'] as $file) {
                $component_files[$file] = true;
            }
        }
        foreach ($files as $file) {
            if (isset($component_files[$file]) || !str_ends_with($file, '.js')) {
                continue;
            }
            foreach (static::references(file_get_contents($file), $names) as $name) {
                $keep_component($name, 'named in ' . static::__relative($file));
            }
        }

        // 4 and 5. Components nothing has to name: a static method, a route.
        foreach ($components as $name => $component) {
            if ($component['static_methods'] !== []) {
                $keep_component($name, 'defines a static method (' . $component['static_methods'][0] . '())');
            } elseif ($component['routed']) {
                $keep_component($name, 'is a routed action');
            }
        }

        // 6. Names the server can send: quoted strings in PHP and config.
        $literals = static::__php_literals();
        foreach ($names as $name => $_) {
            if (isset($literals[$name])) {
                $keep_component($name, 'named as a string in ' . $literals[$name]);
            }
        }

        // The fixpoint: whatever a kept component's template or class names is kept too.
        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($components[$current]['files'] as $file) {
                if (str_ends_with($file, '.scss')) {
                    continue;
                }
                foreach (static::references(file_get_contents($file), $names) as $name) {
                    $keep_component($name, "named by {$current} (" . static::__relative($file) . ')');
                }
            }
        }

        $dropped_files = [];
        foreach ($components as $name => $component) {
            if (!isset($kept[$name])) {
                foreach ($component['files'] as $file) {
                    $dropped_files[$file] = true;
                }
            }
        }

        $dropped = array_keys(array_diff_key($names, $kept));
        sort($dropped, SORT_STRING);
        ksort($kept, SORT_STRING);

        return [
            'files' => array_values(array_filter($files, static fn ($file) => !isset($dropped_files[$file]))),
            'dropped_files' => array_values(array_filter($files, static fn ($file) => isset($dropped_files[$file]))),
            'kept' => $kept,
            'dropped' => $dropped,
        ];
    }

    /**
     * The component names $content references, by the rule in the class docblock.
     *
     * @param array<string,true> $names The names to look for
     * @return array<int,string> Each referenced name once
     */
    public static function references(string $content, array $names): array
    {
        $found = [];

        preg_match_all('/[A-Za-z0-9_]+/', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$word, $offset]) {
            if (!isset($names[$word]) || isset($found[$word])) {
                continue;
            }

            // `.Name` after something that is not a word character is a CSS class selector.
            // After a word character it is a property access, and after a second period it
            // is a spread - both are uses.
            if ($offset >= 1 && $content[$offset - 1] === '.') {
                $before = $offset >= 2 ? $content[$offset - 2] : '';
                if ($before !== '.' && !preg_match('/[A-Za-z0-9_]/', $before)) {
                    continue;
                }
            }

            $found[$word] = true;
        }

        return array_keys($found);
    }

    /**
     * The components the file list defines: name -> its files, its static methods, routed.
     *
     * @return array<string, array{files: array<int,string>, static_methods: array<int,string>, routed: bool}>
     */
    private static function __components(array $files, array $manifest_files): array
    {
        $component_classes = array_fill_keys(
            Manifest::get_full_manifest()['data']['js_subclass_index']['Component'] ?? [],
            true
        );

        $components = [];
        $stylesheets = [];

        foreach ($files as $file) {
            $record = $manifest_files[static::__relative($file)] ?? null;
            if ($record === null) {
                continue;
            }

            $extension = $record['extension'] ?? '';

            if ($extension === 'jqhtml' && !empty($record['id'])) {
                $name = $record['id'];
            } elseif ($extension === 'js' && !empty($record['class']) && isset($component_classes[$record['class']])) {
                $name = $record['class'];
            } elseif ($extension === 'scss' && !empty($record['scss_wrapper_class'])) {
                $stylesheets[$record['scss_wrapper_class']][] = $file;

                continue;
            } else {
                continue;
            }

            $components[$name] ??= ['files' => [], 'static_methods' => [], 'routed' => false];
            $components[$name]['files'][] = $file;

            if ($extension === 'js') {
                $components[$name]['static_methods'] = array_keys($record['public_static_methods'] ?? []);
                foreach ($record['decorators'] ?? [] as $decorator) {
                    if (($decorator[0] ?? null) === 'route') {
                        $components[$name]['routed'] = true;
                    }
                }
            }
        }

        // A stylesheet belongs to a component only when a component of that name is here;
        // a wrapper for anything else (a Blade page's own class) is an ordinary stylesheet.
        foreach ($stylesheets as $name => $stylesheet_files) {
            if (isset($components[$name])) {
                array_push($components[$name]['files'], ...$stylesheet_files);
            }
        }

        return $components;
    }

    /**
     * Every identifier that appears as a whole quoted string in a PHP file the manifest
     * indexes or in a config file: name -> the first file it was found in.
     *
     * @return array<string,string>
     */
    private static function __php_literals(): array
    {
        if (static::$php_literals !== null) {
            return static::$php_literals;
        }

        $paths = [];
        foreach (Manifest::get_all() as $path => $record) {
            if (($record['extension'] ?? '') === 'php') {
                $paths[] = $path;
            }
        }
        foreach ([base_path('config'), base_path('rsx/resource/config')] as $config_dir) {
            foreach (glob($config_dir . '/*.php') ?: [] as $config_file) {
                $paths[] = static::__relative($config_file);
            }
        }
        sort($paths, SORT_STRING);

        $literals = [];
        foreach ($paths as $path) {
            preg_match_all('/([\'"])([A-Za-z_][A-Za-z0-9_]*)\1/', file_get_contents(base_path($path)), $matches);
            foreach ($matches[2] as $literal) {
                $literals[$literal] ??= $path;
            }
        }

        return static::$php_literals = $literals;
    }

    private static function __relative(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }
}
