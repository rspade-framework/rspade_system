<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Task\Task_Concurrency;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Lock;
use App\RSpade\Core\Task\Task_Notify;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Run_Outcome;

/**
 * Task_Runner - the one path every run takes, wherever it runs: write the row, execute the
 * method, settle the outcome. A pool worker, an inline run (Task::internal(), rsx:task:run, a
 * #[Command]) and rsx:task:process --once all go through here, so a run is recorded and
 * settled identically however it was started.
 */
class Task_Runner
{
    /**
     * Insert a run's row and return its id.
     *
     * The row records who started it and for which site - the signed-in identity of the
     * current realm (Rsx_Model_Abstract::_resolve_context_actor()) and the current site - so an
     * application's view gate can show users the runs they started.
     *
     * @param array $fields Any further _tasks columns (status_id, scheduled_for, schedule_id, ...)
     */
    public static function insert_row(string $class, string $method, array $params, int $origin_id, array $fields = []): int
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $actor = Rsx_Model_Abstract::_resolve_context_actor();

        $row = [
            'class' => $class,
            'method' => $method,
            'params' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'params_hash' => Task_Concurrency::params_hash($params),
            'origin_id' => $origin_id,
            'status_id' => Task_Run_Model::STATUS_PENDING,
            'site_id' => static::__current_site_id(),
            'dispatched_by_id' => $actor['id'] ?? null,
            'dispatched_by_type' => $actor !== null ? Type_Ref_Registry::class_to_id($actor['type']) : null,
            'scheduled_for' => $now,
            'timeout' => config('rsx.tasks.default_timeout'),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return (int) DB::table('_tasks')->insertGetId(array_merge($row, $fields));
    }

    /**
     * Execute a run's method and say how it ended. Nothing is settled here (settle() does
     * that), and nothing escapes: a throw is an outcome.
     *
     * Everything the method prints (echo, print, var_dump) is captured line by line as the
     * run's stdout and passed through unchanged, so a console runner still shows it.
     * Service::pre_task() runs first; a non-null return from it ends the run with that value as
     * the return.
     *
     * @param bool $release_leaked_locks A WORKER runs unrelated tasks back to back in one long
     *        process, so it hands back every lock the task took and left held (loudly - that
     *        is a defect in the task). An inline caller keeps its own locks.
     */
    public static function execute(Task_Instance $instance, bool $release_leaked_locks): Task_Run_Outcome
    {
        $class = $instance->get_class();
        $method = $instance->get_method();
        $params = $instance->get_params();

        $lock_checkpoint = RsxLocks::_checkpoint();

        try {
            if (!class_exists($class)) {
                throw new \RuntimeException("Class not found: {$class}");
            }
            if (!method_exists($class, $method)) {
                throw new \RuntimeException("Method not found: {$class}::{$method}");
            }

            $value = static::__capturing_output($instance, function () use ($class, $method, $instance, $params) {
                $pre = $class::pre_task($instance, $params);
                if ($pre !== null) {
                    return $pre;
                }

                return $class::$method($instance, $params);
            });

            $outcome = Task_Run_Outcome::from_return($value);
        } catch (Throwable $e) {
            // Throwable, not Exception: a TypeError inside a task is a failed run like any other.
            $outcome = Task_Run_Outcome::from_throwable($e);
        } finally {
            if ($release_leaked_locks) {
                foreach (RsxLocks::_release_since($lock_checkpoint) as $lock_name) {
                    $message = "Task ended still holding {$lock_name} - released by the worker. Release your locks in a finally block.";
                    $instance->stderr($message);
                    Log::warning("[WORKER] Task {$instance->get_id()} ({$class}::{$method}): {$message}");
                }
            }
        }

        $instance->cleanup_temp_dir();

        return $outcome;
    }

    /**
     * Record a run's outcome on its row, then hand back its identity run lock.
     *
     * The held reports are written first, so nothing the task reported is lost. The row
     * settles COMPLETED (STOPPED when a stop had been requested) or FAILED; the write matches
     * only a row still RUNNING, so a run a kill worker already settled KILLED keeps that verdict.
     * A scheduled run updates its schedule's statistics. A pool worker calls this under its
     * pool lock (THE RULE: task-table writes only; frames are deferred).
     */
    public static function settle(Task_Instance $instance, Task_Run_Outcome $outcome, ?Task_Lock $run_lock = null): void
    {
        if (!$outcome->success) {
            $instance->stderr('Task failed: ' . $outcome->error);
        }
        $instance->flush();

        $id = $instance->get_id();
        $now = now()->format('Y-m-d H:i:s.v');

        $status = $outcome->success
            ? DB::raw('IF(stop_requested_at IS NULL, ' . Task_Run_Model::STATUS_COMPLETED . ', ' . Task_Run_Model::STATUS_STOPPED . ')')
            : Task_Run_Model::STATUS_FAILED;

        $settled = DB::table('_tasks')
            ->where('id', $id)
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->update([
                'status_id' => $status,
                'return_code' => $outcome->return_code,
                'error' => $outcome->error,
                // A success ends whatever an earlier attempt explained (an abandoned run's
                // retry note); a failure's explanation is its error.
                'status_reason' => null,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($settled) {
            static::__update_schedule($id, $outcome, $now);
        }

        Task_Notify::lifecycle($id);

        if ($run_lock !== null) {
            // Release the identity run lock and re-anchor any coalesced pending run (mirrors
            // the JS debounce finally{}).
            $run_lock->release();
            Task_Concurrency::reschedule_pending_after_completion($instance->get_class(), $instance->get_method(), Task_Concurrency::params_hash($instance->get_params()));
        }
    }

    /**
     * Settle an INLINE run its own process left RUNNING - a fatal error ended the request or
     * command mid-task. Registered as a shutdown function by Task::internal(); a no-op for a run
     * that settled normally.
     */
    public static function settle_abandoned_inline(int $task_id): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $settled = DB::table('_tasks')
            ->where('id', $task_id)
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->whereNull('worker_id')
            ->where('worker_pid', getmypid())
            ->update([
                'status_id' => Task_Run_Model::STATUS_FAILED,
                'return_code' => 1,
                'error' => 'The process running this task ended before the task returned.',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($settled) {
            Task_Notify::lifecycle($task_id);
        }
    }

    /**
     * The fields that mark a row RUNNING in THIS process: started now, here, by this pid. A
     * pool worker adds its pool identity (worker_id, worker_generation).
     */
    public static function running_fields(): array
    {
        $now = now()->format('Y-m-d H:i:s.v');

        return [
            'status_id' => Task_Run_Model::STATUS_RUNNING,
            'started_at' => $now,
            'worker_pid' => getmypid(),
            'worker_host' => Task_Pool::host(),
            'updated_at' => $now,
        ];
    }

    /**
     * Run $call with everything it prints recorded as the run's stdout, line by line, as it is
     * printed (the handler runs on every write: chunk size 1); a trailing partial line is
     * recorded when the call returns or throws. The text is passed through unchanged.
     *
     * Output a task buffers itself (a view rendered with ob_start) is the task's own and never
     * reaches this handler; buffers the task opened and left open are flushed down into it
     * before the capture ends.
     */
    private static function __capturing_output(Task_Instance $instance, callable $call): mixed
    {
        $partial = '';

        ob_start(function (string $chunk) use ($instance, &$partial) {
            $partial .= $chunk;
            while (($newline = strpos($partial, "\n")) !== false) {
                $instance->_record_captured_stdout(rtrim(substr($partial, 0, $newline), "\r"));
                $partial = substr($partial, $newline + 1);
            }

            return $chunk;
        }, 1);
        $level = ob_get_level();

        try {
            return $call();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_flush();
            }
            ob_end_flush();

            if ($partial !== '') {
                $instance->_record_captured_stdout(rtrim($partial, "\r"));
            }
        }
    }

    /**
     * A scheduled run's outcome, on its schedule: success clears the failure streak, failure
     * extends it. Either way the schedule remembers its latest run.
     */
    private static function __update_schedule(int $task_id, Task_Run_Outcome $outcome, string $now): void
    {
        $schedule_id = DB::table('_tasks')->where('id', $task_id)->value('schedule_id');
        if ($schedule_id === null) {
            return;
        }

        $update = ['last_task_id' => $task_id, 'updated_at' => $now];
        if ($outcome->success) {
            $update['last_success_at'] = $now;
            $update['consecutive_failures'] = 0;
            $update['last_error'] = null;
        } else {
            $update['last_error_at'] = $now;
            $update['last_error'] = $outcome->error;
            $update['consecutive_failures'] = DB::raw('consecutive_failures + 1');
        }

        DB::table('_task_schedules')->where('id', $schedule_id)->update($update);
    }

    /**
     * The current realm's site (0 - the Default site - for a run started with no session, as
     * a sessionless email files under site 0).
     */
    private static function __current_site_id(): int
    {
        return Rsx_Site_Model_Abstract::get_current_site_id();
    }
}
