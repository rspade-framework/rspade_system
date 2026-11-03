<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Proxority\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Proxority in the LIVE request path: the box's real nginx (public :80 / :8000, loopback
 * :3301 / :3302), the supervised proxy on 127.0.0.1:3300 and the real php-fpm pools.
 *
 * It applies only where the stack is wired that way - an RSpade container whose nginx sends
 * PHP-bound requests to the supervised proxy, the docker test workers included (their image
 * is the development image, proxy and all). Everywhere else (a host without the proxy) every
 * test SKIPS, naming why.
 *
 * PHP's own view of a request comes from the framework's pre-boot probe
 * (bootstrap/rsx_test_probe.php), opened for the span of one test by a token marker this
 * process owns: /_rsx_test_probe/<token>/<label> answers what php-fpm was handed -
 * REMOTE_ADDR, the forwarded headers, HTTPS, the URI. It is reached through the same @php
 * location as every page, because nginx refuses every .php URL.
 *
 * NO TEST HOLDS A WORKER UNTIL THE TEST RELEASES IT. The pools may be `pm = ondemand`, where
 * php-fpm can have fewer children started than pm.max_children at the instant the proxy
 * dispatches a burst; a dispatched request then waits in php-fpm's own backlog for a child to
 * free. A worker held until the test released it would never free, so that request would wait
 * until the proxy's response bound. Instead a probe asked to `hold` answers by itself after a
 * fixed two seconds, every request completes, and every scheduling assertion reads the ORDER
 * of the proxy's event log - never elapsed time and never a momentary busy count.
 *
 * Clients are curl processes, so a request can leave from a chosen local address
 * (--interface): the box's own non-loopback address is a real remote client to nginx. A
 * second client address is a request from loopback (a trusted proxy) carrying
 * X-Forwarded-For, which is exactly what a trusted reverse proxy sends.
 */
class Proxority_Live_Stack_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const STATUS_SOCKET = '/run/proxority/proxority.sock';

    private const ACCESS_LOG = '/var/log/nginx/access.log';

    /** The second client, declared through loopback (trusted) by X-Forwarded-For. TEST-NET-2. */
    private const FORWARDED_CLIENT = '198.51.100.23';

    /** @var Process[] */
    private static array $__processes = [];

    // ------------------------------------------------------------------------------------
    // Applicability, the probe, cleanup
    // ------------------------------------------------------------------------------------

    /**
     * Skip unless the live stack is here: the supervised proxy answers its status socket AND
     * a request to the public nginx is seen by it.
     */
    private static function __require_live_stack(): void
    {
        if (static::__status_or_null() === null) {
            static::__skip('Proxority is not in this box\'s request path (no live status socket at ' . self::STATUS_SOCKET . '). It runs only where nginx sends PHP-bound requests to the supervised proxy: an RSpade container or one of its docker test workers.');
        }
    }

    /**
     * Run $test with the pre-boot probe opened for this process (a token marker naming this
     * pid); close it and end every client this test started, whatever happens.
     *
     * @param callable $test fn(string $base) - $base: the probe's URL path up to the label,
     *                       "/_rsx_test_probe/<token>/"; append a label ([a-z0-9_]).
     */
    private static function __with_probe(callable $test): void
    {
        static::__require_live_stack();

        $token = bin2hex(random_bytes(16));
        $marker = Rsx_Project_Paths::test_probe_dir() . '/' . $token;
        ensure_directory(dirname($marker));
        file_put_contents($marker, (string) getmypid());

        static::$__processes = [];

        try {
            $test('/_rsx_test_probe/' . $token . '/');
        } finally {
            @unlink($marker);
            foreach (static::$__processes as $process) {
                if ($process->isRunning()) {
                    $process->signal(SIGKILL);
                }
            }
            foreach (static::$__processes as $process) {
                if ($process->isRunning()) {
                    $process->wait();
                }
            }
        }
    }

    /** The box's first global IPv4 address (a non-loopback source nginx sees as remote), or skip. */
    private static function __remote_address(): string
    {
        $process = new Process(['ip', '-4', '-o', 'addr', 'show', 'scope', 'global']);
        $process->setTimeout(null);
        $process->run();
        if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)\//', $process->getOutput(), $matches)) {
            return $matches[1];
        }
        static::__skip('This box has no global IPv4 address to send a non-loopback request from.');
    }

    // ------------------------------------------------------------------------------------
    // Clients
    // ------------------------------------------------------------------------------------

    /**
     * Start a curl request to the public nginx. $from: a local address to leave from (nginx
     * then sees that address), or null for loopback.
     */
    private static function __start_request(?string $from, int $port, string $path_and_query, array $headers = [], array $extra = []): Process
    {
        $host = $from ?? '127.0.0.1';
        $command = ['curl', '-s', '-S', '-D', '-', '--http1.1'];
        if ($from !== null) {
            $command[] = '--interface';
            $command[] = $from;
        }
        foreach ($headers as $name => $value) {
            $command[] = '-H';
            $command[] = $name . ': ' . $value;
        }
        $command = array_merge($command, $extra, ['http://' . $host . ':' . $port . $path_and_query]);

        $process = new Process($command);
        $process->setTimeout(null);
        $process->start();
        static::$__processes[] = $process;

        return $process;
    }

    /** Wait for a started request; answers status, lowercased headers (name => [values]), body. */
    private static function __finish(Process $process): array
    {
        $process->wait();
        static::__assert_equals(0, $process->getExitCode(), 'curl failed: ' . $process->getErrorOutput());

        $output = $process->getOutput();
        $split = strpos($output, "\r\n\r\n");
        $head = substr($output, 0, $split);
        $body = substr($output, $split + 4);

        $lines = explode("\r\n", $head);
        preg_match('#^HTTP/\S+ (\d{3})#', array_shift($lines), $matches);

        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $headers[strtolower($name)][] = $value;
        }

        return ['status' => (int) $matches[1], 'headers' => $headers, 'body' => $body];
    }

    private static function __http(?string $from, int $port, string $path_and_query, array $headers = [], array $extra = []): array
    {
        return static::__finish(static::__start_request($from, $port, $path_and_query, $headers, $extra));
    }

    /** What PHP saw, from the probe's JSON. */
    private static function __probe_view(array $response): array
    {
        static::__assert_equals(200, $response['status'], 'the probe answers 200: ' . $response['body']);
        $view = json_decode($response['body'], true);
        static::__assert_true(is_array($view), 'the probe answers JSON: ' . $response['body']);

        return $view;
    }

    // ------------------------------------------------------------------------------------
    // The live proxy
    // ------------------------------------------------------------------------------------

    private static function __socket_verb(string $verb): ?array
    {
        $socket = @stream_socket_client('unix://' . self::STATUS_SOCKET);
        if ($socket === false) {
            return null;
        }
        fwrite($socket, json_encode(['verb' => $verb]) . "\n");
        $line = fgets($socket);
        fclose($socket);

        return $line === false ? null : json_decode($line, true);
    }

    private static function __status_or_null(): ?array
    {
        $frame = static::__socket_verb('status');

        return ($frame['ok'] ?? false) ? $frame['status'] : null;
    }

    private static function __pool(string $pool = 'web'): array
    {
        $status = static::__status_or_null();
        static::__assert_not_null($status, 'the live proxy answers its status socket');

        return $status['pools'][$pool];
    }

    /** Events logged at or after $since (ISO), oldest first. */
    private static function __events_since(string $since): array
    {
        $frame = static::__socket_verb('log');
        static::__assert_true($frame['ok'] ?? false, 'the live proxy answers its log');

        return array_values(array_filter($frame['events'], fn ($event) => $event['ts'] >= $since));
    }

    private static function __now_iso(): string
    {
        return gmdate('Y-m-d\TH:i:s') . '.' . sprintf('%03d', (int) (fmod(microtime(true), 1) * 1000)) . 'Z';
    }

    /**
     * The ring's events for ONE request path (no query string: the proxy logs the path),
     * oldest first, keyed by their position in the ring - the order the proxy logged them.
     */
    private static function __events_for(string $path): array
    {
        $frame = static::__socket_verb('log');
        static::__assert_true($frame['ok'] ?? false, 'the live proxy answers its log');

        $pattern = '# ' . preg_quote($path, '#') . '( |$)#';

        return array_filter($frame['events'], fn ($event) => preg_match($pattern, $event['text']) === 1);
    }

    /** The ring position of the first $type event in $events, or null. */
    private static function __first(array $events, string $type): ?int
    {
        foreach ($events as $position => $event) {
            if ($event['type'] === $type) {
                return $position;
            }
        }

        return null;
    }

    /** A pool's capacity N as `proxority.js status` reports it. */
    private static function __capacity_from_cli(string $pool): int
    {
        $status = new Process(['node', base_path('bin/proxority/proxority.js'), 'status']);
        $status->setTimeout(null);
        $status->run();
        static::__assert_equals(0, $status->getExitCode(), 'proxority.js status: ' . $status->getErrorOutput());
        static::__assert_true(preg_match('/^Pool ' . $pool . ': capacity (\d+) /m', $status->getOutput(), $matches) === 1, 'the status names the ' . $pool . ' pool: ' . $status->getOutput());

        return (int) $matches[1];
    }

    /**
     * A bounded poll, the house shape. The 120 s bound is a wait on processes this test does
     * not control (nginx, the proxy, php-fpm); expiry truncates no work and FAILS the test
     * naming the condition, where a broken stack would otherwise hang the run with no evidence.
     * Each round drains the curl processes' pipes.
     */
    private static function __poll_until(callable $condition, string $condition_description): void
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

    // ------------------------------------------------------------------------------------
    // The client address and the scheme
    // ------------------------------------------------------------------------------------

    /** The `dispatched` event for one request path, asserted present. */
    private static function __dispatched_event(string $path): array
    {
        $events = static::__events_for($path);
        $position = static::__first($events, 'dispatched');
        static::__assert_not_null($position, 'the proxy dispatched ' . $path);

        return $events[$position];
    }

    /**
     * A direct request from a non-loopback address: php-fpm's REMOTE_ADDR is that address on
     * both listeners, the proxy scheduled it under that address, the flavour is the listener
     * (:80 plain with X-Forwarded-Proto pinned to http, :8000 HTTPS=on with it pinned to
     * https - a client's own X-Forwarded-Proto never reaches PHP), X-Rsx-Scheme never reaches
     * PHP, and the URI is the one the client sent.
     */
    public static function test_php_sees_a_direct_remote_client()
    {
        static::__with_probe(function (string $base) {
            $remote = static::__remote_address();

            $plain = static::__probe_view(static::__http($remote, 80, $base . 'plain?marker=plain'));
            static::__assert_equals($remote, $plain['remote_addr'], 'REMOTE_ADDR is the client on :80');
            static::__assert_equals($remote, $plain['x_real_ip'], 'X-Real-IP is the client');
            static::__assert_equals('http', $plain['x_forwarded_proto'], ':80 pins X-Forwarded-Proto to http');
            static::__assert_null($plain['https'], ':80 does not set HTTPS');
            static::__assert_null($plain['x_rsx_scheme'], 'X-Rsx-Scheme is never forwarded to PHP');
            static::__assert_equals($base . 'plain?marker=plain', $plain['request_uri'], 'the URI is the one the client sent');

            $claimed = static::__probe_view(static::__http($remote, 80, $base . 'claimed', ['X-Forwarded-Proto' => 'https']));
            static::__assert_equals('http', $claimed['x_forwarded_proto'], 'a client\'s own X-Forwarded-Proto is replaced by the listener\'s');

            $secure = static::__probe_view(static::__http($remote, 8000, $base . 'secure?marker=secure'));
            static::__assert_equals($remote, $secure['remote_addr'], 'REMOTE_ADDR is the client on :8000');
            static::__assert_equals('on', $secure['https'], ':8000 sets HTTPS=on');
            static::__assert_equals('https', $secure['x_forwarded_proto'], ':8000 pins X-Forwarded-Proto to https');

            $plain_event = static::__dispatched_event($base . 'plain');
            static::__assert_contains(' ' . $remote . ' web GET ', $plain_event['text'], 'scheduled under the client address');
            static::__assert_contains('to http ', $plain_event['text'], ':80 -> the http flavour');
            static::__assert_contains('to https ', static::__dispatched_event($base . 'secure')['text'], ':8000 -> the https flavour');
        });
    }

    /** An X-Forwarded-For written by an untrusted client is never believed, by nginx or the proxy. */
    public static function test_a_forged_forwarded_for_from_an_untrusted_client_is_not_believed()
    {
        static::__with_probe(function (string $base) {
            $remote = static::__remote_address();

            $view = static::__probe_view(static::__http($remote, 80, $base . 'forged', [
                'X-Forwarded-For' => '203.0.113.66',
                'X-Real-IP' => '203.0.113.67',
            ]));

            static::__assert_equals($remote, $view['remote_addr'], 'REMOTE_ADDR is the real peer, not the forged entry');
            static::__assert_equals($remote, $view['x_real_ip'], 'the client\'s own X-Real-IP is replaced');
            static::__assert_contains(' ' . $remote . ' web ', static::__dispatched_event($base . 'forged')['text'], 'the proxy schedules the real peer');
        });
    }

    /**
     * Through a trusted proxy (loopback is always trusted) the X-Forwarded-For client is the
     * client at both hops: nginx resolves it, the proxy schedules it, PHP's REMOTE_ADDR is it.
     */
    public static function test_a_trusted_proxy_forwarded_client_reaches_php()
    {
        static::__with_probe(function (string $base) {
            $view = static::__probe_view(static::__http(null, 80, $base . 'trusted', ['X-Forwarded-For' => self::FORWARDED_CLIENT]));
            static::__assert_equals(self::FORWARDED_CLIENT, $view['remote_addr'], 'REMOTE_ADDR is the forwarded client');
            static::__assert_equals(self::FORWARDED_CLIENT, $view['x_real_ip']);

            $event = static::__dispatched_event($base . 'trusted');
            static::__assert_contains(' ' . self::FORWARDED_CLIENT . ' web ', $event['text'], 'scheduled under the forwarded client, not as loopback');
            static::__assert_false(str_contains($event['text'], '[loopback'), 'a forwarded client is not loopback');
        });
    }

    /** Only the public hop writes the access log: one line per request, under the real client. */
    public static function test_the_access_log_has_one_line_per_request()
    {
        static::__with_probe(function (string $base) {
            $remote = static::__remote_address();
            $marker = 'logmarker' . bin2hex(random_bytes(6));

            static::__probe_view(static::__http($remote, 80, $base . 'log?' . $marker));

            $lines = [];
            static::__poll_until(function () use ($marker, &$lines) {
                $lines = array_values(array_filter(explode("\n", (string) @file_get_contents(self::ACCESS_LOG)), fn ($line) => str_contains($line, $marker)));

                return count($lines) > 0;
            }, 'the request is in ' . self::ACCESS_LOG);

            static::__assert_count(1, $lines, 'logged once: ' . implode(' | ', $lines));
            static::__assert_true(str_starts_with($lines[0], $remote . ' '), 'logged under the real client: ' . $lines[0]);
        });
    }

    /**
     * RSpade has no .php URLs: each public listener answers /index.php with 404 - never
     * executing it, never serving the front controller's source - and the proxy never sees
     * it. "/" reaches the application through the proxy (the one location that runs PHP for
     * it pins the scheme; the .php location that did not is now a refusal).
     */
    public static function test_php_urls_are_refused_and_the_root_reaches_the_application()
    {
        static::__require_live_stack();
        static::$__processes = [];

        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $since = static::__now_iso();

        foreach ([80, 8000] as $port) {
            $response = static::__http(null, $port, '/index.php', ['Host' => $host]);
            static::__assert_equals(404, $response['status'], ':' . $port . ' refuses /index.php');
            static::__assert_false(str_contains($response['body'], '<?php'), ':' . $port . ' never serves the front controller\'s source');
        }
        static::__assert_empty(array_filter(static::__events_since($since), fn ($event) => str_contains($event['text'], '/index.php')), 'a .php URL never reaches the proxy');

        $root = static::__http(null, 80, '/', ['Host' => $host]);
        static::__assert_not_equals(404, $root['status'], '"/" is answered by the application');
        static::__assert_not_empty(array_filter(static::__events_for('/'), fn ($event) => $event['type'] === 'dispatched' && $event['ts'] >= $since), '"/" went through the proxy');
    }

    // ------------------------------------------------------------------------------------
    // Build artifacts
    // ------------------------------------------------------------------------------------

    /**
     * A compiled bundle and a mirrored external that exist are served by nginx from disk -
     * the proxy never sees them - with AssetHandler's headers; a missing one goes to PHP.
     */
    public static function test_build_artifacts_on_disk_are_served_by_nginx()
    {
        static::__require_live_stack();
        static::$__processes = [];

        $bundle = null;
        foreach (glob(Rsx_Project_Paths::bundles_dir() . '/*') as $path) {
            if (preg_match('/^[A-Za-z0-9_]+__(vendor|app)\.[a-f0-9]{8}\.js$/', basename($path))) {
                $bundle = basename($path);
                break;
            }
        }
        $vendor = null;
        foreach (glob(\App\RSpade\Core\Bundle\Cdn_Cache::get_cache_directory() . '/*.woff2') as $path) {
            if (preg_match(\App\RSpade\Core\Bundle\Cdn_Cache::FILENAME_PATTERN, basename($path))) {
                $vendor = basename($path);
                break;
            }
        }
        if ($bundle === null || $vendor === null) {
            static::__skip('No compiled .js bundle or mirrored .woff2 on disk to request.');
        }

        $since = static::__now_iso();

        $js = static::__http(null, 80, '/_compiled/' . $bundle);
        static::__assert_equals(200, $js['status']);
        static::__assert_equals(strtolower('application/javascript; charset=utf-8'), strtolower($js['headers']['content-type'][0]));
        static::__assert_equals('max-age=1209600, public, immutable', implode(', ', $js['headers']['cache-control']));
        static::__assert_true(isset($js['headers']['expires']), 'Expires is set');
        static::__assert_equals(['nosniff'], $js['headers']['x-content-type-options']);
        static::__assert_equals((string) filesize(Rsx_Project_Paths::bundles_dir() . '/' . $bundle), $js['headers']['content-length'][0], 'the file on disk');

        $font = static::__http(null, 8000, '/_vendor/' . $vendor);
        static::__assert_equals(200, $font['status']);
        static::__assert_equals('font/woff2', $font['headers']['content-type'][0], 'no charset on a font');
        static::__assert_equals('max-age=2592000, public, immutable', implode(', ', $font['headers']['cache-control']));
        static::__assert_equals(['https'], $font['headers']['x-forwarded-proto'], ':8000 keeps its X-Forwarded-Proto response header');

        $missing = 'Proxority_Live_Bundle__app.' . substr(bin2hex(random_bytes(4)), 0, 8) . '.js';
        $miss = static::__http(null, 80, '/_compiled/' . $missing);
        static::__assert_equals(404, $miss['status'], 'a missing bundle is PHP\'s 404');

        $events = static::__events_since($since);
        static::__assert_empty(array_filter($events, fn ($event) => str_contains($event['text'], $bundle) || str_contains($event['text'], $vendor)), 'the proxy never saw the files on disk');
        static::__assert_not_empty(array_filter($events, fn ($event) => $event['type'] === 'dispatched' && str_contains($event['text'], $missing) && str_contains($event['text'], 'unthrottled')), 'the miss went through the proxy, unthrottled');
    }

    // ------------------------------------------------------------------------------------
    // Scheduling on the real pools
    // ------------------------------------------------------------------------------------

    /**
     * A flood from one address stops at the per-IP cap and queues; a second address is
     * dispatched on arrival, ahead of the flood's queued requests; every request completes.
     *
     * N is the web pool's capacity as `proxority.js status` reports it. The first address
     * sends N+2 requests at once, each answering by itself after the probe's two-second hold:
     * N-1 (its cap) are dispatched on arrival and the other three queue. Once all three are
     * queued, a second address sends one. Every assertion about WHEN reads the order of the
     * proxy's event ring, never elapsed time; a dispatched request php-fpm has not yet found a
     * child for (an ondemand pool) only answers later, and still answers.
     */
    public static function test_a_flood_from_one_address_leaves_a_worker_for_another()
    {
        static::__with_probe(function (string $base) {
            $remote = static::__remote_address();
            $capacity = static::__capacity_from_cli('web');
            if ($capacity < 2) {
                static::__skip('The web pool has ' . $capacity . ' worker; a per-IP cap of N-1 needs at least 2.');
            }
            $cap = $capacity - 1;
            static::__assert_equals($cap, (int) static::__pool('web')['caps']['per_ip'], 'the per-IP cap is N-1');

            static::__poll_until(function () {
                $pool = static::__pool('web');

                return $pool['busy_total'] === 0 && $pool['queued_total'] === 0;
            }, 'the web pool is idle before the flood (other traffic on the box would make the counts ambiguous)');

            $requests = [];
            for ($i = 0; $i < $capacity + 2; $i++) {
                $requests['a' . $i] = static::__start_request($remote, 80, $base . 'a' . $i . '?hold=1');
            }

            // [label => [type => first ring position]] from ONE read of the ring.
            $positions = function () use ($base, &$requests): array {
                $frame = static::__socket_verb('log');
                static::__assert_true($frame['ok'] ?? false, 'the live proxy answers its log');
                $answer = [];
                foreach (array_keys($requests) as $label) {
                    $pattern = '# ' . preg_quote($base . $label, '#') . '( |$)#';
                    foreach ($frame['events'] as $position => $event) {
                        if (preg_match($pattern, $event['text']) === 1 && !isset($answer[$label][$event['type']])) {
                            $answer[$label][$event['type']] = $position;
                        }
                    }
                }

                return $answer;
            };
            $queued_labels = fn (array $seen) => array_keys(array_filter($seen, fn ($types) => isset($types['queued'])));

            static::__poll_until(fn () => count($queued_labels($positions())) >= 3, "the first address's requests beyond its cap of {$cap} queue");

            $requests['b0'] = static::__start_request(null, 80, $base . 'b0?hold=1', ['X-Forwarded-For' => self::FORWARDED_CLIENT]);

            foreach ($requests as $label => $process) {
                $view = static::__probe_view(static::__finish($process));
                static::__assert_equals($label, $view['label'], 'each request is answered by its own probe');
                static::__assert_equals($label === 'b0' ? self::FORWARDED_CLIENT : $remote, $view['remote_addr']);
            }

            $seen = $positions();
            $queued = $queued_labels($seen);
            static::__assert_count(3, $queued, 'exactly the requests beyond the cap of ' . $cap . ' queued: ' . json_encode($seen));
            static::__assert_false(in_array('b0', $queued, true), 'the second address is dispatched on arrival, never queued: ' . json_encode($seen));
            static::__assert_count($cap, array_filter(array_keys($seen), fn ($label) => $label !== 'b0' && !in_array($label, $queued, true)), 'the first address gets exactly its cap on arrival: ' . json_encode($seen));

            $second = $seen['b0']['dispatched'] ?? null;
            static::__assert_not_null($second, 'the second address was dispatched');
            foreach ($queued as $label) {
                static::__assert_not_null($seen[$label]['dispatched'] ?? null, $label . ' ran after queueing');
                static::__assert_greater_than($second, $seen[$label]['dispatched'], 'the second address was dispatched before the queued ' . $label . ': ' . json_encode($seen));
            }
        });
    }

    /**
     * Media: a multipart upload is counted in the media half until its LAST byte (the proxy
     * releases it at the response's end, not at its head), and a framework download route is
     * classified media from the live whitelist.
     */
    public static function test_uploads_and_downloads_count_as_media()
    {
        static::__with_probe(function (string $base) {
            $remote = static::__remote_address();

            $view = static::__probe_view(static::__http($remote, 80, $base . 'upload', [], ['-F', 'file=@' . base_path('artisan')]));
            static::__assert_equals(1, $view['uploaded_files'], 'the upload reached PHP');

            $upload_events = [];
            static::__poll_until(function () use ($base, &$upload_events) {
                $upload_events = static::__events_for($base . 'upload');

                return static::__first($upload_events, 'released') !== null;
            }, 'the proxy released the upload');
            static::__assert_contains('media]', $upload_events[static::__first($upload_events, 'dispatched')]['text'], 'a multipart upload is media');
            static::__assert_contains('(response end)', $upload_events[static::__first($upload_events, 'released')]['text'], 'media is counted until the response ends, not released at its head');

            $since = static::__now_iso();
            $key = bin2hex(random_bytes(16));
            // From loopback under the APP_URL host, so the application answers it as itself.
            static::__http(null, 80, '/_download/' . $key, ['Host' => (string) parse_url((string) config('app.url'), PHP_URL_HOST)]);
            $media = array_filter(static::__events_since($since), fn ($event) => $event['type'] === 'dispatched' && str_contains($event['text'], '/_download/' . $key));
            static::__assert_count(1, $media, 'the download went through the proxy');
            static::__assert_contains('media]', array_values($media)[0]['text'], 'a /_download/ request is media');
        });
    }

    // ------------------------------------------------------------------------------------
    // The control surface
    // ------------------------------------------------------------------------------------

    /** `status` and `log --follow` work against the live socket; the live whitelist refreshed ok. */
    public static function test_status_and_follow_against_the_live_socket()
    {
        static::__with_probe(function (string $base) {
            $status = new Process(['node', base_path('bin/proxority/proxority.js'), 'status']);
            $status->setTimeout(null);
            $status->run();
            static::__assert_equals(0, $status->getExitCode(), $status->getErrorOutput());
            static::__assert_contains('Pool web: capacity', $status->getOutput());
            static::__assert_contains('Pool ajax: capacity', $status->getOutput());

            $whitelist = static::__status_or_null()['whitelist'];
            static::__assert_not_null($whitelist['accepted_at'], 'the live whitelist has been accepted: ' . json_encode($whitelist));
            static::__assert_greater_than(0, $whitelist['counts']['unthrottled']);
            static::__assert_greater_than(0, $whitelist['counts']['media']);
            static::__assert_equals('ok', substr((string) $whitelist['last_outcome'], 0, 2), 'the last refresh succeeded: ' . $whitelist['last_outcome']);

            $follow = new Process(['node', base_path('bin/proxority/proxority.js'), 'log', '--follow']);
            $follow->setTimeout(null);
            $follow->start();
            static::$__processes[] = $follow;

            static::__poll_until(fn () => $follow->getOutput() !== '' || !$follow->isRunning(), 'log --follow prints the ring');
            static::__probe_view(static::__http(static::__remote_address(), 80, $base . 'follow'));
            static::__poll_until(fn () => str_contains($follow->getOutput(), '[released]') && str_contains($follow->getOutput(), $base . 'follow'), 'log --follow streams the new request: ' . $follow->getErrorOutput());
            static::__assert_true($follow->isRunning(), 'follow keeps streaming');
        });
    }
}
