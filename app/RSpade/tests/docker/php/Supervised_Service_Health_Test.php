<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use App\RSpade\Core\Health\Supervised_Service_Health_Checks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The rsx:health "Supervised Services" row reads the retry runners' state files: FAIL naming
 * each failing service whose runner is live (count, last status, since), OK counting the
 * services up, a file whose runner is gone ignored, and INFO when the state directory does
 * not exist. Driven against a scratch state directory; the "live runner" is a real
 * retry-run.sh for a scratch label, writing to that directory.
 */
class Supervised_Service_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $dir = '';

    /** @var int[] runner pids to end at teardown */
    private static array $runners = [];

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/supervised_service_health_' . getmypid();
        @mkdir(static::$dir . '/state', 0700, true);
        Supervised_Service_Health_Checks::$state_dir_for_tests = static::$dir . '/state';
    }

    public static function teardown()
    {
        Supervised_Service_Health_Checks::$state_dir_for_tests = null;
        foreach (static::$runners as $pid) {
            posix_kill($pid, SIGTERM);
        }
        rmdir_recursive(static::$dir);
    }

    /** Start a runner for $label running $command, writing to the scratch state directory. */
    private static function __runner(string $label, string $command): int
    {
        $output = [];
        exec_safe(
            'RSPADE_RETRY_MIN_DELAY=0 bash ' . escapeshellarg(base_path('app/RSpade/resource/docker/retry-run.sh'))
                . ' --state-dir=' . escapeshellarg(static::$dir . '/state')
                . ' ' . escapeshellarg($label) . ' bash -c ' . escapeshellarg($command) . ' > /dev/null 2>&1 & echo $!',
            $output
        );
        $pid = (int) trim(implode('', $output));
        static::$runners[] = $pid;

        return $pid;
    }

    /**
     * Wait until $label's state file says $status. Bounded (the house's 120 s) because it
     * waits on another process; reaching the bound is a failure naming the condition.
     */
    private static function __await_status(string $label, string $status): void
    {
        $file = static::$dir . "/state/{$label}.state";
        $deadline = time() + 120;

        while (time() < $deadline) {
            if (str_contains((string) @file_get_contents($file), "\nstatus={$status}\n")) {
                return;
            }
            usleep(50000);
        }

        static::__fail("{$label} never reached status {$status}: " . @file_get_contents($file));
    }

    public static function test_the_row_follows_the_runners()
    {
        // A healthy service and nothing else: OK.
        $healthy = static::__runner('health-row-up', 'exec sleep 300');
        static::__await_status('health-row-up', 'running');

        $row = Supervised_Service_Health_Checks::supervised_services();
        static::__assert_equals('OK', $row['status'], json_encode($row));
        static::__assert_contains('1 supervised service up, none failing', $row['detail']);

        // A failing file whose runner is gone: ignored.
        file_put_contents(static::$dir . '/state/health-row-ghost.state', "label=health-row-ghost\nstatus=failing\nconsecutive_failures=9\n"
            . "last_exit_status=1\nstreak_since=2026-01-01T00:00:00Z\nstarted_at=\nupdated_at=\npid=\nrunner_pid=999999999\n");
        static::__assert_equals('OK', Supervised_Service_Health_Checks::supervised_services()['status'], 'a left-over file is ignored');

        // A service failing over and over: FAIL naming it, its last status and since when.
        static::__runner('health-row-crash', 'exit 7');
        static::__await_status('health-row-crash', 'failing');

        $row = Supervised_Service_Health_Checks::supervised_services();
        static::__assert_equals('FAIL', $row['status'], json_encode($row));
        static::__assert_contains('health-row-crash (', $row['detail']);
        static::__assert_contains('consecutive exits, last status 7, since ', $row['detail']);
        static::__assert_false(str_contains($row['detail'], 'health-row-ghost'), 'the left-over file stays ignored');
        static::__assert_contains('supervisorctl tail', $row['remediation'] ?? '');

        // A stopped service is no failure.
        posix_kill($healthy, SIGTERM);
        static::__await_status('health-row-up', 'stopped');
        static::__assert_false(str_contains(Supervised_Service_Health_Checks::supervised_services()['detail'], 'health-row-up'), 'stopped is not failing');
    }

    public static function test_no_state_directory_is_info()
    {
        Supervised_Service_Health_Checks::$state_dir_for_tests = static::$dir . '/absent';

        try {
            $row = Supervised_Service_Health_Checks::supervised_services();
        } finally {
            Supervised_Service_Health_Checks::$state_dir_for_tests = static::$dir . '/state';
        }

        static::__assert_equals('INFO', $row['status'], json_encode($row));
    }
}
