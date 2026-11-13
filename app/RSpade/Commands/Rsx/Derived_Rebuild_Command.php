<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Commands\Rsx\Derived_Command_Support;
use App\RSpade\Core\Database\Rsx_Rederive;
use Illuminate\Console\Command;

/**
 * rsx:derived:rebuild <table> | --all - recompute every record's derived columns and save those
 * whose stored values differ (a derived-only save: no updated_at, no updated_by, no
 * revision; realtime frames are sent as for any save).
 *
 * The same engine, pass loop and output as the rederive step of `migrate`
 * (Rsx_Rederive::rebuild()). --all rebuilds every table whose model declares derived
 * columns. Exit status 1 when a table is still changing after the last allowed pass (the
 * [WARNING] names it) or cannot be rebuilt, else 0.
 *
 * Site-lock enforced like every command: each site's records are re-read and saved inside
 * one Site_Lock::write() section per batch on that site.
 */
class Derived_Rebuild_Command extends Command
{
    protected $signature = 'rsx:derived:rebuild
                            {table? : The table to rebuild}
                            {--all : Every table whose model declares derived columns}';

    protected $description = 'Recompute derived columns and save every record whose stored values differ';

    public function handle(): int
    {
        $tables = Derived_Command_Support::resolve_tables($this, $this->argument('table'), (bool) $this->option('all'));

        if ($tables === null) {
            return 1;
        }

        if ($tables === []) {
            $this->line('[OK] No model declares derived columns - nothing to rebuild.');

            return 0;
        }

        $unsettled = Rsx_Rederive::rebuild($tables, function (string $text): void {
            $this->output->write($text);
        });

        return $unsettled === [] ? 0 : 1;
    }
}
