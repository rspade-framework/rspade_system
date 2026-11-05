<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Proxority;

/**
 * Proxority_Gate - closes the running Proxority to EXTERNAL clients and opens it again.
 *
 *     if (Proxority_Gate::is_running()) {
 *         Proxority_Gate::close_to_external('a test run is in progress');
 *         try { ... } finally { Proxority_Gate::open(); }
 *     }
 *
 * Closed, the proxy answers every PHP-bound request from a non-loopback address with a 503
 * (Retry-After) naming the reason, and passes loopback callers through untouched - a test
 * harness's browser, rsx:debug, companions. Files nginx serves from disk never reach the
 * proxy and keep flowing. close_to_external() returns once the external requests the proxy
 * had already admitted have FINISHED, so nothing external is still running when it returns.
 *
 * THE CLOSURE CANNOT OUTLIVE ITS CLOSER. The close names this process's pid; while closed,
 * the proxy checks that pid on every request it would refuse and reopens itself when the
 * process is gone. A closer that dies without calling open() - killed, fatal error - leaves
 * the site refusing nobody. A Proxority restart also always comes up open (the state is in
 * memory).
 *
 * Only inside the RSpade container, where Proxority runs (resource/docker/proxority); the
 * operator's spelling of the same two verbs is `proxority close --reason=...` / `proxority open`.
 */
class Proxority_Gate
{
    /** The running proxy's status socket (proxority.js DEFAULTS.socket). */
    public const SOCKET = '/run/proxority/proxority.sock';

    /**
     * Whether a Proxority is answering on its socket here.
     */
    public static function is_running(): bool
    {
        if (!file_exists(self::SOCKET)) {
            return false;
        }

        $socket = @stream_socket_client('unix://' . self::SOCKET);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Stop admitting external clients, and return once the external requests already
     * admitted have finished. The wait has no deadline: it lasts as long as the slowest of
     * those requests (a download included).
     */
    public static function close_to_external(string $reason): void
    {
        $answer = static::__ask(['verb' => 'close', 'reason' => $reason, 'holder_pid' => getmypid()]);

        if (empty($answer['ok'])) {
            throw new \RuntimeException('Proxority refused to close: ' . ($answer['error'] ?? 'no reason given'));
        }
    }

    /**
     * Admit every client again. Answers whether the proxy had been closed.
     */
    public static function open(): bool
    {
        $answer = static::__ask(['verb' => 'open']);

        if (empty($answer['ok'])) {
            throw new \RuntimeException('Proxority refused to open: ' . ($answer['error'] ?? 'no reason given'));
        }

        return (bool) $answer['was_closed'];
    }

    /**
     * One request line, one answer line, over the status socket.
     *
     * Blocking, with stream_set_timeout(-1): a read with NO deadline. The default
     * (default_socket_timeout, 60 s) would turn a long drain into a false failure. The answer
     * is exactly one line, so fgets() reads all of it.
     */
    private static function __ask(array $frame): array
    {
        $socket = @stream_socket_client('unix://' . self::SOCKET, $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('Proxority is not running (cannot connect to ' . self::SOCKET . ': ' . $error . ')');
        }

        try {
            stream_set_blocking($socket, true);
            stream_set_timeout($socket, -1);
            fwrite($socket, json_encode($frame) . "\n");
            $line = fgets($socket);
        } finally {
            fclose($socket);
        }

        $answer = $line === false ? null : json_decode($line, true);
        if (!is_array($answer)) {
            throw new \RuntimeException('Proxority closed the connection without answering ' . $frame['verb']);
        }

        return $answer;
    }
}
