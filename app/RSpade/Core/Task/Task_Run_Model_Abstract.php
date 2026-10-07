<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Attachment_Model;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Kill_Worker;
use App\RSpade\Core\Task\Task_Notify;
use App\RSpade\Core\Task\Task_Schedule_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Task_Run_Model - one RUN of a background task (_tasks).
 *
 * Every execution is a row: a dispatched run (Task::dispatch), a run of a #[Schedule], and an
 * inline run (Task::internal(), rsx:task:run, a #[Command]). The row carries the lifecycle
 * (status, when it was scheduled, started and finished), who dispatched it and for which site,
 * the worker that ran it, and the task's small live reports (status text, progress, ETA,
 * heartbeat). The larger reports live beside it - _task_reports (state JSON, state list,
 * summary), _task_output (stdout / stderr / operator lines), _task_messages and
 * _task_attachments - and are read through the methods below.
 *
 * A task WRITES all of this through its Task_Instance; nothing outside the task writes a
 * report. Outside code READS through this model, and acts on a run through its lifecycle
 * methods. Reads here are ungated server-side PHP like any model read: an endpoint that shows a
 * task to a user asks Task_Gates first.
 *
 * @DB-UNBOUNDED-01-EXCEPTION - attachments() reads the attachments of ONE run, which are as many
 * as that run attached; every other whole-set read here is a search capped by its name
 * (find_first_1000) or paged (find_page).
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _tasks
 *
 * @property int $abandon_count
 * @property string $class
 * @property string $completed_at
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $dispatched_by_id
 * @property int $dispatched_by_type
 * @property string $error
 * @property string $eta_at
 * @property int $id
 * @property string $last_heartbeat_at
 * @property string $last_report_at
 * @property string $method
 * @property int $origin_id
 * @property string $output_truncated_at
 * @property array $params
 * @property string $params_hash
 * @property int $pool_id
 * @property int $progress_done
 * @property float $progress_percent
 * @property int $progress_total
 * @property int $return_code
 * @property int $schedule_id
 * @property string $scheduled_for
 * @property int $site_id
 * @property string $started_at
 * @property int $status_id
 * @property string $status_reason
 * @property string $status_text
 * @property string $stop_requested_at
 * @property int $timeout
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property int $worker_generation
 * @property string $worker_host
 * @property int $worker_id
 * @property int $worker_pid
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 * @property-read string $origin_id__label
 * @property-read string $origin_id__constant
 * @property-read string $pool_id__label
 * @property-read string $pool_id__constant
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 * @method static array origin_id__enum() Get all enum definitions with full metadata
 * @method static array origin_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array origin_id__enum_labels() Get simple id => label map
 * @method static array origin_id__enum_ids() Get array of all valid enum IDs
 * @method static array pool_id__enum() Get all enum definitions with full metadata
 * @method static array pool_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array pool_id__enum_labels() Get simple id => label map
 * @method static array pool_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
abstract class Task_Run_Model_Abstract extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_PENDING = 1;
    const STATUS_RUNNING = 2;
    const STATUS_COMPLETED = 3;
    const STATUS_FAILED = 4;
    const STATUS_STOPPED = 5;
    const STATUS_KILLED = 6;
    const STATUS_CANCELLED = 7;
    const ORIGIN_DISPATCHED = 1;
    const ORIGIN_SCHEDULED = 2;
    const ORIGIN_INLINE = 3;
    const POOL_ON_DEMAND = 1;
    const POOL_SCHEDULED = 2;

    /** _task_reports.kind_id: the state object a task reported with $task->state(). */
    const REPORT_STATE_JSON = 1;
    /** _task_reports.kind_id: the list a task reported with $task->state_list(). */
    const REPORT_STATE_LIST = 2;
    /** _task_reports.kind_id: the completion summary a task set with $task->summary(). */
    const REPORT_SUMMARY = 3;

    /** _task_output.stream_id: a line the task wrote to stdout (echo is captured here too). */
    const STREAM_STDOUT = 1;
    /** _task_output.stream_id: a line the task wrote to stderr, and every status() change. */
    const STREAM_STDERR = 2;
    /** _task_output.stream_id: a lifecycle operation someone performed on the run. Shown as stderr. */
    const STREAM_OPERATOR = 3;

    // Infrastructure table: a running task writes its row many times a second, and nothing
    // here may kick the emitter engine. Watchers follow a run through Task_Changed_Topic.
    public static $realtime_silent = true;

    /**
     * UNBOUNDED: one row per run, for as long as rsx.tasks.retention keeps them.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_tasks';
    protected $fillable = [];

    protected $casts = [
        'params' => 'array',
    ];

    protected static $type_ref_columns = ['dispatched_by_type'];

    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_PENDING', 'label' => 'Pending', 'badge' => 'bg-secondary', 'terminal' => false],
            2 => ['constant' => 'STATUS_RUNNING', 'label' => 'Running', 'badge' => 'bg-primary', 'terminal' => false],
            3 => ['constant' => 'STATUS_COMPLETED', 'label' => 'Completed', 'badge' => 'bg-success', 'terminal' => true],
            4 => ['constant' => 'STATUS_FAILED', 'label' => 'Failed', 'badge' => 'bg-danger', 'terminal' => true],
            // The task returned successfully after a stop was requested: it stopped early, by
            // its own choice, at a clean point.
            5 => ['constant' => 'STATUS_STOPPED', 'label' => 'Stopped', 'badge' => 'bg-warning', 'terminal' => true],
            6 => ['constant' => 'STATUS_KILLED', 'label' => 'Killed', 'badge' => 'bg-danger', 'terminal' => true],
            // Removed from the queue before it ran.
            7 => ['constant' => 'STATUS_CANCELLED', 'label' => 'Cancelled', 'badge' => 'bg-dark', 'terminal' => true],
        ],
        'origin_id' => [
            1 => ['constant' => 'ORIGIN_DISPATCHED', 'label' => 'Dispatched'],
            2 => ['constant' => 'ORIGIN_SCHEDULED', 'label' => 'Scheduled'],
            3 => ['constant' => 'ORIGIN_INLINE', 'label' => 'Inline'],
        ],
        'pool_id' => [
            1 => ['constant' => 'POOL_ON_DEMAND', 'label' => 'On demand'],
            2 => ['constant' => 'POOL_SCHEDULED', 'label' => 'Scheduled'],
        ],
    ];

    /** The status ids a run can still leave: pending and running. */
    const LIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    /** Report kind id -> the name used everywhere outside the database. */
    const REPORT_KIND_NAMES = [
        self::REPORT_STATE_JSON => 'state_json',
        self::REPORT_STATE_LIST => 'state_list',
        self::REPORT_SUMMARY => 'summary',
    ];

    /** Stream id -> name. */
    const STREAM_NAMES = [
        self::STREAM_STDOUT => 'stdout',
        self::STREAM_STDERR => 'stderr',
        self::STREAM_OPERATOR => 'operator',
    ];

    // ------------------------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------------------------

    /**
     * The schedule this run belongs to (scheduled runs only).
     */
    #[Relationship]
    public function schedule()
    {
        return $this->belongsTo(Task_Schedule_Model::class, 'schedule_id');
    }

    /**
     * Whoever dispatched the run: a User_Model, Portal_User_Model or Login_User_Model, or null
     * for a run nobody signed in started (a schedule, a worker, the CLI).
     */
    #[Relationship]
    public function dispatched_by()
    {
        return $this->morphTo('dispatched_by');
    }

    // ------------------------------------------------------------------------------------
    // Lifecycle readers
    // ------------------------------------------------------------------------------------

    /** Pending or running: the run can still change. */
    public function is_live(): bool
    {
        return in_array((int) $this->status_id, self::LIVE_STATUSES, true);
    }

    /** Completed, failed, stopped, killed or cancelled: the run is over. */
    public function is_terminal(): bool
    {
        return !$this->is_live();
    }

    /** The task's simple class name (the service). */
    public function service_name(): string
    {
        return class_basename($this->class);
    }

    /** "Service::method", the run's human name. */
    public function task_name(): string
    {
        return $this->service_name() . '::' . $this->method;
    }

    // ------------------------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------------------------

    /** Runs per page of find_page(), and the cap of find_first_1000(). */
    const SEARCH_PAGE_SIZE = 1000;

    /** The keys a search filter may carry. */
    const SEARCH_KEYS = [
        'id', 'ids', 'class', 'method', 'params', 'params_contains', 'status', 'live', 'origin',
        'schedule_id', 'site_id', 'dispatched_by',
        'scheduled_after', 'scheduled_before', 'started_after', 'started_before',
        'completed_after', 'completed_before',
    ];

    /**
     * A query over runs narrowed by $filter. Every key is optional; an unknown key throws.
     *
     *   id, ids                  one run, or several
     *   class                    the service: simple name ("Report_Service") or FQCN
     *   method                   the task method
     *   params                   EXACT parameter set (matched by its canonical hash)
     *   params_contains          runs whose params contain this subset (JSON_CONTAINS), applied
     *                            after the indexed narrowing - pair it with class/method
     *   status                   a status name or id, or a list of them
     *   live                     true: pending or running; false: finished
     *   origin                   dispatched | scheduled | inline (or the id)
     *   schedule_id, site_id     exact
     *   dispatched_by            ['type' => 'User_Model', 'id' => 5]
     *   {scheduled,started,completed}_{after,before}   ISO moments, inclusive
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function search_query(array $filter)
    {
        $unknown = array_diff(array_keys($filter), self::SEARCH_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown task search key(s): ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', self::SEARCH_KEYS) . '.');
        }

        $query = static::query();

        if (isset($filter['id'])) {
            $query->where('id', (int) $filter['id']);
        }
        if (isset($filter['ids'])) {
            $query->whereIn('id', array_map('intval', (array) $filter['ids']));
        }
        if (isset($filter['class'])) {
            $query->where('class', static::__resolve_class((string) $filter['class']));
        }
        if (isset($filter['method'])) {
            $query->where('method', (string) $filter['method']);
        }
        if (array_key_exists('params', $filter)) {
            $query->where('params_hash', Task_Concurrency::params_hash((array) $filter['params']));
        }
        if (isset($filter['params_contains'])) {
            $query->whereRaw('JSON_CONTAINS(params, ?)', [json_encode((array) $filter['params_contains'])]);
        }
        if (isset($filter['status'])) {
            $query->whereIn('status_id', array_map(fn ($status) => static::__status_id($status), (array) $filter['status']));
        }
        if (isset($filter['live'])) {
            $filter['live']
                ? $query->whereIn('status_id', self::LIVE_STATUSES)
                : $query->whereNotIn('status_id', self::LIVE_STATUSES);
        }
        if (isset($filter['origin'])) {
            $query->where('origin_id', static::__enum_id('origin_id', $filter['origin']));
        }
        if (isset($filter['schedule_id'])) {
            $query->where('schedule_id', (int) $filter['schedule_id']);
        }
        if (isset($filter['site_id'])) {
            $query->where('site_id', (int) $filter['site_id']);
        }
        if (isset($filter['dispatched_by'])) {
            $by = $filter['dispatched_by'];
            if (!is_array($by) || !isset($by['type'], $by['id'])) {
                throw new \InvalidArgumentException("dispatched_by is ['type' => <actor model>, 'id' => <id>].");
            }
            $query->where('dispatched_by_type', Type_Ref_Registry::class_to_id(class_basename((string) $by['type'])))
                ->where('dispatched_by_id', (int) $by['id']);
        }
        foreach (['scheduled' => 'scheduled_for', 'started' => 'started_at', 'completed' => 'completed_at'] as $key => $column) {
            if (isset($filter["{$key}_after"])) {
                $query->where($column, '>=', static::__moment($filter["{$key}_after"]));
            }
            if (isset($filter["{$key}_before"])) {
                $query->where($column, '<=', static::__moment($filter["{$key}_before"]));
            }
        }

        return $query;
    }

    /**
     * The newest runs matching $filter, at most 1000 (SEARCH_PAGE_SIZE) - the cap is the
     * function's contract, as its name says. For every match, page with find_page().
     *
     * @return static[]
     */
    public static function find_first_1000(array $filter): array
    {
        return static::search_query($filter)->orderByDesc('id')->limit(self::SEARCH_PAGE_SIZE)->get()->all();
    }

    /**
     * One page of the runs matching $filter, newest first, keyset-paged: pass the returned
     * next_cursor back for the page after it; null means there is none.
     *
     * @return array{tasks: static[], next_cursor: ?string}
     */
    public static function find_page(array $filter, ?string $cursor = null, int $page_size = self::SEARCH_PAGE_SIZE): array
    {
        if ($page_size < 1 || $page_size > self::SEARCH_PAGE_SIZE) {
            throw new \InvalidArgumentException('A task search page holds 1 to ' . self::SEARCH_PAGE_SIZE . ' runs.');
        }

        $query = static::search_query($filter);
        if ($cursor !== null && $cursor !== '') {
            if (!ctype_digit($cursor)) {
                throw new \InvalidArgumentException("Invalid task search cursor '{$cursor}'.");
            }
            $query->where('id', '<', (int) $cursor);
        }

        $rows = $query->orderByDesc('id')->limit($page_size + 1)->get()->all();
        $more = count($rows) > $page_size;
        $rows = array_slice($rows, 0, $page_size);

        return [
            'tasks' => $rows,
            'next_cursor' => $more ? (string) end($rows)->id : null,
        ];
    }

    private static function __resolve_class(string $class): string
    {
        if (str_contains($class, '\\')) {
            return ltrim($class, '\\');
        }

        $fqcn = Manifest::php_class_metadata($class)['fqcn'] ?? null;

        return $fqcn ?? $class;
    }

    private static function __status_id($status): int
    {
        return static::__enum_id('status_id', $status);
    }

    /** An enum id from its id or its label (case-insensitive). */
    private static function __enum_id(string $column, $value): int
    {
        if (is_int($value) || ctype_digit((string) $value)) {
            $id = (int) $value;
            if (isset(static::$enums[$column][$id])) {
                return $id;
            }
        } else {
            foreach (static::$enums[$column] as $id => $definition) {
                if (strcasecmp($definition['label'], (string) $value) === 0) {
                    return $id;
                }
            }
        }

        throw new \InvalidArgumentException("Unknown {$column} '{$value}' in a task search.");
    }

    private static function __moment($value): string
    {
        return date('Y-m-d H:i:s', strtotime(Rsx_Time::to_iso($value))) . '.000';
    }

    // ------------------------------------------------------------------------------------
    // Lifecycle operations
    //
    // Each records an OPERATOR line on the run's output naming who did it, so the console
    // shows the run's history alongside what it printed. None of them is gated here: an
    // endpoint acting for a user asks Task_Gates::can_control() first.
    // ------------------------------------------------------------------------------------

    /**
     * GRACEFUL STOP: ask the run to stop at its next is_stop_requested() check. Nothing is
     * killed, ever - a task that never asks runs to completion. A PENDING run sees the request
     * from its first check. Returns false when the run is already over.
     */
    public function request_stop(?string $explanation = null): bool
    {
        if (!$this->__flag_stop()) {
            return false;
        }

        $this->__operator_line('Graceful stop requested', $explanation);

        return true;
    }

    /**
     * FORCE STOP: ask the run to stop, and kill its worker if it is still running after
     * $grace_seconds (default rsx.tasks.stop_grace_seconds, 60). The kill is carried out by a
     * kill worker (Task_Kill_Worker), never in this request. A PENDING run is cancelled
     * instead - it has no worker to kill. Returns false when the run is already over.
     */
    public function force_stop(?int $grace_seconds = null, ?string $explanation = null): bool
    {
        if ((int) $this->status_id === static::STATUS_PENDING) {
            return $this->cancel($explanation);
        }

        $grace_seconds ??= (int) config('rsx.tasks.stop_grace_seconds');
        if ($grace_seconds < 0) {
            throw new \InvalidArgumentException("force_stop() takes a grace period of 0 seconds or more, got {$grace_seconds}.");
        }

        if (!$this->__flag_stop()) {
            return false;
        }

        $request = Task_Kill_Worker::request(static::find($this->id), Task_Kill_Request_Model::MODE_FORCE_STOP, $grace_seconds, static::__reason('force stop', $explanation), static::__actor_label());
        $line = $request !== null
            ? "Force stop requested: killed if still running in {$grace_seconds}s"
            : 'Force stop requested: a kill of this run is already pending';
        $this->__operator_line($line, $explanation);

        return true;
    }

    /**
     * FORCE KILL: kill the run's worker now (by a kill worker, within moments). A PENDING run
     * is cancelled instead. Returns false when the run is already over.
     */
    public function force_kill(?string $explanation = null): bool
    {
        if ((int) $this->status_id === static::STATUS_PENDING) {
            return $this->cancel($explanation);
        }
        if ((int) $this->status_id !== static::STATUS_RUNNING) {
            return false;
        }

        Task_Kill_Worker::request($this, Task_Kill_Request_Model::MODE_FORCE_KILL, 0, static::__reason('force kill', $explanation), static::__actor_label());
        $this->__operator_line('Force kill requested', $explanation);

        return true;
    }

    /**
     * CANCEL: take a PENDING run off the queue; it never runs. Returns false when the run has
     * already started or ended.
     */
    public function cancel(?string $explanation = null): bool
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $cancelled = DB::table('_tasks')
            ->where('id', $this->id)
            ->where('status_id', static::STATUS_PENDING)
            ->update([
                'status_id' => static::STATUS_CANCELLED,
                'status_reason' => static::__reason('cancelled', $explanation),
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        if (!$cancelled) {
            return false;
        }

        $this->__reload();
        $this->__operator_line('Cancelled', $explanation);
        Task_Notify::lifecycle((int) $this->id);

        return true;
    }

    /**
     * RERUN: dispatch the same task with the same params again, and return the new run's id.
     * The original must be over. #[Exclusive] / #[Debounce] apply as to any dispatch, so the id
     * may be an already-pending run's.
     */
    public function rerun(): int
    {
        if ($this->is_live()) {
            throw new \RuntimeException("Task {$this->id} is still " . strtolower(static::$enums['status_id'][(int) $this->status_id]['label']) . '; only a finished run can be run again.');
        }

        $new_id = Task::dispatch($this->service_name(), $this->method, $this->params ?? []);
        $this->__operator_line("Run again as task {$new_id}", null);

        return $new_id;
    }

    private function __flag_stop(): bool
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $flagged = DB::table('_tasks')
            ->where('id', $this->id)
            ->whereIn('status_id', self::LIVE_STATUSES)
            ->update([
                'stop_requested_at' => DB::raw("COALESCE(stop_requested_at, '{$now}')"),
                'updated_at' => $now,
            ]);

        if ($flagged) {
            $this->__reload();
            Task_Notify::changed((int) $this->id);
        }

        return (bool) $flagged;
    }

    /**
     * Re-read this row's columns after a guarded write. Not Eloquent's refresh(): that reloads
     * relations through load(), which Rsx_Model_Abstract refuses.
     */
    private function __reload(): void
    {
        $this->setRawAttributes((array) DB::table('_tasks')->where('id', $this->id)->first(), true);
    }

    private function __operator_line(string $what, ?string $explanation): void
    {
        $line = $what . ' by ' . static::__actor_label();
        if ($explanation !== null && trim($explanation) !== '') {
            $line .= ': ' . trim($explanation);
        }

        Task_Instance::record_operator_line((int) $this->id, $line);
    }

    private static function __reason(string $what, ?string $explanation): string
    {
        $reason = $what . ' by ' . static::__actor_label();

        return $explanation !== null && trim($explanation) !== '' ? $reason . ': ' . trim($explanation) : $reason;
    }

    /**
     * Who is acting, for an operator line: the signed-in identity's printed name, or where
     * the request came from when nobody is signed in.
     */
    private static function __actor_label(): string
    {
        $actor = Rsx_Model_Abstract::_resolve_context_actor();
        if ($actor !== null) {
            $class = \App\RSpade\Core\Manifest\Manifest::php_class_metadata($actor['type'])['fqcn'] ?? null;
            $record = $class !== null ? $class::withTrashed()->find($actor['id']) : null;
            if ($record !== null) {
                return $record->get_printed_name();
            }
        }

        return php_sapi_name() === 'cli' ? 'the command line' : 'an anonymous request';
    }

    // ------------------------------------------------------------------------------------
    // Report readers
    // ------------------------------------------------------------------------------------

    /** The last status text the task reported, or null. */
    public function status_text(): ?string
    {
        return $this->status_text;
    }

    /**
     * Progress as a percentage, 0-100 with two decimals, or null when the task reported none.
     * A task that reported only a count (progress_count) has its percentage derived from it.
     */
    public function progress_percent(): ?float
    {
        if ($this->progress_percent !== null) {
            return round((float) $this->progress_percent, 2);
        }

        $count = $this->progress_count();
        if ($count === null || $count['total'] <= 0) {
            return null;
        }

        return round(min(100, max(0, $count['done'] * 100 / $count['total'])), 2);
    }

    /**
     * Progress as a count ("3 of 257"), or null when the task reported none.
     *
     * @return array{done: int, total: int}|null
     */
    public function progress_count(): ?array
    {
        if ($this->progress_total === null) {
            return null;
        }

        return ['done' => (int) $this->progress_done, 'total' => (int) $this->progress_total];
    }

    /** The reported ETA as a moment (ISO), or null. */
    public function eta_at(): ?string
    {
        return $this->eta_at !== null ? Rsx_Time::to_iso($this->eta_at) : null;
    }

    /** The state object the task reported with state(), or null. */
    public function state()
    {
        $body = $this->__report_body(self::REPORT_STATE_JSON);

        return $body === null ? null : json_decode($body, true);
    }

    /** The list the task reported with state_list(), or null. */
    public function state_list(): ?array
    {
        $body = $this->__report_body(self::REPORT_STATE_LIST);

        return $body === null ? null : json_decode($body, true);
    }

    /** The completion summary the task set with summary(), or null. */
    public function summary(): ?string
    {
        return $this->__report_body(self::REPORT_SUMMARY);
    }

    /**
     * The names of the reports this run has actually set, in a stable order: the kinds a
     * report viewer offers. Any of: status_text, progress, progress_count, eta, heartbeat,
     * state_json, state_list, summary, messages, return_code.
     *
     * @return string[]
     */
    public function available_reports(): array
    {
        $kinds = [];

        $stored = DB::table('_task_reports')->where('task_id', $this->id)->pluck('kind_id')->map(fn ($k) => (int) $k)->all();
        if (in_array(self::REPORT_STATE_JSON, $stored, true)) {
            $kinds[] = 'state_json';
        }
        if (in_array(self::REPORT_STATE_LIST, $stored, true)) {
            $kinds[] = 'state_list';
        }
        if ($this->status_text !== null) {
            $kinds[] = 'status_text';
        }
        if ($this->progress_percent !== null) {
            $kinds[] = 'progress';
        }
        if ($this->progress_total !== null) {
            $kinds[] = 'progress_count';
        }
        if ($this->eta_at !== null) {
            $kinds[] = 'eta';
        }
        if ($this->last_heartbeat_at !== null) {
            $kinds[] = 'heartbeat';
        }
        if (DB::table('_task_messages')->where('task_id', $this->id)->exists()) {
            $kinds[] = 'messages';
        }
        if (in_array(self::REPORT_SUMMARY, $stored, true)) {
            $kinds[] = 'summary';
        }
        if ($this->return_code !== null) {
            $kinds[] = 'return_code';
        }

        return $kinds;
    }

    /**
     * Output lines after a cursor, oldest first: [{id, stream, line, at}]. $after_id null
     * reads from the start. $streams narrows to 'stdout' / 'stderr' / 'operator'.
     *
     * The set is one page of at most $limit lines; read on from the last id for the rest.
     *
     * @param int|null $after_id
     * @param string[] $streams
     * @param int $limit
     * @return array<int, array{id: int, stream: string, line: string, at: string}>
     */
    public function output_after(?int $after_id = null, array $streams = ['stdout', 'stderr', 'operator'], int $limit = 1000): array
    {
        $stream_ids = [];
        foreach ($streams as $stream) {
            $id = array_search($stream, self::STREAM_NAMES, true);
            if ($id === false) {
                throw new \InvalidArgumentException("Unknown task output stream '{$stream}'; expected stdout, stderr or operator.");
            }
            $stream_ids[] = $id;
        }

        $query = DB::table('_task_output')
            ->where('task_id', $this->id)
            ->whereIn('stream_id', $stream_ids)
            ->orderBy('id')
            ->limit($limit);

        if ($after_id !== null) {
            $query->where('id', '>', $after_id);
        }

        return $query->get(['id', 'stream_id', 'line', 'created_at'])->map(fn ($row) => [
            'id' => (int) $row->id,
            'stream' => self::STREAM_NAMES[(int) $row->stream_id],
            'line' => $row->line,
            'at' => Rsx_Time::to_iso($row->created_at),
        ])->all();
    }

    /**
     * Messages after a cursor, oldest first: [{id, body, at}]. One page of at most $limit.
     *
     * @return array<int, array{id: int, body: string, at: string}>
     */
    public function messages_after(?int $after_id = null, int $limit = 1000): array
    {
        $query = DB::table('_task_messages')->where('task_id', $this->id)->orderBy('id')->limit($limit);
        if ($after_id !== null) {
            $query->where('id', '>', $after_id);
        }

        return $query->get(['id', 'body', 'created_at'])->map(fn ($row) => [
            'id' => (int) $row->id,
            'body' => $row->body,
            'at' => Rsx_Time::to_iso($row->created_at),
        ])->all();
    }

    /**
     * The files this run attached, by name.
     *
     * @return Task_Attachment_Model[]
     */
    public function attachments(): array
    {
        return Task_Attachment_Model::where('task_id', $this->id)->orderBy('name')->get()->all();
    }

    /** One attachment by the name the task gave it, or null. */
    public function attachment(string $name): ?Task_Attachment_Model
    {
        return Task_Attachment_Model::where('task_id', $this->id)->where('name', $name)->first();
    }

    /**
     * The run as JSON-ready data: the shape the task API endpoints and the task widgets read.
     * Lifecycle moments are ISO strings or null; reports a task never set are null.
     *
     * @return array
     */
    public function to_status_array(): array
    {
        $iso = fn ($value) => $value !== null ? Rsx_Time::to_iso($value) : null;

        return [
            'id' => (int) $this->id,
            'class' => $this->service_name(),
            'method' => $this->method,
            'name' => $this->task_name(),
            'params' => $this->params ?? [],
            'origin' => self::$enums['origin_id'][(int) $this->origin_id]['label'],
            'status_id' => (int) $this->status_id,
            'status' => self::$enums['status_id'][(int) $this->status_id]['label'],
            'is_live' => $this->is_live(),
            'status_reason' => $this->status_reason,
            'error' => $this->error,
            'return_code' => $this->return_code !== null ? (int) $this->return_code : null,
            'scheduled_for' => $iso($this->scheduled_for),
            'started_at' => $iso($this->started_at),
            'completed_at' => $iso($this->completed_at),
            'stop_requested_at' => $iso($this->stop_requested_at),
            'status_text' => $this->status_text,
            'progress_percent' => $this->progress_percent(),
            'progress_count' => $this->progress_count(),
            'eta_at' => $this->eta_at(),
            'last_heartbeat_at' => $iso($this->last_heartbeat_at),
            'last_report_at' => $iso($this->last_report_at),
            'reports' => $this->available_reports(),
        ];
    }

    private function __report_body(int $kind_id): ?string
    {
        $body = DB::table('_task_reports')->where('task_id', $this->id)->where('kind_id', $kind_id)->value('body');

        return $body === null ? null : (string) $body;
    }
}
