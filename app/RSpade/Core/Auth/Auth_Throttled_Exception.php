<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Auth;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The authentication surface refused this attempt: the client address or the account is over
 * the sign-in rate limit (Login_Throttle, rsx.sessions.login_throttle).
 *
 * THROWN, NOT RETURNED. RsxAuth::verify_credentials() already answers null for "those
 * credentials are wrong", and a throttled request is not that - nothing was checked at all. A
 * second null would make the two indistinguishable, and a login function would report "invalid
 * email or password" to a user whose password may be perfectly correct.
 *
 * CATCH IT, OR LET IT BE A 429. It is an HTTP 429 exception (HttpExceptionInterface, with
 * Retry-After): uncaught, a page
 * or an API call answers 429 and an Ajax call answers the `too_many_requests` error code. A
 * login function CATCHES it and renders getMessage() as an ordinary form error, so the form
 * answers 200 like any other refusal.
 *
 * The MESSAGE is the user-facing string, deliberately identical for every throttled caller: it
 * says nothing about which account exists, how many attempts remain, or how long to wait.
 * retry_after_seconds is available for the Retry-After header or an operator log line, and is
 * deliberately NOT part of the message.
 */
#[Instantiatable]
class Auth_Throttled_Exception extends RuntimeException implements HttpExceptionInterface
{
    /**
     * The one message every throttled caller gets.
     */
    public const MESSAGE = "You're doing that too fast";

    /**
     * Seconds remaining until an attempt would be accepted, when this exception was created.
     */
    public readonly int $retry_after_seconds;

    /**
     * @param int $retry_after_seconds Seconds until an attempt would be accepted
     */
    public function __construct(int $retry_after_seconds = 0)
    {
        parent::__construct(self::MESSAGE);

        $this->retry_after_seconds = $retry_after_seconds;
    }

    /**
     * 429 Too Many Requests - what a page or an API call answers when nothing catches this.
     */
    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * Retry-After, when the wait is known.
     */
    public function getHeaders(): array
    {
        return $this->retry_after_seconds > 0 ? ['Retry-After' => (string) $this->retry_after_seconds] : [];
    }
}
