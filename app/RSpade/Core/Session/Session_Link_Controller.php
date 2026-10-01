<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\Session_Link;

/**
 * The three GET legs of the linked-session handshake (Session_Link has the protocol).
 *
 * Every route is declared in BOTH tables on the same handler, the way the framework's file
 * routes are: legs 1 and 3 run in the portal realm on the portal's host (under its prefix),
 * leg 2 in the staff realm on APP_URL's host. Each leg's signature is bound to the host it
 * was minted for, so a leg reached on the wrong host is refused like any other bad link.
 *
 * #[Auth('public')]: the legs authenticate by their one-time codes, the staff cookie (leg 2)
 * and the nonce cookie (leg 3) - a gate cannot express "this browser, this link". GET only:
 * a cross-host POST would be refused by the Origin check, and top-level GET navigation is
 * what carries SameSite=Lax cookies across the redirect chain.
 *
 * Any refusal renders the one generic page (Session_Link::FAILURE_MESSAGE) on the host it
 * happened on, and nothing is linked. Nothing about the refusal is logged: the codes are
 * secrets, and which check failed is not something to disclose.
 */
#[Auth('public')]
class Session_Link_Controller extends Rsx_Controller_Abstract
{
    /**
     * Leg 1 (portal host): redeem code 1, set the nonce cookie, go to the staff host.
     */
    #[Route('/_session_link/open', methods: ['GET'])]
    #[Portal_Route('/_session_link/open', methods: ['GET'])]
    public static function open(Request $request, array $params = [])
    {
        $result = Session_Link::open($request);

        if ($result === null) {
            return static::__refused($request);
        }

        return redirect($result['redirect'])->withCookie(Session_Link::nonce_cookie($result['nonce']));
    }

    /**
     * Leg 2 (staff host): prove the staff session, apply the impersonation, go back.
     */
    #[Route('/_session_link/confirm', methods: ['GET'])]
    #[Portal_Route('/_session_link/confirm', methods: ['GET'])]
    public static function confirm(Request $request, array $params = [])
    {
        $redirect = Session_Link::confirm($request);

        if ($redirect === null) {
            return static::__refused($request);
        }

        return redirect($redirect);
    }

    /**
     * Leg 3 (portal host): prove the nonce, point this host's cookie at the row, land.
     */
    #[Route('/_session_link/complete', methods: ['GET'])]
    #[Portal_Route('/_session_link/complete', methods: ['GET'])]
    public static function complete(Request $request, array $params = [])
    {
        $session_id = Session_Link::complete($request);

        if ($session_id === null || !Session::_clone_session_to_this_host($session_id)) {
            return static::__refused($request);
        }

        return redirect(Rsx_Portal::portal_path('/'))->withCookie(Session_Link::forget_nonce_cookie());
    }

    /**
     * The one refusal: a 400 page through the error funnel, the nonce cookie dropped.
     */
    private static function __refused(Request $request)
    {
        $response = Error_Screens::bad_request($request, Session_Link::FAILURE_MESSAGE);
        $response->headers->setCookie(Session_Link::forget_nonce_cookie());

        return $response;
    }
}
