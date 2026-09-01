<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Logging\Rsx_Logrotate;
use Illuminate\Console\Command;

/**
 * rsx:logrotate - rotate, compress and prune storage/logs on demand.
 *
 * The same rotation Log_Maintenance_Service::rotate() runs nightly, reachable by hand.
 * Nothing here assumes an OS logrotate exists: the framework does its own.
 *
 * IT RUNS EVEN WHEN rsx.logging.rotation.enabled IS FALSE, and says so in one line.
 * The config flag governs the unattended sweep; typing the command IS the consent.
 *
 * --directory exists so the rotation can be exercised against a fixture tree instead
 * of the live logs (the cli test does exactly that). It is an ordinary documented
 * option, not a framework-internal --_ flag, because an operator rotating some other
 * log directory is a legitimate thing to want.
 *
 * Silent-success house style: one summary line, or --json for a machine reader.
 *
 * See: php artisan rsx:man logrotate
 */
class Log_Rotate_Command extends Command
{
    protected $signature = 'rsx:logrotate
                            {--directory= : Directory to rotate (default: storage/logs)}
                            {--days-uncompressed= : Generations kept plain (default: rsx.logging.rotation.days_uncompressed)}
                            {--days-retention= : Oldest generation kept (default: rsx.logging.rotation.days_retention)}
                            {--json : Machine-readable JSON output}';

    protected $description = 'Rotate, compress and prune the log directory';

    public function handle(): int
    {
        $directory = $this->option('directory') ?: storage_path('logs');

        $days_uncompressed = $this->option('days-uncompressed') !== null
            ? (int) $this->option('days-uncompressed')
            : (int) config('rsx.logging.rotation.days_uncompressed');

        $days_retention = $this->option('days-retention') !== null
            ? (int) $this->option('days-retention')
            : (int) config('rsx.logging.rotation.days_retention');

        $report = Rsx_Logrotate::rotate($directory, $days_uncompressed, $days_retention);

        $rotated = 0;
        $compressed = 0;
        $deleted = 0;
        $renumbered = 0;

        foreach ($report as $entry) {
            if ($entry['rotated']) {
                $rotated++;
            }

            $compressed += count($entry['compressed']);
            $deleted += count($entry['deleted']);
            $renumbered += count($entry['renumbered']);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'directory' => $directory,
                'days_uncompressed' => $days_uncompressed,
                'days_retention' => $days_retention,
                'scheduled_rotation_enabled' => (bool) config('rsx.logging.rotation.enabled'),
                'rotated' => $rotated,
                'compressed' => $compressed,
                'deleted' => $deleted,
                'renumbered' => $renumbered,
                'files' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (!config('rsx.logging.rotation.enabled')) {
            $this->line('[INFO] Scheduled rotation is disabled (rsx.logging.rotation.enabled); rotating anyway because you asked.');
        }

        if ($renumbered > 0) {
            $this->line("[INFO] Renumbered {$renumbered} generation(s) into contiguous order before rotating.");
        }

        $this->line("[OK] Rotated {$rotated} files ({$compressed} compressed, {$deleted} deleted)");

        return 0;
    }
}
