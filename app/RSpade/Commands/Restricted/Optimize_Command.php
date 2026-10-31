<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

class Optimize_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] RSX builds what production serves with rsx:build';

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
        $this->info("Laravel's optimize would build the config cache, which RSX never uses (env() is read");
        $this->info('at runtime), and would write build outputs outside the build.');
        $this->line('');
        $this->info('Everything the site serves is built by:');
        $this->line('  php artisan rsx:build --force');

        return 1;
    }
}
