<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

class Config_Clear_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] RSX never caches config, so there is nothing to clear';

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
        $this->info('RSX never caches config: env() is read at runtime, and config:cache is restricted.');
        $this->comment('Caching is decided by the run mode alone - a production-like mode caches the');
        $this->comment('parsed .env in tmp/ (refreshed whenever .env is newer); development caches nothing.');
        $this->line('');
        $this->info('To discard derived caches:');
        $this->line('  php artisan rsx:clean');

        return 1;
    }
}
