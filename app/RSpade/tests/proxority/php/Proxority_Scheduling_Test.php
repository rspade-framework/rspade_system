<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Proxority\Php;

use App\RSpade\Tests\Proxority\Php\Proxority_Test_Abstract;

/**
 * The fairness policy through a real proxy: per-IP, media and robot caps, round robin,
 * priority, loopback and unthrottled requests, release on the first byte versus the last,
 * and clients that leave. A request holds its worker until the test releases it at the fake
 * upstream, so every ordering asserted here is decided by the proxy, never by timing.
 */
class Proxority_Scheduling_Test extends Proxority_Test_Abstract
{
    public static function test_one_address_gets_at_most_n_minus_one_workers()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 4);

            foreach (['a1', 'a2', 'a3'] as $token) {
                static::__send_from($s, '203.0.113.1', '/page?hold=' . $token);
            }
            static::__wait_arrived($s, 3);
            static::__send_from($s, '203.0.113.1', '/page?hold=a4');
            static::__wait_queued($s, 1);

            $pool = static::__pool($s);
            static::__assert_equals(3, $pool['caps']['per_ip']);
            static::__assert_equals(3, $pool['busy_total'], 'the fourth worker is idle, and still a1..a3 hold three');
            static::__assert_equals([['ip' => '203.0.113.1', 'count' => 1]], $pool['queued_by_ip']);

            static::__send_from($s, '203.0.113.2', '/page?hold=b1');
            static::__assert_equals('b1', static::__wait_arrived($s, 4)[3], 'another address takes the free worker');

            static::__release($s, 'a1');
            static::__assert_equals('a4', static::__wait_arrived($s, 5)[4], 'the capped address runs again once one of its own finishes');
        });
    }

    public static function test_round_robin_serves_one_request_per_address_per_turn()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 3);

            // Fill the pool: 3 workers, per-IP cap 2.
            static::__send_from($s, '198.51.100.9', '/page?hold=z1');
            static::__send_from($s, '198.51.100.9', '/page?hold=z2');
            static::__send_from($s, '198.51.100.8', '/page?hold=x1');
            static::__wait_arrived($s, 3);

            $queued = 0;
            foreach ([['a', 'a1'], ['a', 'a2'], ['a', 'a3'], ['b', 'b1'], ['b', 'b2'], ['c', 'c1']] as [$who, $token]) {
                $ip = ['a' => '192.0.2.1', 'b' => '192.0.2.2', 'c' => '192.0.2.3'][$who];
                static::__send_from($s, $ip, '/page?hold=' . $token);
                static::__wait_queued($s, ++$queued);
            }

            // Free one worker at a time and see who gets it.
            $order = [];
            $running = 3;
            foreach (['z1', 'z2', 'x1', 'a1', 'b1', 'c1'] as $finished) {
                static::__release($s, $finished);
                $arrived = static::__wait_arrived($s, ++$running);
                $order[] = end($arrived);
            }

            static::__assert_equals(['a1', 'b1', 'c1', 'a2', 'b2', 'a3'], $order);
        });
    }

    public static function test_priority_jumps_the_queue_but_never_the_per_ip_cap()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 3);

            static::__send_from($s, '198.51.100.9', '/_ajax/fill?hold=z1');
            static::__send_from($s, '198.51.100.9', '/_ajax/fill?hold=z2');
            static::__send_from($s, '198.51.100.8', '/_ajax/fill?hold=x1');
            static::__wait_arrived($s, 3);

            static::__send_from($s, '192.0.2.1', '/_ajax/Some_Controller/method?hold=a1');
            static::__wait_queued($s, 1);
            static::__send_from($s, '198.51.100.9', '/_ajax/_priority?hold=zp');
            static::__wait_queued($s, 2);
            static::__send_from($s, '192.0.2.3', '/_ajax/_priority?hold=cp');
            static::__wait_queued($s, 3);

            static::__assert_equals(2, static::__pool($s)['queued_priority']);

            static::__release($s, 'x1');
            static::__assert_equals('cp', static::__wait_arrived($s, 4)[3], 'z is at its cap of 2, so the other priority request runs first');

            static::__release($s, 'z1');
            static::__assert_equals('zp', static::__wait_arrived($s, 5)[4], 'z\'s priority request runs once z is under its cap, ahead of a1');

            static::__release($s, 'z2');
            static::__assert_equals('a1', static::__wait_arrived($s, 6)[5]);
        });
    }

    public static function test_a_revalidation_jumps_the_queue_but_never_the_per_ip_cap()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 3);

            // 3 workers, per-IP cap 2.
            static::__send_from($s, '198.51.100.9', '/page?hold=z1');
            static::__send_from($s, '198.51.100.9', '/page?hold=z2');
            static::__send_from($s, '198.51.100.8', '/page?hold=x1');
            static::__wait_arrived($s, 3);

            static::__send_from($s, '192.0.2.1', '/page?hold=a1');
            static::__wait_queued($s, 1);
            static::__send_from($s, '192.0.2.4', '/page?hold=dp', ['If-None-Match' => '"v1"'], 'POST');
            static::__wait_queued($s, 2);
            static::__send_from($s, '198.51.100.9', '/page?hold=zr', ['If-None-Match' => '"v1"']);
            static::__wait_queued($s, 3);
            static::__send_from($s, '192.0.2.3', '/page?hold=cr', ['If-Modified-Since' => 'Tue, 03 Nov 2026 10:00:00 GMT']);
            static::__wait_queued($s, 4);

            static::__assert_equals(2, static::__pool($s)['queued_priority'], 'the two GET revalidations are priority; the POST is not');

            static::__release($s, 'x1');
            static::__assert_equals('cr', static::__wait_arrived($s, 4)[3], 'z is at its cap of 2, so the other revalidation runs first');

            static::__release($s, 'z1');
            static::__assert_equals('zr', static::__wait_arrived($s, 5)[4], 'z\'s revalidation runs once z is under its cap, ahead of a1');

            static::__release($s, 'z2');
            static::__assert_equals('a1', static::__wait_arrived($s, 6)[5], 'then the round robin, oldest address first');

            static::__release($s, 'cr');
            static::__assert_equals('dp', static::__wait_arrived($s, 7)[6]);
        });
    }

    public static function test_a_priority_call_under_a_portal_prefix_jumps_the_queue()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 2);

            // 2 workers, per-IP cap 1; a portal's prefixed Ajax shares the one pool like every request.
            static::__send_from($s, '198.51.100.9', '/page?hold=z1');
            static::__send_from($s, '198.51.100.8', '/page?hold=x1');
            static::__wait_arrived($s, 2);

            static::__send_from($s, '192.0.2.1', '/page?hold=a1');
            static::__wait_queued($s, 1);
            static::__send_from($s, '192.0.2.2', '/_portal/_ajax/_priority?hold=bp', [], 'POST');
            static::__wait_queued($s, 2);

            static::__assert_equals(1, static::__pool($s)['queued_priority']);

            static::__release($s, 'x1');
            static::__assert_equals('bp', static::__wait_arrived($s, 3)[2]);
        });
    }

    public static function test_media_takes_half_the_pool_and_holds_its_worker_to_the_last_byte()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 4);
            $multipart = ['Content-Type' => 'multipart/form-data; boundary=x'];
            $body = "--x\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n--x--\r\n";

            // Two uploads (multipart) and a whitelisted download; the head of each is sent at
            // once and the body is held, so "the response has started" is visible to the client.
            $m1 = static::__send_from($s, '192.0.2.1', '/upload?head=m1', $multipart, 'POST', $body);
            $m2 = static::__send_from($s, '192.0.2.2', '/upload?head=m2', $multipart, 'POST', $body);
            static::__wait_arrived($s, 2);
            static::__assert_equals(200, static::__read_head($m1)['status']);
            static::__assert_equals(200, static::__read_head($m2)['status']);

            static::__send_from($s, '192.0.2.3', '/dl/key1/file.pdf?head=m3');
            static::__wait_queued($s, 1);

            $pool = static::__pool($s);
            static::__assert_equals(2, $pool['caps']['media']);
            static::__assert_equals(2, $pool['busy_total'], 'both uploads hold their workers after their heads were sent');
            static::__assert_equals(2, $pool['busy_by_class']['media']);

            // A page passes the waiting download and gives its worker back at the first byte.
            $page = static::__send_from($s, '192.0.2.4', '/page?head=n1');
            static::__wait_arrived($s, 3);
            static::__assert_equals(200, static::__read_head($page)['status']);
            static::__wait_busy($s, 2);
            static::__assert_equals(0, static::__pool($s)['busy_by_class']['normal'], 'the page is released at its head while its body is still held');

            // The download runs only when an upload's response has ENDED.
            static::__release($s, 'm1');
            static::__assert_equals('m3', static::__wait_arrived($s, 4)[3]);
            static::__assert_contains('ok m1', (string) stream_get_contents($m1));
        });
    }

    public static function test_robots_take_a_fifth_of_the_pool()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 4);

            static::__send_from($s, '66.249.66.1', '/page?hold=r1', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);
            static::__wait_arrived($s, 1);
            static::__send_from($s, '203.0.113.50', '/page?hold=r2', ['User-Agent' => 'ExampleCrawler/1.0']);
            static::__wait_queued($s, 1);

            $pool = static::__pool($s);
            static::__assert_equals(1, $pool['caps']['robot']);
            static::__assert_equals(1, $pool['busy_by_class']['robot']);

            static::__send_from($s, '192.0.2.1', '/page?hold=p1', ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0']);
            static::__assert_equals('p1', static::__wait_arrived($s, 2)[1], 'a person passes the waiting robot');

            static::__release($s, 'r1');
            static::__assert_equals('r2', static::__wait_arrived($s, 3)[2]);
        });
    }

    public static function test_loopback_is_never_queued_and_never_counted()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 1);

            foreach (['l1', 'l2', 'l3'] as $token) {
                static::__send_from($s, '127.0.0.1', '/page?hold=' . $token);
            }
            // No X-Real-IP: the connection's own address, which is loopback here.
            static::__send($s, '/page?hold=l4');
            static::__send_from($s, '::1', '/page?hold=l5');
            static::__wait_arrived($s, 5);

            $pool = static::__pool($s);
            static::__assert_equals(0, $pool['busy_total'], 'loopback takes no worker');
            static::__assert_equals(5, $pool['loopback_running']);

            static::__send_from($s, '192.0.2.1', '/page?hold=r1');
            static::__assert_equals('r1', static::__wait_arrived($s, 6)[5], 'the single worker is still free for a remote client');
            static::__wait_busy($s, 1);
        });
    }

    public static function test_unthrottled_runs_at_once_and_is_counted_busy()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 1);

            static::__send_from($s, '192.0.2.1', '/page?hold=p1');
            static::__wait_arrived($s, 1);

            static::__send_from($s, '192.0.2.2', '/_compiled/app.js?hold=u1');
            static::__assert_equals('u1', static::__wait_arrived($s, 2)[1], 'a whitelisted unthrottled path is never queued');

            static::__send_from($s, '192.0.2.3', '/page?hold=p2');
            static::__wait_queued($s, 1);

            $pool = static::__pool($s);
            static::__assert_equals(2, $pool['busy_total'], 'unthrottled counts busy, past capacity');
            static::__assert_equals(1, $pool['busy_by_class']['unthrottled']);

            static::__release($s, 'u1');
            static::__wait_busy($s, 1);
            static::__assert_equals(1, static::__pool($s)['queued_total'], 'still at capacity: p2 waits for p1');

            static::__release($s, 'p1');
            static::__assert_equals('p2', static::__wait_arrived($s, 3)[2]);
        });
    }

    public static function test_a_client_that_leaves_is_dropped_from_the_queue_or_aborted_upstream()
    {
        static::__in_scratch(function (array $s) {
            $s = static::__start_proxy($s, [], 1);

            $running = static::__send_from($s, '192.0.2.1', '/page?hold=a1');
            static::__wait_arrived($s, 1);
            $queued = static::__send_from($s, '192.0.2.2', '/page?hold=b1');
            static::__wait_queued($s, 1);

            fclose($queued);
            static::__wait_queued($s, 0);
            static::__wait_event($s, 'client_abort', 'left while queued');

            fclose($running);
            static::__wait_busy($s, 0);
            static::__wait_event($s, 'client_abort', 'left while running');
            static::__poll_until(fn () => static::__upstream_state($s)['requests'][0]['aborted'] === true, 'the upstream request was aborted');

            static::__assert_equals(['a1'], static::__arrived($s), 'the request that left the queue never reached the upstream');
        });
    }
}
