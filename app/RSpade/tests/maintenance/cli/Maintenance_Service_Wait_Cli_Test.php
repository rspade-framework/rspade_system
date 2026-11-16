<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Maintenance\Cli;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * maintenance-mode.sh's wait for rsx-lockd tells "slow to start" from "cannot start"
 * (bin/lib/rsx_service_wait.sh): it waits with no deadline for a daemon that is starting, and
 * stops - naming the cause - only on EVIDENCE that it cannot start: supervisor FATAL, the
 * script its live process runs missing, or its runner's recorded streak of exit 126/127.
 * Exit 1 is never evidence.
 *
 * Nothing here touches this box's supervisor: a stand-in `supervisorctl` sits first on PATH
 * and answers `status` and `pid` from fixture files in a scratch directory, and the processes
 * it names are REAL - a real retry-run.sh with --state-dir pointed at scratch, so the
 * command-line and state-file evidence is read exactly as on a live box. Polls wait only for
 * events (a state file reaching a streak, a waiter exiting), bounded at 200 s, and a bound
 * reached fails naming the condition. Nothing asserts how long anything took.
 */
class Maintenance_Service_Wait_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $dir = '';

    /** @var int[] processes this test started, ended at teardown */
    private static array $pids = [];

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/maint_service_wait_' . getmypid();
        @mkdir(static::$dir . '/bin', 0700, true);
        @mkdir(static::$dir . '/state', 0700, true);
        @mkdir(static::$dir . '/supervisor', 0700, true);
        @mkdir(static::$dir . '/project', 0700, true);

        file_put_contents(static::$dir . '/bin/supervisorctl', <<<'BASH'
#!/bin/bash
# Stand-in supervisorctl: answers from $FAKE_SUPERVISOR_DIR/{status,pid}.
case "${1:-}" in
    status)
        if [ -n "${2:-}" ]; then
            grep -E "^${2}[[:space:]]" "$FAKE_SUPERVISOR_DIR/status"
        else
            cat "$FAKE_SUPERVISOR_DIR/status"
        fi
        ;;
    pid)
        cat "$FAKE_SUPERVISOR_DIR/pid"
        ;;
esac
exit 0
BASH);
        chmod(static::$dir . '/bin/supervisorctl', 0755);
    }

    public static function teardown()
    {
        // Only a process whose command line still names this test's scratch directory is ours.
        foreach (array_reverse(static::$pids) as $pid) {
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            if (str_contains($cmdline, static::$dir)) {
                posix_kill($pid, SIGKILL);
            }
        }
        rmdir_recursive(static::$dir);
    }

    private static function __supervisor(string $status_line, int $pid): void
    {
        file_put_contents(static::$dir . '/supervisor/status', $status_line . "\n");
        file_put_contents(static::$dir . '/supervisor/pid', $pid . "\n");
    }

    private static function __env(array $extra = []): array
    {
        return array_merge([
            'PATH' => static::$dir . '/bin:' . getenv('PATH'),
            'FAKE_SUPERVISOR_DIR' => static::$dir . '/supervisor',
            'RSX_PATHS_PROJECT_ROOT_DIR' => static::$dir . '/project',
            'LOCK_SERVER_HOST' => '127.0.0.1',
        ], $extra);
    }

    /** The libraries, sourced as maintenance-mode.sh sources them. */
    private static function __prelude(): string
    {
        $lib = base_path('bin/lib');

        return '. ' . escapeshellarg("{$lib}/rsx_paths.sh") . '; '
            . '. ' . escapeshellarg("{$lib}/rsx_image_contract.sh") . '; '
            . '. ' . escapeshellarg("{$lib}/rsx_service_wait.sh") . '; ';
    }

    /** Run $body after the prelude; answers [exit code, output]. */
    private static function __bash(string $body, array $env = []): array
    {
        $output = [];
        $code = 0;
        exec_safe('bash -c ' . escapeshellarg(static::__prelude() . $body), $output, $code, static::__env($env));

        return [$code, implode("\n", $output)];
    }

    /** Start a real retry runner for rsx-lockd running `bash -c $command`; answers its pid. */
    private static function __runner(string $command): int
    {
        @unlink(static::$dir . '/state/rsx-lockd.state');
        $output = [];
        exec_safe(
            'RSPADE_RETRY_MIN_DELAY=0 bash ' . escapeshellarg(base_path('app/RSpade/resource/docker/retry-run.sh'))
                . ' --state-dir=' . escapeshellarg(static::$dir . '/state')
                . ' rsx-lockd ' . $command
                . ' > ' . escapeshellarg(static::$dir . '/runner.log') . ' 2>&1 & echo $!',
            $output
        );
        $pid = (int) trim(implode('', $output));
        static::$pids[] = $pid;

        return $pid;
    }

    /** Stop a runner with TERM, as supervisor does, and wait until it has written `stopped`. */
    private static function __stop_runner(int $pid): void
    {
        posix_kill($pid, SIGTERM);
        static::__await(fn () => (static::__state()['status'] ?? '') === 'stopped', 'the runner recording its stop');
    }

    private static function __state(): array
    {
        $raw = @file_get_contents(static::$dir . '/state/rsx-lockd.state');
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
     * Wait for an event in another process. The bound is the test carve-out from the
     * no-timeout mandate: 100 times the ~2 s these events take on an idle box, and reaching
     * it fails naming the event that never happened.
     */
    private static function __await(callable $condition, string $what): void
    {
        $deadline = time() + 200;
        while (time() < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(50000);
        }
        static::__fail("{$what} never happened; state: " . json_encode(static::__state()));
    }

    private static function __free_port(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        return $port;
    }

    public static function test_supervisor_fatal_is_cannot_start()
    {
        static::__supervisor('rsx-lockd                        FATAL     Exited too quickly (process log may have details)', 0);

        [$code, $output] = static::__bash('rsx_service_cannot_start rsx-lockd ' . escapeshellarg(static::$dir . '/state'));

        static::__assert_equals(0, $code, $output);
        static::__assert_equals('rsx-lockd cannot start: supervisor reports it FATAL.', $output);
    }

    public static function test_a_runner_whose_script_is_missing_is_cannot_start()
    {
        $missing = static::$dir . '/gone/lockd-run.sh';
        $runner = static::__runner('bash ' . escapeshellarg($missing));
        static::__supervisor("rsx-lockd                        RUNNING   pid {$runner}, uptime 0:00:01", $runner);

        [$code, $output] = static::__bash('rsx_service_cannot_start rsx-lockd ' . escapeshellarg(static::$dir . '/state'));

        static::__assert_equals(0, $code, $output);
        static::__assert_equals("rsx-lockd cannot start: its supervisor program runs bash {$missing}, and {$missing} does not exist.", $output);

        static::__stop_runner($runner);
    }

    public static function test_a_process_that_is_not_the_program_is_no_evidence()
    {
        // A live `bash <script>` whose script is gone - but it is neither a retry runner for
        // the label nor a child of supervisord, so the pid supervisor answered does not name
        // it, and its command line proves nothing about the program.
        $script = static::$dir . '/doomed.sh';
        $pid_file = static::$dir . '/doomed.pid';
        file_put_contents($script, "echo \$\$ > " . escapeshellarg($pid_file) . "\nrm -f \"\$0\"\nkill -STOP \$\$\n");

        $output = [];
        exec_safe('bash -c ' . escapeshellarg('bash ' . escapeshellarg($script) . ' & wait') . ' > /dev/null 2>&1 & echo $!', $output);
        static::$pids[] = (int) trim(implode('', $output));

        static::__await(fn () => !file_exists($script) && is_file($pid_file), 'the stand-in removing its own script');
        $pid = (int) trim((string) file_get_contents($pid_file));
        static::$pids[] = $pid;

        static::__supervisor("rsx-lockd                        RUNNING   pid {$pid}, uptime 0:00:01", $pid);

        [$code, $output] = static::__bash('rsx_service_cannot_start rsx-lockd ' . escapeshellarg(static::$dir . '/state'));

        static::__assert_equals(1, $code, "a process that is not the program is no evidence: {$output}");
        static::__assert_equals('', $output);
    }

    public static function test_a_recorded_streak_of_127_is_cannot_start()
    {
        // Inline code names no file, so only the runner's record can say it.
        $runner = static::__runner("bash -c 'exit 127'");
        static::__supervisor("rsx-lockd                        RUNNING   pid {$runner}, uptime 0:00:01", 0);

        static::__await(fn () => (int) (static::__state()['last_exit_status_repeats'] ?? 0) >= 5, 'five repeated exits with status 127');

        [$code, $output] = static::__bash('rsx_service_cannot_start rsx-lockd ' . escapeshellarg(static::$dir . '/state'));

        static::__assert_equals(0, $code, $output);
        static::__assert_true(
            (bool) preg_match('/^rsx-lockd cannot start: its runner reports \d+ consecutive exits with status 127 \(command not found\) since \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\.$/', $output),
            "the cause names the streak: {$output}"
        );

        static::__stop_runner($runner);
    }

    public static function test_a_service_failing_with_exit_1_is_still_waited_for()
    {
        // The first-boot shape: the script exists, and fails with 1 until the application
        // arrives. However long the streak, that is slow, not broken.
        $script = static::$dir . '/no_key_yet.sh';
        file_put_contents($script, "exit 1\n");
        $runner = static::__runner('bash ' . escapeshellarg($script));
        static::__supervisor("rsx-lockd                        RUNNING   pid {$runner}, uptime 0:00:01", $runner);

        static::__await(
            fn () => (static::__state()['status'] ?? '') === 'failing' && (int) (static::__state()['last_exit_status_repeats'] ?? 0) >= 5,
            'the runner recording a failing streak of exit 1'
        );

        [$code, $output] = static::__bash('rsx_service_cannot_start rsx-lockd ' . escapeshellarg(static::$dir . '/state'));

        static::__assert_equals(1, $code, "exit 1 is never cannot-start: {$output}");
        static::__assert_equals('', $output);

        static::__stop_runner($runner);
    }

    public static function test_the_wait_stops_on_cannot_start_and_says_what_was_started()
    {
        static::__supervisor('rsx-lockd                        FATAL     Exited too quickly', 0);
        $env = ['LOCK_SERVER_PORT' => (string) static::__free_port()];

        // No image sentence: the pointer.
        [$code, $output] = static::__bash('wait_for_lockd "" ' . escapeshellarg(static::$dir . '/state'), $env);
        static::__assert_equals(1, $code, $output);
        static::__assert_contains('[ERROR] rsx-lockd cannot start: supervisor reports it FATAL.', $output);
        static::__assert_contains('[ERROR] Stopped waiting. redis and rsx-lockd were started; php-fpm, realtime and the proxy were NOT. The maintenance flag stays up.', $output);
        static::__assert_contains('supervisorctl tail rsx-lockd stderr', $output);
        static::__assert_contains('php artisan rsx:framework:breaking_changes', $output);

        // An image older than the checkout: its sentence instead.
        [$code, $output] = static::__bash(
            'rsx_image_contract_problem() { echo "THE IMAGE SENTENCE"; return 0; }; wait_for_lockd /any.conf ' . escapeshellarg(static::$dir . '/state'),
            $env
        );
        static::__assert_equals(1, $code, $output);
        static::__assert_contains('  THE IMAGE SENTENCE', $output);
        static::__assert_false(str_contains($output, 'rsx:framework:breaking_changes'), "the sentence replaces the pointer: {$output}");
    }

    public static function test_a_lockd_that_starts_late_is_waited_for()
    {
        // Running, no evidence either way (pid 0 between attempts): the wait goes on until the
        // daemon answers.
        static::__supervisor('rsx-lockd                        RUNNING   pid 0, uptime 0:00:01', 0);
        $port = static::__free_port();
        $rc_file = static::$dir . '/wait.rc';

        $output = [];
        $code = 0;
        exec_safe(
            'bash -c ' . escapeshellarg(static::__prelude() . 'wait_for_lockd "" ' . escapeshellarg(static::$dir . '/state') . '; echo $? > ' . escapeshellarg($rc_file))
                . ' > ' . escapeshellarg(static::$dir . '/wait.log') . ' 2>&1 & echo $!',
            $output,
            $code,
            static::__env(['LOCK_SERVER_PORT' => (string) $port])
        );
        $waiter = (int) trim(implode('', $output));
        static::$pids[] = $waiter;

        // Nothing listens yet: the waiter is still waiting.
        static::__assert_false(file_exists($rc_file), 'the wait has not ended with nothing listening: ' . @file_get_contents(static::$dir . '/wait.log'));
        static::__assert_true(file_exists("/proc/{$waiter}"), 'the waiter is alive while nothing listens');

        // The daemon comes up: a ping responder on the port.
        $responder = 'require("net").createServer(s => s.on("data", () => s.write(\'{"id":1,"status":"ok"}\n\'))).listen(' . $port . ', "127.0.0.1")';
        $output = [];
        exec_safe('node -e ' . escapeshellarg($responder) . ' ' . escapeshellarg(static::$dir) . ' > /dev/null 2>&1 & echo $!', $output);
        static::$pids[] = (int) trim(implode('', $output));

        static::__await(fn () => is_file($rc_file) && trim((string) file_get_contents($rc_file)) !== '', 'the wait ending once rsx-lockd answers');

        static::__assert_equals('0', trim((string) file_get_contents($rc_file)), 'the wait succeeds: ' . file_get_contents(static::$dir . '/wait.log'));
    }

    public static function test_disable_honours_the_wait()
    {
        $script = (string) file_get_contents(base_path('bin/maintenance-mode.sh'));

        preg_match('/^do_disable\(\) \{\n(.*?)^\}$/ms', $script, $match);
        static::__assert_count(2, $match, 'the script defines do_disable()');
        $disable = $match[1];

        $wait = strpos($disable, 'wait_for_lockd "$IMAGE_CONTRACT_CONF" || exit 1');
        static::__assert_true($wait !== false, 'do_disable() exits when the lockd wait fails');
        static::__assert_true($wait > strpos($disable, "start_unit '^rsx-lockd'"), 'the wait follows the lockd start');
        static::__assert_true($wait < strpos($disable, "start_unit '^php-fpm'"), 'the wait precedes the php-fpm start');
        static::__assert_true($wait < strpos($disable, 'rm -f "$FLAG"'), 'the wait precedes the flag removal');
    }
}
