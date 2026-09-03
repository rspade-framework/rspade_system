<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * Rsx_Two_Factor_Controller - the browser's whole view of the second-factor subsystem.
 *
 * The framework owns second factors, so it owns the endpoints that manage them: the
 * enrollment screens, the settings list and the login challenge all talk to this class, and
 * an application writes no pass-through endpoint of its own. Every method is a thin wrapper
 * over Rsx_Two_Factor - the facade holds the rules, this file holds the transport.
 *
 * IT IS FORCE-INCLUDED IN EVERY BUNDLE. Its JS stub is pushed into every compiled bundle by
 * BundleCompiler (the same mechanism Realtime_Controller and File_Preview_Controller use),
 * because the three screens that drive it live in three different bundles: the challenge is
 * on the LOGIN page (its own bundle, outside the SPA), enrollment is in a settings SPA, and
 * a passkey prompt may be raised from anywhere. A controller reachable from only one bundle
 * would leave "Rsx_Two_Factor_Controller is not defined" on the page that needs it most.
 *
 * TWO GATE POPULATIONS, and the split is the security design:
 *
 *   ENROLLMENT (#[Auth('is_logged_in')]) - adding, listing and removing factors. These
 *   operate on the SIGNED-IN identity and never on one named by an argument; the facade
 *   refuses them outright while impersonating, and so do the mutating endpoints here.
 *
 *   CHALLENGE (#[Auth('public')]) - the half-authenticated state. Between a correct password
 *   and a correct second factor the session is NOT logged in (see
 *   Rsx_Two_Factor::begin_challenge), so a gate demanding a login could never be satisfied
 *   by the very screen that exists to produce one. They are public in the gate sense only:
 *   each answers exclusively from the pending challenge parked on the caller's own session,
 *   and a caller with nothing pending learns nothing.
 *
 * WHAT IS DELIBERATELY NOT HERE: verifying the challenge. Rsx_Two_Factor::verify_challenge()
 * signs the user in, and where they land afterwards is application logic - a redirect the
 * app captured before the login, a portal versus staff destination, an interstitial the app
 * requires. So the APPLICATION owns the verification endpoint, calls verify_challenge()
 * itself, and decides the destination; <Two_Factor_Challenge> is pointed at that endpoint
 * with $controller and $method. A framework endpoint here would have to guess the answer to
 * a question only the app can answer.
 *
 * ERROR SHAPE. Two_Factor_Failed_Exception carries a user-safe message by contract, so it is
 * caught and returned as an ERROR_VALIDATION whose reason IS that message - the component
 * renders it inline beside the input. Nothing else is caught: a RuntimeException from the
 * facade (nobody signed in, impersonating) is a wiring mistake and must surface loudly.
 *
 * See: php artisan rsx:man two_factor
 */
class Rsx_Two_Factor_Controller extends Rsx_Controller_Abstract
{
    // -------------------------------------------------------------------------
    // Enrollment - TOTP
    // -------------------------------------------------------------------------

    /**
     * Start an authenticator-app enrollment: the seed, its otpauth:// URI and the QR code.
     *
     * The seed reaches the browser in plaintext because the user is about to scan or type
     * it. Nothing is written until totp_confirm sees a live code.
     *
     * @return array {secret, otpauth_uri, qr_svg}
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function totp_begin(Request $request, array $params = [])
    {
        return Rsx_Two_Factor::begin_totp_enrollment();
    }

    /**
     * Finish an authenticator-app enrollment by proving a live code.
     *
     * @return array {recovery_codes} - the ONLY time the plaintext codes exist.
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function totp_confirm(Request $request, array $params = [])
    {
        $code = isset($params['code']) ? trim((string) $params['code']) : '';

        if ($code === '') {
            return response_error(Ajax::ERROR_VALIDATION, 'Enter the 6-digit code from your authenticator app.');
        }

        try {
            $codes = Rsx_Two_Factor::confirm_totp_enrollment($code);
        } catch (Two_Factor_Failed_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        return ['recovery_codes' => $codes];
    }

    // -------------------------------------------------------------------------
    // Enrollment - passkeys
    // -------------------------------------------------------------------------

    /**
     * The arguments for navigator.credentials.create(), with the challenge stored
     * server-side. Binary fields are base64url; Rsx_Two_Factor.js decodes them.
     *
     * @return array
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function passkey_register_begin(Request $request, array $params = [])
    {
        return Rsx_Two_Factor::begin_passkey_registration();
    }

    /**
     * Finish registering a passkey.
     *
     * recovery_codes is null when the identity already had a sheet - the UI reveals the
     * one-time code list only when there is genuinely something new to show.
     *
     * @return array {recovery_codes: array|null}
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function passkey_register_confirm(Request $request, array $params = [])
    {
        $attestation = $params['attestation'] ?? null;

        if (!is_array($attestation)) {
            return response_error(Ajax::ERROR_VALIDATION, 'That security key response was incomplete. Please try again.');
        }

        $label = isset($params['label']) ? (string) $params['label'] : null;

        try {
            $codes = Rsx_Two_Factor::confirm_passkey_registration($attestation, $label);
        } catch (Two_Factor_Failed_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        return ['recovery_codes' => $codes];
    }

    // -------------------------------------------------------------------------
    // The settings surface
    // -------------------------------------------------------------------------

    /**
     * Everything a second-factor settings screen renders: the factors as metadata, the
     * unspent recovery-code count, and whether a factor exists at all.
     *
     * ONE call, not three. A settings screen that asked for the list, the count and the
     * enabled flag separately would paint three states that disagree with each other while
     * they land.
     *
     * @return array {credentials, recovery_codes_remaining, is_enabled}
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function credentials_list(Request $request, array $params = [])
    {
        $login_user = static::_identity();

        return [
            'credentials' => Rsx_Two_Factor::list_credentials($login_user),
            'recovery_codes_remaining' => Rsx_Two_Factor::recovery_codes_remaining($login_user),
            'is_enabled' => Rsx_Two_Factor::is_enabled($login_user),
        ];
    }

    /**
     * Remove one factor, and the recovery codes with it if it was the last one.
     *
     * Removing a row that is not this identity's is a no-op in the facade, so the response
     * is the refreshed state either way - a settings screen naming a row that has already
     * gone is a race, not an attack.
     *
     * @return array {credentials, recovery_codes_remaining, is_enabled}
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function credential_remove(Request $request, array $params = [])
    {
        static::_refuse_impersonation();

        $credential_id = isset($params['id']) ? (int) $params['id'] : 0;

        if ($credential_id <= 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'No credential was named.');
        }

        Rsx_Two_Factor::remove_credential(static::_identity(), $credential_id);

        return static::credentials_list($request, []);
    }

    /**
     * Replace the recovery codes and hand back the new plaintext set.
     *
     * The previous sheet stops working the moment this returns, which is what a user who
     * thinks their codes were seen is asking for.
     *
     * @return array {recovery_codes}
     */
    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function recovery_regenerate(Request $request, array $params = [])
    {
        return ['recovery_codes' => Rsx_Two_Factor::regenerate_recovery_codes()];
    }

    // -------------------------------------------------------------------------
    // The login challenge
    // -------------------------------------------------------------------------

    /**
     * What the challenge screen needs to render itself, or null when nothing is pending.
     *
     * NULL PASSES THROUGH AS NULL and is not an error: "no challenge" is the answer a
     * challenge screen gets when the window expired, when the user already signed in, or
     * when they simply navigated here - and the screen's response to all three is the same,
     * to send them back to the login page.
     *
     * @return array|null {email_masked, has_totp, has_passkey}
     */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function challenge_state(Request $request, array $params = [])
    {
        return Rsx_Two_Factor::challenge_pending();
    }

    /**
     * The arguments for navigator.credentials.get() for the pending identity.
     *
     * @return array
     */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function challenge_passkey_options(Request $request, array $params = [])
    {
        try {
            return Rsx_Two_Factor::challenge_passkey_options();
        } catch (Two_Factor_Failed_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
            shouldnt_happen('Rsx_Two_Factor_Controller reached with no login identity behind the is_logged_in gate');
        }

        return $login_user;
    }

    /**
     * Refuse a mutating credential operation performed while impersonating.
     *
     * The facade already refuses every ENROLLMENT path this way; removal is guarded here
     * because it is the other half of the same rule. An impersonator who could strip a
     * victim's second factor has turned impersonation into an authentication backdoor.
     *
     * @return void
     */
    private static function _refuse_impersonation(): void
    {
        if (Session::is_impersonating()) {
            throw new RuntimeException('Two-factor credentials cannot be changed while impersonating another user.');
        }
    }
}
