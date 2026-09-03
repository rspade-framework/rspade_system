<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use RuntimeException;

/**
 * Two_Factor_Failed_Exception - a second-factor verification did not succeed.
 *
 * ITS MESSAGE IS USER-SAFE BY CONTRACT. Every throw site phrases the message for the person
 * at the keyboard, because a login screen has nowhere else to put it and will render this
 * text directly. That constrains what may go in one: no identifier, no count of remaining
 * attempts, no hint about WHICH check failed. "That code is not valid" is the whole answer
 * a failed challenge is entitled to - saying more turns the challenge into an oracle that
 * tells an attacker whether the account has a passkey, how many recovery codes are left, or
 * whether they are even attacking a real account.
 *
 * IT IS NOT THE THROTTLE'S EXCEPTION. Auth_Throttled_Exception means "we did not check",
 * which is a different answer from "that was wrong", and the two must stay distinguishable
 * all the way up to the login function. See Rsx_Two_Factor::verify_challenge().
 *
 * See: php artisan rsx:man two_factor
 */
class Two_Factor_Failed_Exception extends RuntimeException
{
}
