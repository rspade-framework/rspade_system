<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Locks;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Locks\Lock_Status;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Locks screen's endpoints: what rsx-lockd holds and queues right now, and ending a hung
 * holder. Both are the CORE Lock_Status - the same snapshot and the same identity checks as
 * `php artisan rsx:locks:status [--kill-holder=<lock>]`, so the panel adds nothing of its own.
 */
#[Auth('is_sysadmin')]
class _Sys_Locks_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * Lock_Status::snapshot(): {available, reason?, endpoint, host, stall_seconds,
     * wait_notice_seconds, connections, counters, locks[], semaphores[], pools[]}.
     */
    #[Ajax_Endpoint]
    public static function snapshot(Request $request, array $params = [])
    {
        return Lock_Status::snapshot();
    }

    /**
     * SIGTERM the process holding one lock, after Lock_Status::kill_holder() proves every
     * holder is on this host, is not this request's process, and is still the process that
     * took the lock. A refusal answers ERROR_GENERIC with the reason; nothing was signalled.
     *
     * @param array $params name - the lock as the snapshot names it
     */
    #[Ajax_Endpoint]
    #[Writer]
    public static function kill_holder(Request $request, array $params = [])
    {
        $result = Lock_Status::kill_holder((string) ($params['name'] ?? ''));

        if (!$result['ok']) {
            return response_error(Ajax::ERROR_GENERIC, $result['message']);
        }

        return $result;
    }
}
