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
 * THE REASON IS FOR CODE, THE MESSAGE IS FOR THE PERSON. reason() answers one of the REASON_*
 * constants, so a login function can act on WHICH failure this was - tell the account owner
 * that a challenge was exhausted, send an expired visitor back to the password form - without
 * matching a sentence. It is never shown: the message stays the only thing a screen prints.
 *
 * See: php artisan rsx:man two_factor
 */
#[Instantiatable]
class Two_Factor_Failed_Exception extends RuntimeException
{
    /** No challenge is pending: the window closed, or the identity it named is gone. */
    public const REASON_WINDOW_EXPIRED = 'window_expired';

    /** The answer was wrong, and the challenge is still open for another. */
    public const REASON_WRONG_ANSWER = 'wrong_answer';

    /**
     * The answer was wrong and it was the challenge's last: the challenge is destroyed and
     * the visitor starts again at the password. Somebody who knows the password failed the
     * second factor repeatedly - the case an application tells the account owner about.
     */
    public const REASON_CHALLENGE_SPENT = 'challenge_spent';

    /** The identity has too many failed second factors across challenges and is locked. */
    public const REASON_IDENTITY_LOCKED = 'identity_locked';

    /** Anything else: a setup that expired, an answer kind the sign-in does not accept. */
    public const REASON_REFUSED = 'refused';

    private string $reason;

    public function __construct(string $message, string $reason = self::REASON_REFUSED)
    {
        parent::__construct($message);

        $this->reason = $reason;
    }

    /** One of the REASON_* constants. */
    public function reason(): string
    {
        return $this->reason;
    }
}
