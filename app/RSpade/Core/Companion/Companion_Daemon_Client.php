<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use RuntimeException;
use App\RSpade\Core\Companion\Companion_Verifier;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Companion_Daemon_Client - PHP's side of rsx-companiond's control socket.
 *
 * Newline-delimited JSON over the unix socket at Rsx_Project_Paths::companiond_socket_file().
 * Each send() is one connection: a hello proving the daemon key inside the 60 second window,
 * then the verb, each frame carrying an id its reply repeats:
 *
 *   status  {name?}                      {daemon: {...}, companions: [summary, ...]}
 *   reload  {wait?}                      the flags changed: read them now
 *   control {name?, action, wait?}       pause, resume, stop, start, restart; no name = all
 *
 * `wait` answers once no stop or start is in flight. A wait has no deadline: a stop takes as
 * long as the companion's declared grace. ping() needs no key and answers whether a daemon
 * listens at all.
 */
class Companion_Daemon_Client
{
    /**
     * Answers {pid, host} from a listening daemon, or null when none listens.
     */
    public static function ping(): ?array
    {
        $socket = static::__open();

        if ($socket === null) {
            return null;
        }

        try {
            return static::__request($socket, ['verb' => 'ping']);
        } finally {
            fclose($socket);
        }
    }

    /**
     * Send one verb and answer its reply, or null when no daemon listens.
     *
     * @throws RuntimeException when the daemon refuses the key or the verb, naming why
     */
    public static function send(string $verb, array $arguments = []): ?array
    {
        $socket = static::__open();

        if ($socket === null) {
            return null;
        }

        try {
            $timestamp = (string) time();

            static::__request($socket, [
                'verb' => 'hello',
                'ts' => (int) $timestamp,
                'sig' => hash_hmac('sha256', 'companiond-socket|' . $timestamp, Companion_Verifier::daemon_key()),
            ]);

            return static::__request($socket, ['verb' => $verb] + $arguments);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource|null
     */
    private static function __open()
    {
        $path = Rsx_Project_Paths::companiond_socket_file();

        if (!file_exists($path)) {
            return null;
        }

        $socket = @stream_socket_client('unix://' . $path, $errno, $errstr);

        if ($socket === false) {
            return null;
        }

        // Blocking, with no read deadline: a waiting verb answers when the work is done, and
        // a read must never return a partial frame.
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, PHP_INT_MAX);

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private static function __request($socket, array $frame): array
    {
        $frame['id'] = 1;

        if (fwrite($socket, json_encode($frame) . "\n") === false) {
            throw new RuntimeException('rsx-companiond closed the control connection');
        }

        $line = fgets($socket);

        if ($line === false) {
            throw new RuntimeException('rsx-companiond closed the control connection');
        }

        $answer = json_decode($line, true);

        if (!is_array($answer)) {
            throw new RuntimeException('rsx-companiond answered something unreadable: ' . $line);
        }

        if (($answer['ok'] ?? false) !== true) {
            throw new RuntimeException('rsx-companiond refused ' . $frame['verb'] . ': ' . ($answer['error'] ?? 'no reason given'));
        }

        unset($answer['ok'], $answer['id']);

        return $answer;
    }
}
