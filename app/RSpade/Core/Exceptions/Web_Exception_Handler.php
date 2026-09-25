<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Exceptions;

use Illuminate\Http\Request;
use Throwable;
use App\RSpade\Core\Debug\Rsx_Diagnostics;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;
use App\RSpade\Core\Rsx;

/**
 * Web_Exception_Handler - the full-page terminal outcome of an uncaught exception
 *
 * PRIORITY: 1100 (runs LAST)
 *
 * The PAGE channel's error policy, and the terminal handler for a failure outside dispatch (a
 * provider or global middleware that threw). Everything a browsed request can end as is
 * an Error_Screens page: the dispatchers render denials and unmatched routes themselves,
 * and this handler covers an exception that escaped a page's dispatch, which
 * Rsx_Front_Controller hands to the chain exactly once. Nothing here dispatches.
 *
 * IT ANSWERS EVERY CODED FAILURE - every HTTP exception, and the coded exception family
 * (AjaxUnauthorizedException is the 403 / login redirect, never a 500) - through
 * Dispatcher::page_failure_response(), the answer the dispatcher gives at its own seam.
 * Laravel's stock errors/{code}.blade.php views are unreachable from RSX, so a status
 * this handler declined would be the one terminal outcome with no page behind it.
 *
 * A DEVELOPER KEEPS THE DEBUG PAGE. In development mode with config('app.debug')
 * on, and for a caller Rsx_Diagnostics::caller_sees_detail() admits (a signed-in
 * developer, a valid dev-auth request, a loopback caller), this handler declines and
 * Laravel renders the debug error page (Ignition, read-only: config/ignition.php
 * hard-codes runnable solutions and sharing off). Everybody else gets the themed
 * screen, which carries exception detail only for that same caller and an error id
 * otherwise (Error_Screens).
 *
 * app.debug is DERIVED from RSX_MODE (config/app.php); there is no APP_DEBUG env
 * key any more. In development it is therefore always on unless something sets it
 * at runtime - the second half of the condition is kept because that runtime
 * spelling is what makes the "development, debug off" branch reachable and tested.
 *
 * IT SPEAKS FOR THE FULL-PAGE CHANNEL ONLY, and does not re-test for that: the
 * Cli/Ajax/Api/Playwright handlers own their channels at priorities 10-30, so a
 * console command, an ajax call, an API request and a Playwright probe have all
 * been answered before this handler is reached. One place decides per channel.
 */
class Web_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - last in the chain
     *
     * @return int
     */
    public static function get_priority(): int
    {
        return 1100;
    }

    /**
     * Render the themed terminal page for a full-page request
     *
     * @param Throwable $e
     * @param Request $request
     * @return mixed Response, or null to leave the exception to Laravel
     */
    public function handle(Throwable $e, Request $request)
    {
        // A CODED failure - abort() from anywhere in the page channel or from outside
        // dispatch, or the coded exception family (AjaxUnauthorizedException from
        // Permission::require_permission() and Session::terminate_*, AjaxNotFoundException
        // ...) - is an answer, not a crash: the one page answer the dispatcher's own seam
        // gives too (Dispatcher::page_failure_response()).
        $coded = Dispatcher::page_failure_response($e, $request);
        if ($coded !== null) {
            return $coded;
        }

        if (Rsx::is_development() && config('app.debug') && Rsx_Diagnostics::caller_sees_detail()) {
            return null;
        }

        return Error_Screens::fatal($request, $e);
    }
}
