<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use App\RSpade\Core\Rsx;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Security_Update_Health_Checks - is this container taking its security updates?
 *
 * The RSpade container installs the Ubuntu security updates and PHP patch releases it may
 * take before PHP serves anything at every start, and every 4 hours after
 * (resource/docker/security-update.sh). Each check writes STATUS_FILE; this row reads it.
 *
 * WARN, NEVER FAIL. A failed check, a switched-off updater or an update waiting for a
 * restart is something to act on, not a broken install - rsx:health exits non-zero only on
 * FAIL, so this can never break a deploy gate or a container healthcheck.
 *
 * Outside the RSpade container the host's own package manager owns PHP, and the row says so.
 */
class Security_Update_Health_Checks
{
    /** Written by rspade-security-update after every check. */
    public const STATUS_FILE = '/var/lib/rspade/security-updates.json';

    /**
     * Test seam: read this file instead of STATUS_FILE. Nothing outside a test run may set it,
     * and a test that sets it must clear it.
     *
     * @var string|null
     */
    public static ?string $status_file_for_tests = null;

    #[Health_Check('Security Updates')]
    public static function security_updates(): array
    {
        if (!Rsx::is_rspade_container()) {
            return [
                'status' => 'INFO',
                'detail' => 'Not an RSpade container: PHP and system security updates are this host\'s'
                    . ' package manager\'s to install (unattended-upgrades on Ubuntu). PHP ' . PHP_VERSION . '.',
            ];
        }

        $file = static::$status_file_for_tests ?? self::STATUS_FILE;
        $status = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($status)) {
            return [
                'status' => 'WARN',
                'detail' => 'No security update check has run in this container (' . $file . ' is absent).'
                    . ' The image predates automatic security updates: pull or rebuild it. PHP ' . PHP_VERSION . '.',
            ];
        }

        $when = isset($status['checked_at']) ? Rsx_Time::relative((string) $status['checked_at']) : 'at an unknown time';
        $php = 'PHP ' . PHP_VERSION;

        switch ($status['result'] ?? '') {
            case 'disabled':
                return [
                    'status' => 'WARN',
                    'detail' => 'Automatic security updates are off (RSX_AUTO_SECURITY_UPDATES). ' . $php
                        . ' - patch it yourself, or pull a newer image when a PHP security release ships.',
                ];

            case 'failed':
                return [
                    'status' => 'WARN',
                    'detail' => "The last security update check failed ({$when}); the next scheduled one retries. "
                        . $php . '. See the container log.',
                ];
        }

        if (!empty($status['restart_recommended'])) {
            return [
                'status' => 'WARN',
                'detail' => "Security updates were installed {$when}; PHP is reloaded, but other updated packages"
                    . ' load only when the container restarts. ' . $php . '.',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => "Last checked {$when}: "
                . (($status['result'] ?? '') === 'current' ? 'up to date' : 'updates installed') . '. ' . $php . '.',
        ];
    }
}
