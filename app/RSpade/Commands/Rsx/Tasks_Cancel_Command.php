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
 * Take a pending task run off the queue; it never runs.
 */
class Tasks_Cancel_Command extends Command
{
    protected $signature = 'rsx:tasks:cancel
        {id : The run (_tasks id)}
        {--explanation= : Why, recorded on the run}';

    protected $description = 'Cancel a pending task run';

    public function handle()
    {
        $task = Task_Run_Model::find((int) $this->argument('id'));
        if ($task === null) {
            $this->error("[ERROR] No task run {$this->argument('id')}.");

            return 1;
        }

        $explanation = $this->option('explanation') !== null ? (string) $this->option('explanation') : null;
        if (!$task->cancel($explanation)) {
            $this->error("[ERROR] Task {$task->id} ({$task->task_name()}) is " . strtolower($task->status_id__label) . '; only a pending run can be cancelled.');

            return 1;
        }

        $this->info("[OK] Cancelled task {$task->id} ({$task->task_name()}).");

        return 0;
    }
}
