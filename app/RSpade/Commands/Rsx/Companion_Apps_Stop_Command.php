<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Companion\Companion_Flags;
use Illuminate\Console\Command;

/**
 * rsx:companion_apps:stop [name] - desired state STOPPED, then a graceful stop: the stop
 * message, SIGTERM to the process group, the companion's grace, SIGKILL. Returns when they have
 * stopped. See rsx:man companion_apps.
 */
class Companion_Apps_Stop_Command extends Command
{
    use Companion_Apps_Cli_Support;

    protected $signature = 'rsx:companion_apps:stop
        {name? : One companion; all of them when omitted}';

    protected $description = 'Set companion applications to stopped and stop them gracefully';

    public function handle(): int
    {
        $names = $this->target_names();

        if ($names === null) {
            return $this->argument('name') === null ? 0 : 1;
        }

        foreach ($names as $name) {
            Companion_Flags::set_desired($name, false);
        }

        if ($this->tell_daemon('reload', ['wait' => true]) !== null) {
            $this->info('[OK] Stopped: ' . implode(', ', $names) . '.');
        }

        return 0;
    }
}
