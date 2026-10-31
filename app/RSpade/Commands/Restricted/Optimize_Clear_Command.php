<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

class Optimize_Clear_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] RSX discards build state with rsx:clean';

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
        $this->info('The caches it clears are build outputs, and a sealed production build depends on them.');
        $this->line('');
        $this->info('To discard build state and derived caches:');
        $this->line('  php artisan rsx:clean            (add --force in a production mode)');
        $this->info('To rebuild what the site serves:');
        $this->line('  php artisan rsx:build --force');

        return 1;
    }
}
