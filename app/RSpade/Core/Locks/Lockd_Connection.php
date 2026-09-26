<?php

namespace App\RSpade\Core\Locks;

use RuntimeException;

/**
 * ONE socket to rsx-lockd (system/bin/rsx-lockd): dial with retry, the HMAC hello, newline
 * JSON framing, request ids, out-of-order response parking, and loud death. It owns bytes
 * and framing only - what a frame MEANS belongs to its owner.
 *
 * Two owners exist, each with its own instance and therefore its own daemon connection:
 *
 *   - Lockd_Client, the static transport under RsxLocks. Its connection carries this
 *     process's lock group in the hello and is closed with `release_all` at shutdown.
 *   - Task_Pool, the task worker pool's lifelong connection. Its hello carries NO group
 *     (a pool membership is never shared with a process tree), and it is never sent
 *     `release_all` - the socket closing at process exit is what ends the membership.
 *
 * THE CONNECTION IS THE STATE. Everything the daemon grants on a connection - a lock, a
 * pool membership - lives exactly as long as the socket. So a connection opens lazily on
 * the first request, stays open for the rest of the script, and is never persistent across
 * requests (a PHP-FPM worker that carried a socket into its next request would carry the
 * previous request's grants with it).
 *
 * WAITING IS SILENCE. A parked request receives NO frame until it is answered, so a read
 * blocks for as long as the daemon takes - a month, if that is how long the thing ahead of
 * it is held. There is NO read timeout anywhere here: reads block on stream_select() with a
 * NULL timeout, and only a frame or the socket dying ends the wait.
 *
 * Responses can arrive OUT OF ORDER (a parked acquire is answered long after a later ping),
 * so every request carries an `id` and the reader correlates on it, parking any frame that
 * belongs to a different request until its own requester asks for it.
 */
#[Instantiatable]
class Lockd_Connection
{
    /**
     * Endpoint defaults. Deliberately duplicated from the daemon's shipped lockd.conf: a
     * connection must work before config('rsx.locks') exists on a given box, and a wrong port
     * is a loud connect failure rather than a silent misbehavior.
     */
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 6210;

    /** The common failure is a daemon that is restarting, so a connect is worth retrying. */
    private const DEFAULT_CONNECT_RETRIES = 3;
    private const DEFAULT_CONNECT_RETRY_DELAY_MS = 1000;

    /** Applies to establishing the TCP connection only - never to reading an answer. */
    private const CONNECT_TIMEOUT_SECONDS = 2;

    /** @var resource|null */
    private $socket = null;

    /** Bytes read from the socket that do not yet form a complete line. */
    private string $read_buffer = '';

    /** Monotonic per-connection request id source (correlates responses). */
    private int $request_seq = 0;

    /** Frames that arrived while a DIFFERENT request was being awaited, keyed by their id. */
    private array $parked_frames = [];

    /** Set by use_endpoint(); null means config, then the shipped defaults. */
    private ?array $endpoint_override = null;

    /**
     * Every instance this process has created, held weakly - the roster
     * open_socket_inodes() reads so a spawn can close every daemon socket it would
     * otherwise hand to its child.
     *
     * @var \WeakReference[]
     */
    private static array $instances = [];

    /**
     * @param string|null $group_id          The lock group named in the hello, or null to name
     *                                       none (the connection is then its own group).
     * @param string      $death_consequence What losing this connection means to its owner,
     *                                       appended to the exception a dead socket throws.
     */
    public function __construct(
        private ?string $group_id,
        private string $death_consequence
    ) {
        self::$instances[] = \WeakReference::create($this);
    }

    /**
     * The inode numbers of every daemon socket this process currently has open, across
     * every owner (Lockd_Client's connection, Task_Pool's, any other instance).
     *
     * WHY THIS EXISTS: a socket PHP opens is inherited by every child it spawns (PHP sets no
     * FD_CLOEXEC on it), and THE CONNECTION IS THE STATE. A child that outlives its parent -
     * a detached task worker started by a dispatch() inside a task, say - keeps the socket
     * open, so the daemon never sees the parent's connection close: the parent's locks, pool
     * lock and pool membership survive its death for as long as that child lives.
     * RsxLocks::inherited_lock_fds() matches these inodes against the `socket:[<inode>]`
     * links in /proc/self/fd, and every spawn seam closes the matching descriptors in the
     * child. The inode is the one identity a descriptor number can be matched on without
     * this class handing its sockets out.
     *
     * @return int[]
     */
    public static function open_socket_inodes(): array
    {
        $inodes = [];
        $live = [];

        foreach (self::$instances as $reference) {
            $connection = $reference->get();
            if ($connection === null) {
                continue;
            }
            $live[] = $reference;

            if (!is_resource($connection->socket)) {
                continue;
            }

            $stat = fstat($connection->socket);
            if ($stat === false) {
                shouldnt_happen('fstat() failed on an open rsx-lockd socket');
            }
            $inodes[] = (int) $stat['ino'];
        }

        self::$instances = $live;

        return $inodes;
    }

    /** The configured daemon endpoint (config, then the shipped defaults). */
    public static function default_endpoint(): array
    {
        return [
            'host' => (string) config('rsx.locks.server_host', self::DEFAULT_HOST),
            'port' => (int) config('rsx.locks.server_port', self::DEFAULT_PORT),
        ];
    }

    /**
     * Point this connection at a specific daemon, overriding config. Closes any open socket
     * WITHOUT a farewell frame, so the next request redials - an owner that wants a farewell
     * sends it first.
     */
    public function use_endpoint(string $host, int $port): void
    {
        $this->disconnect();
        $this->endpoint_override = ['host' => $host, 'port' => $port];
    }

    /** Where this connection dials: its override, else default_endpoint(). */
    public function endpoint(): array
    {
        return $this->endpoint_override ?? self::default_endpoint();
    }

    /** True when the socket to the daemon is currently open. */
    public function is_connected(): bool
    {
        return is_resource($this->socket);
    }

    /**
     * Dial and say hello if not already connected.
     *
     * @throws RuntimeException on connect failure or a rejected hello.
     */
    public function ensure_connected(): void
    {
        if (!is_resource($this->socket)) {
            $this->__connect();
        }
    }

    /**
     * Send one request frame and return the response frame that answers it.
     *
     * Connects (and says hello) on first use. Blocks for as long as the daemon takes to
     * answer - which for a parked acquire is "until it is granted".
     *
     * @throws RuntimeException on connect failure, socket death, or a protocol violation.
     */
    public function request(array $frame): array
    {
        $this->ensure_connected();

        $frame['id'] = $this->__next_id();

        $this->__write($frame);

        return $this->__await($frame['id']);
    }

    /**
     * Close the socket and forget every in-flight frame. Sends nothing: the daemon releases
     * whatever this connection held when it sees the close. Safe when never connected.
     */
    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->read_buffer = '';
        $this->parked_frames = [];
    }

    // ---------------------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------------------

    private function __next_id(): string
    {
        $this->request_seq++;

        return 'p' . getmypid() . '-' . $this->request_seq;
    }

    /**
     * Dial the daemon and complete the hello handshake.
     *
     * Retries the CONNECT (only) a few times a second apart, because the overwhelmingly
     * common failure is a daemon in the middle of a supervisor restart. A rejected hello is
     * NOT retried - the key is wrong and it will stay wrong.
     */
    private function __connect(): void
    {
        $endpoint = $this->endpoint();
        $attempts = (int) config('rsx.locks.connect_retries', self::DEFAULT_CONNECT_RETRIES);
        $delay_ms = (int) config('rsx.locks.connect_retry_delay_ms', self::DEFAULT_CONNECT_RETRY_DELAY_MS);
        if ($attempts < 1) {
            $attempts = 1;
        }

        $socket = false;
        $errstr = '';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                'tcp://' . $endpoint['host'] . ':' . $endpoint['port'],
                $errno,
                $errstr,
                self::CONNECT_TIMEOUT_SECONDS
            );

            if ($socket !== false) {
                break;
            }

            if ($attempt < $attempts) {
                usleep($delay_ms * 1000);
            }
        }

        if ($socket === false) {
            throw new RuntimeException(
                "Cannot reach rsx-lockd at {$endpoint['host']}:{$endpoint['port']} after {$attempts} attempts"
                . ($errstr !== '' ? " ({$errstr})" : '')
                . ' - is the rsx-lockd supervisor program running?'
            );
        }

        // MANDATORY (enforced by StreamBlockingMode_CodeQualityRule): every read below is a
        // deliberate block. No stream_set_timeout call exists anywhere in this class - see
        // the class docblock on why a wait has no upper bound.
        stream_set_blocking($socket, true);

        $this->socket = $socket;
        $this->read_buffer = '';
        $this->parked_frames = [];

        $this->__hello();
    }

    /**
     * Authenticate the connection: HMAC-SHA256 over host:pid:ts keyed by APP_KEY, exactly as
     * system/bin/rsx-lockd/lib/protocol.js signs it. The RAW APP_KEY string is the shared
     * secret (the daemon reads the same characters out of the environment or .env) - never a
     * base64-decoded form of it.
     */
    private function __hello(): void
    {
        $key = (string) env('APP_KEY');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set - rsx-lockd connections cannot be authenticated');
        }

        $host = (string) gethostname();
        $pid = getmypid();
        $ts = time();

        $frame = [
            'op' => 'hello',
            'host' => $host,
            'pid' => $pid,
            'ts' => $ts,
            'sig' => hash_hmac('sha256', "{$host}:{$pid}:{$ts}", $key),
        ];
        if ($this->group_id !== null) {
            $frame['group_id'] = $this->group_id;
        }
        $frame['protocol'] = 1;
        $frame['id'] = $this->__next_id();

        $this->__write($frame);

        $response = $this->__await($frame['id']);

        if (($response['status'] ?? '') !== 'ok') {
            $message = $response['message'] ?? 'unknown reason';
            @fclose($this->socket);
            $this->socket = null;
            throw new RuntimeException("rsx-lockd rejected the hello handshake: {$message}");
        }
    }

    private function __write(array $frame): void
    {
        $line = json_encode($frame) . "\n";
        $written = @fwrite($this->socket, $line);

        if ($written === false || $written < strlen($line)) {
            $this->__socket_died('write failed');
        }
    }

    /**
     * Read frames until the one answering $id arrives. Anything else is parked for whoever
     * is waiting on it (responses genuinely arrive out of order), and an id-less frame is a
     * connection-level error the daemon emitted without a request to attach it to.
     */
    private function __await(string $id): array
    {
        if (isset($this->parked_frames[$id])) {
            $frame = $this->parked_frames[$id];
            unset($this->parked_frames[$id]);

            return $frame;
        }

        while (true) {
            $line = $this->__read_line();
            $frame = json_decode($line, true);

            if (!is_array($frame)) {
                $this->__socket_died('unparseable frame: ' . $line);
            }

            $frame_id = $frame['id'] ?? null;

            if ($frame_id === $id) {
                return $frame;
            }

            if ($frame_id === null) {
                $message = $frame['message'] ?? 'no message';
                $this->__socket_died("connection-level error from rsx-lockd: {$message}");
            }

            $this->parked_frames[$frame_id] = $frame;
        }
    }

    /**
     * One newline-delimited frame off the wire.
     *
     * stream_select() with a NULL timeout is THE wait: it blocks indefinitely and returns
     * only when there are bytes (or the peer went away). Nothing here arms a clock, so a
     * parked request costs one blocked process and zero traffic.
     */
    private function __read_line(): string
    {
        while (true) {
            $newline = strpos($this->read_buffer, "\n");
            if ($newline !== false) {
                $line = substr($this->read_buffer, 0, $newline);
                $this->read_buffer = substr($this->read_buffer, $newline + 1);

                return trim($line);
            }

            $read = [$this->socket];
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, null);
            if ($ready === false) {
                $this->__socket_died('stream_select failed');
            }

            $chunk = @fread($this->socket, 65536);
            if ($chunk === false || $chunk === '') {
                $this->__socket_died('connection closed by the daemon');
            }

            $this->read_buffer .= $chunk;
        }
    }

    /**
     * The socket is unusable. Drop it (a later request redials a FRESH connection, which by
     * the model holds nothing) and throw - the owner must never carry on believing it holds
     * something that no longer exists anywhere.
     */
    private function __socket_died(string $reason): never
    {
        $this->disconnect();

        $endpoint = $this->endpoint();

        throw new RuntimeException(
            "Lost the connection to rsx-lockd at {$endpoint['host']}:{$endpoint['port']} ({$reason})"
            . ' - ' . $this->death_consequence
        );
    }
}
