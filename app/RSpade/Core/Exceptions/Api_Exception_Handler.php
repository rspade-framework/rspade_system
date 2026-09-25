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
use App\RSpade\Core\Debug\Rsx_Diagnostics;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;

/**
 * Api_Exception_Handler - render uncaught API-dispatch errors as JSON 500.
 *
 * PRIORITY: 25 (between Ajax at 20 and Playwright at 30)
 *
 * The API channel's error policy: it answers exactly when Rsx_Request_Channel classified
 * the request as the external API, so every failure on that channel - an endpoint that
 * threw, a global middleware that failed before dispatch - and nothing else renders here.
 * An API error must never fall through to an HTML error page: it produces the same bare
 * {"error":{"code","message"}} shape as every other API failure.
 *
 * For a caller Rsx_Diagnostics::caller_sees_detail() admits (a developer, outside
 * production) the message carries the exception text and class. Everybody else gets the
 * generic "Internal server error" and an error_id, with the detail logged under that id.
 */
class Api_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - runs after Ajax (20), before Playwright (30).
     */
    public static function get_priority(): int
    {
        return 25;
    }

    /**
     * Handle the exception only for a request on the API channel.
     */
    public function handle(Throwable $e, Request $request)
    {
        if (Rsx_Request_Channel::current() !== Rsx_Request_Channel::API) {
            return null;
        }

        // A coded failure raised outside the endpoint (a global middleware's 413, ...)
        // keeps its status, exactly as one the endpoint raised does.
        $coded = Api_Dispatcher::coded_error_response($e);
        if ($coded !== null) {
            return $coded;
        }

        if (Rsx_Diagnostics::caller_sees_detail()) {
            return response()->json([
                'error' => [
                    'code' => 'internal_error',
                    'message' => $e->getMessage() . ' (' . get_class($e) . ')',
                ],
            ], 500);
        }

        return response()->json([
            'error' => [
                'code' => 'internal_error',
                'message' => 'Internal server error',
                'error_id' => Rsx_Diagnostics::report_redacted($e, 'api'),
            ],
        ], 500);
    }
}
