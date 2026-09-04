<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Breaking_Changes;
use Illuminate\Console\Command;

class Framework_Breaking_Changes_Command extends Command
{
    protected $signature = 'rsx:framework:breaking_changes';

    protected $description = 'List pending (unfulfilled) breaking change mandates';

    public function handle()
    {
        $pending = Breaking_Changes::get_pending();
        $total = count(Breaking_Changes::list_all_files());

        $this->line('');

        if (empty($pending)) {
            $this->line('<fg=green>No pending breaking changes.</>');
            $this->line("All {$total} breaking changes reviewed.");
            $this->line('');

            return 0;
        }

        $pending_count = count($pending);
        $fulfilled_count = $total - $pending_count;

        $this->line('<fg=yellow>Pending breaking changes:</>');
        $this->line('');

        foreach ($pending as $filename) {
            $this->line('  - ' . $filename);
        }

        $this->line('');
        $this->line("{$pending_count} pending, {$fulfilled_count} fulfilled");
        $this->line('Run `php artisan rsx:framework:breaking_changes:show <name>` to review one.');
        $this->line('');

        return 0;
    }
}
