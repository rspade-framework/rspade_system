<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\Lib;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * _Sys_Endpoint_Controller_Abstract - the base of every control-panel controller.
 *
 * It holds the rsx.sys_panel.enabled switch ONCE. Both dispatch seams call the
 * controller's pre_dispatch() as a static call on the concrete class - the page
 * Dispatcher (Dispatcher::__call_action) and the Ajax core (Ajax::execute) - so the
 * refusal declared here runs for every route, SPA bootstrap and Ajax endpoint of a
 * subclass that does not override it. A subclass that does override pre_dispatch()
 * calls parent::pre_dispatch() first and returns its answer when non-null
 * (PHP-PARENT-CHAIN-01; this method is deliberately not #[Replaceable]).
 *
 * The switch is NOT FOUND rather than forbidden: "there is no control panel here" is
 * the honest answer, and it says nothing about who might be allowed one.
 * response_not_found() is the one spelling that is right on both channels - the 404
 * page on a page GET, the not_found envelope on an Ajax call.
 *
 * The gate is NOT here. Attributes are not inherited, so every concrete panel
 * controller still declares #[Auth('is_sysadmin')] at class level; a controller that
 * forgets fails the manifest build (closed by default), and Sys_Panel_Gate_Test pins
 * the check name.
 */
abstract class _Sys_Endpoint_Controller_Abstract extends Rsx_Controller_Abstract
{
    public static function pre_dispatch(Request $request, array $params = [])
    {
        if (!config('rsx.sys_panel.enabled')) {
            return response_not_found();
        }

        return null;
    }
}
