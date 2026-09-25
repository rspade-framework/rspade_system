<?php

namespace App\RSpade\Core\Ajax\Exceptions;

use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Exception thrown when an API method reports ERROR_NOT_FOUND through Ajax::internal().
 *
 * WHY IT EXISTS. Ajax::internal() converts a coded error response into an exception, and
 * whoever catches it and answers a client has to convert it BACK into a code
 * (Ajax::error_envelope). Not-found and validation are both form errors, and a client that
 * branches on Ajax.ERROR_NOT_FOUND (the ORM fetch path does) needs the two told apart.
 * This subclass carries the distinction.
 *
 * It EXTENDS AjaxFormErrorException deliberately: every existing catch of that type keeps
 * catching not-found exactly as it did (the CLI ajax runner, app code around
 * Ajax::internal()). Only a caller that wants the distinction - Ajax::error_envelope() -
 * tests for this narrower type first.
 */
#[Instantiatable]
class AjaxNotFoundException extends AjaxFormErrorException
{
    public function __construct($message = "Record not found", array $details = [], $code = 404, \Throwable $previous = null)
    {
        parent::__construct($message, $details, $code, $previous);
    }
}
