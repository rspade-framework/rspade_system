<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

class Config_Cache_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] RSX never caches config - it reads env() at runtime';

    /**
     * Hide this command from artisan list
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->error('This command has been restricted in RSX.');
        $this->line('');
        $this->info('RSX is env-at-runtime by design, so the config cache is never built:');
        $this->comment('Laravel skips .env entirely when one exists, and every runtime env() call');
        $this->comment('would fall back to its default - RSX_MODE included.');
        $this->line('');
        $this->info('Everything the site serves is built by:');
        $this->line('  php artisan rsx:build --force');
        
        return 1;
    }
}