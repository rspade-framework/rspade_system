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
 * What the operator and the application see of the proxy: the whitelist refresh in every
 * outcome, the status socket and its CLI (status, log, log --follow, close, open, hold, release), and the
 * socket file's own lifecycle (a stale file is replaced, a live one is never stolen).
 */
class Proxority_Control_Test extends Proxority_Test_Abstract
{
    public static function test_the_whitelist_refresh_accepts_only_a_clean_answer()
    {
        static::__in_scratch(function (array $s) {
            // Boot with a failing command: nothing is whitelisted, the fixed priority rule stands.
            $s = static::__start_proxy($s, ['refresh-interval' => '0.5'], 4, 'fail');
            static::__assert_contains('the fake command failed on purpose', static::__wait_event($s, 'refresh_failed', 'exited 1')['text']);
            static::__assert_null(static::__status($s)['whitelist']['accepted_at']);

            static::__send_from($s, '198.51.100.7', '/_compiled/app.js?hold=t0');
            static::__wait_arrived($s, 1);
            static::__assert_false(str_contains(static::__wait_event($s, 'dispatched', 'app.js')['text'], 'unthrottled'), 'before the first answer nothing is whitelisted');
            static::__release($s, 't0');

            // A clean answer replaces the list, and requests are classified by it.
            $since = static::__now_iso();
            static::__refresh_mode($s, 'ok', static::STANDARD_ANSWER);
            static::__wait_event($s, 'refresh_ok', '1 unthrottled, 1 priority, 1 media', $since);
            $whitelist = static::__status($s)['whitelist'];
            static::__assert_equals(['unthrottled' => 1, 'priority' => 1, 'media' => 1], $whitelist['counts']);

            static::__send_from($s, '198.51.100.7', '/_compiled/app.js?hold=t1');
            static::__assert_contains('[unthrottled]', static::__wait_event($s, 'dispatched', '/_compiled/app.js', $since)['text']);
            static::__send_from($s, '198.51.100.7', '/prio/5?hold=t2');
            static::__assert_contains('[priority]', static::__wait_event($s, 'dispatched', '/prio/5')['text']);
            static::__send_from($s, '198.51.100.7', '/dl/key/name.pdf?hold=t3');
            static::__assert_contains('[media]', static::__wait_event($s, 'dispatched', '/dl/key/name.pdf')['text']);

            // Maintenance, bad JSON and a hung run all keep the list.
            $since = static::__now_iso();
            static::__refresh_mode($s, 'maintenance');
            static::__wait_event($s, 'refresh_maintenance', '', $since);
            static::__assert_equals(['unthrottled' => 1, 'priority' => 1, 'media' => 1], static::__status($s)['whitelist']['counts']);

            $since = static::__now_iso();
            static::__refresh_mode($s, 'badjson');
            static::__wait_event($s, 'refresh_failed', 'not JSON', $since);
            static::__assert_equals(['unthrottled' => 1, 'priority' => 1, 'media' => 1], static::__status($s)['whitelist']['counts']);

            $since = static::__now_iso();
            static::__refresh_mode($s, 'hang');
            static::__wait_event($s, 'refresh_skipped', 'still running', $since);
            $whitelist = static::__status($s)['whitelist'];
            static::__assert_not_null($whitelist['running_since'], 'the status shows the run that is still going');
            static::__assert_equals(['unthrottled' => 1, 'priority' => 1, 'media' => 1], $whitelist['counts']);
            static::__assert_equals($whitelist['accepted_at'], static::__status($s)['whitelist']['accepted_at']);
        });
    }

    public static function test_status_and_log_over_the_socket_and_the_cli()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 4);

            static::__send_from($s, '192.0.2.1', '/page?hold=a1');
            static::__send_from($s, '192.0.2.1', '/page?hold=a2');
            static::__send_from($s, '192.0.2.1', '/page?hold=a3');
            static::__wait_arrived($s, 3);
            static::__send_from($s, '192.0.2.1', '/page?hold=a4');
            static::__wait_queued($s, 1);

            $status = static::__cli($s, ['status']);
            static::__assert_contains('Pool web: capacity 4 (caps: per IP 3, media 2, robot 1)', $status);
            static::__assert_contains('busy 3/4: normal 3, priority 0, media 0, robot 0, unthrottled 0', $status);
            static::__assert_contains('queued 1 (priority 0)', $status);
            static::__assert_contains('192.0.2.1  1', $status);
            static::__assert_contains('Whitelist: 1 unthrottled, 1 priority, 1 media pattern(s)', $status);
            static::__assert_true((bool) preg_match('/^[\x09\x0a\x0d\x20-\x7e]*$/', $status), 'the status output is plain ASCII');

            $log = static::__cli($s, ['log']);
            static::__assert_contains('[start] listening on 127.0.0.1:' . $s['port'], $log);
            static::__assert_contains('[queued] #4 192.0.2.1 GET /page', $log);

            $follow = static::__spawn(['node', static::__proxority_js(), 'log', '--follow', '--socket=' . $s['socket']]);
            static::__poll_until(fn () => str_contains($follow->getOutput(), '[start]'), 'log --follow prints the ring first');

            static::__release($s, 'a1');
            static::__poll_until(fn () => str_contains($follow->getOutput(), '[dispatched] #4 192.0.2.1'), 'log --follow streams the next event');
            $follow->signal(SIGINT);
            $follow->wait();

            $missing = new Process(['node', static::__proxority_js(), 'status', '--socket=' . $s['root'] . '/nobody.sock']);
            $missing->setTimeout(null);
            $missing->run();
            static::__assert_equals(1, $missing->getExitCode());
            static::__assert_contains('Proxority is not running', $missing->getErrorOutput());
        });
    }

    /**
     * CLOSED TO EXTERNAL CLIENTS (Proxority_Gate; a sequential rsx:test run inside the
     * container). `close` returns only once the external request already running has finished;
     * meanwhile a new external request is refused with a 503 naming the reason and loopback
     * still passes. `open` admits everyone again, and a closure whose holder process has died
     * reopens on the next request it would have refused.
     */
    public static function test_close_drains_refuses_external_clients_and_reopens()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 4);

            $holder = new Process(['sleep', 'infinity']);
            $holder->setTimeout(null);
            $holder->start();
            $holder_pid = $holder->getPid();

            static::__send_from($s, '198.51.100.7', '/page?hold=e1');
            static::__wait_arrived($s, 1);

            $closer = static::__spawn(['node', static::__proxority_js(), 'close', '--reason=a test run is in progress',
                '--holder-pid=' . $holder_pid, '--socket=' . $s['socket']]);
            static::__wait_event($s, 'closed', 'a test run is in progress');
            static::__assert_true($closer->isRunning(), 'close waits while an external request is still running');

            $refused = static::__read_response(static::__send_from($s, '198.51.100.8', '/page?hold=never'));
            static::__assert_equals(503, $refused['status'], 'a new external request is refused while closed');
            static::__assert_equals('30', $refused['headers']['retry-after'] ?? null);
            static::__assert_contains('a test run is in progress', $refused['body']);

            static::__send($s, '/page?hold=l1');
            static::__assert_equals(['e1', 'l1'], static::__wait_arrived($s, 2), 'a loopback request still reaches the upstream');

            static::__release($s, 'e1');
            $closer->wait();
            static::__assert_equals(0, $closer->getExitCode(), 'close succeeded: ' . $closer->getErrorOutput());
            static::__assert_contains('[OK] Closed to external clients: a test run is in progress', $closer->getOutput());

            $closed = static::__status($s)['closed'];
            static::__assert_equals('a test run is in progress', $closed['reason']);
            static::__assert_equals($holder_pid, $closed['holder_pid']);
            static::__assert_contains('CLOSED to external clients', static::__cli($s, ['status']));

            static::__assert_contains('[OK] Open to every client', static::__cli($s, ['open']));
            static::__send_from($s, '198.51.100.7', '/page?hold=e2');
            static::__assert_equals(['e1', 'l1', 'e2'], static::__wait_arrived($s, 3), 'open admits external clients again');

            // A closer that dies never leaves the site closed. (Nothing external is in flight,
            // so this close returns at once.)
            static::__release($s, 'e2');
            static::__assert_contains('[OK] Closed', static::__cli($s, ['close', '--reason=held by a process that dies', '--holder-pid=' . $holder_pid]));
            $holder->stop(0);
            static::__send_from($s, '198.51.100.7', '/page?hold=e3');
            static::__assert_equals(['e1', 'l1', 'e2', 'e3'], static::__wait_arrived($s, 4), 'a closure whose holder is gone admits the request');
            static::__assert_contains('pid ' . $holder_pid . ', is gone', static::__wait_event($s, 'opened', 'is gone')['text']);
            static::__assert_null(static::__status($s)['closed']);

            foreach (['l1', 'e3'] as $token) {
                static::__release($s, $token);
            }
        });
    }

    /**
     * HELD (the container's security updater, around a php-fpm restart). `hold` returns only
     * once nothing is in flight at php-fpm - a page whose head has arrived but whose body is
     * still coming counts until its upstream response ends. Meanwhile every new request,
     * external and loopback alike, waits parked and is never refused; `release` dispatches
     * the parked requests in arrival order and each one succeeds.
     */
    public static function test_hold_waits_for_in_flight_requests_and_parks_new_ones_until_release()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 4);

            $holder = new Process(['sleep', 'infinity']);
            $holder->setTimeout(null);
            $holder->start();

            $first = static::__send_from($s, '198.51.100.7', '/page?hold=e1');
            static::__send_from($s, '198.51.100.9', '/page?head=e2');
            static::__wait_arrived($s, 2);

            $hold = static::__spawn(['node', static::__proxority_js(), 'hold', '--reason=php security update',
                '--holder-pid=' . $holder->getPid(), '--socket=' . $s['socket']]);
            static::__wait_event($s, 'held', 'php security update');

            $parked_external = static::__send_from($s, '198.51.100.8', '/page?hold=p1');
            $parked_loopback = static::__send($s, '/page?hold=p2');
            static::__poll_until(fn () => static::__status($s)['parked'] === 2, 'two requests parked');
            static::__assert_equals(['e1', 'e2'], static::__arrived($s), 'nothing new is dispatched while held');

            $status = static::__cli($s, ['status']);
            static::__assert_contains('HELD since', $status);
            static::__assert_contains('php security update (held by pid ' . $holder->getPid() . '); 2 request(s) parked', $status);

            // The first in-flight request ends; the second has sent its head but not its body.
            // The client has the whole of e1 only after its upstream response ended.
            static::__release($s, 'e1');
            static::__assert_equals(200, static::__read_response($first)['status']);
            static::__assert_true($hold->isRunning(), 'hold still waits for a response whose body has not ended');
            static::__assert_equals([], array_values(array_filter(static::__events($s), fn ($event) => $event['type'] === 'idle')),
                'nothing is reported idle while a response is still coming');

            static::__release($s, 'e2');
            $hold->wait();
            static::__assert_equals(0, $hold->getExitCode(), 'hold succeeded: ' . $hold->getErrorOutput());
            static::__assert_contains('[OK] Held, nothing in flight: php security update', $hold->getOutput());
            static::__assert_equals(['e1', 'e2'], static::__arrived($s), 'still nothing dispatched once idle');

            static::__assert_contains('[OK] Released; 2 parked request(s) dispatched', static::__cli($s, ['release']));
            static::__assert_equals(['e1', 'e2', 'p1', 'p2'], static::__wait_arrived($s, 4), 'the parked requests are dispatched in arrival order');
            static::__assert_null(static::__status($s)['held']);
            static::__assert_contains('Dispatching (not held)', static::__cli($s, ['status']));

            static::__release($s, 'p1');
            static::__release($s, 'p2');
            foreach ([$parked_external, $parked_loopback] as $client) {
                $response = static::__read_response($client);
                static::__assert_equals(200, $response['status'], 'a request parked while held succeeds after release');
            }

            static::__assert_contains('[OK] Was not held', static::__cli($s, ['release']));
            $holder->stop(0);
        });
    }

    /**
     * A holder that dies without calling `release` never leaves requests waiting: the periodic
     * check sees the pid is no longer that process and releases the hold, dispatching what
     * was parked.
     */
    public static function test_a_hold_whose_holder_dies_is_released()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 4);

            $holder = new Process(['sleep', 'infinity']);
            $holder->setTimeout(null);
            $holder->start();
            $holder_pid = $holder->getPid();

            // Nothing is in flight, so the hold returns at once.
            static::__assert_contains('[OK] Held', static::__cli($s, ['hold', '--reason=held by a process that dies', '--holder-pid=' . $holder_pid]));

            $client = static::__send_from($s, '198.51.100.7', '/page');
            static::__poll_until(fn () => static::__status($s)['parked'] === 1, 'the request is parked');

            $holder->stop(0);
            static::__assert_contains('pid ' . $holder_pid . ', is gone', static::__wait_event($s, 'resumed', 'is gone')['text']);
            static::__assert_equals(200, static::__read_response($client)['status'], 'the parked request is dispatched and succeeds');
            static::__assert_null(static::__status($s)['held']);
            static::__assert_equals(0, static::__status($s)['parked']);
        });
    }

    public static function test_a_stale_socket_is_replaced_and_a_live_one_is_never_stolen()
    {
        static::__in_scratch(function (array $s) {
            // A socket file nobody listens on, as a killed proxy leaves behind.
            $stale = stream_socket_server('unix://' . $s['socket']);
            fclose($stale);
            static::__assert_true(file_exists($s['socket']), 'the stale socket file exists');

            $s = static::__start_proxy($s);

            $second = new Process(static::__proxy_command($s));
            $second->setTimeout(null);
            $second->run();
            static::__assert_equals(1, $second->getExitCode());
            static::__assert_contains('Proxority is already running on ' . $s['socket'], $second->getErrorOutput());
            static::__assert_equals($s['process']->getPid(), static::__status($s)['pid'], 'the first proxy still owns the socket');
        });
    }

    // ------------------------------------------------------------------------------------

    private static function __cli(array $s, array $words): string
    {
        $process = new Process(array_merge(['node', static::__proxority_js()], $words, ['--socket=' . $s['socket']]));
        $process->setTimeout(null);
        $process->run();

        static::__assert_equals(0, $process->getExitCode(), 'proxority.js ' . implode(' ', $words) . ' failed: ' . $process->getErrorOutput());

        return $process->getOutput();
    }
}
