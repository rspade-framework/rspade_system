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
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Rsx_Sso;

/**
 * Rsx_Sso_Controller - the browser's whole view of federated sign-in.
 *
 * Two surfaces live here and they have nothing in common but a subsystem. The CEREMONY is a
 * pair of ordinary browser navigations - the user leaves for the provider and comes back -
 * and the SETTINGS surface is a handful of Ajax endpoints a Connected Accounts screen drives.
 * Every method is a thin wrapper over Rsx_Sso: the facade holds the rules, this file holds
 * the transport.
 *
 * WHY THE ROUTES ARE #[Auth('public')], AND WHERE AUTHORIZATION ACTUALLY LIVES.
 *
 * A sign-in ceremony is HALF-AUTHENTICATED by definition, exactly as a second-factor
 * challenge is. /_sso/google/begin is requested by somebody who is not signed in - that is
 * the entire point of it - and /_sso/google/callback is a navigation the PROVIDER caused, so
 * a gate demanding a login could never be satisfied by the two URLs whose job is to produce
 * one. They are public in the GATE sense only. What actually guards them is not a gate at
 * all, and could not be one:
 *
 *   - begin() parks a random state and redirects. It authenticates nobody, reads nothing
 *     about anybody, and reveals only which providers this install has switched on - which
 *     is already painted on the login page.
 *   - callback() is guarded by the PARKED STATE. A caller who did not start a ceremony in
 *     this browser supplies a state nobody parked, and Rsx_Sso::handle_callback() ends there,
 *     after having already spent a Login_Throttle budget on the attempt.
 *
 * An UNKNOWN or DISABLED provider key is a 404 and not a 403 or a helpful message, because
 * "microsoft is configured here but switched off" is a fact about the install that a stranger
 * walking /_sso/ URLs has no reason to collect. The two answers are deliberately identical -
 * the same posture Rsx_Sso::provider() takes one layer down.
 *
 * THE APPLE POST LEG DOES NO WORK, AND THAT IS A SECURITY PROMISE THIS FILE KEEPS.
 *
 * Sign in with Apple returns its authorization by POSTing the browser back here
 * (response_mode=form_post, which Apple requires whenever 'name' or 'email' is scoped). That
 * POST is cross-site: it carries Origin: https://appleid.apple.com and arrives WITHOUT the
 * SameSite=Lax `rsx` cookie, so there is no session, no token, and nothing a CSRF check could
 * pass. Rsx_Csrf::enforce() therefore exempts exactly one path - Rsx_Sso::APPLE_CALLBACK_PATH
 * - and the justification written in that exemption is that this leg immediately 303s to the
 * same path as a GET, carrying code, state and Apple's one-shot `user` blob on the query
 * string. The top-level GET navigation that follows DOES carry the Lax cookie, and every
 * decision - the state check, the token exchange, the branch - happens there.
 *
 * So the POST branch below reads no session, checks no state, resolves no provider and
 * touches no database. It re-emits three named parameters and returns. Anything added to it
 * would be work performed for an unauthenticated cross-site caller, and would silently make
 * the exemption's docblock a lie.
 *
 * IT IS FORCE-INCLUDED IN EVERY BUNDLE, for the same reason Rsx_Two_Factor_Controller is: the
 * screens that drive it live in different bundles - the buttons render on the LOGIN page,
 * which is its own bundle outside the SPA, and the Connected Accounts list is in a settings
 * SPA - while its PHP source is framework core and therefore in no bundle's file set. Without
 * the force-include, the login page would carry "Rsx_Sso_Controller is not defined".
 *
 * ERROR SHAPE. The ceremony NEVER returns an error to the browser: every path out of
 * handle_callback() is a redirect, because this is a navigation and there is nowhere to render
 * one. A failed sign-in lands back on the login page with a flash. The Ajax surface uses the
 * ordinary envelope, and nothing is caught here - a RuntimeException from the facade (nobody
 * signed in behind an is_logged_in gate, an impersonated session) is a wiring mistake and must
 * surface loudly.
 *
 * See: php artisan rsx:man sso
 */
#[Auth('public')]
class Rsx_Sso_Controller extends Rsx_Controller_Abstract
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
    #[Route('/_sso/:provider/begin', methods: ['GET'])]
    public static function begin(Request $request, array $params = [])
    {
        $key = static::_provider_key($params);

        $intent = isset($params['intent']) ? (string) $params['intent'] : Rsx_Sso::INTENT_LOGIN;

        if ($intent === Rsx_Sso::INTENT_LINK) {
            if (Session::is_impersonating()) {
                abort(403, 'Connected accounts cannot be changed while impersonating another user.');
            }

            if (!Session::is_logged_in()) {
                abort(403, 'Connecting a sign-in provider requires a signed-in identity.');
            }
        }

        return Rsx_Sso::begin($key, $intent === Rsx_Sso::INTENT_LINK ? Rsx_Sso::INTENT_LINK : Rsx_Sso::INTENT_LOGIN);
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
    #[Route('/_sso/:provider/callback', methods: ['GET', 'POST'])]
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

            $url = Rsx_Sso::BASE_PATH . '/' . $key . '/callback'
                . ($carried === [] ? '' : '?' . http_build_query($carried));

            // 303 and not 302: the browser must switch to GET, which is the whole point.
            return new RedirectResponse($url, 303);
        }

        return Rsx_Sso::handle_callback(static::_provider_key($params), $request);
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
    #[Auth('is_logged_in')]
    public static function identities_list(Request $request, array $params = [])
    {
        return Rsx_Sso::identities_list(static::_identity());
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

        Rsx_Sso::unlink(static::_identity(), $identity_id);

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
            'url' => Rsx_Sso::begin_path($key) . '?intent=' . Rsx_Sso::INTENT_LINK,
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

        foreach (Rsx_Sso::enabled_providers() as $provider) {
            if ($provider['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The signed-in login identity.
     *
     * The #[Auth('is_logged_in')] gate has already run, so a null here is not a permission
     * question - it is the gate and the session disagreeing, which is a broken assumption.
     *
     * @return Login_User_Model
     */
    private static function _identity(): Login_User_Model
    {
        $login_user = Session::get_login_user();

        if ($login_user === null) {
            shouldnt_happen('Rsx_Sso_Controller reached with no login identity behind the is_logged_in gate');
        }

        return $login_user;
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
        if (Session::is_impersonating()) {
            throw new RuntimeException('Connected accounts cannot be changed while impersonating another user.');
        }
    }
}
