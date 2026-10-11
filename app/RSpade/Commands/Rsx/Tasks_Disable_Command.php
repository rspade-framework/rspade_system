<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Kill_Worker;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Turn the task system OFF and stop what is running, synchronously: when this returns 0 the
 * switch is off and no task is running on this host. It is the first half of a clean server
 * replacement, and what rsx:maintenance:enable (and so rsx:framework:pull) runs to quiesce
 * background work.
 *
 *   1. THE SWITCH FIRST (Task::disable()): from here nothing starts executing - no worker
 *      claims a run, nothing spawns a task worker, and an inline run is queued while its
 *      caller waits - so the set of runs to stop can only shrink.
 *   2. Every RUNNING run - a pool worker's and an inline one alike - gets a FORCE STOP
 *      (Task_Run_Model::force_stop()): it is asked to stop and, if it is still running after
 *      the grace (--grace, default rsx.tasks.stop_grace_seconds), its process is killed. A run
 *      that became RUNNING in the instant the switch was thrown is caught by the re-read.
 *   3. This host's kill requests are carried out HERE, in this process - under maintenance
 *      mode no kill worker can be spawned - each one waiting out its grace while re-reading
 *      the run, so a task that stops on its own ends the wait at once. A request a kill worker
 *      took first is simply waited for.
 *   4. The rows are re-read until no stopped run on this host is RUNNING with a kill still
 *      owed. Nothing bounds that wait beyond the grace: once the grace has passed the kill is
 *      a SIGKILL, which cannot be refused.
 *
 * TWO KINDS OF RUN ARE LEFT RUNNING, each named in the output:
 *
 *   - a run on ANOTHER host keeps its kill request for that host's own rsx:task:process tick
 *     (a pid names a process on one machine). It does not change the exit code.
 *   - an inline run inside a WEB REQUEST on this host is asked to stop but never signalled -
 *     its pid is a php-fpm worker serving other requests - so it ends when the task sees the
 *     request to stop or when its web request ends. The command does not wait for it: it
 *     lists it and exits 2.
 *
 * EXIT CODES: 0 - off, and nothing is running on this host; 2 - off, and the inline
 * web-request runs listed are still running on this host; 1 - a bad option.
 *
 * PENDING runs are not touched: they wait, and are claimed after rsx:tasks:enable. That
 * includes the queued run of an inline caller that is waiting for the system to come back -
 * a waiting process executes nothing, and if it is ended with the server its run stays queued.
 *
 * Idempotent: on a system that is already off the record of the first disabling is kept and
 * the stop pass runs again.
 */
class Tasks_Disable_Command extends Command
{
    protected $signature = 'rsx:tasks:disable
        {--reason= : Why, shown by rsx:health, rsx:tasks:list and the /_sys task screens}
        {--grace= : Seconds a running task gets to stop on its own before its worker is killed (default rsx.tasks.stop_grace_seconds)}';

    protected $description = 'Turn the task system off: no new task starts, running tasks are stopped (gracefully, then killed after the grace)';

    /**
     * How often the wait re-reads the runs. The poll of a condition, not a deadline: the wait
     * ends when the runs have settled.
     */
    private const POLL_SECONDS = 1;

    public function handle()
    {
        $grace = $this->option('grace');
        if ($grace !== null && !ctype_digit((string) $grace)) {
            $this->error('[ERROR] --grace is a whole number of seconds, 0 or more.');

            return 1;
        }
        $grace = $grace !== null ? (int) $grace : (int) config('rsx.tasks.stop_grace_seconds');

        $reason = trim((string) $this->option('reason'));

        if (Task::disable($reason === '' ? null : $reason)) {
            $this->info('[OK] The task system is OFF. No new task starts until: php artisan rsx:tasks:enable');
        } else {
            $state = Task::disabled_state();
            $this->info("[OK] The task system was already off (since {$state['disabled_at']}, by {$state['disabled_by']}); stopping whatever is still running.");
        }

        return $this->stop_running_runs($grace, 'the task system was turned off' . ($reason !== '' ? ": {$reason}" : ''));
    }

    /**
     * Force-stop the RUNNING runs and wait for this host's to settle. See the class docblock.
     *
     * @return int the command's exit code
     */
    private function stop_running_runs(int $grace, string $explanation): int
    {
        $here = Task_Pool::host();
        $asked = [];

        while (true) {
            $running = Task_Run_Model::where('status_id', Task_Run_Model::STATUS_RUNNING)->orderBy('id')->get();

            foreach ($running as $run) {
                if (isset($asked[$run->id])) {
                    continue;
                }

                $asked[$run->id] = $run->task_name();
                if ($run->force_stop($grace, $explanation)) {
                    $where = $run->worker_host !== null && $run->worker_host !== $here ? " on host {$run->worker_host}" : '';
                    $this->line("  Stopping task {$run->id} ({$run->task_name()}){$where}: killed if still running in {$grace}s");
                }
            }

            if ($asked === []) {
                $this->info('No running tasks.');

                return 0;
            }

            // This host's kills, in this process. carry_out() waits for the request to be due,
            // re-reading the run, and returns early when the run ends on its own.
            $pending = DB::table('_task_kill_requests')
                ->whereIn('task_id', array_keys($asked))
                ->where('host', $here)
                ->where('status_id', Task_Kill_Request_Model::STATUS_PENDING)
                ->orderBy('kill_after_at')
                ->orderBy('id')
                ->pluck('id');
            foreach ($pending as $request_id) {
                if (Task_Kill_Worker::claim_for_this_process((int) $request_id)) {
                    $this->line('  ' . Task_Kill_Worker::carry_out((int) $request_id));
                }
            }

            // Still owed on this host: a stopped run that is RUNNING with a live kill request
            // (one a kill worker holds, or one made a moment ago).
            $owed = DB::table('_tasks')
                ->whereIn('id', array_keys($asked))
                ->where('status_id', Task_Run_Model::STATUS_RUNNING)
                ->where(function ($query) use ($here) {
                    $query->whereNull('worker_host')->orWhere('worker_host', $here);
                })
                ->whereExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('_task_kill_requests')
                        ->whereColumn('_task_kill_requests.task_id', '_tasks.id')
                        ->whereIn('_task_kill_requests.status_id', [Task_Kill_Request_Model::STATUS_PENDING, Task_Kill_Request_Model::STATUS_CLAIMED]);
                })
                ->exists();

            if (!$owed) {
                break;
            }

            sleep(self::POLL_SECONDS);
        }

        return $this->report_left_running($here);
    }

    /**
     * Name what is still RUNNING and why this command leaves it - another host's runs, and
     * this host's inline runs inside a web request - and answer the exit code.
     */
    private function report_left_running(string $here): int
    {
        $left = DB::table('_tasks')
            ->where('status_id', Task_Run_Model::STATUS_RUNNING)
            ->orderBy('id')
            ->get(['id', 'class', 'method', 'worker_host', 'worker_pid']);

        $local = 0;
        foreach ($left as $run) {
            $name = class_basename($run->class) . '::' . $run->method;

            if ($run->worker_host !== null && $run->worker_host !== $here) {
                $this->line("  Task {$run->id} ({$name}) runs on host {$run->worker_host}: its stop is carried out by that host's rsx:task:process tick");
                continue;
            }

            $local++;
            $this->warn("  [WARNING] Task {$run->id} ({$name}) is still running inside a web request (PID {$run->worker_pid}), which is never signalled:"
                . ' it was asked to stop, and ends when it sees that or when its request ends');
        }

        if ($local > 0) {
            $this->warn("[WARNING] The task system is off, and {$local} inline run(s) inside web requests are still running on this host.");

            return 2;
        }

        $this->info('[OK] No task is running on this host.');

        return 0;
    }
}
