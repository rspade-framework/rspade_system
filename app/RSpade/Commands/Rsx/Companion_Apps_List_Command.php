<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Companion\Companion_Flags;
use Illuminate\Console\Command;

/**
 * rsx:companion_apps:list [--json] - every declared companion: desired state, what it is doing,
 * why it waits, its instance and uptime. Read from the flags and the daemon's socket, so it
 * answers with the database down; with no daemon it shows desired state only, and says why.
 */
class Companion_Apps_List_Command extends Command
{
    use Companion_Apps_Cli_Support;

    protected $signature = 'rsx:companion_apps:list
        {--json : Machine-readable JSON output}';

    protected $description = 'List companion applications: desired, actual, status, waiting reason, instance, uptime';

    public function handle(): int
    {
        $declared = $this->declared_companions();
        $status = \App\RSpade\Core\Companion\Companion_Daemon_Client::send('status');

        $live = [];
        foreach ($status['companions'] ?? [] as $summary) {
            $live[$summary['name']] = $summary;
        }

        $rows = [];
        foreach ($declared as $name => $descriptor) {
            $summary = $live[$name] ?? null;
            $started_at = $summary['started_at'] ?? null;

            $rows[] = [
                'name' => $name,
                'desired' => Companion_Flags::is_desired($name) ? 'running' : 'stopped',
                'status' => $summary === null ? 'unknown' : ($summary['held'] ? 'stopped (held)' : $summary['status']),
                'waiting_reason' => $summary['waiting_reason'] ?? null,
                'instance_id' => $summary['instance_id'] ?? null,
                'pid' => $summary['pid'] ?? null,
                'uptime_seconds' => $started_at ? intdiv((int) (microtime(true) * 1000) - (int) $started_at, 1000) : null,
                'restart_count' => $summary['restart_count'] ?? null,
                'status_line' => $summary['status_line'] ?? null,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'daemon' => $status['daemon'] ?? null,
                'maintenance' => Companion_Flags::is_maintenance_on(),
                'companions' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($rows === []) {
            $this->line('No companion applications are declared.');
        } else {
            $this->table(
                ['Name', 'Desired', 'Status', 'Waiting for', 'Instance', 'Uptime', 'Restarts', 'Status line'],
                array_map(fn ($row) => [
                    $row['name'],
                    $row['desired'],
                    $row['status'],
                    $row['waiting_reason'] ?? '',
                    $row['instance_id'] ? $row['instance_id'] . ' (pid ' . $row['pid'] . ')' : '',
                    $row['uptime_seconds'] === null ? '' : duration_to_human($row['uptime_seconds']),
                    $row['restart_count'] ?? '',
                    $row['status_line'] ?? '',
                ], $rows)
            );
        }

        if (Companion_Flags::is_maintenance_on()) {
            $this->line('Companion maintenance is on: every companion is held stopped.');
        }

        if ($status === null) {
            $this->explain_daemon_absent();
        }

        return 0;
    }
}
