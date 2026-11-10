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
 *   - `stop` with a pidfile naming another process removes it and signals nothing.
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
}
