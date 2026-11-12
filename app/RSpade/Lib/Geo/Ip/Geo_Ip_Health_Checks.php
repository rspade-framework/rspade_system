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
use App\RSpade\Lib\Geo\Ip\Geo_Ip_Service;
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;

/**
 * Geo_Ip_Health_Checks - the "IP Geolocation" row of rsx:health, reporting both databases
 * (DB-IP City Lite and DB-IP ASN Lite) and the notable-network list.
 *
 * Never a FAIL: a missing or stale database costs locations beside IP addresses and nothing
 * else (Rsx_Geo_Ip answers null), so it never gates a deploy. The row takes the worst state
 * of its parts:
 *
 *   OK    each database's installed release month, from its own build epoch
 *   INFO  a database not acquired yet (the update task downloads it; its part of a lookup
 *         answers null until then)
 *   WARN  the last finished update run failed; an installed file EXPIRED (past
 *         Rsx_Geo_Ip::DATA_VALIDITY_DAYS after its release - lookups treat it as absent, so
 *         its part answers null); an installed file older than STALE_DAYS; a file that is
 *         there but does not open; or config('rsx.geo_ip.networks') invalid
 */
class Geo_Ip_Health_Checks
{
    /** An installed database built longer ago than this is stale (DB-IP publishes monthly). */
    public const STALE_DAYS = 62;

    private const RANK = ['OK' => 0, 'INFO' => 1, 'WARN' => 2];

    #[Health_Check('IP Geolocation')]
    public static function geo_ip(): array
    {
        $remediation = 'php artisan rsx:task:run ' . Rsx_Geo_Ip::UPDATE_SERVICE . ' ' . Rsx_Geo_Ip::UPDATE_METHOD
            . ' - if it keeps failing, update the framework (the download source may have changed)';

        $failed = static::__last_update_failure();

        $status = 'OK';
        $details = [];
        foreach (array_keys(Geo_Ip_Service::DATABASES) as $database) {
            [$part_status, $detail] = static::__database_state($database);
            $details[] = $detail;
            if (self::RANK[$part_status] > self::RANK[$status]) {
                $status = $part_status;
            }
        }

        // Reporting a broken configuration is this check's job, so it catches the validation.
        try {
            $details[] = count(Rsx_Geo_Ip::networks()) . ' notable networks listed';
        } catch (Throwable $e) {
            $details[] = 'the notable-network list is invalid - ' . $e->getMessage() . ' (lookups answer no location until it is fixed)';
            $status = 'WARN';
        }

        if ($failed !== null) {
            $details[] = "the last update failed - {$failed}";
            $status = 'WARN';
        }

        $row = ['status' => $status, 'detail' => implode('; ', $details)];
        if ($status !== 'OK') {
            $row['remediation'] = $remediation;
        }

        return $row;
    }

    /** One database's [status, detail]. */
    private static function __database_state(string $database): array
    {
        $name = Geo_Ip_Service::DATABASES[$database]['name'];

        // A health check's job is to report an unreadable file, so it catches the open.
        try {
            $metadata = Rsx_Geo_Ip::installed_metadata($database);
        } catch (Throwable $e) {
            return ['WARN', "{$name}: the installed file does not open (" . $e->getMessage() . ')'];
        }

        if ($metadata === null) {
            return ['INFO', "{$name}: not acquired yet - the update task downloads it"];
        }

        if ($metadata['expired']) {
            return ['WARN', "{$name}, {$metadata['release']} release - EXPIRED " . gmdate('Y-m-d', $metadata['expires_at'])
                . ' (' . Rsx_Geo_Ip::DATA_VALIDITY_DAYS . ' days after its release); lookups answer without it until an update succeeds'];
        }

        if (Rsx_Geo_Ip::now() - $metadata['build_epoch'] > self::STALE_DAYS * 86400) {
            return ['WARN', "{$name}, {$metadata['release']} release - older than " . self::STALE_DAYS . ' days'];
        }

        return ['OK', "{$name}, {$metadata['release']} release"];
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
