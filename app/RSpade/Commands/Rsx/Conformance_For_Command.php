<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Conformance\Conformance_Audits;

/**
 * The conformance audits whose scope covers a file - the ones to read before writing or
 * changing it. See rsx:man conformance_audits.
 */
class Conformance_For_Command extends Command
{
    protected $signature = 'rsx:conformance:for
                            {path : A file, relative to the project root (rsx/app/...) or absolute}';

    protected $description = 'List the conformance audits that govern a file';

    public function handle(): int
    {
        $audits = Conformance_Audits::for_path((string) $this->argument('path'));

        if ($audits === []) {
            $this->line('No conformance audit covers this file.');

            return 0;
        }

        foreach ($audits as $audit) {
            $this->line("{$audit['id']}  [{$audit['severity']}]  {$audit['title']}");
        }

        return 0;
    }
}
