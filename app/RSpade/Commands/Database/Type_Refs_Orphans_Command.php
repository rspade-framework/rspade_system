<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Database;

use Illuminate\Console\Command;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Orphan_Report;

/**
 * rsx:type_refs:orphans - which polymorphic ROWS point at a model that no longer exists.
 *
 * A REPORT, and only a report. It reads counts, prints a SELECT per offending
 * (table, column), executes nothing it prints, and always exits 0 - "there are orphaned
 * rows" is information, not a failed command.
 *
 * It deliberately does NOT report `_type_refs` rows for vanished classes. Such a row is
 * inert and permanent: it is the only thing that still gives the stored integer a name, and
 * deleting it is the one action here that can actually destroy information. There is no
 * command to delete one.
 */
class Type_Refs_Orphans_Command extends Command
{
    protected $signature = 'rsx:type_refs:orphans {--json : Emit the findings as JSON}';

    protected $description = 'Report polymorphic rows whose type reference names a model that no longer exists';

    public function handle(): int
    {
        $findings = Type_Ref_Orphan_Report::scan();

        if ($this->option('json')) {
            $payload = [];
            foreach ($findings as $finding) {
                $payload[] = [
                    'table' => $finding['table'],
                    'column' => $finding['column'],
                    'count' => $finding['count'],
                    // JSON object keys are strings; the ids are printed as such.
                    'type_ids' => (object) $finding['type_ids'],
                    'select' => $finding['select'],
                ];
            }

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (empty($findings)) {
            $this->info('[OK] No polymorphic rows point at a vanished model.');

            return 0;
        }

        $total = 0;
        foreach ($findings as $finding) {
            $total += $finding['count'];

            $this->line('');
            $this->warn(
                $finding['table'] . '.' . $finding['column'] . ' - '
                . $finding['count'] . ' row' . ($finding['count'] === 1 ? '' : 's')
            );
            $this->line('  ' . $finding['select']);
        }

        $this->line('');
        $this->line(
            $total . ' row' . ($total === 1 ? '' : 's') . ' across '
            . count($findings) . ' column' . (count($findings) === 1 ? '' : 's') . '.'
            . ' Repoint or delete them (or restore the class).'
            . ' Never delete the _type_refs row - it is what keeps the id readable.'
        );

        return 0;
    }
}
