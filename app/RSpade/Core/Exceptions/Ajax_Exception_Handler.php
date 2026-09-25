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
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;

/**
 * Ajax_Exception_Handler - the AJAX channel's error policy
 *
 * PRIORITY: 20
 *
 * A failure on a request Rsx_Request_Channel classified as Ajax - one raised outside any
 * endpoint call (the transport, the realm preamble), or an endpoint failure the transport
 * did not already turn into its envelope - is answered with the Ajax envelope, never an
 * HTML page, at HTTP 200. The envelope is Ajax::error_envelope(): the same mapping the
 * direct and batch transports apply to a call's own failure, so a coded failure keeps its
 * code (unauthorized, not_found, validation, ...) and anything else is 'fatal', with the
 * detail only for a caller Rsx_Diagnostics admits and an error_id for everybody else.
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

        return response()->json(Ajax::error_envelope($e), 200);
    }
}
