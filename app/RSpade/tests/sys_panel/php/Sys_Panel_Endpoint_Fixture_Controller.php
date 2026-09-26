<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * A panel-shaped controller for Sys_Panel_Gate_Test: it extends the panel's endpoint
 * base and declares the panel's gate, exactly as a controller under Sys/app/sys does,
 * so the Ajax channel's handling of the INHERITED rsx.sys_panel.enabled refusal is
 * provable whether or not the panel itself ships an Ajax endpoint yet.
 */
#[Auth('is_sysadmin')]
class Sys_Panel_Endpoint_Fixture_Controller extends _Sys_Endpoint_Controller_Abstract
{
    #[Ajax_Endpoint]
    public static function ping(Request $request, array $params = [])
    {
        return ['pong' => true];
    }
}
