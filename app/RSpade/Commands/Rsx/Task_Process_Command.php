<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Killer;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Cron_Parser;

/**
 * Task Process Command
 *
 * The scheduler/dispatcher tick. Run via system cron every minute:
 *   * * * * * cd /var/www/html && php artisan rsx:task:process
 *
 * Each tick it:
 * 1. Recovers unsettleable RUNNING rows: those whose worker has left the pool (rsx-lockd's
 *    answer, asked under the pool lock), and those whose live local worker has outrun the
 *    task's timeout (killed via Task_Killer). This is task-level recovery only - worker
 *    concurrency is the pool rsx-lockd accounts (Task_Pool), NOT a count of RUNNING rows.
 * 2. Revives any cron tracker found sitting in a terminal state - the backstop for the
 *    invariant that a recurring schedule is never permanently terminal.
 * 3. Reconciles #[Schedule] tracker rows against the manifest (create new, regenerate
 *    on a changed cron expression, delete removed) so schedule edits take effect within
 *    one tick.
 * 4. If work is due, tries ONE Task::spawn_worker(). It does NOT become a worker itself.
 *
 * One try per tick is enough: a worker drains the queue until nothing is claimable, and every
 * dispatch tries a spawn of its own. The spawn reads the pool's count first, so a full pool
 * (or maintenance mode) starts nothing; a worker that starts anyway admits itself under the
 * pool lock and exits when it finds no room.
 */
class Task_Process_Command extends Command
{
    protected $signature = 'rsx:task:process
        {--once : Process one pending task inline then exit (for testing)}
        {--force-scheduled : Force every scheduled task due now}';

    protected $description = 'Scheduler/dispatcher tick: reconcile schedules and spawn workers (run via cron every minute)';

    /**
     * SILENT ON AN IDLE TICK. This runs from cron every minute, so anything printed
     * unconditionally is printed 1,440 times a day into the service log, burying the
     * lines that matter. There is deliberately no "starting"/"complete" pair.
     *
     * Everything below still speaks up, because everything below is CONDITIONAL: a
     * stuck task, a timeout and a stranded schedule all warn (an unreachable rsx-lockd
     * throws); a schedule actually registered,
     * changed or removed says so; a spawn says so when it started a worker. --once is a testing flag and narrates
     * itself. Keep it that way - problems and real events, never a heartbeat.
     */
    public function handle()
    {
        // Step 1: recover stuck tasks (task-level, not worker accounting)
        $this->detect_stuck_tasks();

        // Step 2: enforce the never-terminal invariant on tracker rows
        $this->revive_stranded_trackers();

        // Step 3: reconcile #[Schedule] tracker rows with the manifest
        $this->reconcile_schedules($this->option('force-scheduled'));

        // Step 4: one worker try, when work is due
        $this->spawn_worker_for_due_work();

        // Testing aid: drain one task inline instead of relying on a spawned worker.
        if ($this->option('once')) {
            $this->process_one_task();
        }

        return 0;
    }

    /**
     * Recover RUNNING rows that will never settle on their own. Two arms:
     *
     * 1. ABANDONED - the row's worker is gone. An on-demand row goes back to pending for a
     *    paced retry (FAILED once rsx.tasks.retry.attempts runs were abandoned); a cron tracker
     *    counts the run as done and waits for its next cadence - failing it would silently
     *    kill the cron. settle_abandoned() has the detail. There is NO grace period: the
     *    verdicts below are evidence, never an age.
     *
     *    The verdict for a row carrying a pool identity (worker_id + worker_generation) comes
     *    from rsx-lockd, in ONE pool.members_alive round trip for every such row, UNDER THE
     *    POOL LOCK (THE RULE: pool ops and `_tasks` rows only), so the view of the pool and of
     *    the rows is one consistent snapshot:
     *
     *      - the daemon's own generation (known): not alive means the worker's connection is
     *        gone, wherever it ran - abandoned;
     *      - an older generation (known: false - the daemon restarted since the claim, and a
     *        restart ends every membership while the workers keep running): only the host
     *        that ran the worker can tell, by its pid. On this host a dead pid is abandoned
     *        and a live one is left to record its own outcome; a row from another host is
     *        left alone for that host's own tick (the Task Worker Pool health row counts
     *        them).
     *
     *    A row with NO worker_id was run inline by --once or by Task::internal(), neither of
     *    which is a pool member: the verdict is the local pid probe, posix_kill(worker_pid, 0),
     *    taken only on the row's own host (a NULL worker_host is taken as local).
     *
     * 2. TIMED OUT - the worker is alive AND runs on this host, and it has exceeded its
     *    execution cap (the row's own timeout, else rsx.tasks.default_timeout). Task_Killer
     *    settles it the same way rsx:tasks:kill does: SIGTERM -> 5s -> SIGKILL, then KILLED
     *    (on-demand) or recycled to PENDING (cron tracker). This is the ONLY enforcement of
     *    tasks.timeout, so the cap's granularity is one cron tick. It runs AFTER the pool lock
     *    is released: a kill waits on another process.
     *
     * A row with neither a row timeout nor a configured default is never timeout-killed.
     */
    private function detect_stuck_tasks(): void
    {
        $default_timeout = (int) config('rsx.tasks.default_timeout', 0);
        $this_host = Task_Pool::host();

        $live = [];

        Task_Pool::lock();
        try {
            $running_tasks = DB::table('_tasks')
                ->where('status', Task_Status::RUNNING)
                ->get();

            // BIGINT columns may arrive as strings: the daemon is asked in integers.
            $members = [];
            foreach ($running_tasks as $task) {
                if ($task->worker_id !== null) {
                    $members[$task->id] = ['wid' => (int) $task->worker_id, 'generation' => (int) $task->worker_generation];
                }
            }
            $verdicts = $members ? Task_Pool::members_alive($members) : [];

            foreach ($running_tasks as $task) {
                $pid = (int) $task->worker_pid;
                $local = $task->worker_host === null || $task->worker_host === $this_host;
                $pid_alive = $local && $pid > 0 && posix_kill($pid, 0);

                if ($task->worker_id !== null) {
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
                            // Another host's worker from an older daemon generation: only
                            // that host's pid evidence can settle it.
                            continue;
                        }
                        if ($pid_alive) {
                            $live[] = $task;
                            continue;
                        }
                        $reason = "worker {$task->worker_id} of a previous rsx-lockd generation is no longer"
                            . " running (PID {$task->worker_pid} is gone on {$this_host})";
                    }
                } else {
                    if (!$local) {
                        continue;
                    }
                    if ($pid_alive) {
                        $live[] = $task;
                        continue;
                    }
                    $reason = "worker PID {$task->worker_pid} is no longer running";
                }

                $this->settle_abandoned($task, $reason);
            }
        } finally {
            // A lost connection already released the lock at the daemon (and holds_lock()
            // says so); unlocking then would only bury the real failure under a refusal.
            if (Task_Pool::holds_lock()) {
                Task_Pool::unlock();
            }
        }

        foreach ($live as $task) {
            // Timeout enforcement signals a pid, so it acts only on a worker running HERE.
            if ($task->worker_pid && posix_kill($task->worker_pid, 0)) {
                $this->enforce_task_timeout($task, $default_timeout);
            }
        }
    }

    /**
     * Settle a RUNNING row whose worker is gone - under the pool lock, and only the row as it
     * was read (still RUNNING under the same worker). Abandonment is not a failure of the
     * task, so neither kind of row is failed for it outright:
     *
     *   - a CRON TRACKER counts the run as done: PENDING, next_run_at = the next cadence
     *     strictly after now (never due at once - the claim's advanced next_run_at may already
     *     be past after a long run), the four worker columns cleared. The abandonment is
     *     recorded as a failed run (error, last_error_at, consecutive_failures + 1), so a
     *     schedule whose runs keep dying reaches the Failing Schedules health row.
     *   - a ONE-SHOT row is retried: PENDING with scheduled_for = now + base * 2^(n-1), n
     *     being this abandonment's number (consecutive_failures + 1; a one-shot that throws
     *     is terminal, so on a live one-shot the counter holds only abandonments), started_at
     *     and the four worker columns cleared. The attempts-th abandonment FAILS it for good.
     *     Pacing is rsx.tasks.retry (base_seconds, attempts).
     *
     * @param object $task The RUNNING _tasks row as detect_stuck_tasks() read it
     * @param string $reason Which worker is gone, and how that is known
     */
    private function settle_abandoned(object $task, string $reason): void
    {
        $row = DB::table('_tasks')
            ->where('id', $task->id)
            ->where('status', Task_Status::RUNNING)
            ->where('worker_pid', $task->worker_pid)
            ->where('worker_id', $task->worker_id)
            ->where('worker_generation', $task->worker_generation);

        $abandonment = (int) $task->consecutive_failures + 1;

        if ($task->next_run_at !== null) {
            if ($task->cron_expression === null) {
                shouldnt_happen("Cron tracker {$task->id} ({$task->class}::{$task->method}) has no cron_expression");
            }
            $next_run_at = date('Y-m-d H:i:s', (new Cron_Parser($task->cron_expression))->get_next_run_time());

            $this->warn("[ABANDONED TASK] Cron tracker {$task->id} ({$task->class}::{$task->method}) waits for its next run at {$next_run_at}: {$reason}");
            $row->update([
                'status' => Task_Status::PENDING,
                'status_reason' => 'abandoned (recycled): ' . $reason,
                'error' => "Task abandoned - {$reason}",
                'last_error_at' => now(),
                'consecutive_failures' => $abandonment,
                'next_run_at' => $next_run_at,
                'worker_pid' => null,
                'worker_id' => null,
                'worker_generation' => null,
                'worker_host' => null,
                'updated_at' => now(),
            ]);

            return;
        }

        [$base_seconds, $attempts] = static::__retry_pacing();

        if ($abandonment >= $attempts) {
            $this->warn("[ABANDONED TASK] Failing task {$task->id} ({$task->class}::{$task->method}) after {$abandonment} abandoned attempt(s): {$reason}");
            $row->update([
                'status' => Task_Status::FAILED,
                'status_reason' => "abandoned: {$reason}; attempt {$abandonment} of {$attempts}, not retried",
                'error' => "Task abandoned - {$reason}. It was abandoned on all {$attempts} attempts"
                    . ' (rsx.tasks.retry.attempts) and is not retried again.',
                'last_error_at' => now(),
                'consecutive_failures' => $abandonment,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $retry_at = time() + $base_seconds * (2 ** ($abandonment - 1));
        $next_attempt = $abandonment + 1;
        $retry_iso = date('c', $retry_at);

        $this->warn("[ABANDONED TASK] Task {$task->id} ({$task->class}::{$task->method}) will retry as attempt {$next_attempt} of {$attempts} at {$retry_iso}: {$reason}");
        $row->update([
            'status' => Task_Status::PENDING,
            'status_reason' => "abandoned: {$reason}; retry as attempt {$next_attempt} of {$attempts} at {$retry_iso}",
            'error' => "Task abandoned - {$reason}",
            'last_error_at' => now(),
            'consecutive_failures' => $abandonment,
            'scheduled_for' => date('Y-m-d H:i:s', $retry_at),
            'started_at' => null,
            'worker_pid' => null,
            'worker_id' => null,
            'worker_generation' => null,
            'worker_host' => null,
            'updated_at' => now(),
        ]);
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
     * Kill a live-worker task that has outrun its execution cap.
     *
     * The cap is the row's own timeout, or the configured default when the row carries none.
     * Zero/absent on both means no cap is defined for this task and it runs unbounded.
     *
     * @param object $task A RUNNING _tasks row whose worker process is alive.
     * @param int $default_timeout Seconds from rsx.tasks.default_timeout (0 = none configured).
     */
    private function enforce_task_timeout(object $task, int $default_timeout): void
    {
        $timeout = (int) ($task->timeout ?? 0);
        if ($timeout <= 0) {
            $timeout = $default_timeout;
        }

        if ($timeout <= 0 || $task->started_at === null) {
            return;
        }

        $ran_for = time() - strtotime($task->started_at);
        if ($ran_for <= $timeout) {
            return;
        }

        $explanation = "timed out after {$ran_for}s (cap {$timeout}s)";
        $outcome = Task_Killer::kill($task, $explanation);

        $this->warn("[TASK TIMEOUT] {$task->class}::{$task->method} (task {$task->id}, worker PID {$task->worker_pid}) {$explanation} -> {$outcome}");
    }

    /**
     * Revive cron tracker rows found sitting in a terminal state.
     *
     * INVARIANT: a cron tracker is never permanently terminal. One tracker row exists per
     * #[Schedule] and IS that schedule - park it in FAILED or KILLED and the schedule stops
     * forever, silently, while the row still looks like a registered task. This is the
     * failure the dead-worker reaper already names in its own comment: "failing them would
     * silently kill the cron."
     *
     * The terminal writers (Task_Instance::mark_failed/mark_completed) and Task_Killer now
     * enforce that rule at the source, so this sweep should never fire. It exists for the
     * strands they cannot reach: rows stranded by a pre-fix release and carried in on a
     * framework pull, rows edited by hand in SQL, and whatever crash window has not been
     * imagined yet. Enforced, not merely intended.
     *
     * The failure record is preserved: only status and the worker columns are touched, so error,
     * status_reason, last_error_at and consecutive_failures still say what went wrong. The
     * row's next_run_at was advanced before its run, so reviving it simply fires the next
     * cadence - it never re-runs immediately.
     */
    private function revive_stranded_trackers(): void
    {
        $stranded = DB::table('_tasks')
            ->whereNotNull('next_run_at')
            ->whereIn('status', [Task_Status::FAILED, Task_Status::KILLED])
            ->get(['id', 'class', 'method', 'status']);

        foreach ($stranded as $tracker) {
            DB::table('_tasks')->where('id', $tracker->id)->update([
                'status' => Task_Status::PENDING,
                'worker_pid' => null,
                'worker_id' => null,
                'worker_generation' => null,
                'worker_host' => null,
                'updated_at' => now(),
            ]);

            $message = "Revived cron tracker {$tracker->id} ({$tracker->class}::{$tracker->method}) "
                . "stranded in status '{$tracker->status}' - a recurring schedule must never be terminal";

            $this->warn("[STRANDED SCHEDULE] {$message}");
            Log::warning("[STRANDED SCHEDULE] {$message}");
        }
    }

    /**
     * Reconcile recurring #[Schedule] tracker rows against the manifest.
     *
     * For each scheduled task: create a tracker if none exists; if one exists but its
     * stored cron_expression differs from the manifest, delete and regenerate it so the
     * next_run_at is recomputed from the new schedule immediately (a stale next_run_at
     * from the old expression can never linger). Tracker rows whose (class, method) no
     * longer appears in the manifest are deleted.
     *
     * @param bool $force_all If true, mark every tracker due now.
     */
    private function reconcile_schedules(bool $force_all): void
    {
        $scheduled_tasks = Task::get_scheduled_tasks();
        $seen = [];

        foreach ($scheduled_tasks as $task_def) {
            $class = $task_def['class'];
            $method = $task_def['method'];
            $cron_expression = $task_def['cron_expression'];
            $queue = $task_def['queue'];
            $seen[$class . '::' . $method] = true;

            $existing = DB::table('_tasks')
                ->where('class', $class)
                ->where('method', $method)
                ->whereNotNull('next_run_at')
                ->first();

            if ($existing && $existing->cron_expression === $cron_expression) {
                if ($force_all) {
                    DB::table('_tasks')->where('id', $existing->id)->update([
                        'next_run_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                continue;
            }

            // Missing, or the schedule changed: (re)create the tracker from scratch.
            if ($existing) {
                $this->info("[SCHEDULED] Schedule changed for {$class}::{$method}, regenerating tracker");
                DB::table('_tasks')
                    ->where('class', $class)
                    ->where('method', $method)
                    ->whereNotNull('next_run_at')
                    ->delete();
            } else {
                $this->info("[SCHEDULED] Registering new scheduled task {$class}::{$method} ({$cron_expression})");
            }

            $next_run_at = $force_all ? time() : (new Cron_Parser($cron_expression))->get_next_run_time();

            DB::table('_tasks')->insert([
                'class' => $class,
                'method' => $method,
                'queue' => $queue,
                'status' => Task_Status::PENDING,
                'params' => json_encode([]),
                'next_run_at' => date('Y-m-d H:i:s', $next_run_at),
                'cron_expression' => $cron_expression,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Delete tracker rows whose schedule was removed from the manifest.
        $trackers = DB::table('_tasks')->whereNotNull('next_run_at')->get(['id', 'class', 'method']);
        foreach ($trackers as $tracker) {
            if (!isset($seen[$tracker->class . '::' . $tracker->method])) {
                $this->info("[SCHEDULED] Removing tracker for deleted schedule {$tracker->class}::{$tracker->method}");
                DB::table('_tasks')->where('id', $tracker->id)->delete();
            }
        }
    }

    /**
     * Try ONE worker spawn when work is due. Task::spawn_worker() declines when the pool is
     * full, maintenance mode is up or this process's own spawns fill the cap; the worker it
     * starts joins only if the pool still has room once it holds the pool lock. An unreachable
     * rsx-lockd throws: the pool is the admission count, and the daemon is a hard dependency.
     */
    private function spawn_worker_for_due_work(): void
    {
        if (!$this->has_pending_work()) {
            return;
        }

        if (Task::spawn_worker()) {
            $this->info('[WORKER SPAWN] Pending work present; spawned a worker');
        }
    }

    /**
     * Whether any task is due to run now (either tier of the priority order).
     */
    private function has_pending_work(): bool
    {
        $on_demand_due = DB::table('_tasks')
            ->where('status', Task_Status::PENDING)
            ->whereNull('next_run_at')
            ->where(function ($query) {
                $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            })
            ->exists();

        if ($on_demand_due) {
            return true;
        }

        return DB::table('_tasks')
            ->where('status', Task_Status::PENDING)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->exists();
    }

    /**
     * Process one on-demand (tier-1) task inline. Testing aid for --once; the normal
     * path spawns detached workers. Does not handle cron-tier rows (use a worker).
     */
    private function process_one_task(): void
    {
        // The claim is taken under the pool lock, exactly as a worker's is (THE RULE: pool ops
        // and `_tasks` rows only). This inline run is NOT a pool member, so its row carries no
        // worker_id and the stuck-task reaper judges it by its pid, on this host.
        Task_Pool::lock();

        try {
            $task_row = DB::table('_tasks')
                ->where('status', Task_Status::PENDING)
                ->whereNull('next_run_at')
                ->where(function ($query) {
                    $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
                })
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if ($task_row) {
                DB::table('_tasks')->where('id', $task_row->id)->update([
                    'status' => Task_Status::RUNNING,
                    'started_at' => now(),
                    'worker_pid' => getmypid(),
                    'worker_host' => Task_Pool::host(),
                    'updated_at' => now(),
                ]);
            }
        } finally {
            if (Task_Pool::holds_lock()) {
                Task_Pool::unlock();
            }
        }

        if (!$task_row) {
            $this->info('[ONCE MODE] No pending tasks');
            return;
        }

        $this->info("[ONCE MODE] Executing task {$task_row->id}: {$task_row->class}::{$task_row->method}");

        $task_instance = Task_Instance::find($task_row->id);

        try {
            $class = $task_row->class;
            $method = $task_row->method;
            $params = json_decode($task_row->params, true) ?? [];

            $result = $class::$method($task_instance, $params);
            $task_instance->mark_completed($result);

            $this->info("[ONCE MODE] Task {$task_row->id} completed successfully");
        } catch (\Throwable $e) {
            // Throwable, not Exception: a TypeError in a task must record its error and
            // settle the row like any exception (same widening as the worker's catch).
            $task_instance->mark_failed($e->getMessage());
            $this->error("[ONCE MODE] Task {$task_row->id} failed: " . $e->getMessage());
        }
    }
}
