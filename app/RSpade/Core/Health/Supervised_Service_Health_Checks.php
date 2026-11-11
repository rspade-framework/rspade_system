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
 * Supervised_Service_Health_Checks - is any service in the RSpade container failing?
 *
 * Every long-running service in the container runs under the retry runner
 * (resource/docker/retry-run.sh), which restarts it forever - so supervisor shows the program
 * RUNNING however often the service itself exits. The runner records each service's failure
 * streak in a state file, STATE_DIR/<label>.state (key=value lines; the runner's header is
 * the format), and marks it `failing` after five consecutive exits until a run lasts a
 * minute. This row reads those files - the same ones the container's health check
 * (resource/docker/healthcheck.sh) reads.
 *
 * FAIL naming each failing service: a service that cannot stay up is a broken box, and the
 * runner's retries hide it from supervisor. A state file whose runner_pid is not a live
 * runner for its label is left over from an earlier runner and is ignored.
 *
 * INFO when STATE_DIR does not exist: outside the RSpade container nothing writes it, and
 * inside one it appears when a runner first starts.
 *
 * Supervisor's own view (FATAL programs) and the lock daemon's ping are not repeated here:
 * supervisorctl is root-only, and "Lock Server" is its own row.
 */
class Supervised_Service_Health_Checks
{
    /** Where the retry runners write their state files (retry-run.sh, STATE_DIR). */
    public const STATE_DIR = '/run/rspade-services';

    /**
     * Test seam: read this directory instead of STATE_DIR. Nothing outside a test run may set
     * it, and a test that sets it must clear it.
     *
     * @var string|null
     */
    public static ?string $state_dir_for_tests = null;

    #[Health_Check('Supervised Services')]
    public static function supervised_services(): array
    {
        $dir = static::$state_dir_for_tests ?? self::STATE_DIR;

        if (!is_dir($dir)) {
            if (!Rsx::is_rspade_container()) {
                return [
                    'status' => 'INFO',
                    'detail' => 'Not in the RSpade container: this host\'s own process manager supervises its services.',
                ];
            }

            return [
                'status' => 'INFO',
                'detail' => "No service runner has recorded its state ({$dir} is absent). The runners write it"
                    . ' from their next start; restart the container to have every service report.',
            ];
        }

        $failing = [];
        $up = 0;

        foreach (glob(rtrim($dir, '/') . '/*.state') ?: [] as $file) {
            $state = static::__read_state($file);
            if ($state === null || !static::__is_live_runner((string) ($state['runner_pid'] ?? ''), (string) ($state['label'] ?? ''))) {
                continue;
            }

            if (($state['status'] ?? '') === 'failing') {
                $since = ($state['streak_since'] ?? '') !== '' ? Rsx_Time::relative($state['streak_since']) : 'an unknown time';
                $failing[] = "{$state['label']} ({$state['consecutive_failures']} consecutive exits, last status"
                    . " {$state['last_exit_status']}, since {$since})";
            } elseif (($state['status'] ?? '') !== 'stopped') {
                $up++;
            }
        }

        if ($failing !== []) {
            return [
                'status' => 'FAIL',
                'detail' => 'Failing: ' . implode('; ', $failing) . '. Its runner keeps restarting it, so supervisor'
                    . ' still shows it RUNNING.',
                'remediation' => 'In the container, `supervisorctl tail <label> stderr` (and stdout) shows why it exits.'
                    . ' The row clears once the service stays up for a minute.',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $up . ' supervised ' . ($up === 1 ? 'service' : 'services') . ' up, none failing.',
        ];
    }

    /** A state file's key=value lines, or null when it cannot be read. */
    private static function __read_state(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }

        $state = [];
        foreach (explode("\n", $raw) as $line) {
            $equals = strpos($line, '=');
            if ($equals !== false) {
                $state[substr($line, 0, $equals)] = substr($line, $equals + 1);
            }
        }

        return $state;
    }

    /** True when $pid is a running retry runner for $label. */
    private static function __is_live_runner(string $pid, string $label): bool
    {
        if ($label === '' || !ctype_digit($pid)) {
            return false;
        }

        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
        if (!is_string($cmdline) || $cmdline === '') {
            return false;
        }

        $cmdline = str_replace("\0", ' ', $cmdline);

        return str_contains($cmdline, 'retry-run') && str_contains($cmdline, " {$label} ");
    }
}
