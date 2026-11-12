<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Http\Request;
use Throwable;
use App\RSpade\Core\Api\Api_Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;
use App\RSpade\Lib\Developer\Debug\Rsx_Diagnostics;

/**
 * Companion_Exception_Handler - render uncaught companion-channel errors as JSON 500.
 *
 * PRIORITY: 26 (after API at 25, before Playwright at 30)
 *
 * The companion channel's error policy: the API's {"error":{"code","message"}} shape, never
 * an HTML page. There is no caller to show detail to, so the detail is logged under the
 * error_id and the response carries only that.
 */
class Companion_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - runs after API (25), before Playwright (30).
     */
    public static function get_priority(): int
    {
        return 26;
    }

    /**
     * Handle the exception only for a request on the companion channel.
     */
    public function handle(Throwable $e, Request $request)
    {
        if (Rsx_Request_Channel::current() !== Rsx_Request_Channel::COMPANION) {
            return null;
        }

        $coded = Api_Dispatcher::coded_error_response($e);
        if ($coded !== null) {
            return $coded;
        }

        return response()->json([
            'error' => [
                'code' => 'internal_error',
                'message' => 'Internal server error',
                'error_id' => Rsx_Diagnostics::report_redacted($e, 'companion'),
            ],
        ], 500);
    }
}
