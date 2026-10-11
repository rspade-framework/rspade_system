<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Task\Task;

/**
 * Turn the task system back ON (Task::enable()). It only removes the switch: nothing is
 * started here, and the next rsx:task:process tick spawns the workers for whatever is due -
 * the runs that waited PENDING, and each schedule that came due meanwhile, once.
 *
 * Run by rsx:maintenance:disable after the services are back. A no-op that says so when the
 * system is already on.
 */
class Tasks_Enable_Command extends Command
{
    protected $signature = 'rsx:tasks:enable';

    protected $description = 'Turn the task system back on; the next rsx:task:process tick picks up the queued work';

    public function handle()
    {
        $state = Task::disabled_state();

        if (!Task::enable()) {
            $this->info('[OK] The task system is already on.');

            return 0;
        }

        $since = $state !== null ? " (it was off since {$state['disabled_at']})" : '';
        $this->info("[OK] The task system is ON{$since}. The next rsx:task:process tick starts the queued work.");

        return 0;
    }
}
