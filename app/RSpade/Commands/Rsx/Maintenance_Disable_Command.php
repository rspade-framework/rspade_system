<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

/**
 * Maintenance mode OFF - STUB ONLY
 *
 * This command should NEVER execute. system/artisan intercepts
 * 'rsx:maintenance:disable' BEFORE Laravel loads and runs bin/maintenance-mode.sh
 * directly: the pair that raises and lowers the maintenance window can never be
 * refused by the gate it controls, and it has to work on a tree too broken to boot.
 * A flag left stuck by a crash is cleared by simply running this.
 *
 * This stub exists only for command registration (so `php artisan list` shows it),
 * help text, and the edge case where the interception fails.
 *
 * THE FLAGS HERE MUST MIRROR bin/maintenance-mode.sh's argument loop. Nothing
 * enforces that - this stub never runs - so a flag added there and not here is
 * simply invisible in help output.
 */
class Maintenance_Disable_Command extends Command
{
    protected $signature = 'rsx:maintenance:disable
        {--no-services : Clear the flag only; leave the services as they are}
        {--force : Clear the flag even when no maintenance window is recorded}';

    protected $description = 'Bring the application back up: start the services in order, then clear the maintenance flag';

    public function handle()
    {
        // This should NEVER execute - artisan intercepts before Laravel loads
        $this->error('CRITICAL ERROR: rsx:maintenance:disable executed via Laravel.');
        $this->line('');
        $this->line('This indicates the artisan interception failed.');
        $this->line('Maintenance mode must run BEFORE Laravel loads - it cannot be subject');
        $this->line('to the gate it is lowering.');
        $this->line('');
        $this->line('Run it directly instead:  bash system/bin/maintenance-mode.sh disable');

        return 1;
    }
}
