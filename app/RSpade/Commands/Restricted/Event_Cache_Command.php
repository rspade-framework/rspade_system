<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

class Event_Cache_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] The event cache is a build output - use rsx:build';

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
        $this->info('The event cache is a build output. Build it with:');
        $this->line('  php artisan rsx:build --force');
        $this->line('');
        $this->comment('rsx:build writes it into build/ along with the manifest, the bundles and');
        $this->comment('the other Laravel caches.');
        
        return 1;
    }
}