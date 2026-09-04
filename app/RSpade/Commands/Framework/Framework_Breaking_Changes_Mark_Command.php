<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Framework\Breaking_Changes;
use Illuminate\Console\Command;

class Framework_Breaking_Changes_Mark_Command extends Command
{
    protected $signature = 'rsx:framework:breaking_changes:mark {name} {--fulfilled} {--unfulfilled}';

    protected $description = 'Mark a breaking change as fulfilled or unfulfilled';

    public function handle()
    {
        $fulfilled = $this->option('fulfilled');
        $unfulfilled = $this->option('unfulfilled');

        if ($fulfilled === $unfulfilled) {
            // Both set or neither set.
            $this->error('Specify exactly one of --fulfilled or --unfulfilled.');
            $this->line('');
            $this->line('Usage:');
            $this->line('  php artisan rsx:framework:breaking_changes:mark <name> --fulfilled');
            $this->line('  php artisan rsx:framework:breaking_changes:mark <name> --unfulfilled');

            return 1;
        }

        try {
            $filename = Breaking_Changes::resolve_filename($this->argument('name'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        Breaking_Changes::mark($filename, (bool) $fulfilled);

        $state = $fulfilled ? 'fulfilled' : 'unfulfilled';
        $this->line("<fg=green>Marked '{$filename}' as {$state}.</>");

        return 0;
    }
}
