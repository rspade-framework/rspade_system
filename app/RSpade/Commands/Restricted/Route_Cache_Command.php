<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

class Route_Cache_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'route:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] RSX has no route cache - Laravel\'s router is not in the request path';

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
        $this->info('RSX has no route cache. Every request is dispatched by the RSX front');
        $this->info('controller from the manifest\'s route table, which rsx:build builds;');
        $this->info('Laravel\'s router is never consulted and holds no routes.');
        $this->line('');
        $this->comment('See: php artisan rsx:man dispatch');

        return 1;
    }
}