<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys;

use Illuminate\Http\Request;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Errors\Error_Screens;

/**
 * The control panel's server-rendered routes - everything that cannot be a SPA
 * action because it ends the session or leaves the panel.
 */
#[Auth('is_sysadmin')]
class _Sys_Controller extends Rsx_Controller_Abstract
{
    /**
     * The same refusal _Sys_Spa_Controller performs, for the panel's non-SPA
     * routes. Restated rather than inherited: a controller's pre_dispatch is its
     * own declaration of what it refuses.
     */
    public static function pre_dispatch(Request $request, array $params = [])
    {
        if (!config('rsx.sys_panel.enabled')) {
            return Error_Screens::not_found($request);
        }

        return null;
    }

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
