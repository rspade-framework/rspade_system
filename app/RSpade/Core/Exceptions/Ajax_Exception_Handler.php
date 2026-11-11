<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Http\Request;
use Throwable;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Exceptions\Infrastructure_Outage;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;

/**
 * Ajax_Exception_Handler - the AJAX channel's error policy
 *
 * PRIORITY: 20
 *
 * A failure on a request Rsx_Request_Channel classified as Ajax - one raised outside any
 * endpoint call (the transport, the realm preamble), or an endpoint failure the transport
 * did not already turn into its envelope - is answered with the Ajax envelope, never an
 * HTML page, at HTTP 200 (an outage aside, below). The envelope is Ajax::error_envelope():
 * the same mapping the direct and batch transports apply to a call's own failure, so a
 * coded failure keeps its code (unauthorized, not_found, validation, ...) and anything else
 * is 'fatal', with the detail only for a caller Rsx_Diagnostics admits and an error_id for
 * everybody else.
 *
 * AN INFRASTRUCTURE OUTAGE (Infrastructure_Outage: the lock daemon or the database connection
 * gone) is answered HTTP 500 instead (Ajax::outage_response()): the whole request failed,
 * exactly as a page or an API request does, and the client rejects every call of it with
 * server_error. Ajax::call_envelope() lets an outage through to here rather than answering
 * one call.
 */
class Ajax_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - AJAX handlers run after CLI but before web
     *
     * @return int
     */
    public static function get_priority(): int
    {
        return 20;
    }

    /**
     * Answer an Ajax-channel failure with the envelope.
     *
     * @param Throwable $e
     * @param Request $request
     * @return mixed JSON response on the AJAX channel, null otherwise
     */
    public function handle(Throwable $e, Request $request)
    {
        if (Rsx_Request_Channel::current() !== Rsx_Request_Channel::AJAX) {
            return null;
        }

        // An outage is the request's failure, not a refusal: HTTP 500, built from nothing
        // that needs the database or the lock daemon.
        if (Infrastructure_Outage::is_outage($e)) {
            return Ajax::outage_response($e);
        }

        // A fault outside any call refuses the whole request: a batch gets the envelope
        // under every call_id it sent, so no call is left unanswered.
        return Ajax::request_refusal($request, Ajax::error_envelope($e));
    }
}
