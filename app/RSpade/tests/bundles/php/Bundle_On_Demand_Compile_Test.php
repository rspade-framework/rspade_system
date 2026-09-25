<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Bundles\Php;

use App\RSpade\Core\Dispatch\AssetHandler;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The development on-demand compile behind /_compiled/<Name>__<type>.<hash>.<ext> builds
 * only a concrete MODULE bundle. A URL naming any other class - a settings class, an
 * abstract base, an asset bundle, nothing at all - must not buy a compile.
 *
 * Behavior of record: php artisan rsx:man bundle_api (ON-DEMAND COMPILE).
 */
class Bundle_On_Demand_Compile_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_a_concrete_module_bundle_qualifies()
    {
        $module_bundle = null;
        foreach (Manifest::php_get_subclasses_of('Rsx_Bundle_Abstract') as $class) {
            if (!Manifest::php_is_subclass_of($class, 'Rsx_Asset_Bundle_Abstract')) {
                $module_bundle = $class;
                break;
            }
        }

        if ($module_bundle === null) {
            static::__skip('the application declares no module bundle');
        }

        static::__assert_true(AssetHandler::_is_on_demand_bundle($module_bundle), "{$module_bundle} should compile on demand");
    }

    public static function test_anything_else_does_not()
    {
        static::__assert_false(AssetHandler::_is_on_demand_bundle('Rsx_Bundle_Abstract'), 'the abstract base');
        static::__assert_false(AssetHandler::_is_on_demand_bundle('Rsx_Asset_Bundle_Abstract'), 'the asset base');
        static::__assert_false(AssetHandler::_is_on_demand_bundle('AssetHandler'), 'a non-bundle framework class');
        static::__assert_false(AssetHandler::_is_on_demand_bundle('No_Such_Probe_Class'), 'an unknown name');

        foreach (Manifest::php_get_subclasses_of('Rsx_Asset_Bundle_Abstract') as $asset_bundle) {
            static::__assert_false(AssetHandler::_is_on_demand_bundle($asset_bundle), "asset bundle {$asset_bundle}");
        }
    }
}
