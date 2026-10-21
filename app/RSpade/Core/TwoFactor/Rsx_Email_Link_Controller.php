<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Rsx_Signed_Url;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor_Abstract;

/**
 * Rsx_Email_Link_Controller - where the link in an emailed sign-in code lands
 * (Rsx_Two_Factor_Abstract::issued_link()).
 *
 * Declared in BOTH route tables on the one handler, like Session_Link_Controller's legs: a
 * staff link is minted for APP_URL's host and a portal link for the portal's (under its
 * prefix), the request's realm picks the facade, and the URL's signature is bound to the
 * host it was minted for, so a link presented on the other host is refused.
 *
 * IT ONLY READS. Mail scanners and link previewers fetch every URL in a message before the
 * recipient sees it, so a GET that spent the link, counted a failure or signed anybody in
 * would be spent or tripped by the scanner. The answer is one of three, none of which changes
 * anything:
 *
 *   the browser whose challenge it is   redirect to the challenge page with the token in the
 *                                       URL FRAGMENT (#rsx_email_link=...), where
 *                                       <Two_Factor_Challenge> submits it to the application's
 *                                       verification endpoint - an ordinary Ajax POST, CSRF
 *                                       and all. A fragment never reaches a server, so the
 *                                       token appears in no access log after this request.
 *   any other browser                   a notice: the sign-in was started elsewhere, use the
 *                                       6-digit code from the email there. Not an attempt; the
 *                                       code stays live.
 *   expired, used, or not a link        a notice: sign in again.
 *
 * #[Auth('public')]: the visitor is by construction not signed in (the challenge signed the
 * realm out), and the link authenticates nothing by itself - it is redeemed only through
 * verify_challenge() on the browser that holds the challenge.
 *
 * Both notices carry Referrer-Policy: no-referrer, so the token in this URL's path is not
 * handed to whatever the page links to.
 */
#[Auth('public')]
class Rsx_Email_Link_Controller extends Rsx_Controller_Abstract
{
    /** The notice for a live link opened in a browser other than the one that signed in. */
    public const OTHER_BROWSER_MESSAGE = 'This sign-in was started in a different browser. '
        . 'Enter the 6-digit code from the email in the browser where you signed in.';

    /** The notice for a link that answers nothing any more. */
    public const EXPIRED_MESSAGE = 'This link has expired or was already used. Sign in again.';

    /**
     * Open an email sign-in link.
     */
    #[Route('/_email_link/:token', methods: ['GET'])]
    #[Portal_Route('/_email_link/:token', methods: ['GET'])]
    public static function open(Request $request, array $params = [])
    {
        /** @var class-string<Rsx_Two_Factor_Abstract> $facade */
        $facade = Rsx_Portal::is_portal_request() ? Rsx_Portal_Two_Factor::class : Rsx_Two_Factor::class;

        $token = (string) ($params['token'] ?? '');
        $signature = $request->query('s');

        if (!is_string($signature)
            || !Rsx_Signed_Url::verify(Rsx_Two_Factor_Abstract::EMAIL_LINK_PURPOSE, $token, $request->getHost(), $signature)) {
            return static::__notice($request, 410, 'Link expired', self::EXPIRED_MESSAGE);
        }

        $state = $facade::_email_link_state($token);

        if ($state['state'] === Rsx_Two_Factor_Abstract::EMAIL_LINK_SAME_BROWSER) {
            $fragment = http_build_query([Rsx_Two_Factor_Abstract::EMAIL_LINK_FRAGMENT_KEY => $token]);

            return redirect($state['return_url'] . '#' . $fragment)
                ->header('Referrer-Policy', 'no-referrer')
                ->header('Cache-Control', 'no-store');
        }

        if ($state['state'] === Rsx_Two_Factor_Abstract::EMAIL_LINK_OTHER_BROWSER) {
            return static::__notice($request, 409, 'Use the code instead', self::OTHER_BROWSER_MESSAGE);
        }

        return static::__notice($request, 410, 'Link expired', self::EXPIRED_MESSAGE);
    }

    /**
     * A notice page through the error funnel (the application's own /error/ page for the
     * status when it declares one, the framework's otherwise), kept out of caches and
     * referrers.
     */
    private static function __notice(Request $request, int $status, string $title, string $message)
    {
        $response = Error_Screens::http_status($request, $status, $message, $title);
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
