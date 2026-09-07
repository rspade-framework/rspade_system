<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\JsTransform\Php;

use ReflectionProperty;
use App\RSpade\Core\Bundle\Concatenator;
use App\RSpade\Core\Bundle\Minifier;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The node service lifecycle: a PRIVATE daemon per PHP process, on a socket name nobody else
 * can learn; a daemon that retires itself when it has been orphaned; and a client that
 * respawns transparently when it finds its own daemon gone.
 *
 * THE DEFECT the private-socket rows cover. A well-known socket path shared between processes
 * has a real race in it: process B's ensure() reaps the daemon process A is mid-request on,
 * and two cold starts fight over the same path. A private, randomly-named socket removes the
 * race by construction - nobody else knows the name, so nobody else can kill it - and it also
 * removes any question of freshness, because a daemon is always spawned from current disk by
 * its own parent and its lifetime is a subset of that parent's.
 *
 * The orphan case that remains is the ABRUPT death: a SIGKILLed parent leaves a daemon whose
 * private socket nobody will ever speak to again. Two mechanisms answer it, and both are
 * asserted below: the daemon's own idle exit, and request()'s transparent respawn.
 *
 * Every test here is REAL: real node processes, real sockets, real pgrep. Nothing is
 * simulated except the events themselves (SIGKILLing a daemon is exactly what an interrupted
 * build does to one).
 *
 * These tests kill daemons, including any that were already running for other work. That is
 * safe by design and is the contract under test: the service holds no state and is respawned
 * on demand by the next process that needs one.
 */
class Rpc_Lifecycle_Test extends Rsx_Test_Abstract
{
    // Real processes and real sockets; no database involvement at all.
    protected static $use_database_transactions = false;

    /**
     * TWO PHP PROCESSES GET TWO PRIVATE DAEMONS, and neither can touch the other's.
     *
     * This is the whole point of the redesign. A second process arriving while ours is live
     * must mint its OWN socket name and spawn its OWN daemon - it must not reuse ours, and
     * (the part that used to be a race) it must not reap ours either.
     *
     * A fresh PHP process is exactly this class's static state cleared, which is what the
     * reflection below produces. Reaching for the private latch is the only honest way to
     * hold TWO processes' worth of service state alive at once inside one test process; a
     * real child is spawned by the test below, where its independence can be observed from
     * the outside.
     */
    public static function test_two_processes_get_two_private_sockets_that_do_not_interfere()
    {
        $first_socket = null;
        $second_socket = null;

        try {
            Rsx_Node_Service::ensure();

            $first_socket = Rsx_Node_Service::socket_path();
            $first_pids = self::__daemon_pids($first_socket);
            static::__assert_count(1, $first_pids, 'exactly one daemon on the first process socket');

            // Hold the first daemon's Symfony handle in a local: ensure() below replaces the
            // class's handle, and dropping the last reference to a Process DESTRUCTS it,
            // which calls stop(0) and kills the very daemon this test is about to prove
            // survives.
            $first_handle = Rsx_Node_Service::get_process();
            static::__assert_not_null($first_handle, 'the first ensure published a process handle');

            // What a SECOND php process is: no socket name, no latch.
            self::__become_a_fresh_process();

            Rsx_Node_Service::ensure();

            $second_socket = Rsx_Node_Service::socket_path();
            $second_pids = self::__daemon_pids($second_socket);

            static::__assert_not_equals(
                $first_socket,
                $second_socket,
                'each process must mint its OWN socket name - a shared name is the kill race'
            );
            static::__assert_count(1, $second_pids, 'exactly one daemon on the second process socket');
            static::__assert_not_equals(
                $first_pids[0],
                $second_pids[0],
                'the second process must spawn its own daemon rather than adopt the first one'
            );

            // The first daemon is untouched and still serving: nothing about the second
            // process reached it.
            static::__assert_count(
                1,
                self::__daemon_pids($first_socket),
                'the first daemon must still be running - a second process must never reap it'
            );
            static::__assert_true(
                self::__raw_ping($first_socket),
                'the first daemon must still ANSWER after another process started its own'
            );

            // And stopping the second must not disturb the first.
            Rsx_Node_Service::stop(force: true);

            static::__assert_count(0, self::__daemon_pids($second_socket), 'the second daemon is stopped');
            static::__assert_true(
                self::__raw_ping($first_socket),
                'stopping one process\'s service must leave every other process\'s service serving'
            );
        } finally {
            Rsx_Node_Service::stop(force: true);
            self::__reap_socket($first_socket);
            self::__reap_socket($second_socket);
            unset($first_handle);
        }
    }

    /**
     * The same property with a REAL second PHP process, observed from outside.
     *
     * A child artisan run that uses the node service (a babel transform of a file it has
     * never seen, so the transform cache cannot answer it) spawns and reaps its own private
     * daemon. Ours must be exactly where we left it: same pid, still answering.
     */
    public static function test_a_real_child_php_process_does_not_disturb_this_process_daemon()
    {
        $probe_file = storage_path('rsx-tmp/node-service-lifecycle-child-probe.js');

        try {
            Rsx_Node_Service::ensure();

            $socket = Rsx_Node_Service::socket_path();
            $pid = self::__daemon_pids($socket)[0] ?? null;
            static::__assert_not_null($pid, 'a daemon of our own to protect');

            // Unique content, so the babel disk cache cannot answer and the child genuinely
            // has to start a node service of its own.
            file_put_contents(
                $probe_file,
                "// " . random_hash(8) . "\nclass Child_Probe { static make() { return new Child_Probe(); } }\n"
            );

            $output = [];
            $exit_code = Rsx_Artisan::run('rsx:js:transform', [$probe_file], $output);

            static::__assert_equals(
                0,
                $exit_code,
                'the child artisan run must succeed: ' . implode("\n", $output)
            );

            static::__assert_equals(
                [$pid],
                self::__daemon_pids($socket),
                'a whole other PHP process came and went - our daemon must be untouched'
            );
            static::__assert_true(
                self::__raw_ping($socket),
                'our daemon must still answer after another process ran a full service lifecycle'
            );
        } finally {
            @unlink($probe_file);
            Rsx_Node_Service::stop(force: true);
        }
    }

    /**
     * IDLE SUICIDE: the daemon retires itself once nothing is using it.
     *
     * This is the orphan insurance. A daemon whose parent was SIGKILLed holds a socket name
     * nobody will ever learn, so nothing else can ever reach it - the only thing that can
     * end it is itself. Proved with the test seam (--idle-exit-ms, which the PHP side never
     * passes) so the window is observable in under a second instead of two minutes.
     */
    public static function test_an_idle_service_exits_on_its_own_and_unlinks_its_socket()
    {
        $socket = storage_path('rsx-tmp/node-service-idle-exit-probe.sock');
        $daemon = null;

        try {
            // The daemon's idle clock starts at BOOT, before this process can speak to it, so
            // the window must not sit in the critical path of the test process's scheduling: a
            // 300ms window can expire while a loaded box is still getting round to running the
            // readiness ping, and the daemon would then be gone before the test ever proved it
            // was there. 3000ms is long enough to survive that and still short enough that the
            // exit is observed in seconds rather than the two-minute production window.
            $daemon = self::__start_daemon($socket, ['--idle-exit-ms=3000']);

            static::__assert_true(self::__raw_ping($socket), 'the probe daemon answers before it goes idle');

            // The exit is the CONDITION, not an elapsed time: poll for it. Both halves must
            // hold - a dead process that left its socket file behind is not a clean exit.
            self::__poll_until(
                function () use ($socket) {
                    return self::__daemon_pids($socket) === [] && !file_exists($socket);
                },
                'the idle daemon never exited and unlinked its socket (' . $socket . ') - an idle '
                . 'daemon must retire itself, cleanly, or a killed parent leaks it forever'
            );
        } finally {
            if ($daemon !== null) {
                $daemon->stop(0);
            }
            self::__reap_socket($socket);
        }
    }

    /**
     * AND IT MUST NEVER FIRE DURING A REQUEST.
     *
     * A three-minute sass compile with no other traffic is WORKING, not idle. Killing it
     * would be exactly the failure the no-timeout mandate exists to prevent, so the window
     * is armed only when there is no connection, nothing in flight, and nothing completed.
     *
     * WHAT THIS PROVES, exactly: a request that is still ARRIVING keeps the daemon alive.
     * The line is written in two halves with a gap between them that is four times the idle
     * window - longer than an unarmed watchdog would tolerate - and the assertion is that
     * the daemon is still there to answer the second half. (The other half of the property,
     * a handler that runs long after its line was accepted, is held by the same two counters
     * and is not given a test of its own: doing so would mean adding a sleep method to the
     * service, and the service does not get methods that exist for tests.)
     */
    public static function test_the_idle_exit_never_fires_while_a_request_is_still_arriving()
    {
        $socket = storage_path('rsx-tmp/node-service-idle-midrequest-probe.sock');
        $daemon = null;
        $client = null;

        try {
            // 3000ms for the same reason as the idle-exit test above: the idle clock starts at
            // boot, and a 300ms window could expire between the readiness pong and this
            // process getting round to connect() on a loaded box.
            $daemon = self::__start_daemon($socket, ['--idle-exit-ms=3000']);

            $client = stream_socket_client('unix://' . $socket, $errno, $errstr, 1);
            static::__assert_true((bool) $client, 'connected to the probe daemon: ' . $errstr);
            stream_set_blocking($client, true);

            // Half a request line, then silence. This fixed wait is legitimate: it proves a
            // NEGATIVE (the daemon did not exit during the interval), which inherently needs a
            // duration. The duration is derived, not a multiplier: the watchdog ticks every
            // idle_exit_ms / 4 = 750ms, so one full window (3000ms) plus two ticks (1500ms) is
            // the point past which the watchdog has certainly observed the window elapsed and
            // decided NOT to exit. 4500ms is that proof; "four windows" was never the property.
            fwrite($client, '{"id":1,"method":"pi');
            usleep(4500000);

            static::__assert_count(
                1,
                self::__daemon_pids($socket),
                'the daemon must still be alive mid-request - a request in progress is WORK, never idle'
            );

            // Now complete the line: it must still be understood and answered.
            fwrite($client, "ng\"}\n");
            $response = fgets($client);

            static::__assert_contains(
                'pong',
                (string) $response,
                'the half-sent request must complete normally once the rest of it arrives'
            );
            static::__assert_count(
                1,
                self::__daemon_pids($socket),
                'the daemon must still be alive at the moment it answers'
            );
        } finally {
            if (is_resource($client)) {
                fclose($client);
            }
            if ($daemon !== null) {
                $daemon->stop(0);
            }
            self::__reap_socket($socket);
        }
    }

    /**
     * TRANSPARENT RESPAWN - the safety half of the idle exit.
     *
     * A long artisan run whose two service uses are more than the idle window apart
     * legitimately finds its private daemon gone. The public door must absorb that: mint a
     * fresh socket, spawn, retry once, answer. SIGKILL is used here because it produces the
     * same observable state as the idle exit (nothing listening) plus the harder half - a
     * stale socket FILE left behind, which an idle exit would have cleaned up.
     */
    public static function test_a_request_respawns_the_service_when_its_daemon_is_gone()
    {
        try {
            Rsx_Node_Service::ensure();

            $dead_socket = Rsx_Node_Service::socket_path();
            $dead_pid = self::__daemon_pids($dead_socket)[0] ?? null;
            static::__assert_not_null($dead_pid, 'a daemon to kill');

            posix_kill($dead_pid, SIGKILL);
            usleep(200000);
            static::__assert_count(0, self::__daemon_pids($dead_socket), 'the daemon is gone');

            // The public door, unchanged, with nothing listening behind it.
            $response = Rsx_Node_Service::request('ping');

            static::__assert_equals(
                'pong',
                $response['result'] ?? null,
                'a request must survive its daemon disappearing - that is what makes the idle exit invisible'
            );

            $new_socket = Rsx_Node_Service::socket_path();

            static::__assert_not_equals(
                $dead_socket,
                $new_socket,
                'the respawn must mint a NEW name - rebinding a path a dead daemon still names in its '
                . 'argv would confuse every pgrep sweep'
            );
            static::__assert_count(1, self::__daemon_pids($new_socket), 'exactly one daemon on the new socket');
            static::__assert_false(
                file_exists($dead_socket),
                'the abandoned socket file must be collected, not left in rsx-tmp forever'
            );
        } finally {
            Rsx_Node_Service::stop(force: true);
        }
    }

    /**
     * LAZY LOADING: a ping must not load a single toolchain.
     *
     * babel, sass, terser, postcss and @jqhtml/parser each cost real time and real memory to
     * require. Loading them all at boot would make every spawn - including the spawn a
     * fully-cached build performs for one uncached file - pay for all eight subsystems.
     */
    public static function test_a_ping_loads_no_subsystem_at_all()
    {
        try {
            Rsx_Node_Service::stop(force: true);
            Rsx_Node_Service::ensure();

            $state = Rsx_Node_Service::introspect();

            static::__assert_equals(
                [],
                $state['loaded'],
                'a freshly started, pinged-only service must have loaded NO subsystem - it reported: '
                . implode(', ', $state['loaded'])
            );
            static::__assert_equals(
                self::__sorted(array_keys(Rsx_Node_Service::module_paths())),
                self::__sorted($state['registered']),
                'the service must register exactly the subsystems the shared registry names'
            );
        } finally {
            Rsx_Node_Service::stop(force: true);
        }
    }

    /**
     * ONE PROCESS SERVES EVERY SUBSYSTEM - the whole point of the consolidation - and each
     * one is loaded only when it is first used.
     */
    public static function test_one_process_serves_two_subsystems_loading_each_on_first_use()
    {
        $output_file = storage_path('rsx-tmp/node-service-lifecycle-test-concat.js');

        try {
            Rsx_Node_Service::stop(force: true);
            Rsx_Node_Service::ensure();

            $socket = Rsx_Node_Service::socket_path();
            $pid = self::__daemon_pids($socket)[0] ?? null;
            static::__assert_not_null($pid, 'exactly one node service to talk to');

            Concatenator::concat_js(
                [['path' => base_path('artisan'), 'source' => null]],
                $output_file
            );

            $after_concat = Rsx_Node_Service::introspect();
            static::__assert_equals(
                ['concat'],
                $after_concat['loaded'],
                'a concat-only session must load concat and NOTHING else (no sass, no babel, no terser)'
            );

            Minifier::minify_js("var lifecycle_probe = 1;\n", 'lifecycle-probe.js');

            $after_minify = Rsx_Node_Service::introspect();
            static::__assert_equals(
                ['concat', 'minify'],
                self::__sorted($after_minify['loaded']),
                'minify must load beside concat rather than in a second process'
            );

            static::__assert_equals(
                $pid,
                $after_minify['pid'],
                'both subsystems must be served by the SAME process - a second pid is the old architecture'
            );
            static::__assert_count(
                1,
                self::__daemon_pids($socket),
                'there must still be exactly one node daemon after two different subsystems were used'
            );
        } finally {
            @unlink($output_file);
            Rsx_Node_Service::stop(force: true);
        }
    }

    /**
     * The registry is the ONE list both sides read, so every path it names must exist. A
     * typo here is a runtime failure in whichever subsystem it names, at whatever moment
     * that subsystem is first used - which can be days after the typo was committed.
     */
    public static function test_every_registered_subsystem_module_exists_on_disk()
    {
        $modules = Rsx_Node_Service::module_paths();

        static::__assert_not_empty($modules, 'the subsystem registry must not be empty');

        foreach ($modules as $prefix => $relative) {
            static::__assert_true(
                is_file(base_path($relative)),
                "the {$prefix} subsystem module is registered but missing from disk: {$relative}"
            );
        }

        static::__assert_true(
            is_file(Rsx_Node_Service::entry_script_path()),
            'the node service entry script must exist: ' . Rsx_Node_Service::entry_script_path()
        );
    }

    /**
     * stop() must mean STOPPED: the process gone and the socket removed. The old
     * implementation sent a message and hoped.
     */
    public static function test_stop_actually_stops_the_service()
    {
        try {
            Rsx_Node_Service::ensure();

            $socket = Rsx_Node_Service::socket_path();
            static::__assert_count(1, self::__daemon_pids($socket), 'a service to stop');

            Rsx_Node_Service::stop();

            static::__assert_count(0, self::__daemon_pids($socket), 'the process must be gone');
            static::__assert_false(file_exists($socket), 'the socket file must be gone');
        } finally {
            Rsx_Node_Service::stop(force: true);
        }
    }

    /**
     * quiesce_all() is what rsx:clean calls before it wipes rsx-tmp. It knows nothing about
     * WHICH daemon it is killing - it matches on the socket directory in argv - so it takes
     * down every process's private node service, the SSR server, a stray left over from an
     * older framework release, and anything added later, and reports how many.
     */
    public static function test_quiesce_all_takes_down_every_daemon_in_the_socket_directory()
    {
        $decoy_socket = storage_path('rsx-tmp/node-service-lifecycle-test-decoy.sock');
        $decoy = null;

        try {
            Rsx_Node_Service::ensure();

            $socket = Rsx_Node_Service::socket_path();
            static::__assert_count(1, self::__daemon_pids($socket), 'the node service is running');

            // A second, unrelated node daemon on its own socket in the same directory - the
            // SSR server's shape, and the shape of another process's private service.
            // quiesce_all must take it too, without having been taught it exists.
            $decoy = self::__start_daemon($decoy_socket);
            static::__assert_count(1, self::__daemon_pids($decoy_socket), 'the decoy daemon is running');

            $killed = Rsx_Node_Service::quiesce_all();

            static::__assert_greater_than(
                1,
                $killed,
                'quiesce_all must report the daemons it reaped - at least the two started here'
            );
            static::__assert_count(0, self::__daemon_pids($socket), 'the node service must be gone');
            static::__assert_count(0, self::__daemon_pids($decoy_socket), 'the decoy must be gone too');
        } finally {
            if ($decoy !== null) {
                $decoy->stop(0);
            }
            self::__reap_socket($decoy_socket);
            Rsx_Node_Service::stop(force: true);
        }
    }

    /**
     * The readiness budget is a DECLARED number, not an accident. Pinned so that moving it
     * is a deliberate edit with a reason, in both places it appears (the config default and
     * the inline default in the config() call in Rsx_Node_Service::ensure()).
     */
    public static function test_the_ready_wait_budget_is_the_declared_default()
    {
        static::__assert_equals(
            20000,
            (int) config('rsx.javascript.rpc_server_ready_wait_ms'),
            'the node service readiness budget is 20s - it bounds a wait on an external party and '
            . 'degrades to a loud, evidence-carrying error'
        );
    }

    /**
     * Start a node service daemon directly and wait for it to bind, returning the handle.
     *
     * @param string[] $extra_args
     */
    private static function __start_daemon(string $socket_path, array $extra_args = []): \Symfony\Component\Process\Process
    {
        $daemon = new \Symfony\Component\Process\Process(array_merge([
            'node',
            Rsx_Node_Service::entry_script_path(),
            '--socket=' . $socket_path,
        ], $extra_args));

        $daemon->setWorkingDirectory(base_path());
        $daemon->setTimeout(null);
        $daemon->start();

        self::__wait_for_daemon($socket_path);

        return $daemon;
    }

    /**
     * Wait for a daemon we started ourselves to become READY.
     *
     * THE PONG IS READINESS. A live pid and a socket file on disk only say the process got as
     * far as binding; a daemon that answers ping has finished booting and is serving. The
     * weaker precondition is therefore not checked separately - it cannot hold while the
     * stronger one does not.
     */
    private static function __wait_for_daemon(string $socket_path): void
    {
        self::__poll_until(
            function () use ($socket_path) {
                return self::__raw_ping($socket_path);
            },
            'a test daemon never answered ping on ' . $socket_path
        );
    }

    /**
     * Poll until $condition holds, or fail naming the condition that never came true.
     *
     * THE HOUSE WAIT. Tests assume EXTREME resource contention: they prove ORDER and
     * EXECUTION, never elapsed time, so every wait is a poll rather than a fixed sleep, and
     * the bound is generous enough that only a genuinely stuck condition can reach it.
     *
     * SANCTIONED BOUND (no-timeout mandate): 120 seconds bounds a wait on an EXTERNAL process
     * this test does not control, and expiry does not truncate any work - it fails the test
     * loudly, naming the condition that was never reached. Without it a broken daemon would
     * hang the suite forever with no evidence at all.
     */
    private static function __poll_until(callable $condition, string $condition_description): void
    {
        $max_wait_ms = 120000;
        $interval_ms = 25;
        $deadline = microtime(true) + ($max_wait_ms / 1000);

        do {
            if ($condition()) {
                return;
            }

            usleep($interval_ms * 1000);
        } while (microtime(true) < $deadline);

        static::__fail(
            $condition_description . ' (waited ' . ($max_wait_ms / 1000) . 's)'
        );
    }

    /**
     * Ping a socket directly, without going through the class's own per-process state - the
     * only way to ask "is THAT daemon, the one this process no longer addresses, still
     * serving?".
     */
    private static function __raw_ping(string $socket_path): bool
    {
        if (!file_exists($socket_path)) {
            return false;
        }

        $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
        if (!$socket) {
            return false;
        }

        stream_set_blocking($socket, true);
        fwrite($socket, json_encode(['id' => 1, 'method' => 'ping']) . "\n");
        $response = fgets($socket);
        fclose($socket);

        $decoded = json_decode((string) $response, true);

        return is_array($decoded) && ($decoded['result'] ?? null) === 'pong';
    }

    /**
     * Take down whatever holds $socket_path and remove the file. Cleanup only - the tests
     * assert on the real reapers.
     */
    private static function __reap_socket(?string $socket_path): void
    {
        if ($socket_path === null) {
            return;
        }

        foreach (self::__daemon_pids($socket_path) as $pid) {
            @posix_kill($pid, SIGKILL);
        }

        @unlink($socket_path);
    }

    /**
     * A copy of $values, sorted - so an assertion compares contents rather than arrival order.
     */
    private static function __sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * Live node daemons bound to $socket_path, by pid.
     *
     * pgrep -a gives the command line as well as the pid, which is what lets this exclude
     * the shell running the pgrep itself - that shell's command line necessarily contains
     * the pattern being searched for.
     *
     * @return int[]
     */
    private static function __daemon_pids(string $socket_path): array
    {
        $output = [];
        $return_var = 0;

        exec_safe('pgrep -a -f -- ' . escapeshellarg('--socket=' . $socket_path), $output, $return_var);

        $pids = [];

        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode(' ', $line, 2);
            $pid = (int) $parts[0];
            $command_line = $parts[1] ?? '';

            if ($pid <= 0 || str_contains($command_line, 'pgrep') || !str_contains($command_line, 'node ')) {
                continue;
            }

            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * Put this process into the state a BRAND NEW php process is in: no minted socket name,
     * no latch, no handle - WITHOUT touching the Symfony handle it holds, since dropping
     * that handle destructs it and kills the daemon we are trying to prove survives.
     *
     * It is the only honest way to hold two processes' worth of service state alive at once
     * inside a single test process.
     */
    private static function __become_a_fresh_process(): void
    {
        foreach (['socket_path' => null, 'ensured' => false] as $name => $value) {
            $property = new ReflectionProperty(Rsx_Node_Service::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
