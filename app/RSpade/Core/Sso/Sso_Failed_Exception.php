<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use RuntimeException;

/**
 * Sso_Failed_Exception - a federated sign-in did not complete.
 *
 * ITS MESSAGE IS USER-SAFE BY CONTRACT, the same contract Two_Factor_Failed_Exception
 * carries. Every throw site phrases the message for the person at the keyboard, because a
 * login screen is where it lands and it will be rendered directly. That constrains what may
 * go in one: no provider error body, no HTTP status, no email address, no statement about
 * whether an account exists. "We could not complete that sign-in. Please try again." is the
 * whole answer a failed ceremony is entitled to.
 *
 * SAYING MORE WOULD BE AN ENUMERATION ORACLE. "No account is connected to that Google
 * address" tells a stranger which addresses hold accounts here, which is precisely the
 * question the login form is built not to answer. The distinction between "the state did
 * not match", "the provider refused the code" and "that identity is connected to nobody"
 * matters to the log, never to the screen - so the detail goes to Login_History and the
 * application log, and the user gets the one sentence.
 *
 * IT IS NOT THE THROTTLE'S EXCEPTION. Auth_Throttled_Exception means "we did not check",
 * which is a different answer from "that did not work", and the two stay distinguishable
 * all the way up to the controller.
 *
 * IT IS ALSO NOT A CONFIGURATION ERROR. A provider enabled with a missing credential, an
 * unknown provider key, an unlink attempted while impersonating - those are RuntimeExceptions
 * naming what the operator got wrong, because nobody at a keyboard can act on them and the
 * person who can needs the detail.
 *
 * See: php artisan rsx:man sso
 */
class Sso_Failed_Exception extends RuntimeException
{
}
