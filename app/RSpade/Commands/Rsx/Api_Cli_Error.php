<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

/**
 * An operator-facing failure in a framework CLI command (rsx:api:*, rsx:users:2fa:*): a user
 * that does not resolve, an expiry that cannot be parsed, an ambiguous email.
 *
 * It exists because every one of these commands has to report the SAME failure two ways -
 * a human line, or a JSON envelope a script can parse - and the check that detects the
 * failure is nowhere near the code that knows which. Carrying a stable machine CODE beside
 * the message is what lets a script branch on 'user_ambiguous' without matching English.
 *
 * This is an EXPECTED input failure, not a broken assumption. Nothing else is caught: a
 * genuine fault still bubbles to the handler with its stack trace intact.
 */
class Api_Cli_Error extends \RuntimeException
{
    /**
     * Machine-stable identifier for the failure ('user_not_found', 'expires_invalid', ...).
     * Reported as error.code in --json output; never localized, never reworded.
     */
    public string $error_code;

    public function __construct(string $error_code, string $message)
    {
        parent::__construct($message);

        $this->error_code = $error_code;
    }
}
