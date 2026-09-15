<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Prod\Rsx_Prod_Env;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;

/**
 * Leave a production-like mode and return to development.
 *
 * Removes the seal, writes RSX_MODE=development, discards the production build tree, and
 * builds the development one in its place. Idempotent: safe to run on a box that is
 * already in development.
 */
class Prod_Disable_Command extends Command
{
    protected $signature = 'rsx:prod:disable';

    protected $description = 'Return to development mode and build the development assets';

    public function handle(): int
    {
        // Heal FIRST: ensure system/.env is a symlink to the authoritative root
        // .env before the RSX_MODE write below, so the mode lands in one file.
        $env_report = \App\RSpade\Core\Prod\Rsx_Env_Symlink::heal();
        if ($env_report['status'] !== 'already_healthy') {
            $this->line('.env symlink invariant restored (status: ' . $env_report['status'] . ').');
        }

        $this->info('Returning to development mode...');
        $this->newLine();

        // The seal goes first: while it is on disk this box claims to have a production
        // build to serve, and every step below is dismantling exactly that.
        $this->line('  [1/4] Removing the seal...');
        Rsx_Prod_Seal::clear();

        $this->line('  [2/4] Setting RSX_MODE=development...');
        Rsx_Prod_Env::set_mode(Rsx::MODE_DEVELOPMENT);

        // A fresh subprocess, which boots in development and therefore needs no --force:
        // the whole build tree is about to be rebuilt anyway.
        $this->line('  [3/4] Discarding the production build...');
        $clean_exit = Rsx_Artisan::passthru('rsx:clean', ['--silent', Clean_Command::FLAG_NO_SYSTEM_RESET]);
        if ($clean_exit !== 0) {
            $this->error('rsx:clean failed (exit ' . $clean_exit . ').');

            return 1;
        }

        // Restore the development composer autoloader. A strict-production build ran
        // `composer dump-autoload --optimize --classmap-authoritative`, which pins the
        // system/ PSR-4 tree to a frozen classmap and FORBIDS the filesystem fallback - so
        // a class added or renamed after that dump would not be found until the next dump.
        // A plain --optimize dump rebuilds the classmap while restoring the fallback
        // development relies on; --classmap-authoritative is deliberately omitted.
        if ($this->_composer_available()) {
            passthru(
                'bash -c ' . escapeshellarg('cd ' . escapeshellarg(base_path()) . ' && composer dump-autoload --optimize --no-interaction'),
                $composer_exit
            );
            if ($composer_exit !== 0) {
                $this->warn('      composer dump-autoload reported an error - run it manually if class loading misbehaves.');
            }
        } else {
            $this->warn('      composer not found on PATH - skipping autoloader restore.');
        }

        $this->line('  [4/4] Building the development assets...');
        $this->newLine();
        $exit_code = Rsx_Artisan::passthru('rsx:build');
        if ($exit_code !== 0) {
            $this->error('The development build failed (exit ' . $exit_code . ').');

            return 1;
        }

        $this->newLine();
        $this->info('[OK] Development mode active - files auto-compile on change.');

        return 0;
    }

    /**
     * Is the composer binary available on PATH?
     */
    private function _composer_available(): bool
    {
        $path = trim((string) @shell_exec('bash -c ' . escapeshellarg('command -v composer 2>/dev/null')));

        return $path !== '';
    }
}
