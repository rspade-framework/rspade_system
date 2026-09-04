<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\JsParsers;

use Symfony\Component\Process\Process;
use App\RSpade\Core\JsParsers\Rpc_Startup_Diagnostics;
use App\RSpade\Core\Locks\RsxLocks;

/**
 * THE node service: one daemon per PHP process, one socket, one lifecycle, eight subsystems.
 *
 * Every JavaScript toolchain the PHP build needs - the parser, babel, terser/cssnano, the
 * sourcemap-merging concatenator, sass, the jqhtml template compiler, the code-quality
 * sanitizer and the code-quality linter - is served by ONE node daemon reached through
 * request(). The eight thin PHP clients (Js_Parser, Js_Transformer, Minifier, Concatenator,
 * Scss_Compiler, JqhtmlWebpackCompiler, FileSanitizer, Js_CodeQuality_Rpc) keep their own
 * request marshaling, their own caches and their own error vocabulary; they own no
 * lifecycle at all.
 *
 * WHY ONE PER PROCESS. There were eight daemons, one per concern, each with its own socket,
 * its own spawn check and its own V8 heap. Compiles are strictly sequential - there is only
 * ever one compiling / manifest-rebuilding process at a time - so eight processes bought no
 * parallelism, and on CLI they were pure waste: Symfony's Process destructor calls stop(0)
 * at exit, so a single artisan run spawned and then immediately killed up to eight node
 * processes. One service is one spawn, one socket, one thing for rsx:clean and the
 * maintenance window to reap.
 *
 * THE SOCKET IS PRIVATE TO THIS PROCESS. Its name carries a random component minted once,
 * lazily, per PHP process: storage/rsx-tmp/node-service-<random>.sock. Nobody else can ever
 * learn that name, so nobody else can ever kill, rebind or "collect" the daemon this process
 * is mid-request on. That is the whole point: a well-known socket shared between processes
 * has a real race in it - process B's ensure() reaps the daemon process A is using, and two
 * cold starts fight over the same path.
 *
 * FRESHNESS IS TRUE BY CONSTRUCTION. A daemon is always spawned from the source and toolchain
 * on disk AT SPAWN TIME by its own parent, and its lifetime is a subset of that parent's. No
 * process ever inherits a daemon it did not start, so there is nothing to validate at all.
 *
 * The socket still lives under storage/rsx-tmp/ deliberately: quiesce_all(),
 * bin/maintenance-mode.sh and rsx:clean all sweep that directory by argv match
 * ('--socket=<dir>/'), so every daemon is reachable by the operational sweeps without any of
 * them knowing a name.
 *
 * PROCESS LIFETIME, as MEASURED (2026-08-13) rather than assumed - the two contexts differ:
 *
 *   - CLI: Symfony's Process destructor calls stop(0), so a service spawned by an artisan
 *     run is killed when that process exits. Verified directly.
 *   - php-fpm: the worker outlives the request, and the service it spawned keeps serving that
 *     worker's later requests - still privately, since the socket name lives in the worker's
 *     own static state.
 *
 * Orphans come from the ABRUPT deaths neither path covers - a SIGKILLed worker, an
 * interrupted build. Two things answer that: the daemon's own idle suicide (it exits after
 * 120s with nothing in flight and nothing completed - see resource/node-service.js), and
 * request()'s transparent respawn, which makes that suicide invisible to a live parent.
 *
 * @see Rpc_Startup_Diagnostics for what the service says when it will not come up.
 * @see resource/node-service.js for the protocol, the lazy-loading rule and the idle exit.
 */
class Rsx_Node_Service
{
    /**
     * Node entry script, relative to base_path().
     */
    public const ENTRY_SCRIPT = 'app/RSpade/Core/JsParsers/resource/node-service.js';

    /**
     * Subsystem registry - the ONE list PHP and node both read. node dispatches
     * '<prefix>.<method>' through it; PHP reads it so the two lists can never drift.
     */
    public const MODULE_REGISTRY = 'app/RSpade/Core/JsParsers/resource/node-service-modules.json';

    /**
     * Directory holding every daemon socket, as a PROJECT-relative 'storage/...' path.
     *
     * Under storage/rsx-tmp/ deliberately: quiesce_all(), bin/maintenance-mode.sh and
     * rsx:clean all sweep that directory by socket-path match, and rsx:clean reaps before
     * it wipes (unlinking a socket under a running daemon strands it forever).
     */
    public const SOCKET_DIR = 'storage/rsx-tmp';

    /**
     * Filename prefix every node-service socket carries. The rest of the name is random.
     */
    public const SOCKET_PREFIX = 'node-service-';

    /**
     * Human name used in diagnostics.
     */
    public const LABEL = 'Node Service';

    /**
     * This process's private socket path, minted on first use.
     */
    private static ?string $socket_path = null;

    /**
     * The live service handle this process published, or null.
     */
    private static ?Process $process = null;

    /**
     * Has this process already proven the service is serving?
     */
    private static bool $ensured = false;

    /**
     * Request id counter for the whole service.
     */
    private static int $request_id = 0;

    /**
     * Is a request already inside its one transparent-respawn retry?
     */
    private static bool $retrying = false;

    /**
     * Guarantee THIS PROCESS has a service running and answering.
     *
     * Always a fresh spawn on a socket name nobody else knows, so there is no reuse branch,
     * nothing to validate and nothing to collect first.
     *
     * @throws \RuntimeException when the service does not answer within the configured budget
     */
    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        $socket_path = self::socket_path();
        $entry_script = self::entry_script_path();

        if (!file_exists($entry_script)) {
            throw new \RuntimeException(self::LABEL . ' entry script not found at ' . $entry_script);
        }

        $socket_dir = dirname($socket_path);
        if (!is_dir($socket_dir)) {
            mkdir($socket_dir, 0755, true);
        }

        console_debug('RPC', self::LABEL . ': starting ' . $entry_script);

        // The lock fds MUST NOT reach this daemon. It can outlive the process that spawns it,
        // and a POSIX flock lives on the open file description - so an inherited lock fd
        // would be held for as long as the node process idles, wedging every future build and
        // (because Manifest::init() locks at boot) the whole CLI with it. See
        // RsxLocks::inherited_lock_fds() for the measured incident.
        $process = new Process(RsxLocks::command_without_inherited_locks([
            'node',
            $entry_script,
            '--socket=' . $socket_path,
        ]));

        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null); // A service runs for as long as it is useful.
        $process->start();

        $max_wait_ms = (int) config('rsx.javascript.rpc_server_ready_wait_ms', 20000);
        $wait_interval_ms = 25;
        $iterations = (int) ($max_wait_ms / $wait_interval_ms);

        for ($i = 0; $i < $iterations; $i++) {
            usleep($wait_interval_ms * 1000);

            if (self::ping()) {
                // Published only once the service is SERVING. Publishing before the wait meant
                // a failed start left a non-null handle, so every later caller saw "already
                // started", skipped the spawn, and died at "socket not found" instead
                // (field report, 2026-08-11: one slow boot, six failed bundles).
                self::$process = $process;
                self::$ensured = true;
                console_debug('RPC', self::LABEL . ': ready after ' . ($i * $wait_interval_ms) . 'ms');

                return;
            }
        }

        // Build the diagnosis BEFORE killing anything: whether the process is still RUNNING
        // (slow) or has EXITED (with its own stderr) is the single most useful fact in the
        // message, and stopping it first would report every slow start as a crash.
        $message = Rpc_Startup_Diagnostics::failure_message(
            self::LABEL,
            $socket_path,
            $entry_script,
            $max_wait_ms,
            $process
        );

        // A failed start must leave NO trace - no handle, no socket - so the next use is a
        // clean retry.
        $process->stop(0);
        self::__self_heal_kill();

        throw new \RuntimeException($message);
    }

    /**
     * The ONE request door.
     *
     * $method is '<prefix>.<method>' (or a top-level 'ping'/'introspect'/'shutdown'); the
     * payload is merged into the request line. Returns the decoded response, whose shape is
     * the subsystem's own - callers read $response['results'] or $response['result'] exactly
     * as they always did.
     *
     * NO TIMEOUT anywhere in here. A request that queues behind a large one already in
     * flight is waiting on REAL WORK, and slowness is never evidence of a hang; the reads
     * have always been unbounded too.
     *
     * TRANSPARENT RESPAWN. The daemon exits after 120 idle seconds (its orphan insurance -
     * see resource/node-service.js), so a long artisan run whose two service uses are more
     * than that far apart legitimately finds its private daemon gone. When the socket cannot
     * be reached, this mints a NEW private socket, spawns a new daemon, and retries the
     * request EXACTLY ONCE; a second failure throws the original legible error. One ~40ms
     * respawn, never a failure mode.
     *
     * @throws \RuntimeException on connect failure, no response, or an undecodable response
     */
    public static function request(string $method, array $payload = []): array
    {
        self::ensure();

        $socket_path = self::socket_path();

        $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, null);
        if (!$socket) {
            $failure = self::LABEL . ": failed to connect for {$method} ({$socket_path}): {$errstr}";

            return self::__respawn_and_retry($method, $payload, $failure);
        }

        stream_set_blocking($socket, true);

        self::$request_id++;
        $request = json_encode(array_merge($payload, [
            'id' => self::$request_id,
            'method' => $method,
        ])) . "\n";

        fwrite($socket, $request);

        $response = fgets($socket);
        fclose($socket);

        if (!$response) {
            // The peer vanished between the connect and the answer - the same dead-daemon
            // case as a refused connect, reached one step later.
            $failure = self::LABEL . ": no response to {$method}";

            return self::__respawn_and_retry($method, $payload, $failure);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                self::LABEL . ": invalid response to {$method}: " . substr((string) $response, 0, 500)
            );
        }

        return $decoded;
    }

    /**
     * Is the service answering?
     */
    public static function ping(): bool
    {
        $socket_path = self::socket_path();

        if (!file_exists($socket_path)) {
            return false;
        }

        try {
            $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
            if (!$socket) {
                return false;
            }

            stream_set_blocking($socket, true);

            self::$request_id++;
            fwrite($socket, json_encode([
                'id' => self::$request_id,
                'method' => 'ping',
            ]) . "\n");

            $response = fgets($socket);
            fclose($socket);

            if (!$response) {
                return false;
            }

            $result = json_decode($response, true);

            return isset($result['result']) && $result['result'] === 'pong';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * What the running service has actually loaded.
     *
     * The lazy-loading proof (a ping must not load babel or sass), and the hook a future
     * health probe reads.
     *
     * @return array {pid, socket, loaded[], registered[]}
     */
    public static function introspect(): array
    {
        $response = self::request('introspect');

        if (!isset($response['result']) || !is_array($response['result'])) {
            throw new \RuntimeException(self::LABEL . ': introspect returned no result');
        }

        return $response['result'];
    }

    /**
     * Stop THIS PROCESS's service, and mean it.
     *
     * Asks politely first (the socket `shutdown` method), then reaps by pid - because the
     * polite half cannot reach a daemon whose socket inode was unlinked, which is precisely
     * the daemon most in need of stopping. $force skips the polite half.
     */
    public static function stop(bool $force = false): void
    {
        if (!$force) {
            self::__send_shutdown();
        }

        self::__self_heal_kill();
    }

    /**
     * Force a brand new service for this process: stop the one it has, then start one on a
     * fresh private socket.
     */
    public static function force_restart(): void
    {
        self::stop(force: true);
        self::__mint_socket_path();
        self::ensure();
    }

    /**
     * The service handle this process published, or null.
     *
     * Exists so a caller (the lifecycle tests) can assert what was published without
     * reaching into private state. Null means "this process has not published a service",
     * which is a normal state - it says nothing about whether one is running.
     */
    public static function get_process(): ?Process
    {
        return self::$process;
    }

    /**
     * Kill EVERY node daemon bound to a socket under storage/rsx-tmp, whatever it is, and
     * return how many were reaped.
     *
     * The PHP twin of the bash reaper in bin/maintenance-mode.sh. It exists because of the
     * rule in bin/CLAUDE.md: any framework operation that changes a socket or state
     * directory must reap the daemons bound to the previous one. rsx:clean is that
     * operation - it unlinks every socket in rsx-tmp, which strands every daemon behind
     * them permanently.
     *
     * The match is on argv, not on identity: the socket path a daemon was launched with is
     * in its command line and never changes, so this catches every process's private node
     * service, the SSR server, a stray daemon left over from an older framework release, and
     * anything added later - without knowing they exist. Killing them is free: they hold no
     * state and are respawned on demand by the next process that needs one.
     */
    public static function quiesce_all(): int
    {
        $socket_dir = rtrim(storage_path('rsx-tmp'), '/');

        $pids = self::__pgrep_node_daemons('--socket=' . $socket_dir . '/');
        $killed = self::__term_then_kill($pids);

        // Whatever this process knew about a running service is no longer true.
        self::$process = null;
        self::$ensured = false;

        return $killed;
    }

    /**
     * Absolute path of THIS PROCESS's private unix socket, minted on first use.
     *
     * The random component is what makes the daemon unreachable by any other process, and
     * therefore unkillable by one. It is stable for the life of the process (so ensure(),
     * ping(), request() and stop() all address the same daemon) and is re-minted only by
     * force_restart() and the transparent respawn.
     */
    public static function socket_path(): string
    {
        if (self::$socket_path === null) {
            self::__mint_socket_path();
        }

        return self::$socket_path;
    }

    /**
     * Absolute path of the node entry script.
     */
    public static function entry_script_path(): string
    {
        return base_path(self::ENTRY_SCRIPT);
    }

    /**
     * Registered subsystem modules, as prefix => path relative to base_path().
     *
     * Read from the SAME registry file node reads, so the two can never drift.
     *
     * @return array<string, string>
     */
    public static function module_paths(): array
    {
        $registry_file = base_path(self::MODULE_REGISTRY);

        if (!file_exists($registry_file)) {
            throw new \RuntimeException(
                self::LABEL . ': subsystem registry not found at ' . $registry_file
                . ' - the framework tree is incomplete.'
            );
        }

        $registry = json_decode((string) file_get_contents($registry_file), true);

        if (!is_array($registry) || !isset($registry['modules']) || !is_array($registry['modules'])) {
            throw new \RuntimeException(
                self::LABEL . ': subsystem registry is malformed (no "modules" map): ' . $registry_file
            );
        }

        return $registry['modules'];
    }

    /**
     * Mint a brand new private socket name for this process.
     *
     * Short random component on purpose: a unix socket path is capped at ~107 bytes by the
     * kernel, and this one has to fit under whatever storage root the install uses.
     */
    private static function __mint_socket_path(): void
    {
        self::$socket_path = rsx_project_file_path(
            self::SOCKET_DIR . '/' . self::SOCKET_PREFIX . random_hash(8) . '.sock'
        );
    }

    /**
     * Reset this process's service state, spawn a fresh daemon on a FRESH socket name, and
     * run the request again - exactly once.
     *
     * The old socket name is never rebound: the daemon that used to hold it may be alive
     * but unreachable (its socket file unlinked), and binding a path a live process still
     * names in its argv would confuse every pgrep-based sweep.
     *
     * @throws \RuntimeException with the ORIGINAL failure when the retry also fails
     */
    private static function __respawn_and_retry(string $method, array $payload, string $failure): array
    {
        if (self::$retrying) {
            throw new \RuntimeException($failure);
        }

        // Collect whatever is left of the old private daemon before abandoning its name. It
        // is unreachable by definition (the connect we just made failed), it is OURS, and its
        // socket file would otherwise sit in rsx-tmp forever.
        self::__self_heal_kill();
        self::__mint_socket_path();

        self::$retrying = true;

        try {
            return self::request($method, $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException($failure, 0, $e);
        } finally {
            self::$retrying = false;
        }
    }

    /**
     * TERM, one settle pass, KILL - then remove the socket.
     *
     * Narrow by construction: it matches only a node process whose command line names THIS
     * process's own socket path, so no other process's daemon can be touched.
     */
    private static function __self_heal_kill(): void
    {
        $socket_path = self::socket_path();

        $pids = self::__pgrep_node_daemons('--socket=' . $socket_path);
        $killed = self::__term_then_kill($pids);

        if ($killed > 0) {
            console_debug('RPC', self::LABEL . ': collected ' . $killed . ' stale daemon(s)');
        }

        @unlink($socket_path);

        self::$process = null;
        self::$ensured = false;
    }

    /**
     * Ask the service to shut itself down over its own socket. Best effort by nature: the
     * message cannot reach a daemon whose socket was already unlinked, which is why every
     * caller follows it with the pid-based reap.
     */
    private static function __send_shutdown(): void
    {
        $socket_path = self::socket_path();

        if (!file_exists($socket_path)) {
            return;
        }

        try {
            $socket = @stream_socket_client('unix://' . $socket_path, $errno, $errstr, 1);
            if (!$socket) {
                return;
            }

            stream_set_blocking($socket, true);

            self::$request_id++;
            fwrite($socket, json_encode([
                'id' => self::$request_id,
                'method' => 'shutdown',
            ]) . "\n");

            fclose($socket);
        } catch (\Exception $e) {
            // A daemon that cannot be asked will be taken.
        }
    }

    /**
     * Node daemons whose command line contains $pattern.
     *
     * `pgrep -a` gives us the command line as well as the pid, which is what lets this
     * exclude the shell that is running the pgrep itself - that shell's own command line
     * necessarily contains the pattern we are searching for.
     *
     * @return int[]
     */
    private static function __pgrep_node_daemons(string $pattern): array
    {
        $output = [];
        $return_var = 0;

        // pgrep exits 1 when nothing matched, which is not an error here.
        exec_safe('pgrep -a -f -- ' . escapeshellarg($pattern), $output, $return_var);

        $pids = [];

        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode(' ', $line, 2);
            $pid = (int) $parts[0];
            $command_line = $parts[1] ?? '';

            if ($pid <= 0 || $pid === getmypid()) {
                continue;
            }

            // The pgrep call runs through a shell whose own command line carries the pattern.
            if (str_contains($command_line, 'pgrep')) {
                continue;
            }

            // These daemons are node processes; nothing else in rsx-tmp is ours to kill.
            if (!str_contains($command_line, 'node ')) {
                continue;
            }

            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * The reaper pattern: SIGTERM, ONE settle pass, SIGKILL to whatever is left.
     *
     * The settle is a fixed pause before escalating - bounded local work, not a wait on an
     * external party, and never a deadline imposed on an operation that is making progress.
     * A survivor is a wedged daemon on a socket nobody can reach; there is nothing in it
     * worth preserving.
     *
     * @param  int[] $pids
     * @return int   How many processes were signalled
     */
    private static function __term_then_kill(array $pids): int
    {
        if (!$pids) {
            return 0;
        }

        foreach ($pids as $pid) {
            @posix_kill($pid, SIGTERM);
        }

        usleep(200000); // 200ms settle: this is a cooperative server that closes on SIGTERM.

        foreach ($pids as $pid) {
            if (@posix_kill($pid, 0)) {
                @posix_kill($pid, SIGKILL);
            }
        }

        return count($pids);
    }
}
