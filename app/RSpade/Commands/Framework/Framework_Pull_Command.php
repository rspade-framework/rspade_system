<?php

namespace App\RSpade\Commands\Framework;

use Illuminate\Console\Command;

/**
 * Framework Update Command - STUB ONLY
 *
 * This command should NEVER execute. system/artisan intercepts
 * 'rsx:framework:pull' BEFORE Laravel loads and runs
 * bin/framework-pull-upstream.sh directly - the update must not run on the
 * framework code it is about to replace, and it has to work on a tree too broken
 * to boot.
 *
 * This stub exists only for command registration (so `php artisan list` shows it),
 * help text, and the edge case where the interception fails.
 *
 * THE FLAGS HERE MUST MIRROR bin/framework-pull-upstream.sh.dist's parse_flags().
 * Nothing enforces that - this stub never runs - so a flag added there and not here
 * is simply invisible in help output.
 */
class Framework_Pull_Command extends Command
{
    protected $signature = 'rsx:framework:pull
        {--no-rebuild : Sync only; print the rebuild commands to run yourself}
        {--no-commit : Update the submodule but leave the pointer uncommitted}
        {--no-service-control : Do not raise the maintenance window (you manage it)}
        {--diff : Preview the incoming changelog; makes no changes}
        {--upstream-url= : Pull from somewhere other than the configured remote (this run only)}
        {--branch= : Track a branch other than the configured one (this run only)}';

    protected $description = 'Update the framework: reset the system/ submodule, check out the upstream tip, and record it';

    public function handle()
    {
        // This should NEVER execute - artisan intercepts before Laravel loads
        $this->error('CRITICAL ERROR: Framework update command executed via Laravel.');
        $this->line('');
        $this->line('This indicates the artisan interception failed.');
        $this->line('The framework update script should run BEFORE Laravel loads.');
        $this->line('');
        $this->line('Please report this issue to framework developers.');

        return 1;
    }
}
