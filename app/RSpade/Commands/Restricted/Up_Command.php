<?php

namespace App\RSpade\Commands\Restricted;

use App\RSpade\Core\Paths\Rsx_Project_Paths;

use Illuminate\Console\Command;

/**
 * Laravel's `up`, refused - the other half of Down_Command. There is nothing for it
 * to lift: RSpade's window is the storage/state flag file, raised and cleared
 * by rsx:maintenance:enable / :disable.
 */
class Up_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'up';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] Use rsx:maintenance:disable instead';

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
        $this->info('Please use the following command instead:');
        $this->line('  php artisan rsx:maintenance:disable');
        $this->line('');
        $this->comment('It is immune to its own gate and idempotent, so it also clears a flag left');
        $this->comment('behind by an interrupted update.');

        return 1;
    }
}
