<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Stop one task run.
 *
 *   rsx:tasks:stop <id>                  graceful: ask the task to stop; never killed
 *   rsx:tasks:stop <id> --force          force stop: ask, then kill after the grace period
 *                                        (--grace=<seconds>, default rsx.tasks.stop_grace_seconds)
 *   rsx:tasks:stop <id> --kill           force kill: kill the worker now
 *
 * A pending run is cancelled by --force and --kill (it has no worker); a graceful stop of a
 * pending run is seen at its first stop check. Every operation is recorded on the run's
 * output as an operator line, with the --explanation when one is given.
 */
class Tasks_Stop_Command extends Command
{
    protected $signature = 'rsx:tasks:stop
        {id : The run (_tasks id)}
        {--force : Kill the worker if the task is still running after the grace period}
        {--grace= : Seconds the task gets to stop on its own before a --force kill}
        {--kill : Kill the worker now}
        {--explanation= : Why, recorded on the run}';

    protected $description = 'Stop a task run: graceful, --force (kill after a grace period) or --kill (now)';

    public function handle()
    {
        $task = Task_Run_Model::find((int) $this->argument('id'));
        if ($task === null) {
            $this->error("[ERROR] No task run {$this->argument('id')}.");

            return 1;
        }

        $explanation = $this->option('explanation') !== null ? (string) $this->option('explanation') : null;

        if ($this->option('kill')) {
            $done = $task->force_kill($explanation);
            $what = 'Force kill requested';
        } elseif ($this->option('force')) {
            $grace = $this->option('grace') !== null ? (int) $this->option('grace') : null;
            $done = $task->force_stop($grace, $explanation);
            $what = 'Force stop requested';
        } else {
            $done = $task->request_stop($explanation);
            $what = 'Graceful stop requested';
        }

        if (!$done) {
            $this->error("[ERROR] Task {$task->id} ({$task->task_name()}) is " . strtolower($task->status_id__label) . '; nothing to stop.');

            return 1;
        }

        $this->info("[OK] {$what} for task {$task->id} ({$task->task_name()}).");

        return 0;
    }
}
