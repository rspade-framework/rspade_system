<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use RuntimeException;

/**
 * The authentication surface refused this client: too many recent failures from its IP.
 *
 * THROWN, NOT RETURNED. RsxAuth::attempt() already answers false for "those credentials are
 * wrong", and a throttled request is not that - nothing was checked at all. A second false
 * would make the two indistinguishable, and a login function would report "invalid email or
 * password" to a user whose password may be perfectly correct.
 *
 * The MESSAGE is the user-facing string, deliberately identical for every throttled caller:
 * it says nothing about which account exists, how many attempts remain, or how long the
 * lockout is. A login function renders getMessage() as its form error.
 *
 * retry_after_seconds is how long the lockout still has to run - available for a Retry-After
 * header or an operator log line. It is deliberately NOT part of the message: telling an
 * attacker exactly when to resume is free scheduling information.
 */
#[Instantiatable]
class Auth_Throttled_Exception extends RuntimeException
{
    /**
     * The one message every throttled caller gets.
     */
    public const MESSAGE = "You're doing that too fast";

    /**
     * Seconds remaining on the lockout when this exception was created.
     */
    public readonly int $retry_after_seconds;

    /**
     * @param int $retry_after_seconds Seconds remaining on the lockout
     */
    public function __construct(int $retry_after_seconds = 0)
    {
        parent::__construct(self::MESSAGE);

        $this->retry_after_seconds = $retry_after_seconds;
    }
}
