<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

/**
 * Transparent git proxy - STUB ONLY
 *
 * This command should NEVER execute. system/artisan intercepts 'rsx:git' BEFORE
 * Laravel loads and runs bin/rsx-git.sh directly: the proxy must be boot-free
 * (invoking it can never be what triggers a manifest rebuild and re-dirties
 * system/), it has to work on a tree too broken to boot, and it runs while the
 * maintenance flag is up.
 *
 * This stub exists only for command registration (so `php artisan list` shows it),
 * help text, and the edge case where the interception fails.
 *
 * There are no options of its own: every argument is forwarded to git verbatim.
 */
class Git_Command extends Command
{
    protected $signature = 'rsx:git {args?* : The git subcommand and its arguments, forwarded verbatim}';

    protected $description = 'Run git through the RSpade proxy, syncing the system/ submodule after an operation that moves it';

    public function handle()
    {
        // This should NEVER execute - artisan intercepts before Laravel loads
        $this->error('CRITICAL ERROR: rsx:git executed via Laravel.');
        $this->line('');
        $this->line('This indicates the artisan interception failed.');
        $this->line('The git proxy must run BEFORE Laravel loads - booting the framework');
        $this->line('to run a git command can rebuild the manifest and re-dirty system/.');
        $this->line('');
        $this->line('Run it directly instead:  bash system/bin/rsx-git.sh <args>');

        return 1;
    }
}
