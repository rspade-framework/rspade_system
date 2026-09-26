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
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Cron_Parser;

/**
 * Task Worker Command
 *
 * The executor. Spawned detached by rsx:task:process (or by Task::dispatch, which fires a
 * worker on enqueue). There is ONE worker pool - workers are generic, not bound to a queue.
 *
 * THE POOL IS ACCOUNTED BY rsx-lockd (Task_Pool). This process's pool connection, opened on
 * its first pool call and held for its whole life, IS its membership: when the process
 * exits, crashes or is SIGKILLed the daemon drops the membership and frees the pool lock
 * with no heartbeat, lease or reaper. The loop:
 *
 *   1. Pool lock. If the pool already holds global_max_workers OTHER members: unlock and
 *      exit 0. Otherwise join.
 *   2. Still under the lock: claim the next row by the single priority order (run-now tasks
 *      FIFO, then due cron tasks by next_run_at) and mark it RUNNING with this worker's pid
 *      and member id. Unlock.
 *   3. Run the task - no pool lock held.
 *   4. Pool lock. Record the outcome, then claim the next row (back to 2). When nothing is
 *      claimable, or --max-time has passed: leave, unlock, exit.
 *
 * THE RULE, at every step that holds the pool lock: only pool ops and reads/writes of
 * `_tasks` rows (plus non-blocking tries and releases of the identity run lock). No other
 * blocking lock, no subprocess, no outbound call - pool waits are invisible to the daemon's
 * deadlock detector, and this is what makes that safe. claim_next_task() asserts it holds
 * the lock, because a claim without it double-runs work silently.
 *
 * A LOST POOL CONNECTION mid-task ends the worker: the daemon has already dropped its
 * membership, so it records the outcome of the row it holds (a row already claimed by this
 * worker cannot race another claim), says so loudly and exits non-zero.
 *
 * Workers are UNGUARDED: nothing serializes them for you - they run concurrently, and each
 * #[Task] is responsible for its own critical-section locking (see RsxLocks).
 */
class Task_Worker_Command extends Command
{
    protected $signature = 'rsx:task:worker
        {--queue= : Ignored (retained for compatibility; workers are generic)}
        {--max-time=300 : Maximum execution time in seconds (default: 5 minutes)}';

    protected $description = 'Background worker for processing queued tasks';

    private int $start_time;
    private int $max_time;
    private int $tasks_processed = 0;

    /** claim_next_task()'s answer for a row it set aside without claiming it. */
    private const CLAIM_SKIPPED = 'skipped';

    /**
     * Pending on-demand rows this worker skipped because their identity is already
     * running elsewhere (the coalesced pending run). Excluded from selection so the
     * worker doesn't busy-loop; the running instance / cron poller picks them up.
     *
     * @var int[]
     */
    private array $skip_ids = [];

    public function handle()
    {
        $this->max_time = (int) $this->option('max-time');
        $this->start_time = time();

        try {
            // Admission, under the pool lock: count() is the OTHER members, so a full pool is
            // one this worker would push past the cap.
            Task_Pool::lock();
            if (Task_Pool::count() >= Task_Pool::max_workers()) {
                Task_Pool::unlock();
                $this->info('[WORKER] Worker pool is full, exiting');

                return 0;
            }

            $member_id = Task_Pool::join();
            $this->info("[WORKER] Joined the pool ({$member_id})");

            // Invariant at the top of every iteration: this process holds the pool lock and
            // is a member.
            while (true) {
                $claim = $this->claim_next_task($member_id);

                if ($claim === null) {
                    $this->info('[WORKER] No more pending tasks, exiting');
                    break;
                }

                if ($claim === self::CLAIM_SKIPPED) {
                    continue;
                }

                [$task_row, $run_lock] = $claim;

                Task_Pool::unlock();

                [$task_instance, $outcome] = $this->run_task($task_row);

                // Re-take the lock to settle. A connection that died while the task ran has
                // taken the membership with it; a fresh connection (redialled by some other
                // pool call the task made) holds a lock but no membership. Either way this
                // worker is no longer counted, and must not claim again.
                $lost = null;
                try {
                    Task_Pool::lock();
                } catch (RuntimeException $e) {
                    $lost = $e->getMessage();
                }
                if ($lost === null && Task_Pool::member_id() !== $member_id) {
                    $lost = 'the pool membership ended while the task ran';
                }

                $this->settle_task($task_row, $task_instance, $outcome, $run_lock);
                $this->tasks_processed++;

                if ($lost !== null) {
                    if (Task_Pool::holds_lock()) {
                        Task_Pool::unlock();
                    }

                    $message = "[WORKER] Lost the task pool connection while running task {$task_row->id}"
                        . " ({$task_row->class}::{$task_row->method}); its outcome was recorded and this worker"
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

            Task_Pool::leave();
            Task_Pool::unlock();
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
     * Claim the next task by the single priority order:
     *   Tier 1 - run-now rows (dispatched): next_run_at IS NULL, FIFO by created_at.
     *   Tier 2 - due cron rows: next_run_at IS NOT NULL AND due, by next_run_at ascending.
     *
     * UNDER THE POOL LOCK, and it never releases it. Everything here is THE RULE's allowance:
     * `_tasks` reads and writes, plus a NON-BLOCKING try of the identity run lock.
     *
     * @return array{0: object, 1: Task_Lock|null}|string|null The claimed row with its held
     *         run lock; CLAIM_SKIPPED for a row set aside (claim again); null when nothing is
     *         claimable.
     */
    private function claim_next_task(string $member_id)
    {
        // Two workers claiming without the lock both select the same row and both run it -
        // and nothing downstream would ever notice.
        if (!Task_Pool::holds_lock()) {
            shouldnt_happen('Task_Worker_Command claimed a task row without holding the task pool lock');
        }

        // Tier 1: run-now tasks.
        $is_cron = false;
        $task_row = DB::table('_tasks')
            ->where('status', Task_Status::PENDING)
            ->whereNull('next_run_at')
            ->where(function ($query) {
                $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            })
            ->when($this->skip_ids, function ($query) {
                $query->whereNotIn('id', $this->skip_ids);
            })
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->first();

        // Tier 2: due cron tasks (only when no run-now work remains).
        if (!$task_row) {
            $is_cron = true;
            $task_row = DB::table('_tasks')
                ->where('status', Task_Status::PENDING)
                ->whereNotNull('next_run_at')
                ->whereNotNull('cron_expression')
                ->where('next_run_at', '<=', now())
                ->orderBy('next_run_at', 'asc')
                ->lockForUpdate()
                ->first();
        }

        if (!$task_row) {
            return null;
        }

        // Per-identity guard for Exclusive/Debounce tasks: at most one instance runs at a
        // time, cluster-wide, via the identity run lock. A NON-BLOCKING try - the only kind
        // of other lock THE RULE allows under the pool lock.
        $run_lock = null;
        if (Task_Concurrency::is_managed($task_row->class, $task_row->method)) {
            $run_lock = Task_Concurrency::try_acquire_run_lock($task_row->class, $task_row->method);
            if (!$run_lock) {
                if ($is_cron) {
                    // Identity already running (an on-demand run): coalesce this tick
                    // into it - advance the schedule and leave the tracker pending.
                    DB::table('_tasks')->where('id', $task_row->id)->update([
                        'next_run_at' => date('Y-m-d H:i:s', (new Cron_Parser($task_row->cron_expression))->get_next_run_time()),
                        'updated_at' => now(),
                    ]);
                } else {
                    $this->skip_ids[] = $task_row->id;
                }

                return self::CLAIM_SKIPPED;
            }
        }

        $claim = [
            'status' => Task_Status::RUNNING,
            'started_at' => now(),
            'worker_pid' => getmypid(),
            'worker_member_key' => $member_id,
            'updated_at' => now(),
        ];

        if ($is_cron) {
            // Advance next_run_at BEFORE running so the cadence holds even if the run is
            // slow or crashes - in the same write that marks it running.
            $claim['next_run_at'] = date('Y-m-d H:i:s', (new Cron_Parser($task_row->cron_expression))->get_next_run_time());
        }

        DB::table('_tasks')->where('id', $task_row->id)->update($claim);

        return [$task_row, $run_lock];
    }

    /**
     * Run a claimed task row - with NO pool lock held - and report what happened. The row is
     * not settled here: settle_task() records the outcome under the pool lock.
     *
     * @param object $task_row Task database row
     * @return array{0: Task_Instance|null, 1: array{ok: bool, result?: mixed, error?: string}|null}
     *         The instance (null when the row could not be loaded) and the outcome.
     */
    private function run_task(object $task_row): array
    {
        $this->info("[WORKER] Executing task {$task_row->id}: {$task_row->class}::{$task_row->method}");

        $task_instance = Task_Instance::find($task_row->id);

        if (!$task_instance) {
            $this->error("[WORKER] Could not load task instance for task {$task_row->id}");

            return [null, null];
        }

        // Locks taken from here on belong to THIS TASK, and are released when it ends (see
        // the finally below). Anything the worker itself already holds is in the
        // checkpoint and is left alone - the identity run lock among them.
        $lock_checkpoint = RsxLocks::_checkpoint();

        // One task is one unit of work for revision history. A worker is a LONG-LIVED
        // process running unrelated tasks back to back; without this, every revision the
        // worker ever recorded would be filed under the first task's transaction.
        \App\RSpade\Core\Revisions\Revision::_reset_request_state('task', $task_row->class . '::' . $task_row->method);

        try {
            $class = $task_row->class;
            $method = $task_row->method;
            $params = json_decode($task_row->params, true) ?? [];

            if (!class_exists($class)) {
                throw new \Exception("Class not found: {$class}");
            }
            if (!method_exists($class, $method)) {
                throw new \Exception("Method not found: {$class}::{$method}");
            }

            $outcome = ['ok' => true, 'result' => $class::$method($task_instance, $params)];
        } catch (\Throwable $e) {
            // Throwable, not Exception: a TypeError inside a task must record its error
            // on the row like any other failure, not vanish unrecorded.
            $outcome = ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            // Hand back every lock this task took. A worker is a LONG-LIVED process running
            // unrelated tasks back to back, but an ordinary application lock is held until
            // the PROCESS exits (Rsx_Site_Model_Abstract registers a shutdown handler and
            // nothing else ever lets go). Without this, the first task to write to a site
            // would hold that tenant's write lock against the whole cluster for the rest of
            // the worker's lifetime, and every later task in this worker would silently
            // inherit it instead of contending for it.
            //
            // Loud on purpose: a task that ends still holding a lock is a defect in that
            // task. This is a safety net, not a license.
            $leaked = RsxLocks::_release_since($lock_checkpoint);
            foreach ($leaked as $lock_name) {
                $this->warn(
                    "[WORKER] Task {$task_row->id} ({$task_row->class}::{$task_row->method}) "
                    . "ended still holding {$lock_name} - released by the worker. "
                    . 'Release your locks in a finally block.'
                );
            }
        }

        // The temp directory goes now, while no pool lock is held: removing a tree is not
        // a `_tasks` write, and the settle below would otherwise do it under the lock.
        $task_instance->cleanup_temp_dir();

        return [$task_instance, $outcome];
    }

    /**
     * Record a run's outcome on its row, then hand back the identity run lock.
     *
     * Called under the pool lock in the ordinary loop (THE RULE: `_tasks` writes plus the
     * non-blocking run-lock release), and without it when the pool connection was lost -
     * the row is this worker's, so its outcome cannot race another worker's claim.
     *
     * The worker does not settle the row itself: Task_Instance::mark_completed() /
     * mark_failed() are the sole terminal writers and already know whether the row is an
     * on-demand row (terminal completed/failed) or a cron tracker (recycled to pending in
     * the SAME update, so its already-advanced next_run_at fires the next cadence).
     *
     * Releasing the run lock and re-anchoring the coalesced pending run happen while the
     * pool lock is still held, so no worker can claim that pending row in between.
     *
     * @param object $task_row Task database row
     * @param Task_Instance|null $task_instance Null when the row could not be loaded
     * @param array|null $outcome run_task()'s outcome (null with a null instance)
     * @param Task_Lock|null $run_lock Held identity run lock for managed tasks (else null)
     */
    private function settle_task(object $task_row, ?Task_Instance $task_instance, ?array $outcome, ?Task_Lock $run_lock): void
    {
        if ($task_instance !== null) {
            if ($outcome['ok']) {
                try {
                    $task_instance->mark_completed($outcome['result']);
                    $this->info("[WORKER] Task {$task_row->id} completed successfully");
                } catch (\Throwable $e) {
                    // A result the completion write cannot record is a failed run, recorded
                    // as one rather than stranding the row RUNNING.
                    $task_instance->mark_failed($e->getMessage());
                    $this->error("[WORKER] Task {$task_row->id} failed: " . $e->getMessage());
                }
            } else {
                $task_instance->mark_failed($outcome['error']);
                $this->error("[WORKER] Task {$task_row->id} failed: " . $outcome['error']);
            }
        }

        if ($run_lock) {
            // Release the identity run lock and re-anchor any coalesced pending run
            // (mirrors the JS debounce finally{}).
            $run_lock->release();
            Task_Concurrency::reschedule_pending_after_completion(
                $task_row->class,
                $task_row->method,
                $task_row->queue
            );
        }
    }
}
