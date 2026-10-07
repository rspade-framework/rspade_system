<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Notify;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Task_Kill_Worker - force stops and force kills, carried out asynchronously.
 *
 * Killing a run never happens inside the request that asked for it: a force stop waits out a
 * grace period first, and a web request must not sit through it. request() records a
 * _task_kill_requests row bound to the HOST that runs the target's worker (a pid names a
 * process on one machine only) and, when that is this host, spawns a kill worker
 * (rsx:task:killer, the kill pool, rsx.tasks.pools.kill.max_workers). Every host's
 * rsx:task:process tick spawns kill workers for its own due requests, so a request made on
 * another host, or left when the pool was full, is carried out within a minute.
 *
 * A KILL WORKER claims one request, then re-reads the run every POLL_SECONDS until the
 * request is due: a run that ended (stopped on its own, completed, failed) or whose worker is
 * no longer the one the request names makes the request MOOT. When it is due, the worker's
 * process is SIGKILLed - on this host only - and the run settles KILLED with the explanation
 * and an operator line. Kill workers never take a task pool's lock beyond their own claim.
 *
 * An inline run inside a WEB REQUEST is never signalled: its pid is a php-fpm worker serving
 * other requests. Its kill request is settled moot, naming that only a graceful stop applies.
 */
class Task_Kill_Worker
{
    /**
     * How often a waiting kill worker re-reads the run. The poll of a condition, not a
     * deadline: the wait ends when the request is due or moot, however long that takes.
     */
    const POLL_SECONDS = 1;

    /**
     * Record a force stop (due after $grace_seconds) or force kill (due now) of a RUNNING run,
     * and start a kill worker when the run's worker is on this host. Returns the request, or
     * null when the run is not running or already has a live request of the same mode.
     *
     * @param int $mode_id Task_Kill_Request_Model::MODE_FORCE_STOP or MODE_FORCE_KILL
     * @param string $actor_label Who asked, for the operator line ("Jane Smith", "the timeout reaper")
     */
    public static function request(Task_Run_Model $task, int $mode_id, int $grace_seconds, string $explanation, string $actor_label): ?Task_Kill_Request_Model
    {
        if ((int) $task->status_id !== Task_Run_Model::STATUS_RUNNING || $task->worker_pid === null) {
            return null;
        }

        $live = Task_Kill_Request_Model::where('task_id', $task->id)
            ->where('mode_id', $mode_id)
            ->whereIn('status_id', [Task_Kill_Request_Model::STATUS_PENDING, Task_Kill_Request_Model::STATUS_CLAIMED])
            ->exists();
        if ($live) {
            return null;
        }

        $actor = \App\RSpade\Core\Database\Models\Rsx_Model_Abstract::_resolve_context_actor();

        $request = new Task_Kill_Request_Model();
        $request->task_id = $task->id;
        $request->host = $task->worker_host ?? Task_Pool::host();
        $request->target_pid = $task->worker_pid;
        $request->mode_id = $mode_id;
        $request->status_id = Task_Kill_Request_Model::STATUS_PENDING;
        $request->kill_after_at = date('Y-m-d H:i:s', time() + max(0, $grace_seconds)) . '.000';
        $request->explanation = $explanation;
        if ($actor !== null) {
            $request->requested_by_type = $actor['type'];
            $request->requested_by_id = $actor['id'];
        }
        $request->save();

        if ($request->host === Task_Pool::host()) {
            Task::spawn_worker(Task_Pool::KILL);
        }

        return $request;
    }

    /**
     * Whether $host has a pending kill request a kill worker should pick up now. A force
     * stop's request is picked up as soon as it is made (its worker waits out the grace), so
     * any pending request counts.
     */
    public static function has_due_requests(string $host): bool
    {
        return DB::table('_task_kill_requests')
            ->where('host', $host)
            ->where('status_id', Task_Kill_Request_Model::STATUS_PENDING)
            ->exists();
    }

    /**
     * The kill worker loop (rsx:task:killer). Admits itself to the kill pool, then claims and
     * carries out this host's requests, earliest due first, until none is left.
     *
     * @param callable $say fn (string $line): void - the command's narration
     * @return int exit code
     */
    public static function run(callable $say): int
    {
        $pool = Task_Pool::KILL;

        try {
            Task_Pool::lock($pool);
            if (Task_Pool::count($pool) >= Task_Pool::max_workers($pool)) {
                Task_Pool::unlock($pool);
                $say('[KILLER] The kill pool is full, exiting');

                return 0;
            }
            $identity = Task_Pool::join($pool);

            while (true) {
                $request_id = static::__claim($identity);
                if ($request_id === null) {
                    break;
                }

                Task_Pool::unlock($pool);
                $say("[KILLER] " . static::carry_out($request_id));
                Task_Pool::lock($pool);
            }

            Task_Pool::leave($pool);
            Task_Pool::unlock($pool);
        } catch (\Throwable $e) {
            Task_Pool::disconnect();

            throw $e;
        }

        return 0;
    }

    /**
     * Carry out one claimed request: wait until it is due, re-reading the run, then kill the
     * run's worker and settle the run KILLED - or settle the request moot. Returns a line
     * describing what happened.
     */
    public static function carry_out(int $request_id): string
    {
        $request = Task_Kill_Request_Model::find($request_id);

        while (true) {
            $task = DB::table('_tasks')->where('id', $request->task_id)->first(['id', 'status_id', 'worker_pid', 'worker_id', 'worker_host', 'schedule_id']);

            if ($task === null || (int) $task->status_id !== Task_Run_Model::STATUS_RUNNING || (int) $task->worker_pid !== (int) $request->target_pid) {
                return static::__finish($request, Task_Kill_Request_Model::STATUS_MOOT, 'the run ended before it was due');
            }

            if (time() >= strtotime($request->kill_after_at)) {
                break;
            }

            sleep(self::POLL_SECONDS);
        }

        $pid = (int) $request->target_pid;

        if ($task->worker_id === null && !static::__is_cli_process($pid)) {
            Task_Instance::record_operator_line((int) $task->id, "Kill not carried out: the run is inside a web request (PID {$pid}), which is never signalled. Only a graceful stop applies.");

            return static::__finish($request, Task_Kill_Request_Model::STATUS_MOOT, 'inline run inside a web request; not signalled');
        }

        $signalled = false;
        if (static::__process_exists($pid)) {
            posix_kill($pid, 9);
            $signalled = true;

            // SIGKILL cannot be refused: wait for the process to be gone, re-reading at a short
            // interval (a poll of a certain outcome, not a deadline).
            while (static::__process_exists($pid)) {
                usleep(100000);
            }
        }

        $now = now()->format('Y-m-d H:i:s.v');
        $settled = DB::table('_tasks')
            ->where('id', $task->id)
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->where('worker_pid', $pid)
            ->update([
                'status_id' => Task_Run_Model::STATUS_KILLED,
                'status_reason' => $request->explanation,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        if (!$settled) {
            return static::__finish($request, Task_Kill_Request_Model::STATUS_MOOT, 'the run settled before the kill');
        }

        if ($task->schedule_id !== null) {
            DB::table('_task_schedules')->where('id', $task->schedule_id)->update([
                'last_task_id' => $task->id,
                'last_error_at' => $now,
                'last_error' => 'killed: ' . $request->explanation,
                'consecutive_failures' => DB::raw('consecutive_failures + 1'),
                'updated_at' => $now,
            ]);
        }

        $mode = Task_Kill_Request_Model::$enums['mode_id'][(int) $request->mode_id]['label'];
        Task_Instance::record_operator_line((int) $task->id, $signalled
            ? "Killed ({$mode}): {$request->explanation}"
            : "Settled as killed ({$mode}): {$request->explanation} - the worker process was already gone");
        Task_Notify::lifecycle((int) $task->id);

        return static::__finish($request, Task_Kill_Request_Model::STATUS_DONE, $signalled ? 'killed' : 'killed_no_process');
    }

    /**
     * Claim a pending request for THIS process to carry out directly (rsx:tasks:kill-all, which
     * cannot spawn a kill worker under maintenance mode). False when a kill worker took it.
     */
    public static function claim_for_this_process(int $request_id): bool
    {
        return (bool) DB::table('_task_kill_requests')
            ->where('id', $request_id)
            ->where('status_id', Task_Kill_Request_Model::STATUS_PENDING)
            ->update([
                'status_id' => Task_Kill_Request_Model::STATUS_CLAIMED,
                'worker_pid' => getmypid(),
                'updated_at' => now(),
            ]);
    }

    /**
     * The earliest-due pending request for this host, claimed by a guarded write under the kill
     * pool's lock. Null when none is pending.
     */
    private static function __claim(array $identity): ?int
    {
        $host = Task_Pool::host();

        while (true) {
            $id = DB::table('_task_kill_requests')
                ->where('host', $host)
                ->where('status_id', Task_Kill_Request_Model::STATUS_PENDING)
                ->orderBy('kill_after_at')
                ->orderBy('id')
                ->value('id');

            if ($id === null) {
                return null;
            }

            $claimed = DB::table('_task_kill_requests')
                ->where('id', $id)
                ->where('status_id', Task_Kill_Request_Model::STATUS_PENDING)
                ->update([
                    'status_id' => Task_Kill_Request_Model::STATUS_CLAIMED,
                    'worker_pid' => getmypid(),
                    'worker_id' => $identity['wid'],
                    'worker_generation' => $identity['generation'],
                    'updated_at' => now(),
                ]);

            if ($claimed) {
                return (int) $id;
            }
        }
    }

    private static function __finish(Task_Kill_Request_Model $request, int $status_id, string $outcome): string
    {
        $request->status_id = $status_id;
        $request->outcome = $outcome;
        $request->completed_at = now()->format('Y-m-d H:i:s.v');
        $request->save();

        return "Kill request {$request->id} for task {$request->task_id}: {$outcome}";
    }

    /**
     * Whether $pid is a running process on this host. A zombie (exited, not yet reaped) has an
     * empty command line and counts as gone.
     */
    private static function __process_exists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');

        return $cmdline !== false && $cmdline !== '';
    }

    /** Whether $pid is an artisan command of this project (rsx:task:run, a #[Command], --once). */
    private static function __is_cli_process(int $pid): bool
    {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');

        return $cmdline !== false && str_contains($cmdline, base_path('artisan'));
    }
}
