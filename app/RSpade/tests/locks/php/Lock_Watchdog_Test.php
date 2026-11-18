<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use App\RSpade\Core\Locks\Lock_Stalled_Exception;
use App\RSpade\Core\Locks\Lock_Status;
use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Support\Process\Process_Identity;
use App\RSpade\Tests\Tasks\Php\Task_Exec_Fixture_Service;

/**
 * Lock visibility and the progress watchdog, end to end through the PHP client:
 *
 *   - Lock_Status::snapshot() (rsx:locks:status) lists a held lock with its holder's
 *     host:pid, and the command prints it;
 *   - Lock_Status::kill_holder() refuses a holder on another host and a pid whose start time
 *     does not match (signalling nothing), and ends a proven holder - its lock then frees;
 *   - a waiter behind a SILENT holder fails with Lock_Stalled_Exception after a short
 *     rsx.locks.stall_seconds, naming the holder;
 *   - a holder calling RsxLocks::still_working() keeps its waiter waiting past that window,
 *     to an ordinary grant;
 *   - a task report (heartbeat()) sends that progress signal;
 *   - a web request's wait logs ONE attribution warning after rsx.locks.wait_notice_seconds
 *     and keeps waiting.
 *
 * Everything runs against a SCRATCH rsx-lockd started from this checkout on a free port -
 * never the configured daemon, which may be older than the client under test (it runs from
 * the container image). This process's lock connection is swapped out for one pointed at the
 * scratch daemon and put back at teardown, never closed: the connection is the lock.
 * Holders are separate PHP processes (resource/lock_watchdog_holder.php) on UNSCOPED lock
 * names, so they contend with this process whichever database each booted on.
 */
class Lock_Watchdog_Test extends Rsx_Test_Abstract
{
    private const LIVE_PORT = 6210;

    private static string $dir = '';

    private static int $port = 0;

    private static int $daemon_pid = 0;

    /** @var array<int, int> pid => start time of every holder process this class spawned */
    private static array $children = [];

    /** @var resource[] raw daemon sockets standing in for holders on other hosts / pids */
    private static array $raw_sockets = [];

    /** The Lockd_Client statics this class replaces, restored at teardown. */
    private static array $saved_client = [];

    private static array $saved_config = [];

    public static function setup()
    {
        static::$dir = sys_get_temp_dir() . '/lock_watchdog_test_' . getmypid();
        @mkdir(static::$dir, 0700, true);

        static::$port = static::__scratch_port(6320);
        $config = static::$dir . '/lockd.conf';
        file_put_contents($config, json_encode([
            'unix' => ['enabled' => false, 'path' => static::$dir . '/lockd.sock', 'mode' => '0660'],
            'tcp' => ['enabled' => true, 'port' => static::$port, 'bind' => '127.0.0.1'],
            'auth' => ['hmac_key_env' => 'APP_KEY'],
            'pidfile' => static::$dir . '/lockd.pid',
            'logfile' => static::$dir . '/lockd.log',
        ]));

        static::$daemon_pid = static::__background('exec node ' . escapeshellarg(base_path('app/RSpade/resource/docker/rsx-lockd/lockd.js'))
            . ' run --config=' . escapeshellarg($config));

        // Bounded at 100x an idle start (~1 s): reached only by a daemon that never serves.
        static::__poll_until('the scratch rsx-lockd never served on port ' . static::$port, 100, function () {
            return !static::__port_free(static::$port) && is_file(static::$dir . '/lockd.pid');
        });

        foreach (['connection', 'endpoint_override'] as $property) {
            $reflection = new \ReflectionProperty(Lockd_Client::class, $property);
            static::$saved_client[$property] = $reflection->getValue();
        }
        (new \ReflectionProperty(Lockd_Client::class, 'connection'))->setValue(null, null);
        (new \ReflectionProperty(Lockd_Client::class, 'endpoint_override'))->setValue(null, ['host' => '127.0.0.1', 'port' => static::$port]);

        static::$saved_config = [
            'rsx.locks.stall_seconds' => config('rsx.locks.stall_seconds'),
            'rsx.locks.wait_notice_seconds' => config('rsx.locks.wait_notice_seconds'),
        ];
    }

    public static function teardown()
    {
        config(static::$saved_config);
        RsxLocks::_force_web_context(null);
        Event::forget(MessageLogged::class);

        foreach (static::$raw_sockets as $socket) {
            @fclose($socket);
        }
        static::$raw_sockets = [];

        // Our connection to the scratch daemon goes; the original is put back untouched.
        $connection = (new \ReflectionProperty(Lockd_Client::class, 'connection'))->getValue();
        $connection?->disconnect();
        foreach (static::$saved_client as $property => $value) {
            (new \ReflectionProperty(Lockd_Client::class, $property))->setValue(null, $value);
        }

        // Only a process proven to be the one this class started is signalled.
        foreach (static::$children as $pid => $start) {
            if (Process_Identity::is_running($pid, $start)) {
                posix_kill($pid, SIGKILL);
            }
        }
        if (static::$daemon_pid > 0 && Process_Identity::start_time(static::$daemon_pid) !== null) {
            posix_kill(static::$daemon_pid, SIGTERM);
            static::__poll_until('the scratch rsx-lockd (pid ' . static::$daemon_pid . ') did not exit', 100, function () {
                return Process_Identity::start_time(static::$daemon_pid) === null;
            });
        }

        rmdir_recursive(static::$dir);
    }

    // ---------------------------------------------------------------------------------
    // The status report
    // ---------------------------------------------------------------------------------

    public static function test_status_lists_a_held_lock_with_its_holders_host_and_pid()
    {
        $pid = static::__spawn_holder('wd_status', 'silent');

        $snapshot = Lock_Status::snapshot();
        $lock = static::__find($snapshot['locks'], 'cluster:wd_status');

        static::__assert_true($snapshot['available']);
        static::__assert_not_null($lock, 'the held lock is listed under the name the application uses');
        static::__assert_equals($pid, $lock['holders'][0]['pid'], 'naming the holder pid');
        static::__assert_equals(gethostname(), $lock['holders'][0]['host'], 'and its host');
        static::__assert_equals(Process_Identity::start_time($pid), $lock['holders'][0]['start'], 'with the start time its hello carried');
        static::__assert_equals('write', $lock['holders'][0]['mode']);

        Artisan::call('rsx:locks:status');
        $output = Artisan::output();
        static::__assert_contains('cluster:wd_status', $output);
        static::__assert_contains(gethostname() . ':' . $pid, $output, 'the command prints host:pid');
    }

    // ---------------------------------------------------------------------------------
    // Killing a holder
    // ---------------------------------------------------------------------------------

    public static function test_kill_holder_refuses_a_holder_on_another_host()
    {
        static::__raw_holder('elsewhere.invalid', 4242, 1, 'wd_foreign');

        $result = Lock_Status::kill_holder('cluster:wd_foreign');

        static::__assert_false($result['ok']);
        static::__assert_contains('not this host', $result['message']);
        static::__assert_equals([], $result['killed']);
    }

    public static function test_kill_holder_refuses_a_pid_whose_start_time_does_not_match()
    {
        $pid = static::__spawn_holder('wd_victim', 'silent');

        // A connection claiming that live pid, but with another process's start time - the
        // shape of a pid the kernel has since reused.
        static::__raw_holder(gethostname(), $pid, Process_Identity::start_time($pid) + 1, 'wd_reused');

        $result = Lock_Status::kill_holder('cluster:wd_reused');

        static::__assert_false($result['ok']);
        static::__assert_contains('no longer the process', $result['message']);
        static::__assert_true(Process_Identity::is_running($pid, static::$children[$pid]), 'the live process behind that pid was not signalled');
    }

    public static function test_kill_holder_ends_a_proven_holder_and_the_lock_frees()
    {
        $pid = static::__spawn_holder('wd_kill', 'silent');

        $result = Lock_Status::kill_holder('cluster:wd_kill');

        static::__assert_true($result['ok'], $result['message']);
        static::__assert_equals([$pid], array_column($result['killed'], 'pid'));

        static::__poll_until("the killed holder (pid {$pid}) never exited", 100, fn () => !Process_Identity::is_running($pid, static::$children[$pid]));
        static::__poll_until('cluster:wd_kill was still held after its holder exited', 100, function () {
            $lock = static::__find(Lock_Status::snapshot()['locks'], 'cluster:wd_kill');

            return $lock === null || $lock['holders'] === [];
        });

        $token = RsxLocks::get_lock(RsxLocks::CLUSTER_LOCK, 'wd_kill', RsxLocks::WRITE_LOCK, 0, false);
        static::__assert_true(RsxLocks::release_lock($token), 'and this process can take it');
    }

    // ---------------------------------------------------------------------------------
    // The watchdog
    // ---------------------------------------------------------------------------------

    public static function test_a_waiter_behind_a_silent_holder_fails_as_stalled_naming_it()
    {
        $pid = static::__spawn_holder('wd_stall', 'silent');
        config(['rsx.locks.stall_seconds' => 3]);

        // $timeout bounds this test at ~70x the 3 s event (the daemon answers `stalled`); a
        // Lock_Timeout_Exception instead means the watchdog never fired.
        $thrown = null;
        try {
            RsxLocks::get_lock(RsxLocks::CLUSTER_LOCK, 'wd_stall', RsxLocks::WRITE_LOCK, 200, false);
        } catch (Lock_Stalled_Exception $e) {
            $thrown = $e;
        }

        static::__assert_not_null($thrown, 'the wait failed as stalled');
        static::__assert_contains('cluster:wd_stall', $thrown->getMessage());
        static::__assert_contains(gethostname() . ':' . $pid, $thrown->getMessage(), 'naming the holder host:pid');
        static::__assert_contains('RsxLocks::still_working()', $thrown->getMessage(), 'and the remedy');
        static::__assert_false(str_contains($thrown->getMessage(), 'Failed to acquire'), 'never in the timeout wording');
        static::__assert_true(Process_Identity::is_running($pid, static::$children[$pid]), 'the holder itself is untouched');
    }

    public static function test_a_holder_calling_still_working_keeps_its_waiter_waiting_past_the_window()
    {
        config(['rsx.locks.stall_seconds' => 5]);
        $pid = static::__spawn_holder('wd_working', 'working', 15);

        // Bounded at 100x the holder's own 15 s; reaching it is a Lock_Timeout_Exception.
        $token = RsxLocks::get_lock(RsxLocks::CLUSTER_LOCK, 'wd_working', RsxLocks::WRITE_LOCK, 1500, false);

        try {
            static::__assert_equals('RELEASING', static::__state(), 'granted only after the holder worked its 15 s through a 5 s window and let go');
        } finally {
            RsxLocks::release_lock($token);
        }
    }

    public static function test_a_task_report_sends_the_progress_signal()
    {
        // A fresh connection, so "never signalled" is where it starts.
        $saved = (new \ReflectionProperty(Lockd_Client::class, 'connection'))->getValue();
        (new \ReflectionProperty(Lockd_Client::class, 'connection'))->setValue(null, null);
        (new \ReflectionProperty(RsxLocks::class, 'last_progress_sent'))->setValue(null, 0.0);

        try {
            $token = RsxLocks::get_lock(RsxLocks::CLUSTER_LOCK, 'wd_heartbeat', RsxLocks::WRITE_LOCK, null, false);
            try {
                static::__assert_null(static::__holder_connection('cluster:wd_heartbeat')['progress_ms'], 'no progress signal before the report');

                $task = Task_Instance::find(Task_Runner::insert_row(Task_Exec_Fixture_Service::class, 'marker_a', [], Task_Run_Model::ORIGIN_INLINE, Task_Runner::running_fields()));
                $task->heartbeat();

                static::__assert_not_null(static::__holder_connection('cluster:wd_heartbeat')['progress_ms'], 'heartbeat() signalled progress for the held lock');
            } finally {
                RsxLocks::release_lock($token);
            }
        } finally {
            (new \ReflectionProperty(Lockd_Client::class, 'connection'))->getValue()?->disconnect();
            (new \ReflectionProperty(Lockd_Client::class, 'connection'))->setValue(null, $saved);
        }
    }

    public static function test_a_web_request_wait_logs_one_attribution_warning_and_keeps_waiting()
    {
        config(['rsx.locks.wait_notice_seconds' => 1]);
        RsxLocks::_force_web_context(true);
        $pid = static::__spawn_holder('wd_notice', 'working', 6);

        $warnings = [];
        Event::forget(MessageLogged::class);
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning' && str_contains($event->message, 'has waited')) {
                $warnings[] = $event->message;
            }
        });

        try {
            // Bounded at 100x the holder's own 6 s.
            $token = RsxLocks::get_lock(RsxLocks::CLUSTER_LOCK, 'wd_notice', RsxLocks::WRITE_LOCK, 600, false);
        } finally {
            Event::forget(MessageLogged::class);
            RsxLocks::_force_web_context(null);
        }

        try {
            static::__assert_count(1, $warnings, 'exactly one attribution warning');
            static::__assert_contains('cluster:wd_notice', $warnings[0]);
            static::__assert_contains(gethostname() . ':' . $pid, $warnings[0], 'naming the holder host:pid');
            static::__assert_equals('RELEASING', static::__state(), 'and the wait continued to an ordinary grant');
        } finally {
            RsxLocks::release_lock($token);
        }
    }

    // ---------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------

    /**
     * Start resource/lock_watchdog_holder.php holding $lock and answer its pid once it holds
     * it. Bounded at 100x an idle boot-and-acquire (~2 s).
     */
    private static function __spawn_holder(string $lock, string $mode, int $hold_seconds = 0): int
    {
        @unlink(static::$dir . '/state');

        $pid = static::__background('exec php ' . escapeshellarg(dirname(__DIR__) . '/resource/lock_watchdog_holder.php')
            . ' ' . static::$port . ' ' . escapeshellarg($lock) . ' ' . escapeshellarg($mode)
            . ' ' . escapeshellarg(static::$dir) . ' ' . $hold_seconds);

        static::$children[$pid] = Process_Identity::start_time($pid);

        static::__poll_until("the holder process (pid {$pid}) never reported holding {$lock}", 200, function () use ($pid) {
            if (Process_Identity::start_time($pid) === null) {
                static::__fail("the holder process (pid {$pid}) exited before taking the lock");
            }

            return static::__state() === "ACQUIRED {$pid}";
        });

        return $pid;
    }

    /** The holder's published state, or '' before it has published one. */
    private static function __state(): string
    {
        return trim((string) @file_get_contents(static::$dir . '/state'));
    }

    /**
     * A raw daemon connection whose hello claims $host / $pid / $start and that holds $lock
     * (unscoped). Kept open until teardown.
     */
    private static function __raw_holder(string $host, int $pid, int $start, string $lock): void
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . static::$port, $errno, $errstr, 5);
        if ($socket === false) {
            static::__fail("cannot reach the scratch daemon: {$errstr}");
        }
        stream_set_blocking($socket, true);
        static::$raw_sockets[] = $socket;

        $ts = time();
        $send = function (array $frame) use ($socket): array {
            fwrite($socket, json_encode($frame) . "\n");

            return json_decode((string) fgets($socket), true);
        };

        $hello = $send([
            'op' => 'hello', 'id' => 'h', 'host' => $host, 'pid' => $pid, 'ts' => $ts, 'start' => $start,
            'sig' => hash_hmac('sha256', "{$host}:{$pid}:{$ts}", (string) env('APP_KEY')),
        ]);
        static::__assert_equals('ok', $hello['status'] ?? null, 'the raw hello is accepted');

        $granted = $send(['op' => 'acquire', 'id' => 'a', 'name' => "cluster:{$lock}", 'mode' => 'write']);
        static::__assert_equals('granted', $granted['status'] ?? null, "the raw connection holds {$lock}");
    }

    /** The connection holding $wire_name in the daemon's dump. */
    private static function __holder_connection(string $wire_name): array
    {
        $dump = Lockd_Client::request(['op' => 'dump']);

        foreach ($dump['connections'] as $conn) {
            foreach ($conn['holds'] as $hold) {
                if ($hold['name'] === $wire_name) {
                    return $conn;
                }
            }
        }

        static::__fail("no connection holds {$wire_name} on the scratch daemon");
    }

    private static function __find(array $entries, string $name): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Poll $condition every 100 ms until it holds. The bound exists only to catch a condition
     * that never comes true (the test carve-out from the no-timeout mandate); reaching it
     * fails naming the condition.
     */
    private static function __poll_until(string $never, int $bound_seconds, callable $condition): void
    {
        $deadline = microtime(true) + $bound_seconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(100000);
        }

        static::__fail($never);
    }

    private static function __background(string $command): int
    {
        $output = [];
        exec_safe($command . ' > /dev/null 2>&1 & echo $!', $output);

        return (int) trim(implode('', $output));
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
}
