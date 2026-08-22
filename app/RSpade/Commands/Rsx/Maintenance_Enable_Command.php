<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

/**
 * Maintenance mode ON - STUB ONLY
 *
 * This command should NEVER execute. system/artisan intercepts
 * 'rsx:maintenance:enable' BEFORE Laravel loads and runs bin/maintenance-mode.sh
 * directly: the pair that raises and lowers the maintenance window can never be
 * refused by the gate it controls, and it has to work on a tree too broken to boot.
 *
 * This stub exists only for command registration (so `php artisan list` shows it),
 * help text, and the edge case where the interception fails.
 *
 * THE FLAGS HERE MUST MIRROR bin/maintenance-mode.sh's argument loop. Nothing
 * enforces that - this stub never runs - so a flag added there and not here is
 * simply invisible in help output.
 */
class Maintenance_Enable_Command extends Command
{
    protected $signature = 'rsx:maintenance:enable
        {--reason= : Operator reason, stored as the flag file content and quoted in the 503}
        {--no-services : Raise the flag only; leave the services running}';

    protected $description = 'Take the application down for maintenance: raise the flag, then stop the services in order';

    public function handle()
    {
        // This should NEVER execute - artisan intercepts before Laravel loads
        $this->error('CRITICAL ERROR: rsx:maintenance:enable executed via Laravel.');
        $this->line('');
        $this->line('This indicates the artisan interception failed.');
        $this->line('Maintenance mode must run BEFORE Laravel loads - it cannot be subject');
        $this->line('to the gate it is raising.');
        $this->line('');
        $this->line('Run it directly instead:  bash system/bin/maintenance-mode.sh enable');

        return 1;
    }
}
