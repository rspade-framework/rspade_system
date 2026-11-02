<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Companion\Companion_Flags;
use Illuminate\Console\Command;

/**
 * rsx:companion_apps:start [name] - desired state RUNNING for one companion or all, then tell
 * rsx-companiond. It starts each once it is ready: the install, the election and the
 * companion's own ready_to_run(). See rsx:man companion_apps.
 */
class Companion_Apps_Start_Command extends Command
{
    use Companion_Apps_Cli_Support;

    protected $signature = 'rsx:companion_apps:start
        {name? : One companion; all of them when omitted}';

    protected $description = 'Set companion applications to running and start them';

    public function handle(): int
    {
        $names = $this->target_names();

        if ($names === null) {
            return $this->argument('name') === null ? 0 : 1;
        }

        foreach ($names as $name) {
            Companion_Flags::set_desired($name, true);
        }

        // An operator's earlier stop or a finished restart policy is cleared by asking again.
        $arguments = ['action' => 'start', 'wait' => true];
        if ($this->argument('name') !== null) {
            $arguments['name'] = $this->argument('name');
        }

        if ($this->tell_daemon('reload', ['wait' => true]) !== null) {
            $this->tell_daemon('control', $arguments);
            $this->info('[OK] Desired running: ' . implode(', ', $names) . '. rsx:companion_apps:list shows what each is doing.');
        }

        return 0;
    }
}
