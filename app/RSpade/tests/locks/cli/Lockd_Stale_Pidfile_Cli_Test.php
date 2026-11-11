<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Cli;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx-lockd's pidfile blocks only a live rsx-lockd. After a host reboot a pidfile's old pid
 * usually belongs to some other process; the daemon then stayed down for as long as that
 * process lived. Now:
 *
 *   - `run` with a pidfile naming a live process that is NOT lockd removes it, says what the
 *     pid is, and starts;
 *   - `run` with a pidfile naming a RUNNING lockd refuses ("already running");
 *   - `stop` with a pidfile naming another process removes it and signals nothing;
 *   - `start` with a pidfile naming another process starts the daemon and acknowledges it
 *     only once the pidfile names the DAEMON;
 *   - the daemon is `lockd.js [--flags] run`: `--config=<f> run` is still stopped by `stop`,
 *     while a client of the same script (`lockd.js exec ...`, holding a lock for as long as
 *     its command runs) is neither obeyed by `run` nor signalled by `stop`.
 *
 * Every daemon here is a scratch one on a scratch port with its own config, pidfile and log;
 * the supervised daemon (port 6210) is never touched.
 */
class Lockd_Stale_Pidfile_Cli_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const LIVE_PORT = 6210;

    private static string $dir = '';

    /** @var int[] processes to end at teardown */
    private static array $spawned = [];

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/lockd_stale_pidfile_' . getmypid();
        @mkdir(static::$dir, 0700, true);
    }

    public static function teardown()
    {
        foreach (static::$spawned as $pid) {
            posix_kill($pid, SIGTERM);
        }

        // A scratch daemon removes its own pidfile as it shuts down, so the directory is
        // removed only once every process is gone - otherwise the daemon's unlink and
        // rmdir_recursive() race for the same file and the loser fails the class.
        foreach (static::$spawned as $pid) {
            static::__await_exit($pid, 'a process this class spawned');
        }

        rmdir_recursive(static::$dir);
    }

    private static function __lockd_js(): string
    {
        return base_path('app/RSpade/resource/docker/rsx-lockd/lockd.js');
    }

    private static function __port_free(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);

            return false;
        }

        return true;
    }

    private static function __scratch_port(int $from): int
    {
        for ($port = $from; $port < $from + 50; $port++) {
            if ($port !== self::LIVE_PORT && static::__port_free($port)) {
                return $port;
            }
        }

        shouldnt_happen("no free scratch port at or above {$from}");
    }

    /** A config for a scratch daemon; answers its path. */
    private static function __config(string $name, int $port, string $pidfile): string
    {
        $path = static::$dir . "/{$name}.conf";
        file_put_contents($path, json_encode([
            'unix' => ['enabled' => false, 'path' => static::$dir . "/{$name}.sock", 'mode' => '0660'],
            'tcp' => ['enabled' => true, 'port' => $port, 'bind' => '127.0.0.1'],
            'auth' => ['hmac_key_env' => 'APP_KEY'],
            'pidfile' => $pidfile,
            'logfile' => static::$dir . "/{$name}.log",
        ]));

        return $path;
    }

    private static function __background(string $command): int
    {
        $output = [];
        exec_safe($command . ' > /dev/null 2>&1 & echo $!', $output);
        $pid = (int) trim(implode('', $output));
        static::$spawned[] = $pid;

        return $pid;
    }

    private static function __lockd(string $verb, string $config): array
    {
        $output = [];
        $code = 0;
        exec_safe('node ' . escapeshellarg(static::__lockd_js()) . " {$verb} --config=" . escapeshellarg($config) . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    /**
     * Wait for a scratch daemon to serve: its port listening and its own pid in the pidfile.
     * Bounded (the house's 120 s) because it waits on another process; reaching the bound is
     * a failure naming the condition.
     */
    private static function __await_serving(int $port, string $pidfile, int $pid): void
    {
        $deadline = time() + 120;

        while (time() < $deadline) {
            if (!static::__port_free($port) && is_file($pidfile) && (int) trim((string) file_get_contents($pidfile)) === $pid) {
                return;
            }
            if (!posix_kill($pid, 0)) {
                break;
            }
            usleep(100000);
        }

        static::__fail("the scratch lockd (pid {$pid}) never served on port {$port} with its own pid in {$pidfile}");
    }

    /** Is $pid running? A zombie counts as gone. */
    private static function __running(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (!is_string($stat)) {
            return false;
        }

        // The state is the field after the parenthesised command name.
        $state = substr($stat, strrpos($stat, ')') + 2, 1);

        return $state !== 'Z' && $state !== 'X';
    }

    /**
     * Wait for $pid to exit. Bounded (the house's 120 s) because it waits on another process;
     * reaching the bound is a failure naming the condition.
     */
    private static function __await_exit(int $pid, string $what): void
    {
        $deadline = time() + 120;

        while (time() < $deadline) {
            if (!static::__running($pid)) {
                return;
            }
            usleep(100000);
        }

        static::__fail("{$what} (pid {$pid}) did not exit");
    }

    /**
     * A live `lockd.js exec` holding a lock on the scratch daemon behind $config: answers its
     * pid once its command (a sleep) is running, which is when the lock is held. Bounded like
     * __await_serving().
     */
    private static function __exec_client(string $config): int
    {
        $pid = static::__background('exec node ' . escapeshellarg(static::__lockd_js()) . ' exec --config=' . escapeshellarg($config)
            . ' --name=lockd_stale_pidfile_test --quiet -- sleep 300');
        $deadline = time() + 120;

        while (time() < $deadline) {
            $children = @file_get_contents("/proc/{$pid}/task/{$pid}/children");
            if (is_string($children) && trim($children) !== '') {
                foreach (preg_split('/\s+/', trim($children)) as $child) {
                    static::$spawned[] = (int) $child;
                }

                return $pid;
            }
            if (!static::__running($pid)) {
                break;
            }
            usleep(100000);
        }

        static::__fail("the lockd.js exec client (pid {$pid}) never started its command under the lock");
    }

    public static function test_a_pidfile_naming_another_live_process_does_not_stop_lockd_starting()
    {
        $bystander = static::__background('sleep 300');
        $pidfile = static::$dir . '/a.pid';
        file_put_contents($pidfile, "{$bystander}\n");

        $port = static::__scratch_port(6330);
        $config = static::__config('a', $port, $pidfile);
        $lockd = static::__background('exec node ' . escapeshellarg(static::__lockd_js()) . ' run --config=' . escapeshellarg($config));

        static::__await_serving($port, $pidfile, $lockd);

        $log = (string) @file_get_contents(static::$dir . '/a.log');
        static::__assert_true(str_contains($log, "Removing stale pidfile {$pidfile} (pid {$bystander} is another process"), "the start says what the pid was: {$log}");
        static::__assert_true(posix_kill($bystander, 0), 'the other process is left alone');

        // A second daemon on the same pidfile now meets a RUNNING lockd and refuses.
        $second = static::__config('b', static::__scratch_port($port + 1), $pidfile);
        [$code, $output] = static::__lockd('run', $second);

        static::__assert_true($code !== 0, 'a second run refuses');
        static::__assert_true(str_contains($output, "already running as pid {$lockd}"), "naming the running daemon: {$output}");
        static::__assert_equals($lockd, (int) trim((string) file_get_contents($pidfile)), 'the running daemon keeps its pidfile');
    }

    public static function test_stop_never_signals_a_pid_that_is_not_lockd()
    {
        $bystander = static::__background('sleep 300');
        $pidfile = static::$dir . '/c.pid';
        file_put_contents($pidfile, "{$bystander}\n");

        [$code, $output] = static::__lockd('stop', static::__config('c', static::__scratch_port(6360), $pidfile));

        static::__assert_equals(0, $code);
        static::__assert_true(posix_kill($bystander, 0), 'the other process was not signalled');
        static::__assert_false(file_exists($pidfile), "the stale pidfile is removed: {$output}");
        static::__assert_true(str_contains($output, 'is another process'), 'and says why');
    }

    public static function test_start_with_a_pidfile_naming_another_process_starts_and_acknowledges_the_daemon()
    {
        $bystander = static::__background('sleep 300');
        $pidfile = static::$dir . '/d.pid';
        file_put_contents($pidfile, "{$bystander}\n");

        $config = static::__config('d', static::__scratch_port(6390), $pidfile);
        [$code, $output] = static::__lockd('start', $config);

        $daemon = is_file($pidfile) ? (int) trim((string) file_get_contents($pidfile)) : 0;
        if ($daemon > 0) {
            static::$spawned[] = $daemon;
        }

        static::__assert_equals(0, $code, $output);
        static::__assert_true(str_contains($output, "Stale pidfile {$pidfile} (pid {$bystander} is another process"), "says what the pid was: {$output}");
        static::__assert_true($daemon > 0 && $daemon !== $bystander, "the pidfile names the new daemon: {$daemon}");
        static::__assert_true(str_contains($output, "Started as pid {$daemon}"), "acknowledged as the daemon's pid: {$output}");
        static::__assert_true(posix_kill($bystander, 0), 'the other process is left alone');

        [$code, $output] = static::__lockd('stop', $config);
        static::__assert_equals(0, $code, $output);
        static::__await_exit($daemon, 'the started daemon');
    }

    public static function test_a_daemon_started_with_flags_before_run_is_still_stopped()
    {
        $pidfile = static::$dir . '/e.pid';
        $port = static::__scratch_port(6420);
        $config = static::__config('e', $port, $pidfile);

        // `--config=<f> run`: a flag BEFORE the command is still the daemon.
        $lockd = static::__background('exec node ' . escapeshellarg(static::__lockd_js()) . ' --config=' . escapeshellarg($config) . ' run');
        static::__await_serving($port, $pidfile, $lockd);

        [$code, $output] = static::__lockd('stop', $config);

        static::__assert_equals(0, $code, $output);
        static::__assert_true(str_contains($output, "Stopped pid {$lockd}"), "stopped: {$output}");
        static::__await_exit($lockd, 'the daemon');
        static::__assert_false(file_exists($pidfile), 'its pidfile is gone');
    }

    public static function test_a_lockd_exec_client_is_not_the_daemon()
    {
        // A scratch daemon for the client to hold its lock on.
        $daemon_pidfile = static::$dir . '/f.pid';
        $port = static::__scratch_port(6450);
        $daemon_config = static::__config('f', $port, $daemon_pidfile);
        $daemon = static::__background('exec node ' . escapeshellarg(static::__lockd_js()) . ' run --config=' . escapeshellarg($daemon_config));
        static::__await_serving($port, $daemon_pidfile, $daemon);

        $client = static::__exec_client($daemon_config);

        // `run` on a pidfile naming the client: stale, replaced, and the client lives on.
        $pidfile = static::$dir . '/g.pid';
        file_put_contents($pidfile, "{$client}\n");
        $second_port = static::__scratch_port($port + 1);
        $second = static::__background('exec node ' . escapeshellarg(static::__lockd_js()) . ' run --config=' . escapeshellarg(static::__config('g', $second_port, $pidfile)));
        static::__await_serving($second_port, $pidfile, $second);

        $log = (string) @file_get_contents(static::$dir . '/g.log');
        static::__assert_true(str_contains($log, "Removing stale pidfile {$pidfile} (pid {$client} is another process"), "the exec client is not the daemon: {$log}");
        static::__assert_true(static::__running($client), 'the exec client is left alone by run');

        // `stop` on a pidfile naming the client: removed, never signalled.
        $stop_pidfile = static::$dir . '/h.pid';
        file_put_contents($stop_pidfile, "{$client}\n");
        [$code, $output] = static::__lockd('stop', static::__config('h', static::__scratch_port($second_port + 1), $stop_pidfile));

        static::__assert_equals(0, $code, $output);
        static::__assert_true(str_contains($output, "pid {$client} is another process"), "says why: {$output}");
        static::__assert_false(file_exists($stop_pidfile), 'the stale pidfile is removed');
        static::__assert_true(static::__running($client), 'the exec client was not signalled by stop');
    }
}
