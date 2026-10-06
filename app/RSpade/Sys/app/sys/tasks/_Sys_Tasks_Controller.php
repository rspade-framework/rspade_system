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
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Killer;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\App\Sys\Tasks\_Sys_Tasks_DataGrid;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Tasks screen's endpoints: the running rows, the history grid, one row's detail and
 * its log tail (the live console), the two actions a developer takes on a row - kill it,
 * or dispatch it again - and the schedules (every #[Schedule] beside its tracker row)
 * with Run now.
 *
 * _tasks has no model; every read is DB::table(). Its datetime columns hold UTC wall
 * clock with no zone (the application and the database session both run in UTC), so
 * every datetime leaves here as an ISO 8601 UTC string through Rsx_Time::to_iso() -
 * a bare 'Y-m-d H:i:s' would be read as LOCAL time by the browser.
 */
#[Auth('is_sysadmin')]
class _Sys_Tasks_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /** The _tasks datetime columns, converted to ISO on every row that leaves here. */
    public const DATETIME_COLUMNS = [
        'scheduled_for', 'next_run_at', 'started_at', 'completed_at', 'last_error_at',
        'last_heartbeat_at', 'created_at', 'updated_at', 'last_run_at',
    ];

    /** The status reason a kill records when the developer gave no explanation. */
    public const KILL_DEFAULT_REASON = 'killed from the control panel';

    /** Characters of a tracker's last error shown on the Schedules tab. */
    public const ERROR_EXCERPT_LENGTH = 120;

    /**
     * worker_state values. Answered from evidence on THIS host only - the worker's process,
     * read through /proc - never from the row's age or its heartbeat:
     *   alive      the worker process that claimed the row is running here
     *   gone       it is not: the row is abandoned, and rsx:task:process settles it on its
     *              next tick (if the row stays, that tick is not running)
     *   elsewhere  the row was claimed on another host, whose processes this one cannot see
     *   unknown    the row records no pid
     */
    public const WORKER_ALIVE = 'alive';
    public const WORKER_GONE = 'gone';
    public const WORKER_ELSEWHERE = 'elsewhere';
    public const WORKER_UNKNOWN = 'unknown';

    /**
     * Every RUNNING row, oldest start first. The set is bounded by the worker pool
     * (rsx.tasks.global_max_workers), so it is returned whole.
     *
     * @return array {rows: [{id, class, class_short, method, queue, worker_pid, worker_host,
     *                        worker_state, started_at, last_heartbeat_at, is_cron}]}
     */
    #[Ajax_Endpoint]
    public static function running(Request $request, array $params = [])
    {
        $rows = DB::table('_tasks')
            ->where('status', Task_Status::RUNNING)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get(['id', 'class', 'method', 'queue', 'worker_pid', 'worker_id', 'worker_host', 'started_at', 'last_heartbeat_at', 'next_run_at'])
            ->map(function ($row) {
                $presented = static::present_row((array) $row);
                $presented['worker_state'] = static::worker_state($row);
                unset($presented['worker_id']);

                return $presented;
            })
            ->all();

        return ['rows' => $rows];
    }

    /**
     * The queue labels in use and how much work each holds, beside the one worker pool that
     * drains them all. A queue is a LABEL: there is one pool of generic workers, capped at
     * rsx.tasks.global_max_workers, and a worker claims run-now work first (FIFO), then due
     * schedules - whatever the label.
     *
     * Per queue, over the pending and running rows:
     *   running         RUNNING rows; stale_running of those have a worker that is gone
     *   pending_due     one-shot rows waiting for a worker now
     *   pending_later   one-shot rows held for later (a delayed dispatch or a retry backoff)
     *   schedules       #[Schedule] trackers; schedules_due of them are due now
     *   oldest_due_at   when the longest-waiting due one-shot row became due
     *
     * @return array {pool: {max_workers, members, waiting}, queues: [...]}
     */
    #[Ajax_Endpoint]
    public static function queues(Request $request, array $params = [])
    {
        $now = now();

        // One row per queue label in use - the result set is the set of labels.
        $counts = DB::table('_tasks')
            ->whereIn('status', [Task_Status::PENDING, Task_Status::RUNNING])
            ->groupBy('queue')
            ->orderBy('queue')
            ->selectRaw(
                'queue,'
                . " SUM(status = 'running') AS running,"
                . " SUM(status = 'pending' AND next_run_at IS NULL AND (scheduled_for IS NULL OR scheduled_for <= ?)) AS pending_due,"
                . " SUM(status = 'pending' AND next_run_at IS NULL AND scheduled_for > ?) AS pending_later,"
                . " SUM(status = 'pending' AND next_run_at IS NOT NULL) AS schedules,"
                . " SUM(status = 'pending' AND next_run_at IS NOT NULL AND next_run_at <= ?) AS schedules_due,"
                . " MIN(CASE WHEN status = 'pending' AND next_run_at IS NULL AND (scheduled_for IS NULL OR scheduled_for <= ?)"
                . ' THEN COALESCE(scheduled_for, created_at) END) AS oldest_due_at',
                [$now, $now, $now, $now]
            )
            ->get();

        $stale = [];
        foreach (DB::table('_tasks')->where('status', Task_Status::RUNNING)->get(['queue', 'worker_pid', 'worker_id', 'worker_host']) as $row) {
            if (static::worker_state($row) === self::WORKER_GONE) {
                $stale[$row->queue] = ($stale[$row->queue] ?? 0) + 1;
            }
        }

        $queues = [];
        foreach ($counts as $row) {
            $queues[] = [
                'queue' => $row->queue,
                'running' => (int) $row->running,
                'stale_running' => $stale[$row->queue] ?? 0,
                'pending_due' => (int) $row->pending_due,
                'pending_later' => (int) $row->pending_later,
                'schedules' => (int) $row->schedules,
                'schedules_due' => (int) $row->schedules_due,
                'oldest_due_at' => Rsx_Time::to_iso($row->oldest_due_at),
            ];
        }

        $stats = Task_Pool::stats();

        return [
            'pool' => [
                'max_workers' => Task_Pool::max_workers(),
                'members' => $stats['members'],
                'waiting' => $stats['waiting'],
            ],
            'queues' => $queues,
        ];
    }

    /**
     * Whether the worker that claimed a RUNNING row is still there; see the WORKER_*
     * constants. A row claimed by a pool worker is checked against the worker's command
     * line (a pid the kernel reused is not that worker); a row run outside the pool
     * (--once, Task::internal()) has no worker_id, and its pid is simply probed.
     *
     * @param object $row A _tasks row with worker_pid, worker_id and worker_host
     */
    public static function worker_state(object $row): string
    {
        if ($row->worker_host !== null && $row->worker_host !== Task_Pool::host()) {
            return self::WORKER_ELSEWHERE;
        }

        $pid = (int) $row->worker_pid;
        if ($pid <= 0) {
            return self::WORKER_UNKNOWN;
        }

        $alive = $row->worker_id !== null ? Task::is_worker_process($pid) : posix_kill($pid, 0);

        return $alive ? self::WORKER_ALIVE : self::WORKER_GONE;
    }

    /**
     * The history grid (_Sys_Tasks_DataGrid).
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return _Sys_Tasks_DataGrid::fetch($params);
    }

    /**
     * The class filter's options: every task class that has a row, labelled by its
     * basename.
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
     * The queue filter's options: every queue label that has a row.
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function queue_options(Request $request, array $params = [])
    {
        return DB::table('_tasks')
            ->distinct()
            ->orderBy('queue')
            ->pluck('queue')
            ->map(fn ($queue) => ['value' => $queue, 'label' => $queue])
            ->all();
    }

    /**
     * One row: params and result decoded, the worker's state beside it. The logs are not
     * here - the live console reads them through logs() - and neither is updated_at, which
     * moves with every log line: the detail screen repaints only when the row's facts change.
     *
     * @param array $params id
     */
    #[Ajax_Endpoint]
    public static function detail(Request $request, array $params = [])
    {
        $row = DB::table('_tasks')->where('id', (int) ($params['id'] ?? 0))->first();

        if (!$row) {
            return response_not_found('No task with that id.');
        }

        $task = static::present_row((array) $row);
        $task['params'] = $row->params === null ? null : json_decode($row->params, true);
        $task['result'] = $row->result === null ? null : json_decode($row->result, true);
        $task['worker_state'] = $row->status === Task_Status::RUNNING ? static::worker_state($row) : null;
        unset($task['logs'], $task['updated_at']);
        $task['can_kill'] = $row->status === Task_Status::RUNNING;
        $task['can_redispatch'] = static::__can_redispatch($row);

        return ['task' => $task];
    }

    /**
     * A row's log lines from line $offset on, for the live console: it asks again with the
     * offset and run it was handed back each time the row changes (Task_Changed_Topic), so
     * asking twice never repeats a line.
     *
     * A #[Schedule] tracker is ONE row across every run, and each run writes its log afresh.
     * `run` names the run the lines belong to (its started_at); when the caller's run is not
     * the row's, or the log is shorter than what the caller holds, the answer is the whole
     * log with restart = true, and the console starts over.
     *
     * @param array $params id, offset (lines already held; default 0), run (as last returned)
     * @return array {lines: string[], offset: int, run: ?string, restart: bool, status: string,
     *                last_heartbeat_at: ?string}
     */
    #[Ajax_Endpoint]
    public static function logs(Request $request, array $params = [])
    {
        $row = DB::table('_tasks')->where('id', (int) ($params['id'] ?? 0))->first(['status', 'logs', 'started_at', 'last_heartbeat_at']);

        if (!$row) {
            return response_not_found('No task with that id.');
        }

        $all = $row->logs ? explode("\n", $row->logs) : [];
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $run = Rsx_Time::to_iso($row->started_at);

        $restart = $offset > count($all) || ($offset > 0 && ($params['run'] ?? null) !== $run);

        return [
            'lines' => array_slice($all, $restart ? 0 : $offset),
            'offset' => count($all),
            'run' => $run,
            'restart' => $restart,
            'status' => $row->status,
            'last_heartbeat_at' => Rsx_Time::to_iso($row->last_heartbeat_at),
        ];
    }

    /**
     * Force-kill a running task - the panel's rsx:tasks:kill. The explanation is
     * optional and recorded on the row as its status reason; left blank, the row records
     * KILL_DEFAULT_REASON instead. Task_Killer signals the worker (SIGTERM, a 5s grace,
     * then SIGKILL), so this call can take that long.
     *
     * The Kill dialog's form endpoint.
     *
     * @param array $params task_id, explanation
     * @return array {id, outcome} - outcome is Task_Killer's: killed | killed_no_process | recycled
     */
    #[Ajax_Endpoint]
    public static function kill(Request $request, array $params = [])
    {
        $explanation = trim((string) ($params['explanation'] ?? ''));
        if ($explanation === '') {
            $explanation = self::KILL_DEFAULT_REASON;
        }

        $row = DB::table('_tasks')->where('id', (int) ($params['task_id'] ?? 0))->first();

        if (!$row) {
            return response_not_found('No task with that id.');
        }

        if ($row->status !== Task_Status::RUNNING) {
            return response_form_error("Task {$row->id} is not running (status: {$row->status}).");
        }

        return [
            'id' => (int) $row->id,
            'outcome' => Task_Killer::kill($row, $explanation),
        ];
    }

    /**
     * Dispatch a failed or killed one-shot row again: the same service, method, params
     * and queue, as a NEW row. The old row is left as it is.
     *
     * A cron tracker is refused - it is never terminal (a failure recycles it to
     * pending), and its next run is the schedule's business.
     *
     * @param array $params id
     * @return array {id} - the new row
     */
    #[Ajax_Endpoint]
    public static function redispatch(Request $request, array $params = [])
    {
        $row = DB::table('_tasks')->where('id', (int) ($params['id'] ?? 0))->first();

        if (!$row) {
            return response_not_found('No task with that id.');
        }

        if (!static::__can_redispatch($row)) {
            return response_error(
                Ajax::ERROR_VALIDATION,
                "Task {$row->id} is {$row->status}; only a failed or killed task can be dispatched again."
            );
        }

        $task_params = $row->params === null ? [] : (json_decode($row->params, true) ?? []);

        $id = Task::dispatch(class_basename($row->class), $row->method, $task_params, ['queue' => $row->queue]);

        return ['id' => (int) $id];
    }

    /**
     * Every #[Schedule] declaration beside its tracker row, sorted by the displayed name.
     *
     * A tracker is the _tasks row with next_run_at set for that class and method; the
     * scheduler (rsx:task:process) creates it on its first tick after the declaration
     * appears, so a declaration may have none yet (tracker null). A tracker is never
     * terminal: completed_at on it is the LAST SUCCESS, and consecutive_failures counts
     * runs that threw since. failing = consecutive_failures >= rsx.tasks.failing_schedule_warn_after,
     * the rsx:health "Failing Schedules" threshold.
     *
     * Bounded by the codebase (one row per declaration), so returned whole.
     *
     * @return array {failing_threshold, rows: [{class, class_short, method, queue, schedule,
     *                cron, concurrency, failing, tracker: null|{id, status, next_run_at,
     *                completed_at, consecutive_failures, last_error_at, error_excerpt,
     *                last_run_at, last_outcome}}]}
     */
    #[Ajax_Endpoint]
    public static function schedules(Request $request, array $params = [])
    {
        $threshold = (int) config('rsx.tasks.failing_schedule_warn_after');

        $trackers = DB::table('_tasks')
            ->whereNotNull('next_run_at')
            ->get(['id', 'class', 'method', 'status', 'next_run_at', 'completed_at',
                'consecutive_failures', 'last_error_at', 'error', 'status_reason'])
            ->keyBy(fn ($row) => $row->class . '::' . $row->method);

        $rows = [];

        foreach (Task::get_scheduled_tasks() as $declaration) {
            $tracker = $trackers->get($declaration['class'] . '::' . $declaration['method']);

            if ($tracker !== null) {
                $tracker = static::present_row([
                    'id' => (int) $tracker->id,
                    'status' => $tracker->status,
                    'next_run_at' => $tracker->next_run_at,
                    'completed_at' => $tracker->completed_at,
                    'consecutive_failures' => (int) $tracker->consecutive_failures,
                    'last_error_at' => $tracker->last_error_at,
                    'error_excerpt' => static::__error_excerpt($tracker->error),
                ] + static::__last_run($tracker));
                unset($tracker['is_cron']);
            }

            $rows[] = [
                'class' => $declaration['class'],
                'class_short' => class_basename($declaration['class']),
                'method' => $declaration['method'],
                'queue' => $declaration['queue'],
                'schedule' => $declaration['cron_expression'],
                'cron' => (new Cron_Parser($declaration['cron_expression']))->get_cron_expression(),
                'concurrency' => Task_Concurrency::get_policy($declaration['class'], $declaration['method'])['mode'],
                'failing' => $tracker !== null && $tracker['consecutive_failures'] >= $threshold,
                'tracker' => $tracker,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['class_short'] . '::' . $a['method'], $b['class_short'] . '::' . $b['method']));

        return ['failing_threshold' => $threshold, 'rows' => $rows];
    }

    /**
     * Run a schedule now: Task::dispatch() of the declared service and method with no
     * params, on the declared queue, as a ONE-SHOT row. The tracker is not touched - its
     * next_run_at still names the next cadence.
     *
     * An #[Exclusive]/#[Debounce] task coalesces as any dispatch does: when a pending
     * one-shot run of it already exists, that row's id comes back and nothing new is
     * queued (outcome "coalesced"); otherwise a new row is created ("dispatched"), which
     * for a managed task waits for a running instance to finish.
     *
     * @param array $params class (FQCN, as the schedules list gives it), method
     * @return array {id, outcome: dispatched|coalesced, concurrency: exclusive|debounce|null}
     */
    #[Ajax_Endpoint]
    public static function run_schedule_now(Request $request, array $params = [])
    {
        $class = (string) ($params['class'] ?? '');
        $method = (string) ($params['method'] ?? '');
        $declaration = null;

        // Only a declared schedule may be run from here - never an arbitrary class/method.
        foreach (Task::get_scheduled_tasks() as $candidate) {
            if ($candidate['class'] === $class && $candidate['method'] === $method) {
                $declaration = $candidate;
                break;
            }
        }

        if ($declaration === null) {
            return response_not_found('No #[Schedule] is declared on that class and method.');
        }

        $mode = Task_Concurrency::get_policy($class, $method)['mode'];
        $pending_before = $mode === null ? null : Task_Concurrency::pending_row_id($class, $method, $declaration['queue']);

        $id = Task::dispatch(class_basename($class), $method, [], ['queue' => $declaration['queue']]);

        return [
            'id' => (int) $id,
            'outcome' => $pending_before !== null && $pending_before === $id ? 'coalesced' : 'dispatched',
            'concurrency' => $mode,
        ];
    }

    /**
     * A _tasks row as the browser reads it: datetimes as ISO UTC, the class's basename
     * beside its FQCN, is_cron for a #[Schedule] tracker row.
     *
     * @param array $row An associative _tasks row (any subset of its columns)
     * @return array
     */
    public static function present_row(array $row): array
    {
        foreach (self::DATETIME_COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = Rsx_Time::to_iso($row[$column]);
            }
        }

        if (array_key_exists('class', $row)) {
            $row['class_short'] = class_basename($row['class']);
        }

        if (array_key_exists('next_run_at', $row)) {
            $row['is_cron'] = $row['next_run_at'] !== null;
        }

        return $row;
    }

    /**
     * A tracker's most recent run and how it ended.
     *
     * completed_at is stamped only by a success and last_error_at only by a failure or an
     * abandonment, so the later of the two is the last run. The reaper prefixes an
     * abandoned run's status_reason with 'abandoned'; any other failure is 'failed'.
     *
     * @return array{last_run_at: ?string, last_outcome: ?string}
     */
    private static function __last_run(object $tracker): array
    {
        $success = $tracker->completed_at;
        $failure = $tracker->last_error_at;

        if ($success === null && $failure === null) {
            return ['last_run_at' => null, 'last_outcome' => null];
        }

        if ($failure === null || ($success !== null && strtotime($success) >= strtotime($failure))) {
            return ['last_run_at' => $success, 'last_outcome' => 'completed'];
        }

        $abandoned = str_starts_with((string) $tracker->status_reason, 'abandoned');

        return ['last_run_at' => $failure, 'last_outcome' => $abandoned ? 'abandoned' : 'failed'];
    }

    /**
     * The first line of a tracker's last error, capped at ERROR_EXCERPT_LENGTH; null when
     * it has none.
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

    private static function __can_redispatch(object $row): bool
    {
        return in_array($row->status, [Task_Status::FAILED, Task_Status::KILLED], true)
            && $row->next_run_at === null;
    }
}
