<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Apidocs\_Apidocs_Bundle;

/**
 * The API reference console as a module of the framework's own application.
 *
 * The console is mounted by an APPLICATION route and rendered by one framework
 * call, so nothing downstream would notice it moving trees - which is exactly
 * why the move needs stating in the suite. These rows pin where it lives, that
 * the manifest still resolves the two names Rsx_Api_Docs::page() depends on, and
 * that Core/Api is engine-only now, so a stray view or stylesheet cannot drift
 * back into it unannounced.
 */
class Sys_Panel_Apidocs_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * RP-APIDOCS-01 - The console's bundle names no rsx/ path.
     *
     * Same invariant as the panel's own bundles (CONV-BUNDLE-04): the console is
     * framework code mounted on an application route, and the application it
     * borrowed a stylesheet from is free to delete it.
     */
    public static function test_console_bundle_names_no_application_path()
    {
        $definition = _Apidocs_Bundle::define();

        $named = array_merge(
            $definition['include'] ?? [],
            $definition['watch'] ?? []
        );

        foreach ($named as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $normalized = str_replace('\\', '/', $entry);

            static::__assert_false(
                str_starts_with($normalized, 'rsx/') || str_contains($normalized, '/rsx/'),
                "_Apidocs_Bundle names the application path '{$entry}'"
            );
        }
    }

    /**
     * RP-APIDOCS-02 - The console draws on the framework application's theme
     * rather than carrying a palette of its own.
     */
    public static function test_console_bundle_includes_the_framework_application_theme()
    {
        $include = _Apidocs_Bundle::define()['include'];

        static::__assert_true(
            in_array('_Sys_Theme_Bundle', $include, true),
            'the console does not include the framework application theme bundle'
        );

        static::__assert_true(
            in_array('app/RSpade/Sys/theme', $include, true),
            'the console does not include the framework application theme directory'
        );
    }

    /**
     * RP-APIDOCS-03 - The manifest holds the console's blade id and its root
     * component, at their addresses in the framework application tree.
     *
     * These are the two names Rsx_Api_Docs::page() depends on: it returns
     * rsx_view('_Apidocs_App'), and that view's body is <_Apidocs_Console />.
     */
    public static function test_manifest_holds_the_console_view_and_component()
    {
        $view_path = Manifest::find_view_by_rsx_id('_Apidocs_App');

        static::__assert_equals(
            'app/RSpade/Sys/app/apidocs/_Apidocs_App.blade.php',
            $view_path,
            '_Apidocs_App does not resolve to the console module'
        );

        $manifest = Manifest::get_full_manifest();
        $components = $manifest['data']['jqhtml']['components'] ?? [];

        static::__assert_array_has_key(
            '_Apidocs_Console',
            $components,
            'the console root component is not a registered jqhtml component'
        );

        static::__assert_equals(
            'app/RSpade/Sys/app/apidocs/components/_Apidocs_Console.jqhtml',
            $components['_Apidocs_Console']['file'],
            '_Apidocs_Console is not in the console module'
        );
    }

    /**
     * RP-APIDOCS-04 - Core/Api is the API ENGINE and holds no view layer.
     *
     * The console left it whole. A .jqhtml, .blade.php or .scss appearing there
     * again means a piece of the console grew back on the engine's side of the
     * line, where no bundle includes it and no CLAUDE.md describes it.
     */
    public static function test_core_api_holds_no_view_layer()
    {
        $engine_dir = base_path('app/RSpade/Core/Api');

        static::__assert_true(is_dir($engine_dir), "{$engine_dir} is missing");

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($engine_dir, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];

        foreach ($iterator as $file) {
            $name = $file->getFilename();

            if (str_ends_with($name, '.jqhtml') || str_ends_with($name, '.blade.php') || str_ends_with($name, '.scss')) {
                $offenders[] = $name;
            }
        }

        static::__assert_equals(
            [],
            $offenders,
            'Core/Api holds view-layer files: ' . implode(', ', $offenders)
        );
    }
}
