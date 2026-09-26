<?php

namespace App\RSpade\Core\Locks;

use RuntimeException;
use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Locks\Lockd_Connection;

/**
 * The RsxLocks transport to rsx-lockd (system/bin/rsx-lockd), the lock daemon whose
 * CONNECTION IS THE LOCK: a static facade over ONE Lockd_Connection per script execution.
 * The socket, framing, hello, response correlation and death handling are
 * Lockd_Connection's; every lock semantic lives in RsxLocks above and the daemon below.
 *
 * THE CONNECTION MODEL - one connection per SCRIPT EXECUTION, not per process:
 *
 *   Opened lazily on the first cluster-lock request, every lock multiplexed over it, held
 *   open for the rest of the script even while holding nothing, closed at the end. It must
 *   NOT be persistent across requests: a PHP-FPM worker serves thousands of requests, and a
 *   socket that outlived a request would carry that request's locks into the next one on
 *   the same worker - and the daemon would be right to keep holding them, because from its
 *   side the holder never went away.
 *
 *   The hello names this process's lock group (current_group_id()), so a synchronous
 *   subprocess inherits what its waiting parent holds.
 *
 *   The shutdown handler sends `release_all` and closes, but correctness does NOT depend on
 *   that frame arriving: closing the socket releases everything by itself, which is exactly
 *   why a `kill -9`'d holder frees its locks instantly.
 *
 * WAITING IS SILENCE. A blocking acquire receives NO frame until it is granted or fails, and
 * the read under it has NO timeout - see Lockd_Connection.
 *
 * This is not the only connection a process may hold: a task worker also holds Task_Pool's,
 * which is independent of this one in every respect (no group, no release_all).
 */
class Lockd_Client
{
    /**
     * The internal flag a parent uses to hand its lock group to a synchronous subprocess.
     * Rsx_Artisan attaches it; nothing else should. See current_group_id().
     */
    public const LOCK_GROUP_FLAG = '--_lock-group';

    /** The one connection for this script execution; created on first use. */
    private static ?Lockd_Connection $connection = null;

    /** Test/tooling seam - see _use_endpoint(). */
    private static ?array $endpoint_override = null;

    private static bool $shutdown_registered = false;

    /**
     * Point this client at a specific daemon for the rest of the process, overriding config.
     * The seam a test (or a tool driving a scratch daemon on another port) uses; production
     * code never calls it. Closes any existing connection so the next request redials.
     */
    public static function _use_endpoint(string $host, int $port): void
    {
        self::close();
        self::$endpoint_override = ['host' => $host, 'port' => $port];
        self::$connection?->use_endpoint($host, $port);
    }

    /** True when a socket to the daemon is currently open. */
    public static function is_connected(): bool
    {
        return self::$connection !== null && self::$connection->is_connected();
    }

    /**
     * rsx:health probe: is the lock daemon listening? A public static
     * `#[Health_Check('label')]` (bare marker attribute - never a defined class). It opens
     * (and immediately closes) a TCP connection to the configured endpoint - a read-only
     * liveness probe that says no hello and therefore needs no key.
     *
     * A down daemon is a FAIL, not a WARN: there is no degraded mode. Outside maintenance
     * every cluster lock throws, which means a site write lock throws, which means the app
     * is down. The ONE exception is a raised maintenance flag, where the daemon is stopped
     * on purpose and cluster locks degrade to flock - that reports INFO, because a health
     * check that screams about a service you deliberately turned off is noise.
     *
     * The maintenance test reads the flag off DISK rather than the per-process snapshot:
     * this is a probe reporting the state of the box right now, not a consumer that needs
     * a stable answer for the life of the process.
     *
     * @return array
     */
    #[Health_Check('Lock Server')]
    public static function lock_server(): array
    {
        $endpoint = self::__endpoint();

        if (Framework_Maintenance::is_active_on_disk()) {
            return [
                'status' => 'INFO',
                'detail' => 'the maintenance flag is up - rsx-lockd is stopped on purpose'
                    . ' (cluster locks degraded to flock)',
            ];
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($endpoint['host'], $endpoint['port'], $errno, $errstr, 2);

        if ($socket === false) {
            return [
                'status' => 'FAIL',
                'detail' => 'rsx-lockd not listening on ' . $endpoint['host'] . ':' . $endpoint['port']
                    . ' (' . trim($errstr) . ') - every cluster lock will throw',
                'remediation' => 'start the lock daemon - check supervisor [program:rsx-lockd]'
                    . ' (bash system/bin/rsx-lockd/lockd-run.sh)',
            ];
        }

        // A listening port is not a serving daemon: a wedged event loop, or anything else
        // squatting the port, accepts the connection and then answers nothing. `ping` is
        // answered PRE-HELLO precisely so a health probe can prove the daemon is serving
        // frames without holding APP_KEY, so ask it rather than trusting the accept.
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, 2);
        @fwrite($socket, json_encode(['id' => 1, 'op' => 'ping']) . "\n");
        $line = @fgets($socket);
        $timed_out = (bool) (stream_get_meta_data($socket)['timed_out'] ?? false);
        fclose($socket);

        $answered = is_string($line) && str_contains($line, '"status":"ok"');

        if (!$answered) {
            return [
                'status' => 'FAIL',
                'detail' => 'rsx-lockd holds ' . $endpoint['host'] . ':' . $endpoint['port']
                    . ' but did not answer a ping'
                    . ($timed_out ? ' within 2s' : '')
                    . ' - it is wedged, or something else owns the port',
                'remediation' => 'restart the lock daemon - supervisorctl restart rsx-lockd'
                    . ' (check /var/log/supervisor/rsx-lockd-error.log first)',
            ];
        }

        return [
            'status' => 'OK',
            'detail' => 'rsx-lockd answering on ' . $endpoint['host'] . ':' . $endpoint['port'],
        ];
    }

    /**
     * Send one request frame and return the response frame that answers it.
     *
     * Connects (and says hello) on first use. Blocks for as long as the daemon takes to
     * answer - which for a parked acquire is "until the lock is available".
     *
     * @throws RuntimeException on connect failure, socket death, or a protocol violation.
     */
    public static function request(array $frame): array
    {
        $connection = self::__connection();

        if (!$connection->is_connected()) {
            $connection->ensure_connected();

            if (!self::$shutdown_registered) {
                self::$shutdown_registered = true;
                register_shutdown_function([self::class, '_shutdown']);
            }
        }

        return $connection->request($frame);
    }

    /**
     * Release everything and close. Safe to call when never connected.
     *
     * The release_all frame is a courtesy that makes the daemon's dump tidy a few
     * milliseconds earlier; the close on the next line is what actually releases.
     */
    public static function close(): void
    {
        if (!self::is_connected()) {
            self::$connection?->disconnect();

            return;
        }

        try {
            self::$connection->request(['op' => 'release_all']);
        } catch (\Throwable $e) {
            // The socket is going away regardless, and its closure releases everything.
        }

        self::$connection->disconnect();
    }

    /** Shutdown handler: end of script = end of the connection = end of every lock it held. */
    public static function _shutdown(): void
    {
        self::close();
    }

    // ---------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------

    /**
     * This process's LOCK GROUP - the unit of lock ownership across a process tree.
     *
     * Resolved once and never recomputed: a process belongs to exactly one group for its
     * whole life, and the answer must not change if the socket reconnects.
     *
     * Two cases, and only two:
     *
     *   1. `--_lock-group=<id>` was on our command line. We are a SUBPROCESS that some
     *      parent spawned synchronously and is now blocked waiting for, so we join its
     *      group and inherit whatever it holds. Rsx_Artisan is what puts the flag there.
     *   2. No flag. We are our own root. The id is unique to this process, so nothing
     *      inherits from us by accident, and behavior is identical to having no groups.
     *
     * The id is not a secret and is not treated as one - the HMAC key is the trust
     * boundary for the whole connection. It is unguessable only so two unrelated roots on
     * one box can never collide.
     */
    public static function current_group_id(): string
    {
        static $group_id = null;

        if ($group_id !== null) {
            return $group_id;
        }

        $inherited = Rsx_Internal_Flags::get(self::LOCK_GROUP_FLAG);
        if ($inherited !== null && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $inherited) === 1) {
            $group_id = $inherited;

            return $group_id;
        }

        $group_id = 'g' . getmypid() . '-' . bin2hex(random_bytes(6));

        return $group_id;
    }

    /**
     * The one connection, created on first use. Its hello names current_group_id(), and a
     * lost socket throws the transport's "every lock this process held has been released".
     */
    private static function __connection(): Lockd_Connection
    {
        if (self::$connection === null) {
            self::$connection = new Lockd_Connection(
                self::current_group_id(),
                'every lock this process held has been released by the daemon'
            );

            if (self::$endpoint_override !== null) {
                self::$connection->use_endpoint(
                    self::$endpoint_override['host'],
                    self::$endpoint_override['port']
                );
            }
        }

        return self::$connection;
    }

    /** Where the daemon lives. Override wins, then config, then the shipped defaults. */
    private static function __endpoint(): array
    {
        return self::$endpoint_override ?? Lockd_Connection::default_endpoint();
    }
}
