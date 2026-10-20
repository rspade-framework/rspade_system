<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use RuntimeException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * The credentials are correct, and the sign-in is refused until the password is reset.
 *
 * Thrown by the credential verifiers - RsxAuth::verify_credentials() (staff) and
 * Portal_Auth::verify_credentials() (client portal) - for an identity whose
 * password_reset_required flag is set, and ONLY once the password has matched (and, for staff,
 * once an active site membership was found). So it tells the caller nothing a guesser could
 * use: whoever sees it already proved they know the password, the same property that lets a
 * second-factor challenge announce itself. Every outcome before that point - an unknown
 * address, a wrong password, no usable membership - still collapses to null.
 *
 * THROWN, NOT RETURNED, like Auth_Throttled_Exception: null already means "those credentials
 * are wrong", and this is not that. A login function catches it and shows the visitor the way
 * to a new password (a forgot-password flow, or whom to ask). The refusal is already recorded
 * (STATUS_FAILED_PASSWORD_RESET_REQUIRED in the realm's login history) by the time the caller
 * sees it.
 *
 * $identity is the identity the credentials proved (a Login_User_Model or a
 * Portal_User_Model), for a caller that starts the reset itself. It is NOT signed in.
 *
 * The flag refuses the PASSWORD door only. A passkey or a federated sign-in never presents
 * the password the flag distrusts, so neither consults it.
 */
#[Instantiatable]
class Auth_Password_Reset_Required_Exception extends RuntimeException
{
    /**
     * The message every caller gets; a login function may render it as is, or say more.
     */
    public const MESSAGE = 'Your password must be reset before you can sign in.';

    /**
     * The identity whose credentials were proven - never signed in by the verifier.
     */
    public readonly Rsx_Model_Abstract $identity;

    /**
     * @param Rsx_Model_Abstract $identity The identity the credentials proved
     */
    public function __construct(Rsx_Model_Abstract $identity)
    {
        parent::__construct(self::MESSAGE);

        $this->identity = $identity;
    }
}
