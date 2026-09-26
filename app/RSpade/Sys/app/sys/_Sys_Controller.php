<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The control panel's server-rendered routes - everything that cannot be a SPA
 * action because it ends the session or leaves the panel. The rsx.sys_panel.enabled
 * refusal is inherited from _Sys_Endpoint_Controller_Abstract.
 */
#[Auth('is_sysadmin')]
class _Sys_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * Sign out of the panel.
     *
     * The panel signs the IDENTITY out, not just the panel - there is one session
     * per browser and no such thing as being signed out of /_sys alone. The
     * destination is the site root, which is the application's own business from
     * there on.
     */
    #[Route('/_sys/logout', methods: ['GET'])]
    public static function logout(Request $request, array $params = [])
    {
        RsxAuth::logout();

        return redirect('/');
    }
}
