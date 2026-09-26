<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys;

use Illuminate\Http\Request;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The way back from the panel's "Sign in as this user" (the Users screen,
 * _Sys_Users_Controller::sign_in_as).
 *
 * THE ONE PANEL SURFACE NOT GATED ON is_sysadmin. While impersonating, the session's
 * effective identity is the user being impersonated, who is never a developer (the panel
 * refuses to sign in as one) - so an is_sysadmin gate would lock the developer inside the
 * impersonation. The class gate is therefore is_logged_in, and it lives on its own
 * controller because method gates only NARROW a class gate (_Sys_Controller's class-level
 * is_sysadmin would still apply). The developer check moves into the body, where it asks
 * about the IMPERSONATOR - the real principal behind the session - instead of the
 * effective identity. Sys_Panel_Gate_Test names this route as the panel's single ungated
 * exception.
 *
 * The rsx.sys_panel.enabled refusal is inherited from _Sys_Endpoint_Controller_Abstract.
 */
#[Auth('is_logged_in')]
class _Sys_Impersonation_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The session-value key holding the site the developer's session was on when the
     * impersonation began (Session::put_value()), restored by stop().
     */
    public const RETURN_SITE_KEY = 'sys.impersonation.return_site';

    /**
     * End an impersonation the panel started and go back to the impersonated user's
     * detail screen.
     *
     * GET, deliberately: it is the link in the application's impersonation banner. It
     * changes state, but the change only ENDS a privilege - the same ruling that keeps
     * GET /logout - so a forged request can do no more than hand the developer back
     * their own identity.
     *
     * - Not impersonating: nothing to stop; redirect to the site root.
     * - The impersonator is not a developer (an impersonation some other code began):
     *   refused, and the impersonation is left exactly as it is - ending it is that
     *   code's business.
     * - Otherwise: Session::stop_impersonation(), the site stored at begin is restored
     *   and forgotten, and the redirect lands on /_sys/users/<impersonated id>.
     */
    #[Route('/_sys/stop-impersonating', methods: ['GET'])]
    public static function stop(Request $request, array $params = [])
    {
        if (!Session::is_impersonating()) {
            return redirect('/');
        }

        if (!Session::get_impersonator_login_user()?->is_developer) {
            return response_unauthorized('This impersonation was not started from the system panel, so the panel cannot end it.');
        }

        $impersonated_login_user_id = (int) Session::get_login_user_id();

        Session::stop_impersonation();

        $return_site_id = Session::get_value(self::RETURN_SITE_KEY);
        Session::forget_value(self::RETURN_SITE_KEY);

        if ($return_site_id !== null) {
            Session::set_site_id((int) $return_site_id);
        }

        return redirect(Rsx::Route('_Sys_User_View_Action', $impersonated_login_user_id));
    }
}
