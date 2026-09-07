<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Artisan;
use App\RSpade\Core\Naming\Rsx_Identifier;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The inventory tools hide the framework's own application from an application
 * developer, and show it to a framework developer.
 *
 * The panel's names are RESERVED from application code (rsx:man sys_panel), so
 * listing them in rsx:routes / rsx:manifest:show only invites the references the
 * rule forbids. This is DISPLAY only: nothing leaves the manifest, which is why
 * both directions are proved from the same built index in one process.
 */
class Sys_Panel_Tool_Visibility_Test extends Rsx_Test_Abstract
{
    /**
     * Run an artisan command with the framework-developer flag forced either way.
     */
    private static function __output_with_flag(string $command, array $args, bool $is_framework_developer): string
    {
        $previous = config('rsx.code_quality.is_framework_developer');

        try {
            config(['rsx.code_quality.is_framework_developer' => $is_framework_developer]);

            Artisan::call($command, $args);

            return Artisan::output();
        } finally {
            config(['rsx.code_quality.is_framework_developer' => $previous]);
        }
    }

    /**
     * RP-TOOLS-01 - rsx:manifest:show --classes hides `_Sys_*` from an application
     * developer and shows it to a framework developer.
     */
    public static function test_manifest_show_classes_hides_the_framework_application()
    {
        $hidden = static::__output_with_flag('rsx:manifest:show', ['--classes' => true], false);
        $shown = static::__output_with_flag('rsx:manifest:show', ['--classes' => true], true);

        static::__assert_true(
            !str_contains($hidden, '_Sys_'),
            'rsx:manifest:show --classes names no _Sys_ class for an application developer'
        );
        static::__assert_contains('_Sys_', $shown, 'a framework developer still sees the panel classes');
        static::__assert_contains('Rsx_Model_Abstract', $hidden, 'ordinary classes are unaffected');
    }

    /**
     * RP-TOOLS-02 - rsx:routes hides every /_sys route from an application developer
     * and shows them to a framework developer.
     */
    public static function test_routes_hides_the_panel_urls()
    {
        $hidden = static::__output_with_flag('rsx:routes', [], false);
        $shown = static::__output_with_flag('rsx:routes', [], true);

        static::__assert_true(
            !str_contains($hidden, '/_sys'),
            'rsx:routes lists no /_sys route for an application developer'
        );
        static::__assert_contains('/_sys', $shown, 'a framework developer still sees the panel routes');
        static::__assert_contains('/login', $hidden, 'ordinary routes are unaffected');
    }

    /**
     * RP-TOOLS-03 - rsx:manifest:show --files hides the tree's FILES, which carry no
     * class name of their own (a .jqhtml, an .scss).
     */
    public static function test_manifest_show_files_hides_the_framework_application_tree()
    {
        $hidden = static::__output_with_flag('rsx:manifest:show', ['--files' => true], false);
        $shown = static::__output_with_flag('rsx:manifest:show', ['--files' => true], true);

        static::__assert_true(
            !str_contains($hidden, 'app/RSpade/Sys/'),
            'rsx:manifest:show --files lists no Sys/ file for an application developer'
        );
        static::__assert_contains('app/RSpade/Sys/', $shown, 'a framework developer still sees the tree');
    }

    /**
     * RP-TOOLS-04 - the predicate itself: a reserved NAME and a Sys/ PATH follow the
     * flag; an ordinary name and path never do.
     */
    public static function test_the_visibility_predicate()
    {
        $previous = config('rsx.code_quality.is_framework_developer');

        try {
            config(['rsx.code_quality.is_framework_developer' => false]);

            static::__assert_false(Rsx_Identifier::is_visible_to_developer('_Sys_Layout'));
            static::__assert_false(Rsx_Identifier::is_path_visible_to_developer('app/RSpade/Sys/app/sys/_Sys_Bundle.php'));
            static::__assert_true(Rsx_Identifier::is_visible_to_developer('Clients_Controller'));
            static::__assert_true(Rsx_Identifier::is_visible_to_developer(''));
            static::__assert_true(Rsx_Identifier::is_path_visible_to_developer('rsx/models/client_model.php'));

            config(['rsx.code_quality.is_framework_developer' => true]);

            static::__assert_true(Rsx_Identifier::is_visible_to_developer('_Sys_Layout'));
            static::__assert_true(Rsx_Identifier::is_path_visible_to_developer('app/RSpade/Sys/app/sys/_Sys_Bundle.php'));
        } finally {
            config(['rsx.code_quality.is_framework_developer' => $previous]);
        }
    }
}
