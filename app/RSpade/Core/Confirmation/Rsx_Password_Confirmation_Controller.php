<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Confirmation;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\Auth_Throttled_Exception;
use App\RSpade\Core\Confirmation\Rsx_Password_Confirmation;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;

/**
 * Rsx_Password_Confirmation_Controller - the browser's side of "confirm it's you".
 *
 * <Password_Confirmation_Form> posts here, and Rsx_Password_Confirmation.js calls the rest. Every
 * method is a thin wrapper over Rsx_Password_Confirmation, which holds the rules.
 *
 * #[Auth_Realm('any')]: the same endpoints serve a staff page and a portal page, and every
 * answer is about the request's own experience (the facade forks on it). #[Auth('is_logged_in')]
 * is evaluated in that realm, so a confirmation is only ever given to somebody signed in there.
 *
 * Each refusal is a form error carrying a user-safe message: a wrong password, a passkey that
 * did not verify, the sign-in rate limit, impersonation.
 *
 * See rsx:man password_confirmation.
 */
#[Auth('is_logged_in')]
#[Auth_Realm('any')]
class Rsx_Password_Confirmation_Controller extends Rsx_Controller_Abstract
{
    /**
     * What the confirmation can be given with.
     *
     * @return array {has_password, has_passkey, confirmed, minutes}
     */
    #[Ajax_Endpoint]
    public static function options(Request $request, array $params = [])
    {
        return Rsx_Password_Confirmation::options();
    }

    /**
     * Confirm with the password. Turnstile first, then the throttle, then the password.
     *
     * @param array $params {password, __turnstile}
     * @return array {confirmed: true, expires_in_seconds}
     */
    #[Ajax_Endpoint]
    public static function verify(Request $request, array $params = [])
    {
        Rsx_Turnstile::validate($request, $params);

        $password = (string) ($params['password'] ?? '');

        if ($password === '') {
            return response_form_error('Enter your password.', ['password' => 'Enter your password.']);
        }

        try {
            $confirmed = Rsx_Password_Confirmation::confirm_password($password);
        } catch (Auth_Throttled_Exception $e) {
            return response_form_error($e->getMessage());
        }

        if (!$confirmed) {
            return response_form_error('That password is not correct.', ['password' => 'That password is not correct.']);
        }

        return static::_confirmed();
    }

    /**
     * The request args for confirming with one of this identity's passkeys.
     *
     * @return array
     */
    #[Ajax_Endpoint]
    public static function passkey_options(Request $request, array $params = [])
    {
        return Rsx_Password_Confirmation::passkey_options();
    }

    /**
     * Confirm with a passkey assertion.
     *
     * @param array $params {assertion: {id, clientDataJSON, authenticatorData, signature}}
     * @return array {confirmed: true, expires_in_seconds}
     */
    #[Ajax_Endpoint]
    public static function verify_passkey(Request $request, array $params = [])
    {
        $assertion = $params['assertion'] ?? null;

        if (!is_array($assertion)) {
            return response_error(Ajax::ERROR_VALIDATION, 'That passkey response was incomplete. Please try again.');
        }

        try {
            $confirmed = Rsx_Password_Confirmation::confirm_passkey($assertion);
        } catch (Auth_Throttled_Exception $e) {
            return response_form_error($e->getMessage());
        }

        if (!$confirmed) {
            return response_form_error('That passkey could not confirm it is you.');
        }

        return static::_confirmed();
    }

    /**
     * End the confirmation now.
     *
     * @return array {cleared: true}
     */
    #[Ajax_Endpoint]
    public static function clear(Request $request, array $params = [])
    {
        Rsx_Password_Confirmation::clear();

        return ['cleared' => true];
    }

    /**
     * The success answer: the browser keeps the same lifetime the server does.
     */
    private static function _confirmed(): array
    {
        return [
            'confirmed' => true,
            'expires_in_seconds' => Rsx_Password_Confirmation::minutes() * 60,
        ];
    }
}
