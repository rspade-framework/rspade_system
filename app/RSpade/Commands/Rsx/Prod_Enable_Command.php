<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Prod\Rsx_Prod_Env;
use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;

/**
 * Enter a production-like mode.
 *
 * Three things, in this order: heal the .env invariant, write the new RSX_MODE, and run
 * the build. The build is what produces and seals the assets - this command adds nothing
 * to it, so `rsx:build --force` on a box that is already in prod mode is the same
 * operation without the mode write.
 */
class Prod_Enable_Command extends Command
{
    protected $signature = 'rsx:prod:enable
                            {--debug : Build the debug variant (unminified, sourcemaps, working console_debug)}';

    protected $description = 'Switch to a production-like mode and build the sealed assets';

    public function handle(): int
    {
        $debug = (bool) $this->option('debug');
        $mode = $debug ? Rsx::MODE_DEBUG : Rsx::MODE_PRODUCTION;

        // Heal FIRST: guarantee system/.env is a symlink to the authoritative root
        // .env before we read config or write RSX_MODE, so the mode write lands in
        // the single healed file (a drifted real system/.env would swallow it).
        $this->_heal_env();

        $this->info('Entering ' . $mode . ' mode...');
        $this->newLine();

        $this->line('  [1/2] Setting RSX_MODE=' . $mode . '...');
        Rsx_Prod_Env::set_mode($mode);

        // The build runs in a fresh subprocess, which boots reading the RSX_MODE just
        // written. --force because a previous seal is exactly what this transition
        // replaces; the operator already said so by running this command.
        $this->line('  [2/2] Running the build...');
        $this->newLine();

        $exit_code = Rsx_Artisan::passthru('rsx:build', ['--force']);

        if ($exit_code !== 0) {
            $this->newLine();
            $this->error('The build failed (exit ' . $exit_code . '). No seal was written.');
            $this->line('RSX_MODE is ' . $mode . ' and this box has nothing to serve until the build succeeds.');
            $this->line('  Fix the error and run:  php artisan rsx:build --force');
            $this->line('  Or return to dev:       php artisan rsx:prod:disable');

            return 1;
        }

        $this->newLine();
        $this->line('Before deploying the live application, read and verify:');
        $this->line('  php artisan rsx:man prelaunch_checklist');
        $this->line('  php artisan rsx:man prod');

        return 0;
    }

    /**
     * Restore the .env symlink invariant and print a one-line summary if it acted.
     */
    private function _heal_env(): void
    {
        $report = \App\RSpade\Core\Prod\Rsx_Env_Symlink::heal();
        if ($report['status'] !== 'already_healthy') {
            $this->line('.env symlink invariant restored (status: ' . $report['status'] . ').');
        }
    }
}
