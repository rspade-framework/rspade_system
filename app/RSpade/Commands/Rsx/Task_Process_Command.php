<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Kill_Worker;
use App\RSpade\Core\Task\Task_Notify;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Health\Rsx_Container_Sizing;

/**
 * Task Process Command
 *
 * The scheduler/dispatcher tick. Run via system cron every minute:
 *   * * * * * cd /var/www/html && php artisan rsx:task:process
 *
 * Each tick it:
 * 1. Recovers RUNNING runs that will never settle on their own: those whose worker has left its
 *    pool (rsx-lockd's answer, asked under that pool's lock), and those whose live local worker
 *    has reported nothing for rsx.tasks.silent_timeout, or has outrun a timeout the run was
 *    dispatched with (a force kill is requested and a kill worker carries it out).
 * 2. Hands kill requests whose kill worker died back to the queue.
 * 3. Reconciles _task_schedules against the manifest's #[Schedule] declarations (register new,
 *    re-register a changed cron expression, delete removed) so schedule edits take effect
 *    within one tick.
 * 4. Tries ONE spawn per pool with due work: on_demand (else scheduled) for due dispatched
 *    runs, scheduled for due schedules, kill for due kill requests on this host. It does NOT
 *    become a worker itself.
 *
 * WHILE THE TASK SYSTEM IS OFF (Task::is_enabled(), read from the database at each spawn) steps 1-3 run as
 * always - a dead run is still settled, a kill request still gets its kill worker, which is
 * what rsx:tasks:disable on another host relies on - and step 4 starts no task worker, so
 * nothing new runs and no schedule produces a run. --once runs nothing.
 *
 * One try per tick is enough: a worker drains its work until nothing is claimable, and every
 * dispatch tries a spawn of its own. The spawn reads the pool's count first, so a full pool
 * (or maintenance mode) starts nothing; a worker that starts anyway admits itself under its
 * pool lock and exits when it finds no room.
 */
class Task_Process_Command extends Command
{
    protected $signature = 'rsx:task:process
        {--once : Process one pending task inline then exit (for testing)}
        {--force-scheduled : Make every schedule due now}';

    protected $description = 'Scheduler/dispatcher tick: reconcile schedules and spawn workers (run via cron every minute)';

    /**
     * SILENT ON AN IDLE TICK. This runs from cron every minute, so anything printed
     * unconditionally is printed 1,440 times a day into the service log, burying the
     * lines that matter. There is deliberately no "starting"/"complete" pair.
     *
     * Everything below still speaks up, because everything below is CONDITIONAL: an abandoned
     * run and a silent or timed-out run warn (an unreachable rsx-lockd throws); a schedule actually registered,
     * changed or removed says so; a spawn says so when it started a worker. --once is a testing
     * flag and narrates itself. Keep it that way - problems and real events, never a heartbeat.
     */
    public function handle()
    {
        $this->detect_stuck_tasks();
        $this->recover_kill_requests();
        $this->reconcile_schedules((bool) $this->option('force-scheduled'));
        $this->spawn_workers_for_due_work();

        // Once per container start: a box the automatic sizing capped says so in the log.
        Rsx_Container_Sizing::log_cap_notice();

        // Testing aid: drain one task inline instead of relying on a spawned worker.
        if ($this->option('once')) {
            if (Task::is_enabled()) {
                $this->process_one_task();
            } else {
                $this->info('[ONCE MODE] The task system is disabled; the queue waits for: php artisan rsx:tasks:enable');
            }
        }

        return 0;
    }

    /**
     * Recover RUNNING runs that will never settle on their own. Two arms:
     *
     * 1. ABANDONED - the run's worker is gone. A dispatched run goes back to pending for a
     *    paced retry (FAILED once rsx.tasks.retry.attempts runs were abandoned); a scheduled run
     *    is FAILED (its schedule runs again at its next cadence) and so is an inline run (its
     *    caller is gone). settle_abandoned() has the detail. There is NO grace period: the
     *    verdicts below are evidence, never an age.
     *
     *    A run carrying a pool identity (worker_id + worker_generation) is judged by rsx-lockd,
     *    one pool.members_alive round trip per pool, UNDER THAT POOL'S LOCK (THE RULE: pool ops
     *    and task rows only), so the view of the pool and of the rows is one snapshot:
     *
     *      - the daemon's own generation (known): not alive means the worker's connection is
     *        gone, wherever it ran - abandoned;
     *      - an older generation (known: false - the daemon restarted since the claim, and a
     *        restart ends every membership while the workers keep running): only the host
     *        that ran the worker can tell, by its pid. On this host the pid must still be a
     *        TASK WORKER of this project (Task::is_worker_process(): its command line, never
     *        bare pid existence - after a reboot the kernel hands old pids to unrelated
     *        processes): one that is not is abandoned, one that is is left to record its own
     *        outcome; a run from another host is left alone for that host's own tick.
     *
     *    A run with NO worker_id ran inline (Task::internal(), rsx:task:run, --once), which is
     *    no pool member: the verdict is the local pid probe, taken only on the run's own host.
     *    An inline run's process may be a php-fpm worker or any artisan command, so there is
     *    no command line to hold it to - the probe is pid existence.
     *
     * 2. SILENT, OR PAST A REQUESTED TIMEOUT - a POOL worker is alive AND runs on this host,
     *    and its run has shown no sign of life for rsx.tasks.silent_timeout seconds, or - for a
     *    run dispatched with an explicit timeout - has run that long since it started
     *    (enforce_task_timeout()). A force kill is requested and a kill worker carries it out -
     *    the same path an operator's force kill takes. This is the only enforcement of either
     *    limit, so their granularity is one cron tick.
     */
    private function detect_stuck_tasks(): void
    {
        $this_host = Task_Pool::host();
        $live = [];

        foreach ([Task_Run_Model::POOL_ON_DEMAND => Task_Pool::ON_DEMAND, Task_Run_Model::POOL_SCHEDULED => Task_Pool::SCHEDULED] as $pool_id => $pool) {
            Task_Pool::lock($pool);
            try {
                $running = DB::table('_tasks')
                    ->where('status_id', Task_Run_Model::STATUS_RUNNING)
                    ->where('pool_id', $pool_id)
                    ->whereNotNull('worker_id')
                    ->get();

                // BIGINT columns may arrive as strings: the daemon is asked in integers.
                $members = [];
                foreach ($running as $task) {
                    $members[$task->id] = ['wid' => (int) $task->worker_id, 'generation' => (int) $task->worker_generation];
                }
                $verdicts = $members ? Task_Pool::members_alive($pool, $members) : [];

                foreach ($running as $task) {
                    $local = $task->worker_host === null || $task->worker_host === $this_host;
                    $verdict = $verdicts[$task->id];

                    if ($verdict['known']) {
                        if ($verdict['alive']) {
                            if ($local) {
                                $live[] = $task;
                            }
                            continue;
                        }
                        $reason = "worker {$task->worker_id} is no longer connected to rsx-lockd"
                            . " (host {$task->worker_host}, PID {$task->worker_pid})";
                    } else {
                        if (!$local) {
                            // Another host's worker from an older daemon generation: only that
                            // host's pid evidence can settle it.
                            continue;
                        }
                        if (Task::is_worker_process((int) $task->worker_pid)) {
                            $live[] = $task;
                            continue;
                        }
                        $reason = "worker {$task->worker_id} of a previous rsx-lockd generation is no longer"
                            . " running (PID {$task->worker_pid} is not a task worker on {$this_host})";
                    }

                    $this->settle_abandoned($task, $reason);
                }
            } finally {
                // A lost connection already released the lock at the daemon (and holds_lock()
                // says so); unlocking then would only bury the real failure under a refusal.
                if (Task_Pool::holds_lock($pool)) {
                    Task_Pool::unlock($pool);
                }
                Task_Notify::flush_deferred();
            }
        }

        // Inline runs: no pool, judged by their pid on their own host.
        $inline = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->whereNull('worker_id')
            ->get();
        foreach ($inline as $task) {
            if ($task->worker_host !== null && $task->worker_host !== $this_host) {
                continue;
            }
            if ($this->pid_alive((int) $task->worker_pid)) {
                $live[] = $task;
                continue;
            }
            $this->settle_abandoned($task, "the process running it (PID {$task->worker_pid}) is gone");
        }
        Task_Notify::flush_deferred();

        foreach ($live as $task) {
            $this->enforce_task_timeout($task);
        }
    }

    private function pid_alive(int $pid): bool
    {
        return $pid > 0 && posix_kill($pid, 0);
    }

    /**
     * Settle a RUNNING run whose worker is gone - only the run as it was read (still RUNNING
     * under the same worker). Abandonment is not a failure of the task, so a DISPATCHED run is
     * retried: PENDING with scheduled_for = now + base * 2^(n-1), n being this abandonment's
     * number (abandon_count + 1), the worker columns cleared; the attempts-th abandonment FAILS
     * it for good. A SCHEDULED run is FAILED - its schedule runs again at its next cadence, and
     * the failure is counted on the schedule - and so is an INLINE run, whose caller is gone.
     *
     * @param object $task The RUNNING _tasks row as it was read
     * @param string $reason Which worker is gone, and how that is known
     */
    private function settle_abandoned(object $task, string $reason): void
    {
        $row = DB::table('_tasks')
            ->where('id', $task->id)
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->where('worker_pid', $task->worker_pid);
        if ($task->worker_id !== null) {
            $row->where('worker_id', $task->worker_id)->where('worker_generation', $task->worker_generation);
        } else {
            $row->whereNull('worker_id');
        }

        $abandonment = (int) $task->abandon_count + 1;
        $now = now()->format('Y-m-d H:i:s.v');
        $name = class_basename($task->class) . '::' . $task->method;

        if ((int) $task->origin_id === Task_Run_Model::ORIGIN_DISPATCHED) {
            [$base_seconds, $attempts] = static::__retry_pacing();

            if ($abandonment < $attempts) {
                $retry_at = time() + $base_seconds * (2 ** ($abandonment - 1));
                $next_attempt = $abandonment + 1;
                $retry_iso = date('c', $retry_at);

                $this->warn("[ABANDONED TASK] Task {$task->id} ({$name}) will retry as attempt {$next_attempt} of {$attempts} at {$retry_iso}: {$reason}");
                $settled = $row->update([
                    'status_id' => Task_Run_Model::STATUS_PENDING,
                    'status_reason' => "abandoned: {$reason}; retry as attempt {$next_attempt} of {$attempts} at {$retry_iso}",
                    'abandon_count' => $abandonment,
                    'scheduled_for' => date('Y-m-d H:i:s', $retry_at),
                    'started_at' => null,
                    'pool_id' => null,
                    'worker_pid' => null,
                    'worker_id' => null,
                    'worker_generation' => null,
                    'worker_host' => null,
                    'updated_at' => $now,
                ]);
                if ($settled) {
                    Task_Instance::record_operator_line((int) $task->id, "Abandoned - {$reason}. Retrying as attempt {$next_attempt} of {$attempts} at {$retry_iso}.");
                    Task_Notify::lifecycle((int) $task->id);
                }

                return;
            }

            $status_reason = "abandoned: {$reason}; attempt {$abandonment} of {$attempts}, not retried";
            $error = "Task abandoned - {$reason}. It was abandoned on all {$attempts} attempts (rsx.tasks.retry.attempts) and is not retried again.";
        } else {
            $status_reason = "abandoned: {$reason}";
            $error = "Task abandoned - {$reason}.";
        }

        $this->warn("[ABANDONED TASK] Failing task {$task->id} ({$name}): {$reason}");
        $settled = $row->update([
            'status_id' => Task_Run_Model::STATUS_FAILED,
            'status_reason' => $status_reason,
            'error' => $error,
            'return_code' => 1,
            'abandon_count' => $abandonment,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        if ($settled) {
            if ($task->schedule_id !== null) {
                DB::table('_task_schedules')->where('id', $task->schedule_id)->update([
                    'last_task_id' => $task->id,
                    'last_error_at' => $now,
                    'last_error' => $error,
                    'consecutive_failures' => DB::raw('consecutive_failures + 1'),
                    'updated_at' => $now,
                ]);
            }
            Task_Instance::record_operator_line((int) $task->id, $error);
            Task_Notify::lifecycle((int) $task->id);
        }
    }

    /**
     * rsx.tasks.retry, validated: [base_seconds, attempts].
     *
     * @return array{0: int, 1: int}
     */
    private static function __retry_pacing(): array
    {
        $base_seconds = config('rsx.tasks.retry.base_seconds');
        $attempts = config('rsx.tasks.retry.attempts');

        if (!is_int($base_seconds) || $base_seconds < 0) {
            throw new \RuntimeException('rsx.tasks.retry.base_seconds must be an integer >= 0, got ' . var_export($base_seconds, true));
        }
        if (!is_int($attempts) || $attempts < 1) {
            throw new \RuntimeException('rsx.tasks.retry.attempts must be an integer >= 1, got ' . var_export($attempts, true));
        }

        return [$base_seconds, $attempts];
    }

    /**
     * Request a force kill of a live worker-run task that is past its limit. Two rules, and a
     * run is under exactly one of them:
     *
     *   - SILENCE (the rule for every run that names no timeout): the run has shown no sign of
     *     life for rsx.tasks.silent_timeout seconds. The sign of life is the latest of
     *     started_at, last_heartbeat_at and last_report_at - every Task_Instance reporting
     *     call stamps one of them - so a task that keeps reporting has NO limit on how long it
     *     runs, and one that reports nothing is killed that long after it started. 0 = no
     *     silence limit.
     *   - A HARD LIMIT (only a run dispatched with an explicit 'timeout'): that many seconds
     *     from started_at, reporting or not - somebody asked for it by name.
     *
     * A run that already has a live kill request is left to it.
     *
     * @param object $task A RUNNING _tasks row whose worker process is alive on this host.
     */
    private function enforce_task_timeout(object $task): void
    {
        // An inline run belongs to the process that started it (a command, a request): its
        // length is that caller's business, and the reaper never kills it for time.
        if ($task->worker_id === null || $task->started_at === null) {
            return;
        }

        $now = time();
        $hard_limit = (int) ($task->timeout ?? 0);

        if ($hard_limit > 0) {
            $ran_for = $now - strtotime($task->started_at);
            if ($ran_for <= $hard_limit) {
                return;
            }

            $explanation = "timed out after {$ran_for}s (cap {$hard_limit}s)";
        } else {
            $silent_limit = static::__silent_timeout();
            if ($silent_limit === 0) {
                return;
            }

            $silent_for = $now - static::__last_sign_of_life($task);
            if ($silent_for <= $silent_limit) {
                return;
            }

            $explanation = "no report for {$silent_for}s (cap {$silent_limit}s)";
        }

        $requested = Task_Kill_Worker::request(Task_Run_Model::find($task->id), Task_Kill_Request_Model::MODE_FORCE_KILL, 0, $explanation, 'the timeout reaper');

        if ($requested) {
            $this->warn("[TASK TIMEOUT] {$task->class}::{$task->method} (task {$task->id}, worker PID {$task->worker_pid}) {$explanation} -> force kill requested");
        }
    }

    /**
     * When a RUNNING run last showed it was alive, as a unix time: the latest of its start, its
     * last heartbeat and its last written report.
     *
     * @param object $task A _tasks row with started_at set
     */
    private static function __last_sign_of_life(object $task): int
    {
        $latest = strtotime($task->started_at);

        foreach ([$task->last_heartbeat_at ?? null, $task->last_report_at ?? null] as $moment) {
            if ($moment !== null) {
                $latest = max($latest, strtotime($moment));
            }
        }

        return $latest;
    }

    /** rsx.tasks.silent_timeout, validated: whole seconds, 0 for no silence limit. */
    private static function __silent_timeout(): int
    {
        $seconds = config('rsx.tasks.silent_timeout');

        if (!is_int($seconds) || $seconds < 0) {
            throw new \RuntimeException('rsx.tasks.silent_timeout must be an integer >= 0 (seconds; 0 = no limit), got ' . var_export($seconds, true));
        }

        return $seconds;
    }

    /**
     * Hand back to the queue every kill request claimed by a kill worker on this host that is
     * no longer a member of the kill pool (it died mid-wait). Under the kill pool's lock.
     */
    private function recover_kill_requests(): void
    {
        $claimed = DB::table('_task_kill_requests')
            ->where('host', Task_Pool::host())
            ->where('status_id', Task_Kill_Request_Model::STATUS_CLAIMED)
            ->get(['id', 'worker_id', 'worker_generation', 'worker_pid']);

        if ($claimed->isEmpty()) {
            return;
        }

        Task_Pool::lock(Task_Pool::KILL);
        try {
            $members = [];
            foreach ($claimed as $request) {
                $members[$request->id] = ['wid' => (int) $request->worker_id, 'generation' => (int) $request->worker_generation];
            }
            $verdicts = Task_Pool::members_alive(Task_Pool::KILL, $members);

            foreach ($claimed as $request) {
                $verdict = $verdicts[$request->id];
                // An older generation's kill worker is judged like a task worker: by its
                // command line. A request with no worker_id was claimed by a command carrying
                // out its own kills (rsx:tasks:disable), which is no worker: its pid is probed.
                if ($verdict['known']) {
                    $gone = !$verdict['alive'];
                } elseif ($request->worker_id !== null) {
                    $gone = !Task::is_worker_process((int) $request->worker_pid);
                } else {
                    $gone = !$this->pid_alive((int) $request->worker_pid);
                }
                if (!$gone) {
                    continue;
                }

                DB::table('_task_kill_requests')
                    ->where('id', $request->id)
                    ->where('status_id', Task_Kill_Request_Model::STATUS_CLAIMED)
                    ->where('worker_id', $request->worker_id)
                    ->update([
                        'status_id' => Task_Kill_Request_Model::STATUS_PENDING,
                        'worker_pid' => null,
                        'worker_id' => null,
                        'worker_generation' => null,
                        'updated_at' => now(),
                    ]);
                $this->warn("[KILL REQUEST] Kill request {$request->id} lost its kill worker; queued again");
            }
        } finally {
            if (Task_Pool::holds_lock(Task_Pool::KILL)) {
                Task_Pool::unlock(Task_Pool::KILL);
            }
        }
    }

    /**
     * Reconcile _task_schedules against the manifest's #[Schedule] declarations.
     *
     * A new declaration is registered with next_run_at at its next cadence, read in the server
     * timezone (Rsx_Time::get_server_timezone()); the row records that zone and the five-field
     * expression the declaration resolved to (cron_resolved - a phrase such as "maintenance"
     * resolves through rsx.tasks.maintenance_hour). A declaration whose expression, resolution
     * or timezone changed is re-registered with next_run_at recomputed (a stale next_run_at from
     * an old expression, an old maintenance hour or an old wall clock never lingers) - its
     * statistics are kept; a row with no resolution recorded yet has it recorded and keeps its
     * next_run_at;
     * a schedule no longer declared is deleted (its past runs keep their rows, schedule_id
     * cleared).
     *
     * @param bool $force_all If true, make every schedule due now.
     */
    private function reconcile_schedules(bool $force_all): void
    {
        $seen = [];
        $now = now()->format('Y-m-d H:i:s.v');
        $timezone = Rsx_Time::get_server_timezone();

        foreach (Task::get_scheduled_tasks() as $task_def) {
            $class = $task_def['class'];
            $method = $task_def['method'];
            $cron_expression = $task_def['cron_expression'];
            $seen[$class . '::' . $method] = true;

            $existing = DB::table('_task_schedules')->where('class', $class)->where('method', $method)->first();
            $parser = new Cron_Parser($cron_expression, $timezone);
            $cron_resolved = $parser->get_cron_expression();
            $next_run_at = $force_all ? $now : date('Y-m-d H:i:s', $parser->get_next_run_time());

            if ($existing === null) {
                $this->info("[SCHEDULED] Registering new scheduled task {$class}::{$method} ({$cron_expression})");
                DB::table('_task_schedules')->insert([
                    'class' => $class,
                    'method' => $method,
                    'cron_expression' => $cron_expression,
                    'cron_resolved' => $cron_resolved,
                    'timezone' => $timezone,
                    'next_run_at' => $next_run_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                continue;
            }

            $resolution_moved = $existing->cron_resolved !== null && $existing->cron_resolved !== $cron_resolved;
            if ($existing->cron_expression !== $cron_expression || $existing->timezone !== $timezone || $resolution_moved) {
                $this->info("[SCHEDULED] Schedule changed for {$class}::{$method}, re-registering ({$cron_expression} = {$cron_resolved}, {$timezone})");
                DB::table('_task_schedules')->where('id', $existing->id)->update([
                    'cron_expression' => $cron_expression,
                    'cron_resolved' => $cron_resolved,
                    'timezone' => $timezone,
                    'next_run_at' => $next_run_at,
                    'updated_at' => $now,
                ]);
                continue;
            }

            if ($existing->cron_resolved === null) {
                DB::table('_task_schedules')->where('id', $existing->id)->update(['cron_resolved' => $cron_resolved]);
            }

            if ($force_all) {
                DB::table('_task_schedules')->where('id', $existing->id)->update(['next_run_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach (DB::table('_task_schedules')->get(['id', 'class', 'method']) as $schedule) {
            if (!isset($seen[$schedule->class . '::' . $schedule->method])) {
                $this->info("[SCHEDULED] Removing deleted schedule {$schedule->class}::{$schedule->method}");
                DB::table('_task_schedules')->where('id', $schedule->id)->delete();
            }
        }
    }

    /**
     * One spawn try per pool with due work. Task::spawn_worker() declines when the pool is
     * full, maintenance mode is up, the task system is off (the two task pools - a kill
     * worker is still started) or this process's own spawns fill the cap; the worker it
     * starts joins only if the pool still has room once it holds the pool lock. An unreachable
     * rsx-lockd throws: the pool is the admission count, and the daemon is a hard dependency.
     */
    private function spawn_workers_for_due_work(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $dispatched_due = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_PENDING)
            ->where('scheduled_for', '<=', $now)
            ->exists();
        $schedule_due = DB::table('_task_schedules')->where('next_run_at', '<=', $now)->exists();
        $kill_due = Task_Kill_Worker::has_due_requests(Task_Pool::host());

        if ($dispatched_due && (Task::spawn_worker(Task_Pool::ON_DEMAND) || Task::spawn_worker(Task_Pool::SCHEDULED))) {
            $this->info('[WORKER SPAWN] Dispatched work is due; spawned a worker');
        } elseif ($schedule_due && Task::spawn_worker(Task_Pool::SCHEDULED)) {
            $this->info('[WORKER SPAWN] A schedule is due; spawned a scheduled worker');
        }

        if ($kill_due && Task::spawn_worker(Task_Pool::KILL)) {
            $this->info('[WORKER SPAWN] A kill request is due; spawned a kill worker');
        }
    }

    /**
     * Run one due pending task inline - the one a worker would claim next (claim tier, then
     * oldest due first). Testing aid for --once; the normal path spawns detached workers. This
     * run is no pool member, so its row carries no worker_id and the reaper judges it by its
     * pid, on this host. It runs as a worker's run does (Task_Runner::execute()): in a context
     * of its own - bound to its dispatcher for #[Run_As_Dispatcher] - and the process is blank
     * after it.
     */
    private function process_one_task(): void
    {
        $row = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_PENDING)
            ->where('scheduled_for', '<=', now()->format('Y-m-d H:i:s.v'))
            ->orderBy('priority')
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->first(['id']);

        $claimed = $row && DB::table('_tasks')
            ->where('id', $row->id)
            ->where('status_id', Task_Run_Model::STATUS_PENDING)
            ->update(Task_Runner::running_fields());

        if (!$claimed) {
            $this->info('[ONCE MODE] No pending tasks');

            return;
        }

        Task_Notify::lifecycle((int) $row->id);
        $instance = Task_Instance::find((int) $row->id);
        $this->info("[ONCE MODE] Executing task {$row->id}: {$instance->get_class()}::{$instance->get_method()}");

        // The run leaves the process blank; a caller running --once IN-PROCESS (Artisan::call)
        // gets back the context it called with.
        $launch_context = Session::_snapshot_context();

        try {
            $outcome = Task_Runner::execute($instance, false, true);
        } finally {
            Session::_restore_context($launch_context);
        }

        Task_Runner::settle($instance, $outcome);

        if ($outcome->success) {
            $this->info("[ONCE MODE] Task {$row->id} completed successfully");
        } else {
            $this->error("[ONCE MODE] Task {$row->id} failed: {$outcome->error}");
        }
    }
}
