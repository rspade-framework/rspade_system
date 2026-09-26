<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys;

use Illuminate\Http\Request;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The control panel's SPA bootstrap: one PHP entry point, many JS actions.
 *
 * Every panel screen is a @spa('_Sys_Spa_Controller::index') action, so this
 * class is the single place the panel's bundle is named. The rsx.sys_panel.enabled
 * switch is inherited from _Sys_Endpoint_Controller_Abstract, and every SPA screen
 * passes through this bootstrap, so it covers the whole SPA.
 */
#[Auth('is_sysadmin')]
class _Sys_Spa_Controller extends _Sys_Endpoint_Controller_Abstract
{
    #[SPA]
    public static function index(Request $request, array $params = [])
    {
        return rsx_view(SPA, ['bundle' => '_Sys_Bundle']);
    }
}
