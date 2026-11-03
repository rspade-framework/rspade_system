<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Proxority\Proxority_Routes;
use Illuminate\Console\Command;

/**
 * rsx:proxority:routes - the application's scheduling whitelist for the Proxority proxy.
 *
 * Hidden: its caller is Proxority (system/bin/proxority/lib/whitelist.js), which runs
 * `php artisan rsx:proxority:routes --json` on an interval, spawned without a shell, and
 * accepts an answer only from a run that exits 0 with well-formed JSON.
 *
 *   --json       exactly {"unthrottled":[...],"priority":[...],"media":[...]} on stdout,
 *                nothing else (Proxority_Routes documents what is listed and the pattern
 *                syntax)
 *   (no flag)    the same lists, human-readable
 *
 * EXIT CODES
 *   0                    the whitelist was printed
 *   3 (EXIT_MAINTENANCE) maintenance mode is up on disk: prints {"maintenance":true} (with
 *                        --json) and nothing else - the proxy keeps its previous list, since
 *                        mid-maintenance the tree may be half-updated
 *   other non-zero       a failure, reported on stderr by the console as usual
 */
class Proxority_Routes_Command extends Command
{
    /** Maintenance mode is up: the proxy keeps the list it has. */
    public const EXIT_MAINTENANCE = 3;

    protected $signature = 'rsx:proxority:routes
                            {--json : Print the whitelist as the JSON Proxority reads}';

    protected $description = 'Print the routes Proxority treats as unthrottled, priority or media';

    protected $hidden = true;

    public function handle()
    {
        if (Framework_Maintenance::is_active_on_disk()) {
            if ($this->option('json')) {
                $this->line(json_encode(['maintenance' => true]));
            } else {
                $this->line('Maintenance mode is up; no whitelist is given while it is.');
            }

            return self::EXIT_MAINTENANCE;
        }

        $answer = Proxority_Routes::answer();

        if ($this->option('json')) {
            $this->line(json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return 0;
        }

        foreach ($answer as $list => $patterns) {
            $this->line(strtoupper($list) . ' (' . count($patterns) . ')');

            foreach ($patterns as $pattern) {
                $this->line('  ' . $pattern);
            }

            $this->line('');
        }

        return 0;
    }
}
