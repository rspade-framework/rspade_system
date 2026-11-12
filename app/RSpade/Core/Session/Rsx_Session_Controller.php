<?php

namespace App\RSpade\Core\Session;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Lib\Portal\Portal_Session;
use App\RSpade\Lib\Portal\Rsx_Portal;

/**
 * Rsx_Session_Controller - the browser's report that somebody looked at a page.
 *
 * A server-rendered page records its own page view when it renders its bundle. Use of a
 * page that reaches no page route is reported here by the client instead
 * (Rsx_Session.report_page_view(), at most once a minute: after every completed SPA
 * dispatch, on a URL-hash change made in answer to a person's input, and wherever the
 * application's UI acts on a person's input). Either way
 * the result is the same stamp: the one rsx.sessions.*.max_idle_minutes is measured from,
 * and last_seen on the account.
 *
 * Works in both experiences - the request's own decides which identity is stamped.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Rsx_Session_Controller extends Rsx_Controller_Abstract
{
    /**
     * Record a page view for the signed-in identity of this experience.
     *
     * A navigation is JUDGED LIKE A PAGE, not like an Ajax call: there is no grace. If
     * the sign-in has outlived its limits it is ended here and the answer is the
     * session_expired envelope - otherwise somebody who only ever navigates inside the
     * SPA would never meet the limit at all.
     *
     * A caller with nobody signed in gets a plain false: there is nothing to stamp.
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function page_view(Request $request, array $params = [])
    {
        $is_portal = Rsx_Portal::is_portal_request();

        $reason = $is_portal
            ? Portal_Session::enforce_timeouts(true)
            : Session::enforce_timeouts(true);

        if ($reason !== null) {
            return response_error(Ajax::ERROR_SESSION_EXPIRED, Session::timeout_message($reason));
        }

        if ($is_portal) {
            if (!Portal_Session::is_logged_in()) {
                return false;
            }

            Portal_Session::touch_page_view();

            return true;
        }

        if (!Session::is_logged_in()) {
            return false;
        }

        Session::touch_page_view();

        return true;
    }
}
