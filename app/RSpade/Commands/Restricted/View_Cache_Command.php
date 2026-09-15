<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

class View_Cache_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'view:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] Compiled views are a build output - use rsx:build';

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
        $this->info('Compiled views are a build output. Build them with:');
        $this->line('  php artisan rsx:build --force');
        $this->line('');
        $this->comment('rsx:build precompiles every Blade template into build/views, along with the');
        $this->comment('manifest, the bundles and the route/event caches.');

        return 1;
    }
}