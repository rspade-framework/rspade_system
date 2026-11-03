<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Proxority\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Tests\Proxority\Php\Proxority_Test_Abstract;

/**
 * The proxy as a proxy: which upstream a request goes to, what reaches it, what happens when
 * the upstream is gone or silent, how much of a response the proxy will hold for a slow
 * client, and the pool sizes it reads from the php-fpm pool files.
 */
class Proxority_Proxying_Test extends Proxority_Test_Abstract
{
    public static function test_headers_reach_the_upstream_untouched_and_the_scheme_picks_it()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s);

            $https = static::__send($s, '/one?x=1', [
                'X-Rsx-Scheme' => 'https',
                'X-Forwarded-For' => '203.0.113.9, 10.1.1.1',
                'X-Real-IP' => '203.0.113.9',
                'X-Custom' => 'kept',
            ]);
            static::__assert_equals('ok', static::__read_response($https)['body']);

            static::__read_response(static::__send($s, '/two', ['X-Rsx-Scheme' => 'http', 'X-Real-IP' => '203.0.113.9']));
            static::__read_response(static::__send($s, '/three', ['X-Real-IP' => '203.0.113.9']));

            $requests = static::__upstream_state($s)['requests'];
            static::__assert_equals(['https', 'http', 'http'], array_column($requests, 'upstream'), 'X-Rsx-Scheme: https goes to the https upstream, anything else to http');
            static::__assert_equals('/one?x=1', $requests[0]['url'], 'the path and query are forwarded as received');

            $headers = [];
            $raw = $requests[0]['raw_headers'];
            for ($i = 0; $i < count($raw); $i += 2) {
                $headers[$raw[$i]] = $raw[$i + 1];
            }
            static::__assert_equals('203.0.113.9, 10.1.1.1', $headers['X-Forwarded-For'] ?? null);
            static::__assert_equals('203.0.113.9', $headers['X-Real-IP'] ?? null);
            static::__assert_equals('kept', $headers['X-Custom'] ?? null);
            static::__assert_equals('proxority.test', $headers['Host'] ?? null, 'the Host header is the client\'s');
            static::__assert_false(isset(array_change_key_case($headers)['x-rsx-scheme']), 'the proxy\'s own routing header is never forwarded');
        });
    }

    public static function test_a_slow_client_pauses_the_upstream_at_the_buffer_bound()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s);
            $size = 80 * 1024 * 1024;

            // The client reads nothing until the upstream has been made to wait.
            $client = static::__send_from($s, '192.0.2.1', '/big?size=' . $size . '&stream=big');

            static::__poll_until(function () use ($s) {
                $stream = static::__upstream_state($s)['streams']['big'] ?? null;

                return $stream !== null && $stream['waiting_drain'] === true;
            }, 'the upstream is paused waiting for the proxy to drain');

            $written = static::__upstream_state($s)['streams']['big']['written'];

            // 10 MB in the proxy plus what the kernel buffers on two loopback connections.
            static::__assert_less_than(50 * 1024 * 1024, $written, "the proxy stopped reading at its buffer bound (upstream wrote {$written} bytes of {$size})");

            $rss_kb = static::__rss_kb($s['process']->getPid());
            static::__assert_less_than(200 * 1024, $rss_kb, "the proxy holds no whole body (RSS {$rss_kb} kB)");

            // Now read it all: every byte arrives.
            $head = static::__read_head($client);
            static::__assert_equals((string) $size, $head['headers']['content-length'] ?? null);
            $received = 0;
            while (!feof($client)) {
                $received += strlen((string) fread($client, 1024 * 1024));
            }
            static::__assert_equals($size, $received);
            static::__wait_busy($s, 0);
        });
    }

    public static function test_an_upstream_that_refuses_the_connection_is_a_502()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, ['upstream-http' => '127.0.0.1:' . static::__closed_port()]);

            $response = static::__read_response(static::__send_from($s, '192.0.2.1', '/page'));

            static::__assert_equals(502, $response['status']);
            static::__assert_contains('Bad Gateway', $response['body']);
            static::__wait_event($s, 'upstream_error', '/page');
            static::__wait_busy($s, 0);
        });
    }

    public static function test_an_upstream_that_never_answers_is_a_504()
    {
        static::__in_scratch(function (array $s) {
            // The test seam shortens the 360 s response-head bound.
            $s = static::__start_proxy($s, ['_response-timeout-ms' => '500']);

            $response = static::__read_response(static::__send_from($s, '192.0.2.1', '/page?hold=never'));

            static::__assert_equals(504, $response['status']);
            static::__assert_contains('Gateway Timeout', $response['body']);
            static::__assert_contains('500 ms waiting for the response head', static::__wait_event($s, 'timeout')['text']);
            static::__wait_busy($s, 0);
            static::__poll_until(fn () => static::__upstream_state($s)['requests'][0]['aborted'] === true, 'the upstream request was abandoned');
        });
    }

    public static function test_sighup_rereads_the_pool_sizes()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 1);

            static::__send_from($s, '192.0.2.1', '/page?hold=a1');
            static::__send_from($s, '192.0.2.2', '/page?hold=b1');
            static::__wait_queued($s, 1);

            static::__write_pool($s, 6);
            posix_kill($s['process']->getPid(), SIGHUP);

            static::__poll_until(fn () => static::__pool($s)['capacity'] === 6, 'the pool is 6 after SIGHUP');
            static::__assert_equals(5, static::__pool($s)['caps']['per_ip']);
            static::__wait_event($s, 'capacity', 'pool 1 -> 6');
            static::__assert_equals('b1', static::__wait_arrived($s, 2)[1], 'the larger pool starts what was waiting');
        });
    }

    public static function test_sigterm_finishes_what_is_in_flight_then_exits()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s);

            $client = static::__send_from($s, '192.0.2.1', '/page?hold=a1');
            static::__wait_arrived($s, 1);

            posix_kill($s['process']->getPid(), SIGTERM);
            static::__poll_until(fn () => !file_exists($s['socket']), 'the status socket is removed on SIGTERM');
            static::__assert_false(@stream_socket_client('tcp://127.0.0.1:' . $s['port']), 'no new connection is accepted');
            static::__assert_true($s['process']->isRunning(), 'the proxy waits for the request in flight');

            static::__release($s, 'a1');
            static::__assert_equals('ok a1', static::__read_response($client)['body']);
            static::__poll_until(fn () => !$s['process']->isRunning(), 'the proxy exits once nothing is in flight');
            static::__assert_equals(0, $s['process']->getExitCode());
        });
    }

    public static function test_a_missing_pool_file_fails_the_start()
    {
        static::__in_scratch(function (array $s) {
            static::__write_pool($s, 4);
            unlink($s['root'] . '/pool/www.conf');

            $process = new Process(static::__proxy_command($s));
            $process->setTimeout(null);
            $process->run();

            static::__assert_equals(1, $process->getExitCode());
            static::__assert_contains('[ERROR] Cannot read the pool file', $process->getErrorOutput());
        });
    }

    public static function test_conditional_requests_reach_the_upstream_and_a_304_reaches_the_client()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s);

            $fresh = static::__read_raw(static::__send_from($s, '192.0.2.1', '/res?reply=conditional'));
            static::__assert_equals(200, $fresh['status']);
            static::__assert_equals('fresh', $fresh['body']);

            $stale = static::__read_raw(static::__send_from($s, '192.0.2.1', '/res?reply=conditional', ['If-None-Match' => '"v1"']));
            static::__assert_equals(304, $stale['status'], 'If-None-Match matched: 304');
            static::__assert_equals('', $stale['body'], 'a 304 carries no body');
            static::__assert_equals(['"v1"'], static::__header_values($stale, 'etag'), 'the ETag is kept on the 304');
            static::__assert_equals(['no-cache'], static::__header_values($stale, 'cache-control'));

            $since = static::__read_raw(static::__send_from($s, '192.0.2.1', '/res?reply=conditional', ['If-Modified-Since' => 'Tue, 03 Nov 2026 10:00:00 GMT']));
            static::__assert_equals(304, $since['status'], 'If-Modified-Since: 304');

            $requests = static::__upstream_state($s)['requests'];
            static::__assert_equals(['"v1"'], static::__raw_values($requests[1]['raw_headers'], 'if-none-match'), 'If-None-Match reached the upstream untouched');
            static::__assert_equals(['Tue, 03 Nov 2026 10:00:00 GMT'], static::__raw_values($requests[2]['raw_headers'], 'if-modified-since'));
        });
    }

    public static function test_a_range_request_reaches_the_upstream_and_a_206_reaches_the_client()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s);

            $partial = static::__read_raw(static::__send_from($s, '192.0.2.1', '/file?reply=range', ['Range' => 'bytes=2-5', 'If-Range' => '"v1"']));
            static::__assert_equals(206, $partial['status']);
            static::__assert_equals(['bytes 2-5/26'], static::__header_values($partial, 'content-range'));
            static::__assert_equals('cdef', $partial['body'], 'the partial body arrives as sent');

            $raw = static::__upstream_state($s)['requests'][0]['raw_headers'];
            static::__assert_equals(['bytes=2-5'], static::__raw_values($raw, 'range'), 'Range reached the upstream untouched');
            static::__assert_equals(['"v1"'], static::__raw_values($raw, 'if-range'));

            $head = static::__read_raw(static::__send_from($s, '192.0.2.1', '/file?reply=range', [], 'HEAD'));
            static::__assert_equals(200, $head['status']);
            static::__assert_equals(['26'], static::__header_values($head, 'content-length'), 'HEAD gets the head the GET would have');
            static::__assert_equals('', $head['body'], 'HEAD gets no body');
            static::__assert_equals('HEAD', static::__upstream_state($s)['requests'][1]['method']);
        });
    }

    public static function test_response_headers_pass_through_and_repeated_set_cookie_stays_separate()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s);

            $response = static::__read_raw(static::__send_from($s, '192.0.2.1', '/login?reply=headers', ['Accept-Encoding' => 'gzip']));

            static::__assert_equals(302, $response['status']);
            static::__assert_equals(['a=1; Path=/', 'b=2; Path=/; HttpOnly', 'c=3; Path=/; Secure'], static::__header_values($response, 'set-cookie'), 'three Set-Cookie headers, each on its own line');
            static::__assert_equals(['/elsewhere?x=1'], static::__header_values($response, 'location'));
            static::__assert_equals(['Accept-Encoding, Cookie'], static::__header_values($response, 'vary'));
            static::__assert_equals(['private, max-age=60'], static::__header_values($response, 'cache-control'));
            static::__assert_equals(['gzip'], static::__header_values($response, 'content-encoding'));
            static::__assert_equals('compressed', gzdecode($response['body']), 'the encoded body is relayed byte for byte');
        });
    }

    public static function test_hop_by_hop_request_headers_stop_at_the_proxy_and_upstream_connections_are_reused()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s);

            // __send() always sends Connection: close, as nginx does on every proxied request;
            // the second Connection line names a header that is hop-by-hop by declaration.
            $headers = [
                'Connection' => 'X-Hop',
                'X-Hop' => 'not forwarded',
                'Keep-Alive' => 'timeout=5',
                'TE' => 'trailers',
                'X-Forwarded-For' => '192.0.2.1',
                'Cookie' => 'sid=abc',
                'Authorization' => 'Bearer rsx_test',
                'X-Custom' => 'kept',
            ];
            for ($i = 0; $i < 3; $i++) {
                static::__assert_equals('ok', static::__read_raw(static::__send_from($s, '192.0.2.1', '/page?n=' . $i, $headers, 'POST', 'abc'))['body']);
            }

            $state = static::__upstream_state($s);
            $raw = $state['requests'][0]['raw_headers'];
            static::__assert_false(in_array('close', array_map('strtolower', static::__raw_values($raw, 'connection')), true), 'the client\'s Connection: close never reaches the upstream');
            static::__assert_equals([], static::__raw_values($raw, 'x-hop'), 'a header the Connection header names is dropped');
            static::__assert_equals([], static::__raw_values($raw, 'keep-alive'));
            static::__assert_equals([], static::__raw_values($raw, 'te'));
            static::__assert_equals(['192.0.2.1'], static::__raw_values($raw, 'x-real-ip'));
            static::__assert_equals(['192.0.2.1'], static::__raw_values($raw, 'x-forwarded-for'));
            static::__assert_equals(['sid=abc'], static::__raw_values($raw, 'cookie'));
            static::__assert_equals(['Bearer rsx_test'], static::__raw_values($raw, 'authorization'));
            static::__assert_equals(['kept'], static::__raw_values($raw, 'x-custom'));
            static::__assert_equals(['3'], static::__raw_values($raw, 'content-length'), 'the body framing is kept');

            static::__assert_equals(3, count($state['requests']));
            static::__assert_equals(1, $state['sockets']['http'], 'three sequential requests share one upstream connection');
        });
    }

    // ------------------------------------------------------------------------------------

    /**
     * Read a whole response as sent: status, every header line in order (repeats kept, names
     * lower-cased) and the body. The proxy closes after it (Connection: close).
     */
    private static function __read_raw($client): array
    {
        $raw = (string) stream_get_contents($client);
        $split = strpos($raw, "\r\n\r\n");
        static::__assert_true($split !== false, 'a whole response head arrived');

        $lines = explode("\r\n", substr($raw, 0, $split));
        $status = (int) explode(' ', array_shift($lines))[1];
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[] = [strtolower(trim($name)), trim($value)];
        }

        return ['status' => $status, 'headers' => $headers, 'body' => substr($raw, $split + 4)];
    }

    /** Every value of one header in a __read_raw() response, in order. */
    private static function __header_values(array $response, string $name): array
    {
        $values = [];
        foreach ($response['headers'] as [$header, $value]) {
            if ($header === $name) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** Every value of one header in a node rawHeaders list, matched case-insensitively. */
    private static function __raw_values(array $raw, string $name): array
    {
        $values = [];
        for ($i = 0; $i < count($raw); $i += 2) {
            if (strtolower($raw[$i]) === $name) {
                $values[] = $raw[$i + 1];
            }
        }

        return $values;
    }

    private static function __rss_kb(int $pid): int
    {
        preg_match('/^VmRSS:\s+(\d+)/m', (string) file_get_contents('/proc/' . $pid . '/status'), $match);

        return (int) $match[1];
    }

    /** A port nothing listens on: bound, read, released. */
    private static function __closed_port(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
