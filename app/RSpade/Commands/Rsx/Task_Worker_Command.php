<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use RuntimeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Task\Cron_Parser;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Notify;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;

/**
 * Task Worker Command
 *
 * The executor. Spawned detached into one pool (--pool=on_demand|scheduled) by Task::dispatch
 * or the rsx:task:process tick.
 *
 * THE POOLS ARE ACCOUNTED BY rsx-lockd (Task_Pool). This process's pool connection, opened on
 * its first pool call and held for its whole life, IS its membership: when the process exits,
 * crashes or is SIGKILLed the daemon drops the membership and frees the pool lock with no
 * heartbeat, lease or reaper. The loop:
 *
 *   1. Pool lock. If the pool already holds its cap of OTHER members: unlock and exit 0.
 *      Otherwise join.
 *   2. Still under the lock: claim the next run - a due PENDING run (dispatched work), or, in
 *      the scheduled pool when no dispatched work is due, a due #[Schedule], whose run row is
 *      created here - marked RUNNING with this worker's pool identity (worker_id +
 *      worker_generation), worker_host and worker_pid. Unlock.
 *   3. Run the task - no pool lock held.
 *   4. Pool lock. Record the outcome, then claim the next run (back to 2). When nothing is
 *      claimable, or --max-time has passed: leave, unlock, exit.
 *
 * TWO POOLS CLAIM THE SAME PENDING ROWS (the scheduled pool takes dispatched work first), and
 * each holds only its own lock - so every claim is a GUARDED write (WHERE status is still
 * PENDING) that must affect exactly one row; a worker that loses the race claims again.
 *
 * THE RULE, at every step that holds the pool lock: only pool ops and reads/writes of the task
 * tables (plus non-blocking tries and releases of the identity run lock). No other blocking
 * lock, no subprocess, no outbound call - pool waits are invisible to the daemon's deadlock
 * detector, and this is what makes that safe. claim_next_task() asserts it holds the lock.
 *
 * A LOST POOL CONNECTION ends the worker, and the daemon has already dropped its membership:
 *
 *   - lost at the unlock right after a claim, before the task ran: the worker puts its claim
 *     back (release_unrun_claim()), says so loudly and exits non-zero - nothing ran;
 *   - lost while the task ran: it records the outcome of the run it holds (a run already
 *     claimed by this worker cannot race another claim), says so loudly and exits non-zero.
 *
 * Workers are UNGUARDED: nothing serializes them for you - they run concurrently, and each
 * #[Task] is responsible for its own critical-section locking (see RsxLocks).
 */
class Task_Worker_Command extends Command
{
    protected $signature = 'rsx:task:worker
        {--pool=on_demand : The pool this worker joins: on_demand or scheduled}
        {--max-time=300 : Stop claiming new work after this many seconds (default: 5 minutes)}';

    protected $description = 'Background worker for processing queued tasks';

    private int $start_time;
    private int $max_time;
    private int $tasks_processed = 0;
    private string $pool;

    /** claim_next_task()'s answer for a run it set aside without claiming it. */
    private const CLAIM_SKIPPED = 'skipped';

    /**
     * Pending runs this worker skipped because their identity is already running elsewhere
     * (the coalesced pending run). Excluded from selection so the worker doesn't busy-loop;
     * the running instance / cron poller picks them up.
     *
     * @var int[]
     */
    private array $skip_ids = [];

    public function handle()
    {
        $this->pool = (string) $this->option('pool');
        if (!in_array($this->pool, [Task_Pool::ON_DEMAND, Task_Pool::SCHEDULED], true)) {
            $this->error("[WORKER] --pool must be on_demand or scheduled, got '{$this->pool}'");

            return 1;
        }

        $this->max_time = (int) $this->option('max-time');
        $this->start_time = time();

        // Per-invocation state. The console application resolves a command ONCE and reuses
        // the instance for every in-process call (Artisan::call), so a run set aside by an
        // earlier invocation must not stay excluded from this one.
        $this->skip_ids = [];
        $this->tasks_processed = 0;

        try {
            // Admission, under the pool lock: count() is the OTHER members, so a full pool is
            // one this worker would push past the cap.
            Task_Pool::lock($this->pool);
            if (Task_Pool::count($this->pool) >= Task_Pool::max_workers($this->pool)) {
                Task_Pool::unlock($this->pool);
                $this->info("[WORKER] The {$this->pool} pool is full, exiting");

                return 0;
            }

            $identity = Task_Pool::join($this->pool);
            $this->info("[WORKER] Joined the {$this->pool} pool (wid {$identity['wid']}, generation {$identity['generation']})");

            // Invariant at the top of every iteration: this process holds the pool lock and
            // is a member.
            while (true) {
                $claim = $this->claim_next_task($identity);

                if ($claim === null) {
                    $this->info('[WORKER] No more pending tasks, exiting');
                    break;
                }

                if ($claim === self::CLAIM_SKIPPED) {
                    continue;
                }

                [$task_id, $run_lock] = $claim;

                try {
                    Task_Pool::unlock($this->pool);
                } catch (RuntimeException $e) {
                    // The daemon has already dropped this worker - the reaper may treat the
                    // run as abandoned - and the task has not started. Hand the claim back.
                    $this->release_unrun_claim($task_id, $run_lock, $identity, $e->getMessage());
                    Task_Pool::disconnect();

                    return 1;
                }

                // The previous run's settle (written under the lock) and this claim.
                Task_Notify::flush_deferred();
                Task_Notify::lifecycle($task_id);

                [$instance, $outcome] = $this->run_task($task_id);

                // Re-take the lock to settle. A connection that died while the task ran has
                // taken the membership with it; a fresh connection (redialled by some other
                // pool call the task made) holds a lock but no membership. Either way this
                // worker is no longer counted, and must not claim again.
                $lost = null;
                try {
                    Task_Pool::lock($this->pool);
                } catch (RuntimeException $e) {
                    $lost = $e->getMessage();
                }
                if ($lost === null && (Task_Pool::wid() !== $identity['wid'] || Task_Pool::generation() !== $identity['generation'])) {
                    $lost = 'the pool membership ended while the task ran';
                }

                Task_Runner::settle($instance, $outcome, $run_lock);
                $this->tasks_processed++;
                $this->info("[WORKER] Task {$task_id} " . ($outcome->success ? 'completed' : 'failed: ' . $outcome->error));

                if ($lost !== null) {
                    if (Task_Pool::holds_lock()) {
                        Task_Pool::unlock($this->pool);
                    }
                    Task_Notify::flush_deferred();

                    $message = "[WORKER] Lost the task pool connection while running task {$task_id}"
                        . " ({$instance->get_class()}::{$instance->get_method()}); its outcome was recorded and this worker"
                        . " is exiting: {$lost}";
                    $this->error($message);
                    Log::error($message);

                    return 1;
                }

                if ((time() - $this->start_time) >= $this->max_time) {
                    $this->info('[WORKER] --max-time reached, exiting');
                    break;
                }
            }

            Task_Pool::leave($this->pool);
            Task_Pool::unlock($this->pool);
            Task_Notify::flush_deferred();
        } catch (\Throwable $e) {
            // Whatever escaped left this process a member and perhaps the lock holder.
            // Closing the pool connection ends both at the daemon - which matters when the
            // worker runs in-process (Artisan::call) and the process lives on.
            Task_Pool::disconnect();

            throw $e;
        }

        $this->info("[WORKER] Worker finished. Processed {$this->tasks_processed} task(s)");

        return 0;
    }

    /**
     * Claim the next run: a due pending run (dispatched work, oldest due first) in either pool;
     * then, in the scheduled pool only, a due schedule.
     *
     * UNDER THE POOL LOCK, and it never releases it. Everything here is THE RULE's allowance:
     * task-table reads and writes, plus a NON-BLOCKING try of the identity run lock.
     *
     * @return array{0: int, 1: Task_Lock|null}|string|null The claimed run's id with its held
     *         run lock; CLAIM_SKIPPED for a candidate set aside (claim again); null when nothing
     *         is claimable.
     */
    private function claim_next_task(array $identity)
    {
        if (!Task_Pool::holds_lock($this->pool)) {
            shouldnt_happen('Task_Worker_Command claimed a task without holding its pool lock');
        }

        $pending = $this->claim_pending_run($identity);
        if ($pending !== null) {
            return $pending;
        }

        if ($this->pool === Task_Pool::SCHEDULED) {
            return $this->claim_due_schedule($identity);
        }

        return null;
    }

    /**
     * The oldest due PENDING run, claimed by a guarded write.
     */
    private function claim_pending_run(array $identity)
    {
        $row = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_PENDING)
            ->where('scheduled_for', '<=', now()->format('Y-m-d H:i:s.v'))
            ->when($this->skip_ids, function ($query) {
                $query->whereNotIn('id', $this->skip_ids);
            })
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->first(['id', 'class', 'method', 'params_hash']);

        if (!$row) {
            return null;
        }

        // Per-identity guard for Exclusive/Debounce tasks: at most one instance runs at a
        // time, cluster-wide, via the identity run lock. A NON-BLOCKING try - the only kind
        // of other lock THE RULE allows under the pool lock.
        $run_lock = null;
        if (Task_Concurrency::is_managed($row->class, $row->method)) {
            $run_lock = Task_Concurrency::try_acquire_run_lock($row->class, $row->method, $row->params_hash);
            if (!$run_lock) {
                $this->skip_ids[] = (int) $row->id;

                return self::CLAIM_SKIPPED;
            }
        }

        $claimed = DB::table('_tasks')
            ->where('id', $row->id)
            ->where('status_id', Task_Run_Model::STATUS_PENDING)
            ->update($this->claim_fields($identity) + ['pool_id' => $this->pool_id()]);

        if (!$claimed) {
            // The other pool's worker took it, or it was cancelled, between the read and the
            // write.
            $run_lock?->release();

            return self::CLAIM_SKIPPED;
        }

        return [(int) $row->id, $run_lock];
    }

    /**
     * A due #[Schedule]: advance its next_run_at and create its run row, RUNNING under this
     * worker, in one transaction. A schedule whose identity is already running (an on-demand
     * run of an #[Exclusive] task) has this tick coalesced into that run: next_run_at is
     * advanced and nothing runs.
     */
    private function claim_due_schedule(array $identity)
    {
        $schedule = DB::table('_task_schedules')
            ->where('next_run_at', '<=', now()->format('Y-m-d H:i:s.v'))
            ->orderBy('next_run_at')
            ->first();

        if (!$schedule) {
            return null;
        }

        $next_run_at = date('Y-m-d H:i:s', (new Cron_Parser($schedule->cron_expression))->get_next_run_time());
        $params_hash = Task_Concurrency::params_hash([]);

        $run_lock = null;
        if (Task_Concurrency::is_managed($schedule->class, $schedule->method)) {
            $run_lock = Task_Concurrency::try_acquire_run_lock($schedule->class, $schedule->method, $params_hash);
            if (!$run_lock) {
                DB::table('_task_schedules')->where('id', $schedule->id)->update([
                    'next_run_at' => $next_run_at,
                    'updated_at' => now(),
                ]);

                return self::CLAIM_SKIPPED;
            }
        }

        $task_id = DB::transaction(function () use ($schedule, $next_run_at, $identity) {
            // Advance the cadence BEFORE running, so it holds even if the run is slow or
            // crashes - guarded on the due time this worker read.
            $advanced = DB::table('_task_schedules')
                ->where('id', $schedule->id)
                ->where('next_run_at', $schedule->next_run_at)
                ->update(['next_run_at' => $next_run_at, 'updated_at' => now()]);

            if (!$advanced) {
                return null;
            }

            return Task_Runner::insert_row(
                $schedule->class,
                $schedule->method,
                [],
                Task_Run_Model::ORIGIN_SCHEDULED,
                $this->claim_fields($identity) + ['schedule_id' => $schedule->id, 'pool_id' => Task_Run_Model::POOL_SCHEDULED]
            );
        });

        if ($task_id === null) {
            $run_lock?->release();

            return self::CLAIM_SKIPPED;
        }

        return [(int) $task_id, $run_lock];
    }

    /** The columns a claim writes: RUNNING here, by this pid, under this pool identity. */
    private function claim_fields(array $identity): array
    {
        return Task_Runner::running_fields() + [
            'worker_id' => $identity['wid'],
            'worker_generation' => $identity['generation'],
        ];
    }

    private function pool_id(): int
    {
        return $this->pool === Task_Pool::SCHEDULED ? Task_Run_Model::POOL_SCHEDULED : Task_Run_Model::POOL_ON_DEMAND;
    }

    /**
     * Hand back a claim whose task never ran: the pool connection was lost between the claim
     * and the run. A dispatched run goes back to PENDING (the worker columns and started_at
     * cleared) for the next worker; a scheduled run, created by the claim, is deleted - its
     * schedule's next_run_at was advanced, so that tick is lost, which is what a crash at
     * that point costs. The identity run lock is released.
     *
     * The write matches only the run still RUNNING under THIS worker's identity: once the
     * daemon dropped the membership, a reaper tick may already have settled it, and that
     * verdict stands. Loud on purpose, and the worker exits non-zero after it.
     */
    private function release_unrun_claim(int $task_id, ?Task_Lock $run_lock, array $identity, string $reason): void
    {
        $mine = DB::table('_tasks')
            ->where('id', $task_id)
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->where('worker_id', $identity['wid'])
            ->where('worker_generation', $identity['generation']);

        $origin = (int) DB::table('_tasks')->where('id', $task_id)->value('origin_id');
        if ($origin === Task_Run_Model::ORIGIN_SCHEDULED) {
            $restored = (clone $mine)->delete();
        } else {
            $restored = (clone $mine)->update([
                'status_id' => Task_Run_Model::STATUS_PENDING,
                'started_at' => null,
                'pool_id' => null,
                'worker_pid' => null,
                'worker_id' => null,
                'worker_generation' => null,
                'worker_host' => null,
                'updated_at' => now(),
            ]);
        }

        $run_lock?->release();

        $message = "[WORKER] Lost the task pool connection after claiming task {$task_id} and before running it; "
            . ($restored ? 'the claim was handed back' : 'the run was already settled by the reaper')
            . " and this worker is exiting: {$reason}";
        $this->error($message);
        Log::error($message);
    }

    /**
     * Run a claimed task - with NO pool lock held - and report what happened. The run is not
     * settled here: Task_Runner::settle() records the outcome under the pool lock.
     *
     * @return array{0: Task_Instance, 1: \App\RSpade\Core\Task\Task_Run_Outcome}
     */
    private function run_task(int $task_id): array
    {
        $instance = Task_Instance::find($task_id);
        $this->info("[WORKER] Executing task {$task_id}: {$instance->get_class()}::{$instance->get_method()}");

        // One task is one unit of work for revision history. A worker is a LONG-LIVED
        // process running unrelated tasks back to back; without this, every revision the
        // worker ever recorded would be filed under the first task's transaction.
        Revision::_reset_request_state('task', $instance->get_class() . '::' . $instance->get_method());

        return [$instance, Task_Runner::execute($instance, true)];
    }
}
