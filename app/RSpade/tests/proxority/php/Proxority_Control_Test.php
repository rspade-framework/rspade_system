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
 * outcome, the status socket and its CLI (status, log, log --follow), and the socket file's
 * own lifecycle (a stale file is replaced, a live one is never stolen).
 */
class Proxority_Control_Test extends Proxority_Test_Abstract
{
    public static function test_the_whitelist_refresh_accepts_only_a_clean_answer()
    {
        static::__in_scratch(function (array $s) {
            // Boot with a failing command: nothing is whitelisted, the fixed priority rule stands.
            $s = static::__start_proxy($s, ['refresh-interval' => '0.5'], 4, 4, 'fail');
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
            $s = static::__start_proxy($s, [], 4, 2);

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
            static::__assert_contains('Pool ajax: capacity 2 (caps: per IP 1, media 1, robot 1)', $status);
            static::__assert_contains('Whitelist: 1 unthrottled, 1 priority, 1 media pattern(s)', $status);
            static::__assert_true((bool) preg_match('/^[\x09\x0a\x0d\x20-\x7e]*$/', $status), 'the status output is plain ASCII');

            $log = static::__cli($s, ['log']);
            static::__assert_contains('[start] listening on 127.0.0.1:' . $s['port'], $log);
            static::__assert_contains('[queued] #4 192.0.2.1 web GET /page', $log);

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
