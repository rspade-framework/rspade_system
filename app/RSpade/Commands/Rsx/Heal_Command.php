<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Health\Heal_Runner;
use Illuminate\Console\Command;

/**
 * rsx:heal - repair one named, definitionally-absent piece of an installation.
 *
 * The executable half of rsx:health: a WARN/FAIL row names its remedy, and this runs it.
 * Targets are declared next to the feature they repair via `#[Health_Heal('target')]`,
 * discovered through the manifest by Heal_Runner - so the roster can never drift from
 * the features it covers.
 *
 * ONE TARGET AT A TIME, ALWAYS NAMED. There is deliberately no --all: healing is not
 * housekeeping, and a command that repairs several things at once encourages running it
 * blind after any failure. `rsx:heal --list` shows what exists; you then choose.
 *
 * A healer only ever CREATES what is absent. Anything present-but-unexpected is REFUSED
 * with what was found - see Heal_Runner's boundary note for why that line is drawn hard.
 */
class Heal_Command extends Command
{
    protected $signature = 'rsx:heal
        {target? : The heal target to run (omit with --list to see them all)}
        {--list : List every declared heal target and exit}';

    protected $description = 'Repair a named, definitionally-absent piece of this installation';

    public function handle(): int
    {
        $targets = Heal_Runner::discover();

        if ($this->option('list') || $this->argument('target') === null) {
            if (empty($targets)) {
                $this->line('No heal targets are declared.');

                return 0;
            }

            $this->line('Declared heal targets:');
            $this->newLine();
            foreach ($targets as $target => $meta) {
                $this->line('  ' . $target . '   (' . $meta['fqcn'] . '::' . $meta['method'] . ')');
            }
            $this->newLine();
            $this->line('Run one with: php artisan rsx:heal <target>');

            return 0;
        }

        $result = Heal_Runner::run((string) $this->argument('target'));

        if ($result['status'] === 'REFUSED') {
            $this->error('[REFUSED] ' . $result['detail']);

            return 1;
        }

        if ($result['status'] === 'ALREADY_OK') {
            // Nothing to do is not an event. Say so once and exit clean.
            $this->line('[OK] ' . $result['detail']);

            return 0;
        }

        $this->info('[HEALED] ' . $result['detail']);

        return 0;
    }
}
