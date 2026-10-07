<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Task\Task_Kill_Request_Model;
use App\RSpade\Core\Task\Task_Kill_Worker;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Task\Task_Run_Model;

/**
 * Force-kill EVERY running task run, synchronously. Used by rsx:maintenance:enable (and so by
 * rsx:framework:pull) to quiesce background work before the window: it returns only once this
 * host's runs are killed and settled KILLED.
 *
 * Each run gets a force-kill request; this host's are carried out right here, in this process
 * (a kill worker could not be spawned - maintenance mode refuses every spawn). A run on
 * another host keeps its request for that host's kill workers.
 */
class Tasks_Kill_All_Command extends Command
{
    protected $signature = 'rsx:tasks:kill-all
        {--explanation= : Required human reason recorded on every killed run}';

    protected $description = "Force-kill every running task run (this host's at once, synchronously)";

    public function handle()
    {
        $explanation = trim((string) $this->option('explanation'));
        if ($explanation === '') {
            $this->error('[ERROR] An --explanation="..." is required to kill tasks.');

            return 1;
        }

        $runs = Task_Run_Model::where('status_id', Task_Run_Model::STATUS_RUNNING)->orderBy('id')->get();
        if ($runs->isEmpty()) {
            $this->info('No running tasks to kill.');

            return 0;
        }

        $here = Task_Pool::host();
        foreach ($runs as $run) {
            $request = Task_Kill_Worker::request($run, Task_Kill_Request_Model::MODE_FORCE_KILL, 0, $explanation, 'the command line');
            if ($request === null) {
                continue;
            }

            if ($request->host !== $here) {
                $this->line("  {$run->id} {$run->task_name()} -> queued for host {$request->host}");
                continue;
            }

            if (Task_Kill_Worker::claim_for_this_process((int) $request->id)) {
                $this->line('  ' . Task_Kill_Worker::carry_out((int) $request->id));
            }
        }

        return 0;
    }
}
