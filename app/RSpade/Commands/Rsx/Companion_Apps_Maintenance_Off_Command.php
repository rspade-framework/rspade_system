<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Companion\Companion_Flags;
use Illuminate\Console\Command;

/**
 * rsx:companion_apps:maintenance_off - lift the overlay: the companions whose desired state is
 * running start again. See rsx:companion_apps:maintenance_on.
 */
class Companion_Apps_Maintenance_Off_Command extends Command
{
    use Companion_Apps_Cli_Support;

    protected $signature = 'rsx:companion_apps:maintenance_off';

    protected $description = 'Lift companion maintenance; those desired running start again';

    public function handle(): int
    {
        Companion_Flags::set_maintenance(false);

        if ($this->tell_daemon('reload', ['wait' => true]) !== null) {
            $this->info('[OK] Companion maintenance is off.');
        }

        return 0;
    }
}
