<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Companion\Companion_Control_Exception;
use App\RSpade\Core\Companion\Companion_Gates;
use App\RSpade\Core\Companion\Rsx_Companion;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Rsx_Companion_Controller - the browser's way to watch a companion application and act on one.
 *
 * Every endpoint goes through Rsx_Companion, which asks Companion_Gates and nothing else decides:
 * the surface is public (#[Auth('public')]) because WHO may watch or act is the application's
 * view and control gates - deny by default, a developer passes. A companion the viewer may not
 * see answers exactly like one that is not declared (anti-enumeration). Staff realm only.
 *
 * JS twin: Rsx_Companion (Rsx_Companion.get(), .output_after(), ...), which <Companion_Output>
 * reads through.
 */
#[Auth('public')]
class Rsx_Companion_Controller extends Rsx_Controller_Abstract
{
    /**
     * Every companion the viewer may see: {companions: [status, ...]} (Rsx_Companion's shape).
     */
    #[Ajax_Endpoint]
    public static function all(Request $request, array $params = [])
    {
        return ['companions' => Rsx_Companion::all()];
    }

    /**
     * One companion's status. @param array $params name
     */
    #[Ajax_Endpoint]
    public static function get(Request $request, array $params = [])
    {
        $status = Rsx_Companion::status((string) ($params['name'] ?? ''));

        return $status ?? response_error(Ajax::ERROR_NOT_FOUND, 'Companion not found');
    }

    /**
     * Live instances and recent ended ones: {live: [...], history: [...]}.
     *
     * @param array $params name, count (optional, 1 to 100)
     */
    #[Ajax_Endpoint]
    public static function instances(Request $request, array $params = [])
    {
        $count = isset($params['count']) && $params['count'] !== '' ? (int) $params['count'] : Rsx_Companion::HISTORY_SHOWN;
        if ($count < 1 || $count > 100) {
            return response_error(Ajax::ERROR_VALIDATION, 'count is 1 to 100');
        }

        $instances = Rsx_Companion::instances((string) ($params['name'] ?? ''), $count);

        return $instances ?? response_error(Ajax::ERROR_NOT_FOUND, 'Companion not found');
    }

    /**
     * Output after an opaque cursor: {lines, last_id, more, trimmed, is_live}.
     *
     * @param array $params name, after_id (optional, a last_id this answered), streams (optional list)
     */
    #[Ajax_Endpoint]
    public static function output(Request $request, array $params = [])
    {
        $cursor = isset($params['after_id']) && $params['after_id'] !== '' && $params['after_id'] !== null ? (string) $params['after_id'] : null;
        $streams = isset($params['streams']) ? array_values((array) $params['streams']) : Rsx_Companion::STREAMS;

        try {
            $answer = Rsx_Companion::output_after((string) ($params['name'] ?? ''), $cursor, $streams);
        } catch (\InvalidArgumentException $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        return $answer ?? response_error(Ajax::ERROR_NOT_FOUND, 'Companion not found');
    }

    /**
     * Endpoint traffic over the last hour: {endpoints: {name: {calls, errors, avg_ms}}, minutes}.
     *
     * @param array $params name
     */
    #[Ajax_Endpoint]
    public static function stats(Request $request, array $params = [])
    {
        $stats = Rsx_Companion::request_stats((string) ($params['name'] ?? ''));

        if ($stats === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Companion not found');
        }

        return ['endpoints' => $stats, 'minutes' => Rsx_Companion::STATS_MINUTES];
    }

    /**
     * An operator verb: pause, resume, stop, start or restart. Answers the companion's status as
     * the request left it; the verb's outcome arrives on Companion_Changed_Topic.
     *
     * @param array $params name, companion_action
     */
    #[Ajax_Endpoint]
    #[Writer]
    public static function act(Request $request, array $params = [])
    {
        $name = (string) ($params['name'] ?? '');
        $action = (string) ($params['companion_action'] ?? '');

        if (!in_array($action, Companion_Gates::ACTIONS, true)) {
            return response_error(Ajax::ERROR_VALIDATION, 'companion_action is one of ' . implode(', ', Companion_Gates::ACTIONS));
        }

        if (!Companion_Gates::can_view($name)) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Companion not found');
        }

        try {
            Rsx_Companion::control($name, $action);
        } catch (Companion_Control_Exception $e) {
            return response_error(Ajax::ERROR_GENERIC, $e->getMessage());
        }

        return Rsx_Companion::status($name);
    }
}
