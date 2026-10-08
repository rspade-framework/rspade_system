<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Bundle_Component_Shaker;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE SEALED-BUILD COMPONENT SHAKE (Bundle_Component_Shaker).
 *
 * A sealed build drops from a bundle every jqhtml component nothing the bundle serves names.
 * These tests hold the two halves of that decision - what counts as naming a component, and
 * what a plan may and may not remove - against the framework's own API-console bundle, which
 * every install carries, so nothing here depends on the application's modules.
 *
 * The plan is the same function a sealed build runs; it is computed here without building.
 */
class Bundle_Component_Shaker_Test extends Rsx_Test_Abstract
{
    private const BUNDLE = '_Apidocs_Bundle';

    /** @return array{files: array<int,string>, keep: array<int,string>} */
    private static function __resolved(): array
    {
        foreach (Manifest::get_all() as $record) {
            if (($record['class'] ?? null) === self::BUNDLE) {
                return (new BundleCompiler())->resolve_for_shake($record['fqcn']);
            }
        }

        static::__assert_true(false, self::BUNDLE . ' is in the manifest');
    }

    // -------------------------------------------------------------------------
    // What a reference is
    // -------------------------------------------------------------------------

    public static function test_a_name_is_a_reference_only_as_a_whole_word()
    {
        $names = ['My_Card' => true];

        foreach (['<My_Card $x=1>', "component('My_Card')", 'class X extends My_Card {', 'see My_Card.', '// My_Card'] as $content) {
            static::__assert_equals(['My_Card'], Bundle_Component_Shaker::references($content, $names), $content);
        }

        foreach (['My_Card__title', 'Not_My_Card', 'My_Cards', 'my_card'] as $content) {
            static::__assert_equals([], Bundle_Component_Shaker::references($content, $names), $content);
        }
    }

    /**
     * `.Name` after anything but a word character is a CSS class selector; after a word
     * character it is a property access and after a second period a spread - both uses.
     */
    public static function test_a_css_class_selector_is_not_a_reference()
    {
        $names = ['My_Card' => true];

        foreach (["\$('.My_Card')", '".My_Card"', ' .My_Card {', '>.My_Card', '.My_Card'] as $content) {
            static::__assert_equals([], Bundle_Component_Shaker::references($content, $names), $content);
        }

        foreach (['window.My_Card', '{...My_Card.DEFAULTS}', 'Lib.My_Card.open()'] as $content) {
            static::__assert_equals(['My_Card'], Bundle_Component_Shaker::references($content, $names), $content);
        }
    }

    public static function test_a_selector_does_not_hide_a_real_reference_in_the_same_file()
    {
        static::__assert_equals(
            ['My_Card'],
            Bundle_Component_Shaker::references("\$('.My_Card').component('My_Card')", ['My_Card' => true])
        );
    }

    // -------------------------------------------------------------------------
    // What a plan removes
    // -------------------------------------------------------------------------

    public static function test_a_plan_keeps_the_file_order_and_removes_only_dropped_components()
    {
        $resolved = static::__resolved();
        $plan = Bundle_Component_Shaker::plan(self::BUNDLE, $resolved['files'], $resolved['keep']);

        static::__assert_not_empty($plan['kept'], 'the bundle keeps components');
        static::__assert_not_empty($plan['dropped'], 'and has components nothing it serves names');
        static::__assert_equals([], array_intersect(array_keys($plan['kept']), $plan['dropped']), 'no component is both');

        static::__assert_equals(
            array_values(array_diff($resolved['files'], $plan['dropped_files'])),
            $plan['files'],
            'the kept list is the input list, in order, minus the dropped files'
        );

        // Every dropped file is a template, class or stylesheet of a dropped component.
        $manifest_files = Manifest::get_all();
        $dropped = array_fill_keys($plan['dropped'], true);

        foreach ($plan['dropped_files'] as $file) {
            $record = $manifest_files[str_replace(base_path() . '/', '', $file)];
            $name = $record['id'] ?? $record['class'] ?? $record['scss_wrapper_class'] ?? null;

            static::__assert_true(isset($dropped[$name]), "{$file} belongs to a dropped component");
        }
    }

    /** Nothing but a component file is ever removed: every other file survives. */
    public static function test_a_file_that_is_not_a_component_is_always_kept()
    {
        $resolved = static::__resolved();
        $plan = Bundle_Component_Shaker::plan(self::BUNDLE, $resolved['files'], $resolved['keep']);

        $components = array_fill_keys(Manifest::get_full_manifest()['data']['js_subclass_index']['Component'] ?? [], true);
        $manifest_files = Manifest::get_all();

        foreach ($plan['dropped_files'] as $file) {
            $record = $manifest_files[str_replace(base_path() . '/', '', $file)];

            if ($record['extension'] === 'js') {
                static::__assert_true(isset($components[$record['class']]), "{$file} is a component class");
            } else {
                static::__assert_true(in_array($record['extension'], ['jqhtml', 'scss'], true), "{$file} is a template or a stylesheet");
            }
        }
    }

    /** A kept component's reason leads back, link by link, to something that is not a component. */
    public static function test_every_kept_component_traces_to_a_root()
    {
        $resolved = static::__resolved();
        $kept = Bundle_Component_Shaker::plan(self::BUNDLE, $resolved['files'], $resolved['keep'])['kept'];

        foreach ($kept as $name => $reason) {
            $current = $name;
            $hops = 0;

            while (preg_match('/^named by ([A-Za-z0-9_]+) /', $kept[$current], $match)) {
                $current = $match[1];
                static::__assert_true(isset($kept[$current]), "{$name}: {$current} in its chain is kept");
                static::__assert_true(++$hops <= count($kept), "{$name}: the chain ends");
            }
        }
    }

    public static function test_the_keep_list_keeps_a_component_and_what_it_names()
    {
        $resolved = static::__resolved();
        $before = Bundle_Component_Shaker::plan(self::BUNDLE, $resolved['files'], $resolved['keep']);
        $name = $before['dropped'][0];

        $after = Bundle_Component_Shaker::plan(self::BUNDLE, $resolved['files'], array_merge($resolved['keep'], [$name]));

        static::__assert_equals("listed in " . self::BUNDLE . "'s keep", $after['kept'][$name]);
        static::__assert_false(in_array($name, $after['dropped'], true));
        static::__assert_true(count($after['files']) > count($before['files']), 'its files are back in the bundle');
    }

    public static function test_a_keep_entry_naming_no_component_is_refused()
    {
        $resolved = static::__resolved();

        static::__assert_throws(
            \RuntimeException::class,
            fn () => Bundle_Component_Shaker::plan(self::BUNDLE, $resolved['files'], ['Zz_No_Such_Component']),
            "lists 'Zz_No_Such_Component' in 'keep'"
        );
    }

    // -------------------------------------------------------------------------
    // Which Blade files belong to a bundle
    // -------------------------------------------------------------------------

    /**
     * A view is served with the bundles it prints and with every bundle of the layout it
     * extends - which is how a page that prints nothing itself belongs to its module's bundle.
     */
    public static function test_a_view_inherits_the_bundles_of_the_layout_it_extends()
    {
        $manifest = Manifest::get_full_manifest()['data'];
        $bundles = Manifest::blade_bundles();
        $inherited = 0;

        foreach ($manifest['blade_views'] as $id => $path) {
            $record = Manifest::get_file($path);

            foreach ($record['bundles'] ?? [] as $printed) {
                static::__assert_true(in_array($printed, $bundles[$id] ?? [], true), "{$id} is served with {$printed}, which it prints");
            }

            $parent = $record['rsx_extends'] ?? null;
            if ($parent !== null && isset($bundles[$parent])) {
                static::__assert_equals([], array_diff($bundles[$parent], $bundles[$id] ?? []), "{$id} is served with every bundle of {$parent}");
                $inherited++;
            }
        }

        static::__assert_true($inherited > 0, 'the tree has a page that inherits its bundle from a layout');
    }
}
