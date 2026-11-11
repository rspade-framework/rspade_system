<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Rsx_User_Facing_Exception - a failure whose MESSAGE is a sentence written for the person
 * using the application, shown to EVERY caller when it goes unhandled instead of the
 * channel's generic "An unexpected error occurred" answer.
 *
 *     } catch (ConnectException $e) {
 *         throw new Rsx_User_Facing_Exception('The payment provider did not answer. Please try again in a few minutes.', $e);
 *     }
 *
 * It changes only the words. The failure is still a fault: it is logged under an error id
 * (with $previous, which carries the internals), the Ajax call still answers 'fatal' (a form
 * shows the sentence in its <Form_Errors />, any other call in the registered error dialog),
 * the external API still answers 500 internal_error, a page still renders the 500 screen. A
 * caller the framework admits as a developer (Rsx_Diagnostics::caller_sees_detail()) also
 * gets the file and line.
 *
 * The message is DELIBERATELY user-safe: a complete sentence, no class names, paths,
 * hostnames, SQL or identifiers - it is shown to an anonymous visitor in strict production.
 * Throw it (or a subclass) for a failure the user can act on; everything else keeps the
 * generic answer.
 *
 * Rsx_Diagnostics::user_message() is the one resolver every channel asks. It also answers
 * Infrastructure_Outage::USER_MESSAGE for an outage, which is never this class (a lost
 * database connection is a PDOException).
 *
 * rsx:man error_handling (USER-SAFE MESSAGES).
 */
#[Instantiatable]
class Rsx_User_Facing_Exception extends RuntimeException
{
    /**
     * @param string $user_message The sentence shown to the user
     * @param Throwable|null $previous The failure behind it, logged with it
     */
    public function __construct(string $user_message, ?Throwable $previous = null)
    {
        parent::__construct($user_message, 0, $previous);
    }

    /**
     * The sentence shown to the user in place of the generic error.
     *
     * @return string
     */
    public function user_message(): string
    {
        return $this->getMessage();
    }
}
