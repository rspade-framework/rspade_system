<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Task\Task_Gates;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Rsx_Task_Controller - the browser's way to read a task run and act on one, in both realms.
 *
 * Every endpoint asks Task_Gates and nothing else decides: the surface is public
 * (#[Auth('public')]) because WHO may see or act on a run is the application's view and
 * control gates - deny by default, a developer passes. A run the viewer may not see answers
 * exactly like a run that does not exist (anti-enumeration).
 *
 * JS twin: Rsx_Task (Rsx_Task.get(), .output_after(), .watch(), ...), which the task widgets
 * (Task_Status_Badge, Task_Output, Task_Report, Task_Report_Browser) are built on.
 *
 * Read endpoints are #[Portal_Impersonation_Readable]; the lifecycle endpoints are not, so a
 * View-as-Client session can watch a run and never stop one.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class Rsx_Task_Controller extends Rsx_Controller_Abstract
{
    /** Output lines / messages per read; the caller reads on from the last id. */
    const READ_PAGE = 1000;

    /**
     * One run's status: Task_Run_Model::to_status_array() plus `can` - which lifecycle actions
     * the viewer may perform on it.
     *
     * @param array $params task_id
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function get(Request $request, array $params = [])
    {
        $task = static::__viewable($params);
        if ($task === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        return static::__present($task);
    }

    /**
     * One report's value. kind: state_json, queue, summary, messages (the first page),
     * or any column report (status_text, progress, progress_count, eta, heartbeat, return_code)
     * - which to_status_array() carries too.
     *
     * queue takes an optional `limit` (a whole number >= 1): only the FIRST that many items -
     * the oldest, the head of the queue - are read and sent, and `total` says how many the
     * queue holds, so a viewer sized to show N rows asks for N. `total` is null for every
     * other kind.
     *
     * @param array $params task_id, kind, limit (queue only)
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function report(Request $request, array $params = [])
    {
        $task = static::__viewable($params);
        if ($task === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        $kind = (string) ($params['kind'] ?? '');

        $limit = null;
        if (isset($params['limit']) && $params['limit'] !== '') {
            $limit = filter_var($params['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($limit === false) {
                return response_error(Ajax::ERROR_VALIDATION, 'limit must be a whole number of 1 or more');
            }
        }

        $value = match ($kind) {
            'state_json' => $task->state(),
            'queue' => $task->queue($limit),
            'summary' => $task->summary(),
            'messages' => $task->messages_after(null, self::READ_PAGE),
            'status_text' => $task->status_text(),
            'progress' => $task->progress_percent(),
            'progress_count' => $task->progress_count(),
            'eta' => $task->eta_at(),
            'heartbeat' => $task->to_status_array()['last_heartbeat_at'],
            'return_code' => $task->return_code !== null ? (int) $task->return_code : null,
            default => null,
        };

        if ($value === null && !in_array($kind, ['state_json', 'queue', 'summary', 'messages', 'status_text', 'progress', 'progress_count', 'eta', 'heartbeat', 'return_code'], true)) {
            return response_error(Ajax::ERROR_VALIDATION, "Unknown task report '{$kind}'");
        }

        $total = $kind === 'queue' && is_array($value) ? $task->queue_depth() : null;

        return ['kind' => $kind, 'value' => $value, 'total' => $total, 'status' => static::__present($task)];
    }

    /**
     * Output lines after a cursor: {lines: [{id, stream, line, at}], last_id, more}.
     *
     * @param array $params task_id, after_id (optional), streams (optional list)
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function output(Request $request, array $params = [])
    {
        $task = static::__viewable($params);
        if ($task === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        $after_id = isset($params['after_id']) && $params['after_id'] !== '' ? (int) $params['after_id'] : null;
        $streams = isset($params['streams']) ? (array) $params['streams'] : ['stdout', 'stderr', 'operator'];

        $lines = $task->output_after($after_id, $streams, self::READ_PAGE);

        return [
            'lines' => $lines,
            'last_id' => $lines !== [] ? end($lines)['id'] : $after_id,
            'more' => count($lines) === self::READ_PAGE,
            'is_live' => $task->is_live(),
        ];
    }

    /**
     * Messages after a cursor: {messages: [{id, body, at}], last_id, more}.
     *
     * @param array $params task_id, after_id (optional)
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function messages(Request $request, array $params = [])
    {
        $task = static::__viewable($params);
        if ($task === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }

        $after_id = isset($params['after_id']) && $params['after_id'] !== '' ? (int) $params['after_id'] : null;
        $messages = $task->messages_after($after_id, self::READ_PAGE);

        return [
            'messages' => $messages,
            'last_id' => $messages !== [] ? end($messages)['id'] : $after_id,
            'more' => count($messages) === self::READ_PAGE,
        ];
    }

    /**
     * The newest runs matching a filter (Task_Run_Model::search_query() keys) that the viewer
     * may see, at most 1000: {tasks: [status, ...]}.
     *
     * @param array $params filter (object)
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function find(Request $request, array $params = [])
    {
        $query = static::__search($params);
        if (!$query instanceof \Illuminate\Database\Eloquent\Builder) {
            return $query;
        }

        $rows = $query->orderByDesc('id')->limit(Task_Run_Model::SEARCH_PAGE_SIZE)->get();

        return ['tasks' => $rows->map(fn ($task) => $task->to_status_array())->all()];
    }

    /**
     * One page of the runs matching a filter that the viewer may see, newest first:
     * {tasks: [...], next_cursor}. Pass next_cursor back for the next page (null: no more).
     *
     * @param array $params filter (object), cursor (optional), page_size (optional, max 1000)
     */
    #[Ajax_Endpoint]
    #[Portal_Impersonation_Readable]
    public static function page(Request $request, array $params = [])
    {
        $query = static::__search($params);
        if (!$query instanceof \Illuminate\Database\Eloquent\Builder) {
            return $query;
        }

        $page_size = isset($params['page_size']) ? (int) $params['page_size'] : Task_Run_Model::SEARCH_PAGE_SIZE;
        if ($page_size < 1 || $page_size > Task_Run_Model::SEARCH_PAGE_SIZE) {
            return response_error(Ajax::ERROR_VALIDATION, 'page_size is 1 to ' . Task_Run_Model::SEARCH_PAGE_SIZE);
        }

        $cursor = isset($params['cursor']) && $params['cursor'] !== null ? (string) $params['cursor'] : '';
        if ($cursor !== '') {
            if (!ctype_digit($cursor)) {
                return response_error(Ajax::ERROR_VALIDATION, 'Invalid cursor');
            }
            $query->where('id', '<', (int) $cursor);
        }

        $rows = $query->orderByDesc('id')->limit($page_size + 1)->get()->all();
        $more = count($rows) > $page_size;
        $rows = array_slice($rows, 0, $page_size);

        return [
            'tasks' => array_map(fn ($task) => $task->to_status_array(), $rows),
            'next_cursor' => $more ? (string) end($rows)->id : null,
        ];
    }

    /** Graceful stop. @param array $params task_id, explanation (optional) */
    #[Ajax_Endpoint]
    public static function stop(Request $request, array $params = [])
    {
        return static::__act($params, 'stop', fn (Task_Run_Model $task) => $task->request_stop(static::__explanation($params)));
    }

    /** Force stop. @param array $params task_id, grace_seconds (optional), explanation (optional) */
    #[Ajax_Endpoint]
    public static function force_stop(Request $request, array $params = [])
    {
        $grace = isset($params['grace_seconds']) && $params['grace_seconds'] !== '' ? (int) $params['grace_seconds'] : null;
        if ($grace !== null && $grace < 0) {
            return response_error(Ajax::ERROR_VALIDATION, 'grace_seconds is 0 or more');
        }

        return static::__act($params, 'force_stop', fn (Task_Run_Model $task) => $task->force_stop($grace, static::__explanation($params)));
    }

    /** Force kill. @param array $params task_id, explanation (optional) */
    #[Ajax_Endpoint]
    public static function force_kill(Request $request, array $params = [])
    {
        return static::__act($params, 'force_kill', fn (Task_Run_Model $task) => $task->force_kill(static::__explanation($params)));
    }

    /** Cancel a pending run. @param array $params task_id, explanation (optional) */
    #[Ajax_Endpoint]
    public static function cancel(Request $request, array $params = [])
    {
        return static::__act($params, 'cancel', fn (Task_Run_Model $task) => $task->cancel(static::__explanation($params)));
    }

    /**
     * Run a finished run again: {task_id: the new run's id} (an existing pending run's, for a
     * coalesced task).
     *
     * @param array $params task_id
     */
    #[Ajax_Endpoint]
    public static function rerun(Request $request, array $params = [])
    {
        $task = static::__viewable($params);
        if ($task === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }
        if (!Task_Gates::can_control($task, 'rerun')) {
            return response_error(Ajax::ERROR_UNAUTHORIZED, 'You may not run this task again');
        }
        if ($task->is_live()) {
            return response_error(Ajax::ERROR_VALIDATION, 'Only a finished run can be run again');
        }

        return ['task_id' => $task->rerun()];
    }

    /**
     * The xterm.js ES module the Task_Output widget imports on first use (never bundled: most
     * pages show no console). Clients append ?v=build_key.
     */
    #[Route('/_task/xterm.mjs', methods: ['GET'])]
    #[Portal_Route('/_task/xterm.mjs', methods: ['GET'])]
    public static function xterm_module(Request $request, array $params = [])
    {
        return static::__serve_module(base_path('node_modules/@xterm/xterm/lib/xterm.mjs'));
    }

    /** The xterm.js fit addon module, for the same widget. */
    #[Route('/_task/xterm-fit.mjs', methods: ['GET'])]
    #[Portal_Route('/_task/xterm-fit.mjs', methods: ['GET'])]
    public static function xterm_fit_module(Request $request, array $params = [])
    {
        return static::__serve_module(base_path('node_modules/@xterm/addon-fit/lib/addon-fit.mjs'));
    }

    // ------------------------------------------------------------------------------------

    /** The run named by $params['task_id'], if the viewer may see it; else null. */
    private static function __viewable(array $params): ?Task_Run_Model
    {
        $task_id = (int) ($params['task_id'] ?? 0);
        $task = $task_id > 0 ? Task_Run_Model::find($task_id) : null;

        return $task !== null && Task_Gates::can_view($task) ? $task : null;
    }

    private static function __present(Task_Run_Model $task): array
    {
        $status = $task->to_status_array();
        $status['can'] = [];
        foreach (Task_Gates::ACTIONS as $action) {
            $status['can'][$action] = Task_Gates::can_control($task, $action);
        }

        return $status;
    }

    /**
     * The viewer's search query, or an error response for a bad filter.
     *
     * @return \Illuminate\Database\Eloquent\Builder|mixed
     */
    private static function __search(array $params)
    {
        $filter = $params['filter'] ?? [];
        if (!is_array($filter)) {
            return response_error(Ajax::ERROR_VALIDATION, 'filter is an object');
        }

        try {
            $query = Task_Run_Model::search_query($filter);
        } catch (\InvalidArgumentException $e) {
            return response_error(Ajax::ERROR_VALIDATION, $e->getMessage());
        }

        return Task_Gates::scope_viewable($query);
    }

    private static function __act(array $params, string $action, callable $operation)
    {
        $task = static::__viewable($params);
        if ($task === null) {
            return response_error(Ajax::ERROR_NOT_FOUND, 'Task not found');
        }
        if (!Task_Gates::can_control($task, $action)) {
            return response_error(Ajax::ERROR_UNAUTHORIZED, 'You may not do that to this task');
        }

        if (!$operation($task)) {
            return response_error(Ajax::ERROR_VALIDATION, 'The task is ' . strtolower($task->status_id__label) . '; nothing to do');
        }

        return static::__present(Task_Run_Model::find($task->id));
    }

    private static function __explanation(array $params): ?string
    {
        $explanation = isset($params['explanation']) ? trim((string) $params['explanation']) : '';

        return $explanation === '' ? null : mb_substr($explanation, 0, 1000);
    }

    private static function __serve_module(string $path)
    {
        if (!file_exists($path)) {
            return Response::make('@xterm/xterm not installed - run npm install in system/', 404, ['Content-Type' => 'text/plain']);
        }

        return Response::file($path, [
            'Content-Type' => 'text/javascript',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }
}
