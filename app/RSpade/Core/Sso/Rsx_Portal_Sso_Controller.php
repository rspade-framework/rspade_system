<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Sso\Rsx_Portal_Sso;
use App\RSpade\Core\Sso\Rsx_Sso;

/**
 * Rsx_Portal_Sso_Controller - the browser's whole view of federated sign-in, in the CLIENT
 * PORTAL realm.
 *
 * The portal twin of Rsx_Sso_Controller: the same ceremony pair and the same Connected
 * Accounts endpoints, over Rsx_Portal_Sso. Read that class's docblock for why the routes are
 * public in the gate sense only, why an unknown provider is a 404, and why the Apple POST leg
 * does no work - all of it holds here word for word.
 *
 * THE CEREMONY ROUTES ARE #[Portal_Route], and that is load-bearing, not cosmetic. A portal
 * ceremony has to run inside PORTAL dispatch: that is where the application declares the
 * portal's site (Portal_Main::init()), and every lookup, link and sign-in in the portal realm
 * is scoped to that declaration. It also has to finish on the host that started it - on a
 * dedicated portal domain the browser's cookie jar for the portal is not the staff host's -
 * which is why the portal callback is its own redirect URI (Rsx_Portal_Sso::callback_url()).
 *
 * The Apple POST leg 303s to the PORTAL callback path, and Rsx_Csrf exempts exactly that path
 * (Rsx_Portal_Sso::apple_callback_path()) beside the staff one.
 *
 * #[Auth_Realm('portal')]: the Ajax endpoints ride the portal's own channel and resolve their
 * gates against Portal_Permission.
 *
 * See: php artisan rsx:man sso
 */
#[Auth_Realm('portal')]
#[Auth('public')]
class Rsx_Portal_Sso_Controller extends Rsx_Controller_Abstract
{
    // -------------------------------------------------------------------------
    // The ceremony
    // -------------------------------------------------------------------------

    /**
     * Start a sign-in: park a state and send the browser to the provider.
     *
     * intent=link is the settings-screen spelling, and it is REFUSED rather than downgraded
     * when nobody is signed in. Quietly turning it into a login would be the worst possible
     * outcome of an expired session: a user pressing "Connect" on a settings page would be
     * signed in as whoever owns the provider account instead, having asked for nothing of the
     * sort. The facade refuses it too; this refusal is earlier and says why.
     *
     * @param Request $request
     * @param array $params Carries the :provider route segment, and intent from the query.
     * @return RedirectResponse
     */
    #[Portal_Route('/_sso/:provider/begin', methods: ['GET'])]
    public static function begin(Request $request, array $params = [])
    {
        $key = static::_provider_key($params);

        $intent = isset($params['intent']) ? (string) $params['intent'] : Rsx_Sso::INTENT_LOGIN;

        if ($intent === Rsx_Sso::INTENT_LINK) {
            if (Portal_Session::is_impersonating()) {
                abort(403, 'Connected accounts cannot be changed while viewing the portal as a client.');
            }

            if (!Portal_Session::is_logged_in()) {
                abort(403, 'Connecting a sign-in provider requires a signed-in identity.');
            }
        }

        return Rsx_Portal_Sso::begin($key, $intent === Rsx_Sso::INTENT_LINK ? Rsx_Sso::INTENT_LINK : Rsx_Sso::INTENT_LOGIN);
    }

    /**
     * Finish a sign-in.
     *
     * GET is the real leg. POST exists for Apple alone and does nothing but hand the same
     * request to GET - see the class docblock, and the exemption's own justification in
     * Rsx_Csrf::enforce(). The three parameters re-emitted are a WHITELIST and not a
     * pass-through: code and state are the ceremony, and `user` is the profile blob Apple
     * sends exactly once, on the first authorization, and never again.
     *
     * @param Request $request
     * @param array $params Carries the :provider route segment.
     * @return RedirectResponse
     */
    #[Portal_Route('/_sso/:provider/callback', methods: ['GET', 'POST'])]
    public static function callback(Request $request, array $params = [])
    {
        if ($request->is_post()) {
            // NOTHING happens here. No session, no state, no provider resolution, no query
            // of any kind. The route segment is re-encoded rather than trusted into a
            // Location header, and an unknown key simply 404s on the GET leg that follows.
            $key = rawurlencode((string) ($params['provider'] ?? ''));

            $carried = [];

            foreach (['code', 'state', 'user'] as $field) {
                $value = $request->input($field);

                if (is_string($value) && $value !== '') {
                    $carried[$field] = $value;
                }
            }

            $url = Rsx_Portal_Sso::base_path() . '/' . $key . '/callback'
                . ($carried === [] ? '' : '?' . http_build_query($carried));

            // 303 and not 302: the browser must switch to GET, which is the whole point.
            return new RedirectResponse($url, 303);
        }

        return Rsx_Portal_Sso::handle_callback(static::_provider_key($params), $request);
    }

    // -------------------------------------------------------------------------
    // The settings surface
    // -------------------------------------------------------------------------

    /**
     * The signed-in identity's connected accounts, as metadata.
     *
     * @return array One row per connection, oldest first.
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    #[Auth('is_logged_in')]
    public static function identities_list(Request $request, array $params = [])
    {
        return Rsx_Portal_Sso::identities_list(static::_identity());
    }

    /**
     * Disconnect one provider account, and answer with the refreshed list.
     *
     * The refreshed list rather than an acknowledgement, for the reason
     * Rsx_Two_Factor_Controller::credential_remove() returns one: a settings screen that
     * removed a row locally and asked for the list separately would paint two states that can
     * disagree. Removing a row that is not this identity's is a no-op in the facade - a stale
     * screen naming a connection that has already gone is a race, not an attack.
     *
     * @return array The refreshed connection list.
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function identity_unlink(Request $request, array $params = [])
    {
        static::_refuse_impersonation();

        $identity_id = isset($params['id']) ? (int) $params['id'] : 0;

        if ($identity_id <= 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'No connection was named.');
        }

        Rsx_Portal_Sso::unlink(static::_identity(), $identity_id);

        return static::identities_list($request, []);
    }

    /**
     * Where to send the browser to connect one more provider account.
     *
     * It hands back a URL rather than performing the redirect, because the caller is an Ajax
     * request and a redirect answered to XMLHttpRequest is followed by the transport, not by
     * the page. The browser navigates itself with window.location, which is what makes the
     * subsequent callback a top-level navigation carrying the SameSite=Lax cookie.
     *
     * @return array {url}
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function link_begin(Request $request, array $params = [])
    {
        static::_refuse_impersonation();

        $key = isset($params['provider']) ? (string) $params['provider'] : '';

        if (!static::_is_live($key)) {
            return response_error(Ajax::ERROR_VALIDATION, 'That sign-in provider is not available.');
        }

        return [
            'url' => Rsx_Portal_Sso::begin_path($key) . '?intent=' . Rsx_Sso::INTENT_LINK,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The :provider route segment, proven live, or a 404.
     *
     * Asking enabled_providers() rather than provider() is deliberate: an unknown key and a
     * switched-off key must be the same answer to the outside world, while a HALF-CONFIGURED
     * provider still throws its RuntimeException naming the literal .env keys. A 404 that
     * swallowed an operator's misconfiguration would be the one failure nobody ever finds.
     *
     * @param array $params
     * @return string
     */
    private static function _provider_key(array $params): string
    {
        $key = isset($params['provider']) ? (string) $params['provider'] : '';

        if (!static::_is_live($key)) {
            abort(404);
        }

        return $key;
    }

    /**
     * Is this key one of the providers this install has switched on?
     *
     * @param string $key
     * @return bool
     */
    private static function _is_live(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        foreach (Rsx_Portal_Sso::enabled_providers() as $provider) {
            if ($provider['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The signed-in portal user.
     *
     * The #[Auth('is_logged_in')] gate has already run, so a null here is not a permission
     * question - it is the gate and the session disagreeing, which is a broken assumption.
     *
     * @return Portal_User_Model
     */
    private static function _identity(): Portal_User_Model
    {
        $portal_user = Portal_Session::get_portal_user();

        if ($portal_user === null) {
            shouldnt_happen('Rsx_Portal_Sso_Controller reached with no portal user behind the is_logged_in gate');
        }

        return $portal_user;
    }

    /**
     * Refuse a change to what an identity is connected to, while impersonating.
     *
     * The facade refuses the same operations one layer down; this refusal is the endpoint's
     * own, for the reason Rsx_Two_Factor_Controller carries one. An impersonator who could
     * attach - or strip - a provider account has turned a support tool into an authentication
     * backdoor, and the user whose account it is would have no way to see it happen.
     *
     * @return void
     */
    private static function _refuse_impersonation(): void
    {
        if (Portal_Session::is_impersonating()) {
            throw new RuntimeException('Connected accounts cannot be changed while viewing the portal as a client.');
        }
    }
}
