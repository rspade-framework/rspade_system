<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Geo\Ip;

use Throwable;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;

/**
 * Geo_Ip_Health_Checks - the "IP Geolocation" row of rsx:health.
 *
 * Never a FAIL: a missing or stale database costs locations beside IP addresses and nothing
 * else (Rsx_Geo_Ip answers null), so it never gates a deploy.
 *
 *   OK    the installed release month, from the database's own build epoch
 *   INFO  not acquired yet (the update task downloads it; lookups answer null until then)
 *   WARN  the last finished update run failed; the installed file is older than STALE_DAYS;
 *         or a file is there but does not open
 */
class Geo_Ip_Health_Checks
{
    /** An installed database built longer ago than this is stale (DB-IP publishes monthly). */
    public const STALE_DAYS = 62;

    #[Health_Check('IP Geolocation')]
    public static function geo_ip(): array
    {
        $remediation = 'php artisan rsx:task:run ' . Rsx_Geo_Ip::UPDATE_SERVICE . ' ' . Rsx_Geo_Ip::UPDATE_METHOD;

        $failed = static::__last_update_failure();

        // A health check's job is to report an unreadable file, so it catches the open.
        try {
            $metadata = Rsx_Geo_Ip::installed_metadata();
        } catch (Throwable $e) {
            return [
                'status' => 'WARN',
                'detail' => 'the installed database does not open (' . $e->getMessage() . '); lookups answer no location',
                'remediation' => $remediation,
            ];
        }

        if ($metadata === null) {
            if ($failed !== null) {
                return [
                    'status' => 'WARN',
                    'detail' => "not acquired: the last update failed - {$failed}",
                    'remediation' => $remediation,
                ];
            }

            return [
                'status' => 'INFO',
                'detail' => 'not acquired yet - the update task downloads DB-IP City Lite; lookups answer no location until then',
                'remediation' => $remediation,
            ];
        }

        $release = "DB-IP City Lite, {$metadata['release']} release";

        if (time() - $metadata['build_epoch'] > self::STALE_DAYS * 86400) {
            return [
                'status' => 'WARN',
                'detail' => "{$release} - older than " . self::STALE_DAYS . ' days' . ($failed !== null ? "; the last update failed - {$failed}" : ''),
                'remediation' => $remediation,
            ];
        }

        if ($failed !== null) {
            return [
                'status' => 'WARN',
                'detail' => "{$release}; the last update failed - {$failed}",
                'remediation' => $remediation,
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $release,
        ];
    }

    /** The error of the last FINISHED update run when it failed or was killed, else null. */
    private static function __last_update_failure(): ?string
    {
        $class = Task::resolve_task_class(Rsx_Geo_Ip::UPDATE_SERVICE, Rsx_Geo_Ip::UPDATE_METHOD);

        $last = Task_Run_Model::query()
            ->where('class', $class)
            ->where('method', Rsx_Geo_Ip::UPDATE_METHOD)
            ->whereNotIn('status_id', Task_Run_Model::LIVE_STATUSES)
            ->orderByDesc('id')
            ->first();

        // Stopped and cancelled runs were asked to end; only a failure or a kill is a failure.
        if ($last === null || !in_array((int) $last->status_id, [Task_Run_Model::STATUS_FAILED, Task_Run_Model::STATUS_KILLED], true)) {
            return null;
        }

        $error = trim((string) ($last->error ?: $last->status_reason ?: ''));
        if (mb_strlen($error) > 160) {
            $error = mb_substr($error, 0, 157) . '...';
        }

        return "run #{$last->id} " . strtolower((string) $last->status_id__label) . ($error !== '' ? ": {$error}" : '');
    }
}
