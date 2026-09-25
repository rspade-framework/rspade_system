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
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * Rsx_Portal_Two_Factor_Controller - the browser's whole view of the second-factor subsystem,
 * in the CLIENT PORTAL realm.
 *
 * The portal twin of Rsx_Two_Factor_Controller: the same endpoints, the same names, the same
 * two gate populations (enrollment behind the portal's is_logged_in, the challenge and the
 * passwordless begin public in the gate sense only), the same error shape - over
 * Rsx_Portal_Two_Factor. Read that class's docblock for the reasoning; all of it holds here.
 * The names are identical on purpose: Rsx_Two_Factor.js resolves the controller from the
 * page's experience (Rsx_Portal.is_portal()) and calls the same method names either way, so
 * <Passkey_Register>, <Totp_Enrollment>, <Two_Factor_Challenge> and <Passkey_Sign_In> work
 * on a portal page with no argument telling them so.
 *
 * #[Auth_Realm('portal')]: these endpoints are served on the portal's own Ajax channel and
 * their gates resolve against Portal_Permission. A staff page cannot reach them, and the
 * staff controller denies a portal page - the realm seam refuses before any gate runs.
 *
 * IMPERSONATION. "View as Client" is Portal_Session::is_impersonating(); the facade refuses
 * every enrollment under it and credential_remove() refuses removal here. A staff member
 * viewing a client's portal must never be able to add or strip that client's credentials.
 *
 * It is force-included in every bundle for the reason the staff controller is: the portal
 * login page is its own bundle, and carries no application controller that would pull the
 * stub in.
 *
 * See: php artisan rsx:man two_factor
 */
#[Auth_Realm('portal')]
class Rsx_Portal_Two_Factor_Controller extends Rsx_Controller_Abstract
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
        return Rsx_Portal_Two_Factor::begin_totp_enrollment();
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
            $codes = Rsx_Portal_Two_Factor::confirm_totp_enrollment($code);
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
        return Rsx_Portal_Two_Factor::begin_passkey_registration();
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
            $codes = Rsx_Portal_Two_Factor::confirm_passkey_registration($attestation, $label);
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
    #[Portal_Impersonation_Readable]
    #[Auth('is_logged_in')]
    public static function credentials_list(Request $request, array $params = [])
    {
        $login_user = static::_identity();

        return [
            'credentials' => Rsx_Portal_Two_Factor::list_credentials($login_user),
            'recovery_codes_remaining' => Rsx_Portal_Two_Factor::recovery_codes_remaining($login_user),
            'is_enabled' => Rsx_Portal_Two_Factor::is_enabled($login_user),
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

        Rsx_Portal_Two_Factor::remove_credential(static::_identity(), $credential_id);

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
        return ['recovery_codes' => Rsx_Portal_Two_Factor::regenerate_recovery_codes()];
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
        return Rsx_Portal_Two_Factor::challenge_pending();
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
            return Rsx_Portal_Two_Factor::challenge_passkey_options();
        } catch (Two_Factor_Failed_Exception $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Passwordless sign-in
    // -------------------------------------------------------------------------

    /**
     * The arguments for navigator.credentials.get() for a PASSWORDLESS sign-in: no identity
     * named, no allowCredentials list, user verification required. The challenge parks on
     * the caller's own session.
     *
     * The verification is the application's endpoint - see the class docblock.
     *
     * @return array
     */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function passkey_login_options(Request $request, array $params = [])
    {
        return Rsx_Portal_Two_Factor::begin_passkey_login();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
            shouldnt_happen('Rsx_Portal_Two_Factor_Controller reached with no portal user behind the is_logged_in gate');
        }

        return $portal_user;
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
        if (Portal_Session::is_impersonating()) {
            throw new RuntimeException('Two-factor credentials cannot be changed while viewing the portal as a client.');
        }
    }
}
