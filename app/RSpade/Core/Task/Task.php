<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Exception;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Notify;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Task - starting background work: dispatch() a run to the worker pools, or run one
 * internal()ly in this process. Either way the run is a _tasks row (Task_Run_Model) that
 * outside code reads and acts on, and the task reports into through its Task_Instance.
 */
class Task
{
    /**
     * Run a task in THIS process and return its settled row.
     *
     * The run is recorded like any other (origin Inline): its row, its reports, its output.
     * A task that is #[Exclusive] or #[Debounce] waits for a running instance of its identity
     * to finish first - an inline run is a run, and the single-instance guarantee holds.
     *
     * The return value is the run's row, settled. A task that THROWS is settled FAILED and the
     * exception is rethrown to the caller; a task that returns a failure code is settled
     * FAILED and returned (read status_id / return_code).
     *
     * @param string $rsx_service Service name (e.g., 'Seeder_Service')
     * @param string $rsx_task Task/method name (e.g., 'seed_clients')
     * @param array $params Parameters to pass to the task
     * @param array{0: resource|null, 1: resource|null}|null $console_streams RUNNER-ONLY.
     *        [stdout, stderr] the run's output lines are echoed to, live. rsx:task:run and the
     *        #[Command] aliases pass their own; every other caller passes nothing, so a web
     *        request or a task calling another task prints to nobody's console.
     * @return Task_Run_Model
     * @throws \Throwable whatever the task threw
     */
    public static function internal($rsx_service, $rsx_task, $params = [], ?array $console_streams = null): Task_Run_Model
    {
        $service_class = static::resolve_task_class($rsx_service, $rsx_task);
        $params_hash = Task_Concurrency::params_hash($params);

        // An inline run of a single-instance task takes the identity's run lock like a
        // worker would, waiting for a running instance to finish.
        $run_lock = Task_Concurrency::is_managed($service_class, $rsx_task)
            ? Task_Concurrency::acquire_run_lock($service_class, $rsx_task, $params_hash)
            : null;

        $id = Task_Runner::insert_row($service_class, $rsx_task, $params, Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields());
        Task_Notify::lifecycle($id);

        // A fatal error ends this process without returning here; the run must not be left
        // RUNNING behind it.
        register_shutdown_function([Task_Runner::class, 'settle_abandoned_inline'], $id);

        $instance = Task_Instance::find($id);
        if ($console_streams !== null) {
            $instance->set_console_streams($console_streams[0] ?? null, $console_streams[1] ?? null);
        }

        // One task is one unit of work for revision history - the in-process twin of the
        // reset a worker performs per task. The caller's unit is handed back in the finally
        // below: a web request that runs a task in-process keeps filing its own later writes
        // under its own transaction, not under the task's.
        $previous_revision_state = Revision::_snapshot_request_state();
        Revision::_reset_request_state('task', $service_class . '::' . $rsx_task);

        try {
            $outcome = Task_Runner::execute($instance, false);
            Task_Runner::settle($instance, $outcome, $run_lock);
        } finally {
            Revision::_restore_request_state($previous_revision_state);
        }

        if ($outcome->throwable !== null) {
            throw $outcome->throwable;
        }

        return Task_Run_Model::find($id);
    }

    /**
     * Dispatch a task: enqueue a run and start it promptly. Returns the run's id - the row to
     * watch (Task_Run_Model::find($id)).
     *
     * The row is written PENDING and, when the run is due now, a detached worker is spawned
     * if a pool has room (on_demand first, then scheduled - the scheduled pool takes on-demand
     * work first). The ONLY thing that defers a run is a future 'scheduled_for': it waits and
     * is picked up by the cron tick (or a later spawn) once due. A process that turned
     * spawning off - and every test, unless it opted in - enqueues only; see spawn_workers().
     *
     * A task that is #[Exclusive] or #[Debounce] is coalesced: at most one run of its identity
     * is ever pending (Task_Concurrency), and the id returned is that pending run's, whether it
     * already existed or was just created.
     *
     * @param string $rsx_service Service name (basename, e.g. 'Seeder_Service')
     * @param string $rsx_task    Static task method (e.g. 'seed_clients')
     * @param array  $params      Parameters to pass to the task
     * @param array  $options     Optional:
     *   - 'scheduled_for' => Earliest run time (default: now). A future value defers the run.
     *                        Refused (throws) for an #[Exclusive] / #[Debounce] task, which
     *                        times its own runs.
     *   - 'timeout'       => Maximum execution time in seconds (default: from config)
     * @return int The run's id (the existing pending run's, when coalesced).
     */
    public static function dispatch(string $rsx_service, string $rsx_task, array $params = [], array $options = []): int
    {
        $unknown = array_diff(array_keys($options), ['scheduled_for', 'timeout']);
        if ($unknown !== []) {
            throw new Exception('Task::dispatch() options are scheduled_for and timeout; unknown: ' . implode(', ', $unknown));
        }

        $service_class = static::resolve_task_class($rsx_service, $rsx_task);
        $managed = Task_Concurrency::is_managed($service_class, $rsx_task);

        if ($managed && isset($options['scheduled_for'])) {
            throw new Exception("Task::dispatch(): {$rsx_service}::{$rsx_task} is #[Exclusive] or #[Debounce], which times its own runs; scheduled_for cannot be given.");
        }

        $fields = [];
        if (array_key_exists('timeout', $options)) {
            $fields['timeout'] = $options['timeout'];
        }

        $scheduled_for = null;
        if ($managed) {
            $id = Task_Concurrency::enqueue_coalesced(
                $service_class,
                $rsx_task,
                Task_Concurrency::params_hash($params),
                function (string $coalesced_for) use ($service_class, $rsx_task, $params, $fields) {
                    $id = Task_Runner::insert_row($service_class, $rsx_task, $params, Task_Run_Model::ORIGIN_DISPATCHED, $fields + ['scheduled_for' => $coalesced_for]);
                    Task_Notify::lifecycle($id);

                    return $id;
                }
            );
        } else {
            if (isset($options['scheduled_for'])) {
                $scheduled_for = \Illuminate\Support\Carbon::parse($options['scheduled_for']);
                $fields['scheduled_for'] = $scheduled_for->format('Y-m-d H:i:s.v');
            }
            $id = Task_Runner::insert_row($service_class, $rsx_task, $params, Task_Run_Model::ORIGIN_DISPATCHED, $fields);
            Task_Notify::lifecycle($id);
        }

        // Run promptly. Managed tasks always spawn now (the coalesced row governs their
        // debounce timing); an unmanaged task with a future scheduled_for is deferred to the
        // cron tick. With both pools full nothing is spawned: a busy worker reaches this row
        // when it finishes its current one, and the cron tick covers the rest.
        if ($managed || $scheduled_for === null || !$scheduled_for->isFuture()) {
            static::spawn_worker(Task_Pool::ON_DEMAND) || static::spawn_worker(Task_Pool::SCHEDULED);
        }

        return $id;
    }

    /**
     * Get all scheduled tasks from manifest
     *
     * Scans the manifest for methods with #[Schedule] attribute
     * and returns information about each scheduled task.
     *
     * @return array Array of scheduled task definitions
     */
    public static function get_scheduled_tasks(): array
    {
        $scheduled_tasks = [];

        // The attribute index answers "who declares #[Schedule]" directly, arguments
        // included. This used to be a full sweep of every indexed file's method map, once
        // per scheduler tick.
        foreach (Manifest::by_attribute('Schedule') as $row) {
            if ($row['member'] === null || $row['class'] === null) {
                continue;
            }

            $fqcn = Manifest::php_class_metadata($row['class'])['fqcn'] ?? null;

            if ($fqcn === null) {
                continue;
            }

            foreach ($row['instances'] as $attr_instance) {
                $cron_expression = $attr_instance[0] ?? null;

                if (!$cron_expression) {
                    continue;
                }

                $scheduled_tasks[] = [
                    'class' => $fqcn,
                    'method' => $row['member'],
                    'cron_expression' => $cron_expression,
                ];
            }
        }

        return $scheduled_tasks;
    }

    /**
     * Whether this process spawns workers at all - see spawn_workers(). Null is the process
     * default: spawn, except under the test suite.
     *
     * @var bool|null
     */
    private static ?bool $spawn_workers = null;

    /**
     * Pids of the workers THIS process spawned, per pool, pruned to the ones still running at
     * every spawn_worker() call (is_worker_process()). Never holds more than a pool's cap.
     *
     * @var array<string, int[]>
     */
    private static array $spawned_worker_pids = [];

    /**
     * Turn worker spawning on or off for the rest of THIS process.
     *
     * Off, dispatch() still ENQUEUES - every row is written exactly as before - but starts no
     * worker; the cron tick (rsx:task:process, every minute) spawns the workers that drain the
     * queue. This is the sanctioned switch for a long-running script that writes many rows:
     * every model save, mail send or upload may dispatch a task, and each dispatch that finds
     * room in a pool starts a whole PHP process. Turning spawning off leaves the work to the
     * pools' own schedule and keeps the script's CPU for the script.
     *
     * It is also the test-suite behaviour. Under rsx:test the default is OFF: a detached worker
     * is a whole PHP boot racing the test that dispatched it, so a test that asserts on queued
     * work drives it itself (Task::internal(), the service method, or
     * Artisan::call('rsx:task:worker') in-process). A test whose SUBJECT is the spawn calls
     * this with true; the harness calls it with false at every class boundary, so the opt-in
     * never outlives the class that made it.
     *
     * It governs this process only. A worker, a web request and the cron tick each decide
     * for themselves.
     *
     * @param bool $enabled
     * @return void
     */
    public static function spawn_workers(bool $enabled): void
    {
        self::$spawn_workers = $enabled;
    }

    /**
     * Does this process spawn workers? The value spawn_workers() set, or the process default:
     * true, except under the test suite.
     *
     * @return bool
     */
    public static function spawning_workers(): bool
    {
        return self::$spawn_workers ?? !Rsx_Test_Abstract::suite_is_running();
    }

    /**
     * Spawn a detached worker for $pool (fire-and-forget) - if, and only if, the pool has
     * room for it. A task pool (on_demand, scheduled) gets an rsx:task:worker, the kill pool an
     * rsx:task:killer.
     *
     * Three refusals come before the spawn, cheapest first:
     *
     *   0. Maintenance mode (Framework_Maintenance::is_active()): nothing is started while the
     *      window is up - the worker command itself is refused there. The work stays queued,
     *      and the first rsx:task:process tick after the window closes starts its worker.
     *   1. The workers THIS process spawned into the pool that are still running
     *      (is_worker_process(), /proc, no shared state). This is what caps a script that
     *      dispatches on every write: once its own spawns fill the cap, every further
     *      dispatch returns here, with no daemon round trip, until one of them exits.
     *   2. The pool itself, counted by rsx-lockd (Task_Pool): under the pool lock, the members
     *      OTHER than this process (count()) plus this process when it is a member itself - a
     *      worker whose task dispatches is one of the workers. At the cap, nothing is started.
     *
     * The count is read and the lock released BEFORE the spawn: starting a process waits on
     * a shell handshake, and nothing but pool ops and task rows may run under a pool lock
     * (Task_Pool, THE RULE). So concurrent spawners can each see room and each start a
     * worker; the worker ADMITS ITSELF under the lock and one that finds the pool full exits
     * at once. The cap is enforced there, never here.
     *
     * A lost or unreachable daemon THROWS: the pool is the admission count, and rsx-lockd is
     * a hard framework dependency.
     *
     * @return bool True when a worker was spawned; false when the pool is full, maintenance
     *              mode is up, or this process does not spawn workers (spawn_workers()).
     */
    public static function spawn_worker(string $pool): bool
    {
        if (!self::spawning_workers()) {
            return false;
        }

        if (Framework_Maintenance::is_active()) {
            return false;
        }

        $cap = Task_Pool::max_workers($pool);

        self::$spawned_worker_pids[$pool] = array_values(array_filter(
            self::$spawned_worker_pids[$pool] ?? [],
            [self::class, 'is_worker_process']
        ));
        if (count(self::$spawned_worker_pids[$pool]) >= $cap) {
            return false;
        }

        // Taking a pool lock while holding one would park this process behind itself.
        // Only a worker's claim/settle section holds one, and nothing there dispatches.
        if (Task_Pool::holds_lock()) {
            shouldnt_happen('Task::spawn_worker() called while this process holds a task pool lock');
        }

        // THE RULE: under the pool lock, pool ops only.
        Task_Pool::lock($pool);
        try {
            $members = Task_Pool::count($pool) + (Task_Pool::member_pool() === $pool ? 1 : 0);
        } finally {
            // A lost connection already released the lock at the daemon (and holds_lock()
            // says so); unlocking then would only bury the real failure under a refusal.
            if (Task_Pool::holds_lock($pool)) {
                Task_Pool::unlock($pool);
            }
        }

        if ($members >= $cap) {
            return false;
        }

        // Fully detached: we do NOT wait for this worker, so it must NOT inherit our
        // lock group. A worker that ran concurrently while holding a lock this process
        // also holds would break the exclusion both of them think they have - which is
        // why propagation is opt-in and this caller does not opt in.
        $pid = $pool === Task_Pool::KILL
            ? Rsx_Artisan::dispatch_detached('rsx:task:killer')
            : Rsx_Artisan::dispatch_detached('rsx:task:worker', ['--pool=' . $pool]);

        if ($pid === null) {
            return false;
        }

        self::$spawned_worker_pids[$pool][] = $pid;

        return true;
    }

    /**
     * Is this pid a running task or kill worker of THIS project? Read from /proc/<pid>/cmdline,
     * so it needs no shared state at all.
     *
     * A worker is a process whose command line names both this project's artisan path and
     * rsx:task:worker or rsx:task:killer - the command line Rsx_Artisan::dispatch_detached()
     * builds. The match is on the command-line TEXT, not on exact argv tokens, so a worker is
     * recognised from the moment it is forked: until its exec the child is a copy of the
     * spawning bash, whose single `-c` argument carries that same text. And the command line,
     * rather than bare pid existence, is what makes the answer safe to act on: a pid the
     * kernel reused for an unrelated process is not a worker, and an exited worker nobody has
     * reaped yet (a zombie) has an empty command line. A process can exit between the check
     * and the read, and that race IS the answer "not running", so a failed read means gone.
     *
     * @param int $pid
     * @return bool
     */
    public static function is_worker_process(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if ($cmdline === false || $cmdline === '') {
            return false;
        }

        return (str_contains($cmdline, 'rsx:task:worker') || str_contains($cmdline, 'rsx:task:killer'))
            && str_contains($cmdline, base_path('artisan'));
    }

    /**
     * Resolve a service basename to its FQCN and validate that $rsx_task is a #[Task] method of
     * it. Throws, naming what is wrong, when it is not.
     *
     * @param string $rsx_service
     * @param string $rsx_task
     * @return string Fully-qualified service class
     * @throws Exception
     */
    public static function resolve_task_class(string $rsx_service, string $rsx_task): string
    {
        $record = Manifest::php_class_metadata($rsx_service);
        $service_class = $record['fqcn'] ?? null;

        if ($service_class === null) {
            throw new Exception("Service class not found: {$rsx_service}");
        }

        if (!Manifest::php_is_subclass_of($service_class, Rsx_Service_Abstract::class)) {
            throw new Exception("Service {$service_class} must extend Rsx_Service_Abstract");
        }

        // A service's file record is in the HOT half of the index precisely because of this
        // read - #[Task], #[Exclusive] and #[Debounce] are consulted on every dispatch.
        $method_info = Manifest::get_file($record['file'])['public_static_methods'][$rsx_task] ?? null;

        if ($method_info === null) {
            throw new Exception("Task {$rsx_task} not found in service {$service_class}");
        }

        if (!isset($method_info['attributes']['Task'])) {
            throw new Exception("Method {$rsx_task} in {$service_class} must have #[Task]");
        }

        return $service_class;
    }
}
