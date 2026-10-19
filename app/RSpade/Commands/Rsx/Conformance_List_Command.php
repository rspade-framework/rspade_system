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
 * List the conformance audits - the framework's and the application's - with the header
 * fields that say which are worth running now: severity, when, and what they cover.
 * See rsx:man conformance_audits.
 */
class Conformance_List_Command extends Command
{
    protected $signature = 'rsx:conformance:list
                            {--when= : Only audits for this occasion (launch, change, periodic)}
                            {--severity= : Only audits at this severity or above (critical, high, medium, low)}
                            {--json : Output as JSON}';

    protected $description = 'List the conformance audits: id, severity, when to run them, and the files they cover';

    public function handle(): int
    {
        $when = $this->option('when');
        $severity = $this->option('severity');

        if ($when !== null && !in_array($when, Conformance_Audits::OCCASIONS, true)) {
            $this->error('[ERROR] --when is one of: ' . implode(', ', Conformance_Audits::OCCASIONS));

            return 1;
        }

        if ($severity !== null && !in_array($severity, Conformance_Audits::SEVERITIES, true)) {
            $this->error('[ERROR] --severity is one of: ' . implode(', ', Conformance_Audits::SEVERITIES));

            return 1;
        }

        $rank = array_flip(Conformance_Audits::SEVERITIES);

        $audits = array_values(array_filter(Conformance_Audits::all(), static function (array $audit) use ($when, $severity, $rank): bool {
            if ($when !== null && !in_array($when, $audit['when'], true)) {
                return false;
            }

            return $severity === null || $rank[$audit['severity']] <= $rank[$severity];
        }));

        if ($this->option('json')) {
            $this->line(json_encode(['audits' => array_map(static function (array $audit): array {
                unset($audit['body']);

                return $audit;
            }, $audits)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($audits === []) {
            $this->line('No conformance audits match.');

            return 0;
        }

        $this->newLine();
        $this->table(
            ['Id', 'Severity', 'When', 'Origin', 'Title'],
            array_map(static fn (array $audit) => [
                $audit['id'],
                $audit['severity'],
                implode(', ', $audit['when']),
                $audit['origin'],
                $audit['title'],
            ], $audits)
        );
        $this->line('  php artisan rsx:conformance:show <id> prints one; rsx:conformance:for <path> lists those covering a file.');
        $this->newLine();

        return 0;
    }
}
