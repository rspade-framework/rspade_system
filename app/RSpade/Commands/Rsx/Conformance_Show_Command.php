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
 * Print one conformance audit: its header, then its prompt. See rsx:man conformance_audits.
 */
class Conformance_Show_Command extends Command
{
    protected $signature = 'rsx:conformance:show
                            {id : The audit id (rsx:conformance:list)}';

    protected $description = 'Print one conformance audit - the prompt an agent follows to review the application against it';

    public function handle(): int
    {
        $audit = Conformance_Audits::find((string) $this->argument('id'));

        $this->line('id:       ' . $audit['id']);
        $this->line('title:    ' . $audit['title']);
        $this->line('severity: ' . $audit['severity']);
        $this->line('when:     ' . implode(', ', $audit['when']));
        $this->line('scope:    ' . implode(', ', $audit['scope']));
        if ($audit['related'] !== []) {
            $this->line('related:  ' . implode(', ', $audit['related']));
        }
        $this->line('file:     ' . $audit['file']);
        $this->newLine();
        $this->line(rtrim($audit['body']));

        return 0;
    }
}
