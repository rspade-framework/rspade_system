<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Tasks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\App\Sys\Tasks\_Sys_Tasks_DataGrid;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Tasks screens' endpoints: the tasks grid (active runs on top, finished runs below),
 * one run's detail with its attachments, the lifecycle actions a developer takes on a run
 * (graceful stop, force stop, force kill, cancel, run again), the schedules with Run now, and
 * the worker pools.
 *
 * A run's live views - its output console and its reports - are the Core task widgets
 * (Task_Output, Task_Report, Task_Report_Browser) reading through Rsx_Task_Controller, where a
 * developer passes every task gate. This controller adds what only the console shows: the
 * worker evidence, the schedules and the pools.
 *
 * @DB-UNBOUNDED-01-EXCEPTION - workers() lists every RUNNING run, which is bounded by the worker
 * pools' caps plus the inline runs in flight; its queued list is limited to UPCOMING_LIMIT.
 */
#[Auth('is_sysadmin')]
class _Sys_Tasks_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /** How many queued runs and upcoming schedules the Workers screen lists. */
    public const UPCOMING_LIMIT = 10;

    /** Characters of a schedule's last error shown on the Schedules tab. */
    public const ERROR_EXCERPT_LENGTH = 120;

    /** The lifecycle actions act() performs. */
    public const ACTIONS = ['stop', 'force_stop', 'force_kill', 'cancel', 'rerun'];

    /**
     * worker_state values. Answered from evidence on THIS host only - the worker's process,
     * read through /proc - never from the row's age or its heartbeat:
     *   alive      the process that claimed the run is running here
     *   gone       it is not: the run is abandoned, and rsx:task:process settles it on its
     *              next tick (if it stays, that tick is not running)
     *   elsewhere  the run was claimed on another host, whose processes this one cannot see
     *   unknown    the run records no pid
     */
    public const WORKER_ALIVE = 'alive';
    public const WORKER_GONE = 'gone';
    public const WORKER_ELSEWHERE = 'elsewhere';
    public const WORKER_UNKNOWN = 'unknown';

    /**
     * The Tasks grid: active runs on top (running, then queued), finished runs below them,
     * newest first; filtered by scope, status, task, origin and finish time.
     */
    #[Ajax_Endpoint]
    public static function tasks_fetch(Request $request, array $params = [])
    {
        return _Sys_Tasks_DataGrid::fetch($params);
    }

    /**
     * The task filter's options: every task class that has a run, labelled by its basename.
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function class_options(Request $request, array $params = [])
    {
        return DB::table('_tasks')
            ->distinct()
            ->orderBy('class')
            ->pluck('class')
            ->map(fn ($class) => ['value' => $class, 'label' => class_basename($class)])
            ->all();
    }

    /**
     * One run: its status (Task_Run_Model::to_status_array()), its params, the worker that
     * runs it, its schedule, its attachments, and the actions that apply to it now.
     *
     * @param array $params id
     */
    #[Ajax_Endpoint]
    public static function detail(Request $request, array $params = [])
    {
        $task = Task_Run_Model::find((int) ($params['id'] ?? 0));
        if ($task === null) {
            return response_not_found('No task with that id.');
        }

        return ['task' => static::present($task, true)];
    }

    /**
     * Perform a lifecycle action on a run and return its new state.
     *
     * @param array $params task_id, task_action (stop | force_stop | force_kill | cancel | rerun),
     *                      grace_seconds (force_stop, optional), explanation (optional)
     * @return array {task, rerun_id: ?int}
     */
    #[Ajax_Endpoint]
    public static function act(Request $request, array $params = [])
    {
        $task = Task_Run_Model::find((int) ($params['task_id'] ?? 0));
        if ($task === null) {
            return response_not_found('No task with that id.');
        }

        // task_action, not action: a form field named "action" shadows the form element's own
        // action property in the browser.
        $action = (string) ($params['task_action'] ?? '');
        if (!in_array($action, self::ACTIONS, true)) {
            return response_error(Ajax::ERROR_VALIDATION, 'Unknown action.');
        }

        $explanation = trim((string) ($params['explanation'] ?? ''));
        $explanation = $explanation === '' ? null : mb_substr($explanation, 0, 1000);

        $grace = null;
        if ($action === 'force_stop' && isset($params['grace_seconds']) && $params['grace_seconds'] !== '') {
            if (!ctype_digit((string) $params['grace_seconds'])) {
                return response_form_error('The grace period is a whole number of seconds.', ['grace_seconds' => 'Whole seconds, 0 or more']);
            }
            $grace = (int) $params['grace_seconds'];
        }

        if ($action === 'rerun') {
            if ($task->is_live()) {
                return response_form_error("Task {$task->id} is still " . strtolower($task->status_id__label) . '; only a finished run can be run again.');
            }

            $new_id = $task->rerun();

            return ['task' => static::present(Task_Run_Model::find($task->id), false), 'rerun_id' => $new_id];
        }

        $done = match ($action) {
            'stop' => $task->request_stop($explanation),
            'force_stop' => $task->force_stop($grace, $explanation),
            'force_kill' => $task->force_kill($explanation),
            'cancel' => $task->cancel($explanation),
        };

        if (!$done) {
            return response_form_error("Task {$task->id} is " . strtolower($task->status_id__label) . '; that action no longer applies.');
        }

        return ['task' => static::present(Task_Run_Model::find($task->id), false), 'rerun_id' => null];
    }

    /**
     * Start the sample task: a scripted three-minute game of the Oregon Trail
     * (_Sys_Oregon_Trail_Service::travel) that reports every kind of task status and stops
     * gracefully when asked.
     *
     * @return array {task_id}
     */
    #[Ajax_Endpoint]
    public static function start_sample(Request $request, array $params = [])
    {
        return ['task_id' => Task::dispatch('_Sys_Oregon_Trail_Service', 'travel')];
    }

    /**
     * Download one of a run's attachments.
     */
    #[Route('/_sys/tasks/:id/attachments/:name', methods: ['GET'])]
    public static function attachment(Request $request, array $params = [])
    {
        $task = Task_Run_Model::find((int) ($params['id'] ?? 0));
        $attachment = $task?->attachment((string) ($params['name'] ?? ''));
        if ($attachment === null) {
            return response_not_found('No such attachment.');
        }

        return $attachment->download_response();
    }

    /**
     * Every declared #[Schedule] with its cadence and run statistics.
     *
     * @return array {failing_threshold, rows: [{class, class_short, method, schedule, cron,
     *                concurrency, failing, registered: null|{id, next_run_at, last_task_id,
     *                last_success_at, last_error_at, error_excerpt, consecutive_failures,
     *                last_run_status}}]}
     */
    #[Ajax_Endpoint]
    public static function schedules(Request $request, array $params = [])
    {
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after');

        $registered = DB::table('_task_schedules')->get()->keyBy(fn ($row) => $row->class . '::' . $row->method);

        $last_ids = $registered->pluck('last_task_id')->filter()->all();
        $last_status = $last_ids ? DB::table('_tasks')->whereIn('id', $last_ids)->pluck('status_id', 'id')->all() : [];

        $rows = [];
        foreach (Task::get_scheduled_tasks() as $declaration) {
            $schedule = $registered->get($declaration['class'] . '::' . $declaration['method']);

            $presented = null;
            if ($schedule !== null) {
                $last_status_id = $schedule->last_task_id !== null ? ($last_status[$schedule->last_task_id] ?? null) : null;
                $presented = [
                    'id' => (int) $schedule->id,
                    'next_run_at' => Rsx_Time::to_iso($schedule->next_run_at),
                    'last_task_id' => $schedule->last_task_id !== null ? (int) $schedule->last_task_id : null,
                    'last_success_at' => Rsx_Time::to_iso($schedule->last_success_at),
                    'last_error_at' => Rsx_Time::to_iso($schedule->last_error_at),
                    'error_excerpt' => static::__error_excerpt($schedule->last_error),
                    'consecutive_failures' => (int) $schedule->consecutive_failures,
                    'last_run_status_id' => $last_status_id !== null ? (int) $last_status_id : null,
                    'last_run_status' => $last_status_id !== null ? Task_Run_Model::$enums['status_id'][(int) $last_status_id]['label'] : null,
                ];
            }

            $rows[] = [
                'class' => $declaration['class'],
                'class_short' => class_basename($declaration['class']),
                'method' => $declaration['method'],
                'schedule' => $declaration['cron_expression'],
                'cron' => (new Cron_Parser($declaration['cron_expression']))->get_cron_expression(),
                'concurrency' => Task_Concurrency::get_policy($declaration['class'], $declaration['method'])['mode'],
                'failing' => $presented !== null && $presented['consecutive_failures'] >= $threshold,
                'registered' => $presented,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['class_short'] . '::' . $a['method'], $b['class_short'] . '::' . $b['method']));

        return ['failing_threshold' => $threshold, 'rows' => $rows];
    }

    /**
     * Run a schedule now: Task::dispatch() of the declared service and method with no params.
     * The schedule itself is not touched - its next_run_at still names the next cadence.
     *
     * An #[Exclusive]/#[Debounce] task coalesces as any dispatch does: when a pending run of it
     * already exists, that run's id comes back and nothing new is queued (outcome "coalesced").
     *
     * @param array $params class (FQCN, as the schedules list gives it), method
     * @return array {id, outcome: dispatched|coalesced, concurrency: exclusive|debounce|null}
     */
    #[Ajax_Endpoint]
    public static function run_schedule_now(Request $request, array $params = [])
    {
        $class = (string) ($params['class'] ?? '');
        $method = (string) ($params['method'] ?? '');

        // Only a declared schedule may be run from here - never an arbitrary class/method.
        $declared = false;
        foreach (Task::get_scheduled_tasks() as $candidate) {
            if ($candidate['class'] === $class && $candidate['method'] === $method) {
                $declared = true;
                break;
            }
        }

        if (!$declared) {
            return response_not_found('No #[Schedule] is declared on that class and method.');
        }

        $mode = Task_Concurrency::get_policy($class, $method)['mode'];
        $params_hash = Task_Concurrency::params_hash([]);
        $pending_before = $mode === null ? null : Task_Concurrency::pending_row_id($class, $method, $params_hash);

        $id = Task::dispatch(class_basename($class), $method, []);

        return [
            'id' => $id,
            'outcome' => $pending_before !== null && $pending_before === $id ? 'coalesced' : 'dispatched',
            'concurrency' => $mode,
        ];
    }

    /**
     * The worker pools and what they are doing: each pool's members against its cap, every
     * running run with the worker that runs it, the kill workers' requests, and what comes
     * next - the oldest queued runs and the next schedules due - with their totals.
     *
     * @return array {pools: [{pool, members, max_workers, waiting}], running: [run...],
     *                kill_requests: [...], queued: [run...], queued_total, schedules_due: [...],
     *                schedules_total}
     */
    #[Ajax_Endpoint]
    public static function workers(Request $request, array $params = [])
    {
        $pools = [];
        foreach (Task_Pool::POOLS as $pool) {
            $stats = Task_Pool::stats($pool);
            $pools[] = [
                'pool' => $pool,
                'members' => $stats['members'],
                'max_workers' => Task_Pool::max_workers($pool),
                'waiting' => $stats['waiting'],
            ];
        }

        // Bounded by the pools' caps: every running run is listed.
        $running = Task_Run_Model::where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($task) => static::present($task, false))
            ->all();

        $kill_requests = DB::table('_task_kill_requests')
            ->whereIn('status_id', [1, 2])
            ->orderBy('kill_after_at')
            ->get(['id', 'task_id', 'host', 'mode_id', 'status_id', 'kill_after_at', 'worker_pid', 'explanation'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'task_id' => (int) $row->task_id,
                'host' => $row->host,
                'mode' => (int) $row->mode_id === 1 ? 'Force stop' : 'Force kill',
                'claimed' => (int) $row->status_id === 2,
                'kill_after_at' => Rsx_Time::to_iso($row->kill_after_at),
                'worker_pid' => $row->worker_pid !== null ? (int) $row->worker_pid : null,
                'explanation' => $row->explanation,
            ])
            ->all();

        $queued_query = Task_Run_Model::where('status_id', Task_Run_Model::STATUS_PENDING);
        $queued = (clone $queued_query)->orderBy('scheduled_for')->orderBy('id')->limit(self::UPCOMING_LIMIT)->get()
            ->map(fn ($task) => static::present($task, false))
            ->all();

        $schedules_due = DB::table('_task_schedules')
            ->orderBy('next_run_at')
            ->limit(self::UPCOMING_LIMIT)
            ->get(['id', 'class', 'method', 'cron_expression', 'next_run_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => class_basename($row->class) . '::' . $row->method,
                'cron_expression' => $row->cron_expression,
                'next_run_at' => Rsx_Time::to_iso($row->next_run_at),
            ])
            ->all();

        return [
            'host' => Task_Pool::host(),
            'pools' => $pools,
            'running' => $running,
            'kill_requests' => $kill_requests,
            'queued' => $queued,
            'queued_total' => $queued_query->count(),
            'schedules_due' => $schedules_due,
            'schedules_total' => DB::table('_task_schedules')->count(),
        ];
    }

    /**
     * A run as the panel reads it: the status array, the worker evidence (while it runs), the
     * schedule and which actions apply. $full adds params and attachments.
     */
    public static function present(Task_Run_Model $task, bool $full): array
    {
        $status = $task->to_status_array();
        $status['pool'] = $task->pool_id !== null ? Task_Run_Model::$enums['pool_id'][(int) $task->pool_id]['label'] : null;
        // The worker is shown only while the run is RUNNING: once it settles the process that
        // ran it is over, and the pid the row keeps as evidence names nothing a reader can act on.
        $running = (int) $task->status_id === Task_Run_Model::STATUS_RUNNING;
        $status['worker_pid'] = $running && $task->worker_pid !== null ? (int) $task->worker_pid : null;
        $status['worker_host'] = $running ? $task->worker_host : null;
        $status['worker_state'] = $running ? static::worker_state($task) : null;
        $status['schedule_id'] = $task->schedule_id !== null ? (int) $task->schedule_id : null;
        $status['created_at'] = Rsx_Time::to_iso($task->created_at);
        $status['actions'] = static::__actions($task);

        if (!$full) {
            unset($status['params']);
        } else {
            $status['attachments'] = array_map(fn ($attachment) => $attachment->to_listing_array() + [
                'url' => Rsx::Route('_Sys_Tasks_Controller::attachment', ['id' => $task->id, 'name' => $attachment->name]),
            ], $task->attachments());
        }

        return $status;
    }

    /**
     * Whether the process that claimed a RUNNING run is still there; see the WORKER_*
     * constants. A run claimed by a pool worker is checked against the worker's command line
     * (a pid the kernel reused is not that worker); a run outside the pool (inline) has no
     * worker_id, and its pid is simply probed.
     */
    public static function worker_state(Task_Run_Model $task): string
    {
        if ($task->worker_host !== null && $task->worker_host !== Task_Pool::host()) {
            return self::WORKER_ELSEWHERE;
        }

        $pid = (int) $task->worker_pid;
        if ($pid <= 0) {
            return self::WORKER_UNKNOWN;
        }

        $alive = $task->worker_id !== null ? Task::is_worker_process($pid) : posix_kill($pid, 0);

        return $alive ? self::WORKER_ALIVE : self::WORKER_GONE;
    }

    /** The lifecycle actions that apply to a run in its present state. */
    private static function __actions(Task_Run_Model $task): array
    {
        return match ((int) $task->status_id) {
            Task_Run_Model::STATUS_RUNNING => ['stop', 'force_stop', 'force_kill'],
            Task_Run_Model::STATUS_PENDING => ['stop', 'cancel'],
            default => ['rerun'],
        };
    }

    /**
     * The first line of a schedule's last error, capped at ERROR_EXCERPT_LENGTH; null when it
     * has none.
     */
    private static function __error_excerpt(?string $error): ?string
    {
        $text = trim(explode("\n", trim((string) $error))[0]);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::ERROR_EXCERPT_LENGTH) {
            $text = mb_substr($text, 0, self::ERROR_EXCERPT_LENGTH - 3) . '...';
        }

        return $text;
    }
}
