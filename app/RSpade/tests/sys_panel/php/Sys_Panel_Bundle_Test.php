<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\_Sys_Bundle;
use App\RSpade\Sys\Theme\_Sys_Theme_Bundle;

/**
 * The panel's bundles: the invariant that keeps them independent of the
 * application they ship beside, and the Bootstrap build they compile.
 *
 * CONV-BUNDLE-04 already refuses an rsx/ include at check time; this proves the
 * declaration itself, so a violation is visible in the suite and not only in a
 * quality pass.
 */
class Sys_Panel_Bundle_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * RP-BUNDLE-01 - Neither panel bundle names an rsx/ path.
     */
    public static function test_panel_bundles_name_no_application_path()
    {
        foreach ([_Sys_Bundle::class, _Sys_Theme_Bundle::class] as $bundle_class) {
            $definition = $bundle_class::define();

            $named = array_merge(
                $definition['include'] ?? [],
                $definition['watch'] ?? [],
                array_keys($definition['config']['module_paths'] ?? [])
            );

            foreach ($named as $entry) {
                if (!is_string($entry)) {
                    continue;
                }

                $normalized = str_replace('\\', '/', $entry);

                static::__assert_false(
                    str_starts_with($normalized, 'rsx/') || str_contains($normalized, '/rsx/'),
                    "{$bundle_class} names the application path '{$entry}'"
                );
            }
        }
    }

    /**
     * RP-BUNDLE-02 - _Sys_Bundle includes the panel's own theme bundle and its
     * own module directory, and nothing that belongs to an application.
     */
    public static function test_sys_bundle_include_list()
    {
        $include = _Sys_Bundle::define()['include'];

        static::__assert_true(in_array('_Sys_Theme_Bundle', $include, true));
        static::__assert_true(in_array('app/RSpade/Sys/theme', $include, true));
        static::__assert_true(
            in_array(rsxrealpath(base_path('app/RSpade/Sys/app/sys')), array_map(
                static fn ($entry) => is_string($entry) ? rsxrealpath($entry) : $entry,
                $include
            ), true),
            'the panel module directory is not in its own bundle'
        );
    }

    /**
     * RP-BUNDLE-03 - The theme bundle compiles: Bootstrap's SCSS reaches the
     * compiler through the one @import the processor permits (a '/vendor/' path),
     * and the panel's variable overrides win.
     */
    public static function test_theme_bundle_compiles_bootstrap_with_the_panel_palette()
    {
        $compiler = new BundleCompiler([]);

        $compiled = $compiler->compile(_Sys_Theme_Bundle::class, ['force_build' => true]);

        // compile() returns bundle-relative filenames for the vendor/app split;
        // the bundles themselves live in one directory.
        $bundle_dir = storage_path('rsx-build/bundles');
        $css = '';

        foreach (['vendor_css_bundle_path', 'app_css_bundle_path'] as $key) {
            if (empty($compiled[$key])) {
                continue;
            }

            $path = $bundle_dir . '/' . $compiled[$key];

            static::__assert_true(file_exists($path), "compiled CSS missing at {$path}");

            $css .= file_get_contents($path);
        }

        static::__assert_not_empty($css, 'the theme bundle produced no CSS');
        static::__assert_contains('.btn', $css, 'Bootstrap did not reach the compiled output');
        static::__assert_contains('--rsx-accent', $css, 'the panel design tokens are missing');
        static::__assert_contains('#58a6ff', strtolower($css), 'the panel accent did not override Bootstrap $primary');
    }
}
