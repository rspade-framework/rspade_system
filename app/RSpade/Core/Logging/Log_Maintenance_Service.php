<?php

namespace App\RSpade\Core\Logging;

use App\RSpade\Core\Logging\Rsx_Logrotate;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Log_Maintenance_Service
 *
 * The scheduled half of log rotation. It lives in framework core rather than in an
 * application's /rsx/services/ so that EVERY app inherits the sweep without wiring
 * anything: an unrotated storage/logs grows until the disk says stop, and that is
 * not a per-application decision.
 *
 * Nothing here assumes an OS logrotate exists. See rsx:man logrotate.
 */
class Log_Maintenance_Service extends Rsx_Service_Abstract
{
    /**
     * Rotate storage/logs once a day.
     *
     * Midnight because a generation number is meant to read as a day count: a
     * rotation at a fixed daily boundary makes `laravel.log.3` mean "three days
     * ago" rather than "three runs ago".
     *
     * #[Exclusive] because a slow run (a very large log being gzipped) overlapping
     * its own next tick would shift generations twice for one day.
     *
     * @param Task_Instance $task Task instance for logging
     * @param array $params Task parameters (unused)
     * @return array The Rsx_Logrotate report, plus a 'skipped' key when disabled
     */
    #[Task('Rotate, compress and prune storage/logs')]
    #[Exclusive]
    #[Schedule('daily at 12:00am')]
    public static function rotate(Task_Instance $task, array $params = []): array
    {
        if (!config('rsx.logging.rotation.enabled')) {
            // Unix silent success: the operator turned it off and does not need
            // to be told again every night.
            return ['skipped' => 'disabled'];
        }

        $days_uncompressed = (int) config('rsx.logging.rotation.days_uncompressed');
        $days_retention = (int) config('rsx.logging.rotation.days_retention');

        $report = Rsx_Logrotate::rotate(storage_path('logs'), $days_uncompressed, $days_retention);

        $rotated = 0;
        $compressed = 0;
        $deleted = 0;

        foreach ($report as $entry) {
            if ($entry['rotated']) {
                $rotated++;
            }

            $compressed += count($entry['compressed']);
            $deleted += count($entry['deleted']);
        }

        if ($rotated > 0 || $compressed > 0 || $deleted > 0) {
            $task->info("Rotated {$rotated} log(s); compressed {$compressed}, deleted {$deleted}");
        }

        return $report;
    }
}
