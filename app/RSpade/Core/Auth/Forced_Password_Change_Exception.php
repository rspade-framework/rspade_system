<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use RuntimeException;

/**
 * The forced password change at sign-in was refused, and nothing changed.
 *
 * Thrown by RsxAuth::complete_forced_password_change() and its portal twin,
 * Portal_Auth::complete_forced_password_change(). ITS MESSAGE IS USER-SAFE BY CONTRACT: the
 * "Choose a new password" page renders it as is. reason() answers one of the REASON_*
 * constants, for code that acts on which refusal this was (an expired step goes back to the
 * sign-in page; a reused password is an error on the new-password field).
 *
 * NOT THE THROTTLE'S EXCEPTION: Auth_Throttled_Exception means "we did not check" and stays
 * its own answer all the way up.
 *
 * See: php artisan rsx:man session (FORCED PASSWORD CHANGE AT SIGN-IN)
 */
#[Instantiatable]
class Forced_Password_Change_Exception extends RuntimeException
{
    /**
     * Nothing is pending on this browser: never begun, already completed, abandoned, signed
     * out, or the identity's password changed some other way since it was begun (the proof
     * the step was holding is stale). The visitor signs in again.
     */
    public const REASON_NOTHING_PENDING = 'nothing_pending';

    /** The new password is empty. */
    public const REASON_EMPTY = 'empty';

    /** The new password IS the current password: a forced change means a new one. */
    public const REASON_UNCHANGED = 'unchanged';

    public const MESSAGE_NOTHING_PENDING = 'Your sign-in has expired. Sign in again to choose a new password.';

    public const MESSAGE_EMPTY = 'Choose a new password.';

    public const MESSAGE_UNCHANGED = 'Choose a password different from your current one.';

    private string $reason;

    public function __construct(string $reason)
    {
        $message = match ($reason) {
            self::REASON_NOTHING_PENDING => self::MESSAGE_NOTHING_PENDING,
            self::REASON_EMPTY => self::MESSAGE_EMPTY,
            self::REASON_UNCHANGED => self::MESSAGE_UNCHANGED,
        };

        parent::__construct($message);

        $this->reason = $reason;
    }

    /** One of the REASON_* constants. */
    public function reason(): string
    {
        return $this->reason;
    }
}
