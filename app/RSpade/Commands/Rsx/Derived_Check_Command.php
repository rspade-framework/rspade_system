<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Commands\Rsx\Derived_Command_Support;
use App\RSpade\Core\Database\Rsx_Rederive;
use Illuminate\Console\Command;

/**
 * rsx:derived:check <table> | --all - report records whose stored derived columns differ from what
 * their model computes now, changing nothing.
 *
 * Per table: the records examined, how many drift, and a few of them as
 * `#id column: stored -> computed`. --all checks every table whose model declares
 * derived columns. Exit status 1 when any record drifts (and for a table that cannot be
 * checked), 0 when every value is current. `rsx:derived:rebuild` repairs what this reports.
 *
 * Reads only - no site lock is taken; each site's records are read with that site declared
 * (Rsx_Rederive::check()).
 */
class Derived_Check_Command extends Command
{
    protected $signature = 'rsx:derived:check
                            {table? : The table to check}
                            {--all : Every table whose model declares derived columns}';

    protected $description = 'Report records whose stored derived columns differ from what their model computes now';

    public function handle(): int
    {
        $tables = Derived_Command_Support::resolve_tables($this, $this->argument('table'), (bool) $this->option('all'));

        if ($tables === null) {
            return 1;
        }

        if ($tables === []) {
            $this->line('[OK] No model declares derived columns - nothing to check.');

            return 0;
        }

        $drifting = false;

        foreach ($tables as $table => $fqcn) {
            $report = Rsx_Rederive::check($fqcn);

            if ($report['drifting'] === 0) {
                $this->line("[OK] {$table}: {$report['examined']} record(s), none drifting.");

                continue;
            }

            $drifting = true;

            $this->line("[WARNING] {$table}: {$report['drifting']} of {$report['examined']} record(s) drifting.");

            foreach ($report['examples'] as [$id, $drift]) {
                foreach ($drift as $column => [$stored, $computed]) {
                    $this->line("  #{$id} {$column}: " . self::__show($stored) . ' -> ' . self::__show($computed));
                }
            }

            if ($report['drifting'] > count($report['examples'])) {
                $this->line('  ... and ' . ($report['drifting'] - count($report['examples'])) . ' more');
            }
        }

        if ($drifting) {
            $this->line('Repair with: php artisan rsx:derived:rebuild ' . ($this->argument('table') ?? '--all'));

            return 1;
        }

        return 0;
    }

    private static function __show(mixed $value): string
    {
        return $value === null ? 'NULL' : var_export((string) $value, true);
    }
}
