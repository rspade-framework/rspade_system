<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

/**
 * rsx:companion_apps:restart [name] - a graceful stop, then a start, with desired state
 * unchanged: a companion whose desired state is stopped stays stopped. See rsx:man companion_apps.
 */
class Companion_Apps_Restart_Command extends Command
{
    use Companion_Apps_Cli_Support;

    protected $signature = 'rsx:companion_apps:restart
        {name? : One companion; all of them when omitted}';

    protected $description = 'Restart companion applications gracefully; desired state is unchanged';

    public function handle(): int
    {
        $names = $this->target_names();

        if ($names === null) {
            return $this->argument('name') === null ? 0 : 1;
        }

        $arguments = ['action' => 'restart', 'wait' => true];
        if ($this->argument('name') !== null) {
            $arguments['name'] = $this->argument('name');
        }

        if ($this->tell_daemon('control', $arguments, false) !== null) {
            $this->info('[OK] Restarted: ' . implode(', ', $names) . '.');
        }

        return 0;
    }
}
