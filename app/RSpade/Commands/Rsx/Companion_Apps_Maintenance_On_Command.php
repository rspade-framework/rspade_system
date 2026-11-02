<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Companion\Companion_Flags;
use Illuminate\Console\Command;

/**
 * rsx:companion_apps:maintenance_on - stop every companion for a window, desired state
 * untouched. Returns when they have stopped.
 *
 * The framework's own maintenance window does not call this: it stops the rsx-companiond
 * supervisor program, which stops every companion the same way and works on a broken tree.
 * This is the operator's verb, and the one for a box where the daemon runs without supervisor.
 */
class Companion_Apps_Maintenance_On_Command extends Command
{
    use Companion_Apps_Cli_Support;

    protected $signature = 'rsx:companion_apps:maintenance_on';

    protected $description = 'Stop every companion application for a window, leaving desired state alone';

    public function handle(): int
    {
        Companion_Flags::set_maintenance(true);

        if ($this->tell_daemon('reload', ['wait' => true]) !== null) {
            $this->info('[OK] Companion maintenance is on: every companion is stopped. maintenance_off starts those desired running.');
        }

        return 0;
    }
}
