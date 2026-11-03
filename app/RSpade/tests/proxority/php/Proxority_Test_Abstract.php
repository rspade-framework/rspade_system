<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Proxority\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The harness every Proxority process test shares: a scratch directory, a fake upstream
 * (resource/fake_upstream.js, two ports standing in for the two loopback nginx flavours), a
 * scripted whitelist command (resource/fake_refresh.js), and a proxy started on a free port
 * against them with scratch pool files and a scratch status socket.
 *
 * Clients are raw TCP streams so a test can hold many requests open at once and decide when
 * each is answered (the fake upstream's hold tokens). Every wait polls the state it wants -
 * the proxy's status socket, the upstream's record of what arrived - never a fixed sleep.
 * Every process and socket a test made is killed and closed in a finally.
 */
abstract class Proxority_Test_Abstract extends Rsx_Test_Abstract
{
    /** Run only on purpose: see the CLAUDE.md beside the proxy source (resource/docker/proxority/CLAUDE.md). */
    protected static $explicit_group_only = true;

    protected static $use_database_transactions = false;

    /** The whitelist answer the 'ok' mode gives unless a test writes another. */
    protected const STANDARD_ANSWER = [
        'unthrottled' => ['/_compiled/*'],
        'priority' => ['/prio/:id'],
        'media' => ['/dl/:key/:name?'],
    ];

    /** @var Process[] */
    private static array $__processes = [];

    /** @var resource[] */
    private static array $__clients = [];

    // ------------------------------------------------------------------------------------
    // Scratch, processes, cleanup
    // ------------------------------------------------------------------------------------

    /** Run $test in a fresh scratch directory with a fake upstream running; clean up whatever happens. */
    protected static function __in_scratch(callable $test): void
    {
        $root = rtrim(sys_get_temp_dir(), '/') . '/proxority-test-' . bin2hex(random_bytes(4));
        ensure_directory($root . '/pool');

        $s = [
            'root' => $root,
            'socket' => $root . '/proxority.sock',
            'mode_file' => $root . '/refresh_mode.json',
        ];

        static::$__processes = [];
        static::$__clients = [];

        try {
            $upstream = static::__spawn(['node', static::__resource('fake_upstream.js'), $root]);
            static::__poll_until(fn () => is_file($root . '/ports.json'), 'the fake upstream is listening (' . $upstream->getErrorOutput() . ')');
            $ports = json_decode((string) file_get_contents($root . '/ports.json'), true);
            $s['upstream_http'] = (int) $ports['http'];
            $s['upstream_https'] = (int) $ports['https'];

            $test($s);
        } finally {
            foreach (static::$__clients as $client) {
                if (is_resource($client)) {
                    fclose($client);
                }
            }
            foreach (array_reverse(static::$__processes) as $process) {
                if ($process->isRunning()) {
                    $process->signal(SIGKILL);
                }
            }
            foreach (array_reverse(static::$__processes) as $process) {
                if ($process->isRunning()) {
                    $process->wait();
                }
            }
            if (is_file($s['mode_file'] . '.pid')) {
                @posix_kill((int) file_get_contents($s['mode_file'] . '.pid'), SIGKILL);
            }

            $cleanup = new Process(['rm', '-rf', $root]);
            $cleanup->setTimeout(null);
            $cleanup->run();
        }
    }

    protected static function __resource(string $file): string
    {
        return base_path('app/RSpade/tests/proxority/resource/' . $file);
    }

    protected static function __proxority_js(): string
    {
        return base_path('app/RSpade/resource/docker/proxority/proxority.js');
    }

    protected static function __spawn(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();
        static::$__processes[] = $process;

        return $process;
    }

    protected static function __write_pool(array $s, int $workers): void
    {
        file_put_contents($s['root'] . '/pool/www.conf', "[www]\npm = dynamic\npm.max_children = {$workers}\n");
    }

    /** Set what the scripted whitelist command answers on its next run. */
    protected static function __refresh_mode(array $s, string $mode, ?array $answer = null): void
    {
        $config = ['mode' => $mode];
        if ($answer !== null) {
            $config['answer'] = $answer;
        }
        file_put_contents($s['mode_file'] . '.tmp', json_encode($config));
        rename($s['mode_file'] . '.tmp', $s['mode_file']);
    }

    /** The serve-mode argv for this scratch, with $flags overriding the defaults by name. */
    protected static function __proxy_command(array $s, array $flags = []): array
    {
        $flags = array_merge([
            'listen' => '127.0.0.1:0',
            'upstream-http' => '127.0.0.1:' . $s['upstream_http'],
            'upstream-https' => '127.0.0.1:' . $s['upstream_https'],
            'socket' => $s['socket'],
            'pool-dir' => $s['root'] . '/pool',
            'refresh-command' => json_encode(['node', static::__resource('fake_refresh.js'), $s['mode_file']]),
            'refresh-interval' => '3600',
        ], $flags);

        $command = ['node', static::__proxority_js()];
        foreach ($flags as $name => $value) {
            $command[] = '--' . $name . '=' . $value;
        }

        return $command;
    }

    /**
     * Start the proxy. With the default 'ok' whitelist mode it is returned once the first
     * answer has been accepted, so a test never races the boot refresh.
     *
     * @return array $s with 'port' and 'process' added
     */
    protected static function __start_proxy(array $s, array $flags = [], int $workers = 4, ?string $mode = 'ok'): array
    {
        static::__write_pool($s, $workers);
        if ($mode !== null) {
            static::__refresh_mode($s, $mode, $mode === 'ok' ? static::STANDARD_ANSWER : null);
        }

        $process = static::__spawn(static::__proxy_command($s, $flags));

        $status = null;
        static::__poll_until(function () use ($s, $process, &$status) {
            $status = static::__status_or_null($s);

            return $status !== null && $status['pid'] === $process->getPid();
        }, 'the proxy answers on its status socket (' . $process->getErrorOutput() . ')');

        if ($mode === 'ok') {
            static::__poll_until(fn () => static::__status($s)['whitelist']['accepted_at'] !== null, 'the boot whitelist refresh was accepted');
        }

        $s['process'] = $process;
        $s['port'] = (int) substr($status['listen'], strrpos($status['listen'], ':') + 1);

        return $s;
    }

    // ------------------------------------------------------------------------------------
    // The status socket
    // ------------------------------------------------------------------------------------

    protected static function __socket_verb(array $s, string $verb): ?array
    {
        $socket = @stream_socket_client('unix://' . $s['socket']);
        if ($socket === false) {
            return null;
        }
        fwrite($socket, json_encode(['verb' => $verb]) . "\n");
        $line = fgets($socket);
        fclose($socket);

        return $line === false ? null : json_decode($line, true);
    }

    protected static function __status_or_null(array $s): ?array
    {
        return static::__socket_verb($s, 'status')['status'] ?? null;
    }

    protected static function __status(array $s): array
    {
        $status = static::__status_or_null($s);
        static::__assert_true($status !== null, 'the proxy answered status');

        return $status;
    }

    protected static function __pool(array $s): array
    {
        return static::__status($s)['pool'];
    }

    protected static function __events(array $s): array
    {
        return static::__socket_verb($s, 'log')['events'] ?? [];
    }

    /** Wait for an event of $type whose text contains $contains, logged at or after $since (ISO). */
    protected static function __wait_event(array $s, string $type, string $contains = '', string $since = ''): array
    {
        $found = null;
        static::__poll_until(function () use ($s, $type, $contains, $since, &$found) {
            foreach (static::__events($s) as $event) {
                if ($event['type'] === $type && $event['ts'] >= $since && ($contains === '' || str_contains($event['text'], $contains))) {
                    $found = $event;

                    return true;
                }
            }

            return false;
        }, "a '{$type}' event" . ($contains !== '' ? " containing '{$contains}'" : ''));

        return $found;
    }

    protected static function __now_iso(): string
    {
        return gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) (fmod(microtime(true), 1) * 1000));
    }

    // ------------------------------------------------------------------------------------
    // Clients and the fake upstream
    // ------------------------------------------------------------------------------------

    /**
     * Open a connection to the proxy and send one request. Answers the open stream; nothing
     * is read until the test asks.
     *
     * @return resource
     */
    protected static function __send(array $s, string $path, array $headers = [], string $method = 'GET', string $body = '')
    {
        $client = stream_socket_client('tcp://127.0.0.1:' . $s['port'], $errno, $error);
        static::__assert_true($client !== false, "connected to the proxy ({$error})");
        static::$__clients[] = $client;

        $lines = [$method . ' ' . $path . ' HTTP/1.1', 'Host: proxority.test', 'Connection: close'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if ($body !== '' || $method === 'POST') {
            $lines[] = 'Content-Length: ' . strlen($body);
        }
        fwrite($client, implode("\r\n", $lines) . "\r\n\r\n" . $body);

        return $client;
    }

    /** Send from a given client address (the X-Real-IP the public nginx would set). */
    protected static function __send_from(array $s, string $ip, string $path, array $headers = [], string $method = 'GET', string $body = '')
    {
        return static::__send($s, $path, ['X-Real-IP' => $ip] + $headers, $method, $body);
    }

    /** Read a response head: status code and lower-cased headers. Blocks until it arrives. */
    protected static function __read_head($client): array
    {
        $status_line = fgets($client);
        static::__assert_true($status_line !== false, 'a response head arrived');

        $headers = [];
        while (($line = fgets($client)) !== false && trim($line) !== '') {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return ['status' => (int) explode(' ', $status_line)[1], 'headers' => $headers];
    }

    /** Read a whole response (the proxy closes after it: Connection: close). */
    protected static function __read_response($client): array
    {
        $head = static::__read_head($client);
        $head['body'] = (string) stream_get_contents($client);

        return $head;
    }

    protected static function __upstream_state(array $s): array
    {
        return json_decode((string) file_get_contents('http://127.0.0.1:' . $s['upstream_http'] . '/__state'), true);
    }

    /** Answer the held request(s) carrying $token. */
    protected static function __release(array $s, string $token): void
    {
        $answer = json_decode((string) file_get_contents('http://127.0.0.1:' . $s['upstream_http'] . '/__release?token=' . urlencode($token)), true);
        static::__assert_equals(1, $answer['released'], "exactly one request held as {$token}");
    }

    /** The hold/head tokens of the requests that reached the upstream, in arrival order. */
    protected static function __arrived(array $s): array
    {
        $tokens = [];
        foreach (static::__upstream_state($s)['requests'] as $request) {
            parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
            $tokens[] = $query['hold'] ?? $query['head'] ?? $query['stream'] ?? $request['path'];
        }

        return $tokens;
    }

    protected static function __wait_arrived(array $s, int $count): array
    {
        $arrived = [];
        static::__poll_until(function () use ($s, $count, &$arrived) {
            $arrived = static::__arrived($s);

            return count($arrived) >= $count;
        }, "{$count} request(s) reached the upstream");

        return $arrived;
    }

    protected static function __wait_queued(array $s, int $count): void
    {
        static::__poll_until(fn () => static::__pool($s)['queued_total'] === $count, "{$count} request(s) queued");
    }

    protected static function __wait_busy(array $s, int $count): void
    {
        static::__poll_until(fn () => static::__pool($s)['busy_total'] === $count, "{$count} worker(s) busy");
    }

    // ------------------------------------------------------------------------------------

    /**
     * A bounded poll of a condition, the house shape. The 120 s bound is a wait on processes
     * this test does not control (the proxy, the fake upstream), expiry truncates no work, and
     * it FAILS the test naming the condition: without it a broken proxy would hang the suite
     * with no evidence at all. Each round also drains the children's output pipes, so a chatty
     * child never blocks on a full pipe while the test waits.
     */
    protected static function __poll_until(callable $condition, string $condition_description): void
    {
        $deadline = microtime(true) + 120;

        do {
            foreach (static::$__processes as $process) {
                $process->isRunning();
            }
            if ($condition()) {
                return;
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        static::__fail($condition_description . ' (waited 120s)');
    }
}
