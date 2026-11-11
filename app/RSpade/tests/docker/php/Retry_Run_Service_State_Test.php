<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The supervisor runner (resource/docker/retry-run.sh) makes a failing service VISIBLE:
 * supervisor shows the runner RUNNING however often the service exits, so the runner keeps a
 * state file per label and logs one [ERROR] line per milestone of a failure streak. And the
 * container's health check (resource/docker/healthcheck.sh) reports from it.
 *
 *   - five consecutive exits mark the service `failing`, with one [ERROR] line at 5 and at each
 *     doubling (10, 20, ...) - never one per attempt;
 *   - a run that lasts MAX_DELAY ends the streak the moment it has, not at its next exit:
 *     status `running`, consecutive_failures 0;
 *   - a stop signal writes `stopped`;
 *   - the health check: unhealthy naming a failing label while its runner lives, healthy once
 *     the runner is gone or stopped; a supervisor program FATAL is unhealthy, STOPPED is not;
 *     a lock daemon that does not answer is unhealthy; maintenance mode and a missing checkout
 *     are healthy with one note.
 *
 * Every runner here is a scratch label writing to a scratch state directory (--state-dir);
 * no supervised service, and no file under /run/rspade-services, is touched. The waits are
 * shortened with RSPADE_RETRY_MIN_DELAY / RSPADE_RETRY_MAX_DELAY.
 */
class Retry_Run_Service_State_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $dir = '';

    /** @var int[] runner pids to end at teardown */
    private static array $runners = [];

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/retry_run_state_' . getmypid();
        @mkdir(static::$dir . '/state', 0700, true);
        @mkdir(static::$dir . '/bin', 0700, true);
    }

    public static function teardown()
    {
        foreach (static::$runners as $pid) {
            posix_kill($pid, SIGTERM);
        }
        rmdir_recursive(static::$dir);
    }

    private static function __docker(string $file): string
    {
        return base_path('app/RSpade/resource/docker/' . $file);
    }

    /** Start a runner for $label in the background; answers its pid. Output goes to <label>.log. */
    private static function __runner(string $label, string $command, int $max_delay = 60): int
    {
        $output = [];
        exec_safe(
            'RSPADE_RETRY_MIN_DELAY=0 RSPADE_RETRY_MAX_DELAY=' . $max_delay
                . ' bash ' . escapeshellarg(static::__docker('retry-run.sh'))
                . ' --state-dir=' . escapeshellarg(static::$dir . '/state')
                . ' ' . escapeshellarg($label) . ' bash -c ' . escapeshellarg($command)
                . ' > ' . escapeshellarg(static::$dir . "/{$label}.log") . ' 2>&1 & echo $!',
            $output
        );
        $pid = (int) trim(implode('', $output));
        static::$runners[] = $pid;

        return $pid;
    }

    /** The state file for $label as key => value, or [] while there is none. */
    private static function __state(string $label): array
    {
        $raw = @file_get_contents(static::$dir . "/state/{$label}.state");
        if (!is_string($raw)) {
            return [];
        }

        $state = [];
        foreach (explode("\n", trim($raw)) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $state[$key] = $value;
        }

        return $state;
    }

    /**
     * Wait until $condition holds for $label's state. Bounded (the house's 120 s) because it
     * waits on another process; reaching the bound is a failure naming the condition.
     */
    private static function __await_state(string $label, callable $condition, string $what): array
    {
        $deadline = time() + 120;

        while (time() < $deadline) {
            $state = static::__state($label);
            if ($state !== [] && $condition($state)) {
                return $state;
            }
            usleep(50000);
        }

        static::__fail("{$label}: {$what} never happened: " . json_encode(static::__state($label)));
    }

    /** Stop a runner with TERM, as supervisor does, and wait for it to record the stop. */
    private static function __stop(int $pid, string $label): array
    {
        posix_kill($pid, SIGTERM);

        return static::__await_state($label, fn ($s) => $s['status'] === 'stopped', 'the stop');
    }

    /** Run the health check with $args (and $env prefix); answers [exit code, output]. */
    private static function __healthcheck(string $args = '', string $env = ''): array
    {
        $output = [];
        $code = 0;
        exec_safe($env . 'bash ' . escapeshellarg(static::__docker('healthcheck.sh')) . ' ' . $args . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    private static function __state_dir_arg(): string
    {
        return '--state-dir=' . escapeshellarg(static::$dir . '/state');
    }

    public static function test_five_exits_mark_a_service_failing_with_one_error_line_per_milestone()
    {
        $label = 'state-test-crash';
        $runner = static::__runner($label, 'exit 3');

        $state = static::__await_state($label, fn ($s) => (int) $s['consecutive_failures'] >= 21, '21 consecutive exits');

        static::__assert_equals('failing', $state['status']);
        static::__assert_equals('3', $state['last_exit_status']);
        static::__assert_equals((string) $runner, $state['runner_pid']);
        static::__assert_true((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $state['streak_since']), 'streak_since is a UTC time: ' . $state['streak_since']);

        // The health check sees it while the runner lives.
        [$code, $output] = static::__healthcheck(static::__state_dir_arg(), 'PATH=' . escapeshellarg(static::$dir . '/bin') . ':"$PATH" ');
        static::__assert_equals(1, $code, $output);
        static::__assert_contains("[ERROR] {$label}: failing - ", $output);
        static::__assert_contains('(last status 3)', $output);

        $state = static::__stop($runner, $label);
        static::__assert_equals('', $state['pid'], 'no service pid once stopped');

        $log = (string) file_get_contents(static::$dir . "/{$label}.log");
        preg_match_all('/^\[ERROR\] \[' . preg_quote($label, '/') . '\] failing: (\d+) consecutive exits \(last status 3\) since \S+$/m', $log, $matches);
        $milestones = array_map('intval', $matches[1]);

        static::__assert_true(count($milestones) >= 3, 'at least the 5, 10 and 20 lines: ' . json_encode($milestones));
        foreach ($milestones as $index => $count) {
            static::__assert_equals(5 * (2 ** $index), $count, 'one line at 5 and at each doubling: ' . json_encode($milestones));
        }
        static::__assert_true(substr_count($log, "[{$label}] exited with status 3") >= 21, 'the per-attempt lines are kept');

        // A stopped runner's file is no failure.
        [$code, $output] = static::__healthcheck(static::__state_dir_arg(), 'PATH=' . escapeshellarg(static::$dir . '/bin') . ':"$PATH" ');
        static::__assert_false(str_contains($output, $label), "a stopped service is not reported: {$output}");
    }

    public static function test_a_run_that_lasts_max_delay_ends_the_streak_while_it_runs()
    {
        $label = 'state-test-recover';
        $counter = static::$dir . '/recover.count';

        // Six quick failures, then a run that stays up.
        $runner = static::__runner(
            $label,
            'n=$(cat ' . escapeshellarg($counter) . ' 2>/dev/null || echo 0); echo $((n + 1)) > ' . escapeshellarg($counter)
                . '; [ "$n" -lt 6 ] && exit 2; exec sleep 300',
            2
        );

        $state = static::__await_state($label, fn ($s) => (int) $s['consecutive_failures'] === 6 && $s['pid'] !== '', 'the seventh run');
        static::__assert_equals('failing', $state['status'], 'still failing while the seventh run is young');

        $state = static::__await_state($label, fn ($s) => $s['status'] === 'running', 'recovery');
        static::__assert_equals('0', $state['consecutive_failures']);
        static::__assert_equals('', $state['streak_since']);
        static::__assert_true(file_exists('/proc/' . $state['pid']), 'the service is the live run');

        static::__stop($runner, $label);
    }

    public static function test_a_failing_file_whose_runner_is_gone_is_ignored()
    {
        file_put_contents(static::$dir . '/state/state-test-ghost.state', implode("\n", [
            'label=state-test-ghost', 'status=failing', 'consecutive_failures=9', 'last_exit_status=1',
            'streak_since=2026-01-01T00:00:00Z', 'started_at=', 'updated_at=', 'pid=', 'runner_pid=999999999',
        ]) . "\n");

        [$code, $output] = static::__healthcheck(static::__state_dir_arg(), 'PATH=' . escapeshellarg(static::$dir . '/bin') . ':"$PATH" ');

        static::__assert_false(str_contains($output, 'state-test-ghost'), "a left-over file is ignored: {$output}");
        unlink(static::$dir . '/state/state-test-ghost.state');
    }

    public static function test_the_health_check_reads_supervisor_and_pings_the_lock_daemon()
    {
        // A stand-in supervisorctl, first on PATH: one FATAL, one STOPPED, rsx-lockd RUNNING.
        $fake = static::$dir . '/bin/supervisorctl';
        file_put_contents($fake, "#!/bin/bash\n"
            . "echo 'nginx                            FATAL     Exited too quickly (process log may have details)'\n"
            . "echo 'redis                            STOPPED   Nov 10 08:00 PM'\n"
            . "echo 'rsx-lockd                        RUNNING   pid 1, uptime 0:00:01'\n"
            . "exit 3\n");
        chmod($fake, 0755);

        $dead_port = static::__free_port();
        [$code, $output] = static::__healthcheck(static::__state_dir_arg(), 'PATH=' . escapeshellarg(static::$dir . '/bin') . ':"$PATH" LOCK_SERVER_HOST=127.0.0.1 LOCK_SERVER_PORT=' . $dead_port . ' ');

        static::__assert_equals(1, $code, $output);
        static::__assert_contains('[ERROR] supervisor: nginx is FATAL (Exited too quickly', $output);
        static::__assert_false(str_contains($output, 'redis'), "STOPPED is deliberate: {$output}");
        static::__assert_contains("[ERROR] rsx-lockd: no answer to a ping on 127.0.0.1:{$dead_port}", $output);

        // supervisorctl not answering at all is a problem too.
        file_put_contents($fake, "#!/bin/bash\necho 'unix:///var/run/supervisor.sock no such file'\nexit 2\n");
        [$code, $output] = static::__healthcheck(static::__state_dir_arg(), 'PATH=' . escapeshellarg(static::$dir . '/bin') . ':"$PATH" ');
        static::__assert_equals(1, $code, $output);
        static::__assert_contains('[ERROR] supervisor: supervisorctl status did not answer', $output);

        // Everything RUNNING, no rsx-lockd program: healthy and silent.
        file_put_contents($fake, "#!/bin/bash\necho 'nginx                            RUNNING   pid 1, uptime 0:00:01'\nexit 0\n");
        [$code, $output] = static::__healthcheck(static::__state_dir_arg(), 'PATH=' . escapeshellarg(static::$dir . '/bin') . ':"$PATH" ');
        static::__assert_equals(0, $code, $output);
        static::__assert_equals('', $output);

        unlink($fake);
    }

    public static function test_maintenance_mode_and_a_missing_checkout_are_healthy()
    {
        // A failing service AND a dead supervisor would both be problems - but not here.
        $fake = static::$dir . '/bin/supervisorctl';
        file_put_contents($fake, "#!/bin/bash\necho 'nginx   FATAL   Exited too quickly'\nexit 3\n");
        chmod($fake, 0755);
        $path = 'PATH=' . escapeshellarg(static::$dir . '/bin') . ':"$PATH" ';

        $storage = static::$dir . '/storage';
        @mkdir($storage . '/state', 0700, true);
        file_put_contents($storage . '/state/.maintenance.mode.framework.update', "test\nmode=development\n");

        [$code, $output] = static::__healthcheck(static::__state_dir_arg(), $path . 'RSX_STORAGE_PATH=' . escapeshellarg($storage) . ' ');
        static::__assert_equals(0, $code, $output);
        static::__assert_contains('[INFO] maintenance mode is on', $output);

        [$code, $output] = static::__healthcheck('--project-root=' . escapeshellarg(static::$dir . '/no_checkout') . ' ' . static::__state_dir_arg(), $path);
        static::__assert_equals(0, $code, $output);
        static::__assert_contains('[INFO] no checkout at ' . static::$dir . '/no_checkout/system', $output);

        unlink($fake);
    }

    public static function test_the_image_installs_the_health_check()
    {
        $dockerfile = (string) file_get_contents(static::__docker('Dockerfile'));
        $base_stage = substr($dockerfile, 0, (int) strpos($dockerfile, 'FROM base AS dev'));

        static::__assert_contains('COPY healthcheck.sh    /usr/local/bin/rspade-healthcheck', $base_stage);
        static::__assert_contains('HEALTHCHECK CMD ["bash", "/usr/local/bin/rspade-healthcheck"]', $base_stage);
    }

    /** A loopback port nothing is listening on: bound by the OS, then released. */
    private static function __free_port(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        return $port;
    }
}
