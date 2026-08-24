<?php

namespace App\RSpade\Commands\Restricted;

use Illuminate\Console\Command;

/**
 * Laravel's `down`, refused.
 *
 * RSpade has ONE maintenance mode (rsx:maintenance:enable - flag + operator reason,
 * task kill, service quiesce, allow-most CLI gate). Laravel's down wrote
 * storage/framework/maintenance.php and gated the WEB only; that file is no longer
 * read anywhere, so running it would have produced a convincing no-op.
 */
class Down_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'down';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[RESTRICTED] Use rsx:maintenance:enable instead';

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
        $this->line('  php artisan rsx:maintenance:enable --reason="database surgery"');
        $this->line('');
        $this->comment('RSpade has one maintenance mode: it gates the CLI as well as the web, kills');
        $this->comment('running background tasks, quiesces the runtime services, and carries the');
        $this->comment('operator reason through to every message.');

        return 1;
    }
}
