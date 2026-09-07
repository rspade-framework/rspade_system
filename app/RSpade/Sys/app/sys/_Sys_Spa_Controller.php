<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Errors\Error_Screens;

/**
 * The control panel's SPA bootstrap: one PHP entry point, many JS actions.
 *
 * Every panel screen is a @spa('_Sys_Spa_Controller::index') action, so this
 * class is the single place the panel's bundle is named and the single place the
 * config switch is enforced for the whole SPA.
 */
#[Auth('is_sysadmin')]
class _Sys_Spa_Controller extends Rsx_Controller_Abstract
{
    /**
     * The switch, enforced where every panel URL passes.
     *
     * A disabled panel is NOT FOUND rather than forbidden: "there is no control
     * panel here" is the honest answer, and it says nothing about who might be
     * allowed one. There is no manifest or dispatcher mechanism that removes a
     * route by config, so the refusal lives at the dispatch seam, exactly as
     * Realtime_Controller refuses when realtime is switched off.
     */
    public static function pre_dispatch(Request $request, array $params = [])
    {
        if (!config('rsx.sys_panel.enabled')) {
            return Error_Screens::not_found($request);
        }

        return null;
    }

    #[SPA]
    public static function index(Request $request, array $params = [])
    {
        return rsx_view(SPA, ['bundle' => '_Sys_Bundle']);
    }
}
