<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Scaffold\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\CodeTemplates\StubProcessor;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins the output of the module scaffolder (rsx:app:module:create +
 * rsx:app:module:feature:create).
 *
 * The scaffolder writes into rsx/app/ directly, so each test creates a module
 * under a fixed reserved name, asserts the generated file set and the load-bearing
 * content lines, and removes the directory in a finally block. A pre-existing
 * directory under a reserved name is a stale artifact from an aborted run - the
 * test refuses rather than deleting somebody's module.
 *
 * The content assertions are the ones a broken scaffold fails on immediately:
 * the mandatory #[Auth]/@auth gates (a missing gate FAILS the next manifest
 * build), #[SPA] + the bundle parameter rsx_view(SPA) requires, the layout's
 * $sid="content" landing zone, the four Action decorators, and the base classes.
 */
class Module_Scaffold_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const SPA_MODULE = 'zzz_scaffold_spa';
    private const BLADE_MODULE = 'zzz_scaffold_blade';

    public static function test_module_create_generates_expected_files()
    {
        $module = self::SPA_MODULE;
        $module_path = base_path("rsx/app/{$module}");
        self::__require_absent($module_path);

        try {
            $exit_code = Artisan::call('rsx:app:module:create', ['name' => $module]);
            static::__assert_equals(0, $exit_code, 'SPA module scaffold should exit 0');

            $bundle = self::__read("{$module_path}/{$module}_bundle.php");
            $bootstrap = self::__read("{$module_path}/{$module}_spa_controller.php");
            $layout_js = self::__read("{$module_path}/Zzz_Scaffold_Spa_Spa_Layout.js");
            $layout_tpl = self::__read("{$module_path}/Zzz_Scaffold_Spa_Spa_Layout.jqhtml");
            $action_js = self::__read("{$module_path}/index/Zzz_Scaffold_Spa_Index_Action.js");
            $action_tpl = self::__read("{$module_path}/index/Zzz_Scaffold_Spa_Index_Action.jqhtml");
            $feature_ctrl = self::__read("{$module_path}/index/{$module}_index_controller.php");

            // Bundle: module bundle base (an asset-bundle base would not accept __DIR__ modules).
            static::__assert_contains('extends Rsx_Module_Bundle_Abstract', $bundle);
            static::__assert_contains('__DIR__', $bundle);

            // Bootstrap: gated, marked, and carrying the bundle the SPA shell requires.
            static::__assert_contains("#[Auth('is_logged_in')]", $bootstrap);
            static::__assert_contains('#[SPA]', $bootstrap);
            static::__assert_contains("rsx_view(SPA, ['bundle' => 'Zzz_Scaffold_Spa_Bundle'])", $bootstrap);

            // Layout: the persistent chrome plus the region Actions render into.
            static::__assert_contains('extends Spa_Layout', $layout_js);
            static::__assert_contains('$sid="content"', $layout_tpl);

            // Action: all four decorators, including the mandatory auth gate.
            static::__assert_contains("@route('/{$module}')", $action_js);
            static::__assert_contains("@layout('Zzz_Scaffold_Spa_Spa_Layout')", $action_js);
            static::__assert_contains("@spa('Zzz_Scaffold_Spa_Spa_Controller::index')", $action_js);
            static::__assert_contains("@auth('is_logged_in')", $action_js);
            static::__assert_contains('extends Spa_Action', $action_js);
            static::__assert_contains('<Define:Zzz_Scaffold_Spa_Index_Action>', $action_tpl);

            // Feature controller: gated Ajax surface, no routes.
            static::__assert_contains("#[Auth('is_logged_in')]", $feature_ctrl);
            static::__assert_contains('class Zzz_Scaffold_Spa_Index_Controller', $feature_ctrl);
            static::__assert_true(
                !str_contains($feature_ctrl, '#[Route('),
                'SPA feature controller must not declare server routes'
            );

            // The SPA module root holds no Blade page files.
            static::__assert_count(0, glob("{$module_path}/*.blade.php"), 'SPA module should generate no Blade views');
        } finally {
            self::__remove_directory($module_path);
        }
    }

    public static function test_module_create_blade_generates_expected_files()
    {
        $module = self::BLADE_MODULE;
        $module_path = base_path("rsx/app/{$module}");
        self::__require_absent($module_path);

        try {
            $exit_code = Artisan::call('rsx:app:module:create', ['name' => $module, '--blade' => true]);
            static::__assert_equals(0, $exit_code, 'Blade module scaffold should exit 0');

            $bundle = self::__read("{$module_path}/{$module}_bundle.php");
            $layout = self::__read("{$module_path}/{$module}_layout.blade.php");
            $controller = self::__read("{$module_path}/{$module}_index_controller.php");
            self::__read("{$module_path}/{$module}_index.blade.php");
            self::__read("{$module_path}/{$module}_index.js");
            self::__read("{$module_path}/{$module}_index.scss");

            // Repaired base class: a module layout renders the bundle through it.
            static::__assert_contains('extends Rsx_Module_Bundle_Abstract', $bundle);
            static::__assert_contains('Zzz_Scaffold_Blade_Bundle::render()', $layout);

            // Repaired gate: the generated controller used to ship ungated and fail the build.
            static::__assert_contains("#[Auth('is_logged_in')]", $controller);
            static::__assert_contains("#[Route('/{$module}')]", $controller);
            static::__assert_true(
                !str_contains($controller, 'pre_dispatch'),
                'Blade controller stub must not carry a pre_dispatch stub (authorization is declared with #[Auth])'
            );
        } finally {
            self::__remove_directory($module_path);
        }
    }

    public static function test_module_create_rejects_invalid_names()
    {
        // Illegal characters: uppercase, hyphen, digits.
        foreach (['Bad_Name', 'bad-name', 'bad9name'] as $bad_name) {
            $exit_code = Artisan::call('rsx:app:module:create', ['name' => $bad_name]);
            static::__assert_equals(1, $exit_code, "Module name '{$bad_name}' should be rejected");
            static::__assert_true(
                !is_dir(base_path("rsx/app/{$bad_name}")),
                "Rejected module name '{$bad_name}' must not leave a directory behind"
            );
        }

        // An existing module is never overwritten.
        $exit_code = Artisan::call('rsx:app:module:create', ['name' => 'frontend']);
        static::__assert_equals(1, $exit_code, 'An existing module must be refused');

        // A feature cannot be created in a module that does not exist.
        $exit_code = Artisan::call('rsx:app:module:feature:create', [
            'module' => 'zzz_scaffold_missing',
            'feature' => 'widgets',
        ]);
        static::__assert_equals(1, $exit_code, 'A feature in a missing module must be refused');
    }

    /**
     * Refuse to run against a leftover directory rather than deleting it.
     */
    private static function __require_absent(string $path)
    {
        if (is_dir($path)) {
            static::__fail("Reserved scaffold test directory already exists: {$path} (remove it and re-run)");
        }
    }

    private static function __read(string $path): string
    {
        static::__assert_true(file_exists($path), "Expected generated file missing: {$path}");
        return (string) file_get_contents($path);
    }

    private static function __remove_directory(string $path)
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? self::__remove_directory($child) : unlink($child);
        }

        rmdir($path);
    }

    /**
     * The scaffolder derives every generated identifier from the snake_case name it was
     * given, so the derivation must carry a leading framework-application underscore
     * through untouched: `_sys_card` names `_Sys_Card`, not `Sys_Card` and not
     * `_sys_card`. Nothing in the templates would report the loss - the generated file
     * would simply declare the wrong class.
     */
    public static function test_stub_processor_preserves_the_framework_application_prefix()
    {
        static::__assert_equals('_Sys_Card', StubProcessor::to_class_name('_sys_card'));
        static::__assert_equals('Sys_Card', StubProcessor::to_class_name('sys_card'));
        static::__assert_equals('Sys Card', StubProcessor::to_title('_sys_card'));
        static::__assert_equals('Sys Card', StubProcessor::to_title('sys_card'));
    }
}
