<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Task\Task_Kill_Worker;

/**
 * Kill worker: carries out this host's force stops and force kills (Task_Kill_Worker).
 *
 * Spawned detached into the kill pool by Task_Kill_Worker::request() and by the
 * rsx:task:process tick. It admits itself to the kill pool (rsx.tasks.pools.kill.max_workers),
 * claims this host's pending kill requests one at a time, waits out each one's grace period
 * re-reading the run, and kills the run's worker when it is due - or settles the request moot
 * when the run ended first. It exits when no request is left.
 */
class Task_Killer_Command extends Command
{
    protected $signature = 'rsx:task:killer';

    protected $description = "Kill worker: carries out this host's force stops and force kills";

    public function handle()
    {
        return Task_Kill_Worker::run(fn (string $line) => $this->info($line));
    }
}
