<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Companions;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Companion\Companion_Control_Exception;
use App\RSpade\Core\Companion\Companion_Daemon_Client;
use App\RSpade\Core\Companion\Companion_Gates;
use App\RSpade\Core\Companion\Rsx_Companion;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Companions screen's endpoints: every declared companion with rsx-companiond's own state,
 * one companion's detail (status, instances, endpoint traffic), and the per-companion verbs a
 * developer takes from the panel - graceful stop, start (releasing that stop), restart, pause
 * and resume.
 *
 * Everything reads through Rsx_Companion, where a developer passes every Companion_Gates gate.
 * The companion's live console is the Core <Companion_Output>. The global desired state
 * (rsx:companion_apps:start / stop) is the command line's alone, as the task system's switch is.
 */
#[Auth('is_sysadmin')]
class _Sys_Companions_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * {companions: [status, ...], daemon: {running, pid, host} | null, maintenance}.
     */
    #[Ajax_Endpoint]
    public static function overview(Request $request, array $params = [])
    {
        $ping = Companion_Daemon_Client::ping();

        return [
            'companions' => Rsx_Companion::all(),
            'daemon' => $ping === null ? null : ['pid' => $ping['pid'] ?? null, 'host' => $ping['host'] ?? null],
            'maintenance' => Rsx_Companion::maintenance_reason(),
        ];
    }

    /**
     * One companion: {status, instances: {live, history}, stats: {endpoints, minutes}}.
     *
     * @param array $params name
     */
    #[Ajax_Endpoint]
    public static function detail(Request $request, array $params = [])
    {
        $name = (string) ($params['name'] ?? '');
        $status = Rsx_Companion::status($name);

        if ($status === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Companion not found');
        }

        return [
            'status' => $status,
            'instances' => Rsx_Companion::instances($name),
            'stats' => ['endpoints' => Rsx_Companion::request_stats($name), 'minutes' => Rsx_Companion::STATS_MINUTES],
        ];
    }

    /**
     * One verb on one companion. Answers its status as the request left it; the outcome arrives
     * on Companion_Changed_Topic.
     *
     * @param array $params name, companion_action (pause, resume, stop, start, restart)
     */
    #[Ajax_Endpoint]
    public static function act(Request $request, array $params = [])
    {
        $name = (string) ($params['name'] ?? '');
        $action = (string) ($params['companion_action'] ?? '');

        if (!in_array($action, Companion_Gates::ACTIONS, true)) {
            return response_error(Ajax::ERROR_VALIDATION, 'companion_action is one of ' . implode(', ', Companion_Gates::ACTIONS));
        }

        if (Rsx_Companion::status($name) === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Companion not found');
        }

        try {
            Rsx_Companion::control($name, $action);
        } catch (Companion_Control_Exception $e) {
            return response_error(Ajax::ERROR_GENERIC, $e->getMessage());
        }

        return ['status' => Rsx_Companion::status($name)];
    }
}
