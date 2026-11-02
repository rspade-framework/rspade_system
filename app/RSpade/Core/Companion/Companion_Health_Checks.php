<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use App\RSpade\Core\Companion\Companion_Daemon_Client;
use App\RSpade\Core\Companion\Companion_Flags;
use App\RSpade\Core\Companion\Rsx_Companion;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Companion_Health_Checks - rsx:health rows for companion applications: one for rsx-companiond
 * on this box, one per declared companion.
 *
 * Read from what the daemon last wrote to Redis and from this box's flag files (Rsx_Companion,
 * ungated - a health report has no viewer), plus a ping of the local control socket. Runs in
 * the CLI and inside the /_sys dashboard's web request alike, and nothing here waits on the
 * daemon: no socket is a "not running" answer.
 *
 * A companion row, against its desired state on this box:
 *   INFO  maintenance holds every companion down on purpose
 *   OK    desired matches actual (stopped and stopped; running, paused, starting or stopping)
 *   WARN  waiting with a reason, a stale heartbeat, a crash being backed off, or an operator
 *         hold (rsx:companion_apps:stop's twin in the panel) on a companion desired running
 *   FAIL  crash-looping (Rsx_Companion::CRASH_LOOP_STREAK crashes in a row), or desired
 *         running and not running - an instance ended by its restart policy, or no live
 *         daemon reporting it at all
 *
 * With no companion declared the per-companion check answers one INFO row: an empty list of
 * rows is a FAIL by the runner's contract.
 */
class Companion_Health_Checks
{
    /**
     * rsx-companiond on this box.
     *
     * @return array
     */
    #[Health_Check('Companion Daemon')]
    public static function daemon(): array
    {
        if (Framework_Maintenance::is_active_on_disk()) {
            return [
                'status' => 'INFO',
                'detail' => 'stopped by maintenance mode, on purpose; php artisan rsx:maintenance:disable starts it again',
            ];
        }

        $answer = Companion_Daemon_Client::ping();

        if ($answer !== null) {
            return [
                'status' => 'OK',
                'detail' => 'running, pid ' . ($answer['pid'] ?? '?') . ' on ' . ($answer['host'] ?? '?'),
            ];
        }

        $declared = array_keys(Manifest::get_companions());
        $desired = array_values(array_filter($declared, fn ($name) => Companion_Flags::is_desired($name)));

        if ($declared === []) {
            return [
                'status' => 'INFO',
                'detail' => 'not running; no companion application is declared, so nothing needs it',
            ];
        }

        if ($desired === []) {
            return [
                'status' => 'WARN',
                'detail' => 'not running; no companion is desired running on this box yet, so nothing is affected',
                'remediation' => 'rsx-companiond runs under the container supervisor: supervisorctl status rsx-companiond',
            ];
        }

        return [
            'status' => 'FAIL',
            'detail' => 'not running, and ' . implode(', ', $desired) . ' should be',
            'remediation' => 'rsx-companiond runs under the container supervisor: supervisorctl status rsx-companiond, '
                . 'and its log is storage/logs/rsx-companiond.log',
        ];
    }

    /**
     * One row per declared companion, labelled "Companion: <name>".
     *
     * @return array
     */
    #[Health_Check('Companion')]
    public static function companions(): array
    {
        $names = array_keys(Manifest::get_companions());

        if ($names === []) {
            return [
                'status' => 'INFO',
                'detail' => 'no companion application is declared',
            ];
        }

        return array_map(fn ($name) => ['label' => 'Companion: ' . $name] + static::row(Rsx_Companion::read_status($name)), $names);
    }

    /**
     * One companion's row from its status (Rsx_Companion's status shape). Public for its test.
     */
    public static function row(array $status): array
    {
        if ($status['maintenance'] !== null) {
            return ['status' => 'INFO', 'detail' => 'held stopped by ' . $status['maintenance']];
        }

        $actual = $status['status'];

        if ($status['desired'] === 'stopped') {
            if (in_array($actual, ['unknown', 'stopped', 'stopping', 'exited', 'killed'], true) || !$status['is_live']) {
                return ['status' => 'OK', 'detail' => 'stopped, as desired'];
            }

            return ['status' => 'WARN', 'detail' => "desired stopped, and {$actual}"];
        }

        if ($actual === 'unknown') {
            return [
                'status' => 'FAIL',
                'detail' => 'desired running, and its state is unknown: ' . $status['unknown_reason'],
                'remediation' => 'See the Companion Daemon row.',
            ];
        }

        if ($status['crash_looping']) {
            return [
                'status' => 'FAIL',
                'detail' => "crash-looping: {$status['crash_streak']} crashes in a row" . static::__exit_text($status),
                'remediation' => 'Its output is in storage/logs/companions/' . $status['name'] . '/, and in /_sys/companions.',
            ];
        }

        if ($status['held']) {
            return [
                'status' => 'WARN',
                'detail' => 'desired running, and held stopped by an operator',
                'remediation' => 'php artisan rsx:companion_apps:start ' . $status['name'] . ' releases the hold.',
            ];
        }

        if ($actual === 'waiting') {
            return ['status' => 'WARN', 'detail' => 'waiting: ' . ($status['waiting_reason'] ?? 'no reason given')];
        }

        if ($actual === 'crashed') {
            return ['status' => 'WARN', 'detail' => 'crashed, restarting' . static::__exit_text($status)];
        }

        if (in_array($actual, ['running', 'paused', 'starting', 'stopping'], true)) {
            if ($status['heartbeat_stale']) {
                return ['status' => 'WARN', 'detail' => "{$actual}, and its last heartbeat was {$status['heartbeat_age_seconds']} seconds ago"];
            }

            $detail = $actual . ($status['host'] !== null ? ' on ' . $status['host'] : '')
                . ($status['uptime_seconds'] !== null ? ' for ' . duration_to_human($status['uptime_seconds']) : '');

            return ['status' => 'OK', 'detail' => $detail];
        }

        return [
            'status' => 'FAIL',
            'detail' => "desired running, and {$actual}" . static::__exit_text($status),
            'remediation' => 'php artisan rsx:companion_apps:restart ' . $status['name'] . ' starts it again; why it ended is in its output.',
        ];
    }

    private static function __exit_text(array $status): string
    {
        $exit = $status['last_exit'];

        if (!is_array($exit)) {
            return '';
        }

        $how = $exit['how_ended'] ?? $exit['status'] ?? 'ended';
        $code = ($exit['code'] ?? null) !== null && ($exit['code'] ?? '') !== '' ? ' code ' . $exit['code'] : '';
        $signal = ($exit['signal'] ?? null) ? ' signal ' . $exit['signal'] : '';

        return " (last exit: {$how}{$code}{$signal})";
    }
}
