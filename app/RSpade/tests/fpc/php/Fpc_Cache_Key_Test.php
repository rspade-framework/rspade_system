<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Fpc\Php;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use App\RSpade\Core\FPC\Rsx_FPC;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The full-page-cache key: fpc:{build_key}:{host}:{sha1(path?sorted_query)}.
 *
 * THE HOST IS PART OF THE KEY. One application answers on more than one host - APP_URL's,
 * and a client portal on a host of its own - and the same path on two hosts is two pages;
 * a host-blind key served one host's cached page to the other. Hosts are compared without
 * ports and without case, as cookies are.
 *
 * THE KEY IS A CONTRACT between Rsx_FPC (PHP, which clears) and system/bin/fpc-proxy.js
 * (Node, which writes and reads), so the proxy's own derivation is run and compared, and
 * the clear tests seed the exact keys the proxy computes.
 *
 * No database access - skip the per-test transaction. Redis DB 2 entries the tests seed
 * are deleted in a finally.
 */
class Fpc_Cache_Key_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * What the Node proxy computes for these inputs (compose_cache_key()).
     */
    private static function __proxy_key(string $build_key, string $host, string $url): string
    {
        $script = 'const p = require(process.argv[1]); process.stdout.write(p.compose_cache_key(process.argv[2], process.argv[3], process.argv[4]));';

        // No timeout: node starts as fast as the machine allows.
        $process = new Process(['node', '-e', $script, base_path('bin/fpc-proxy.js'), $build_key, $host, $url]);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);
        $process->run();

        static::__assert_true($process->isSuccessful(), 'fpc-proxy.js loads as a module: ' . $process->getErrorOutput());

        return $process->getOutput();
    }

    private static function __redis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect((string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);

        $password = env('REDIS_PASSWORD');
        if ($password && $password !== 'null') {
            $redis->auth($password);
        }

        $redis->select(2);

        return $redis;
    }

    // -------------------------------------------------------------------------
    // The derivation
    // -------------------------------------------------------------------------

    public static function test_the_same_path_on_two_hosts_is_two_keys()
    {
        $app = Rsx_FPC::cache_key('app.example.test', '/about', 'B');
        $portal = Rsx_FPC::cache_key('portal.example.test', '/about', 'B');

        static::__assert_not_equals($app, $portal, 'a host-blind key would serve one host the other host\'s page');
        static::__assert_equals('fpc:B:app.example.test:' . sha1('/about'), $app);
    }

    public static function test_the_host_is_compared_without_case_or_port()
    {
        static::__assert_equals(
            Rsx_FPC::cache_key('app.example.test', '/about', 'B'),
            Rsx_FPC::cache_key('App.Example.TEST:8080', '/about', 'B')
        );
        static::__assert_equals('::1', Rsx_FPC::key_host('[::1]:6200'));
    }

    public static function test_query_parameters_are_sorted()
    {
        static::__assert_equals(
            Rsx_FPC::cache_key('h', '/search?b=2&a=1', 'B'),
            Rsx_FPC::cache_key('h', '/search?a=1&b=2', 'B')
        );
    }

    public static function test_php_and_the_proxy_compute_the_same_key()
    {
        $cases = [
            ['app.example.test', '/about'],
            ['Portal.Example.test:8443', '/'],
            ['portal.example.test', '/x/dash?b=2&a=1'],
            ['[::1]:6200', '/search?q=two+words'],
        ];

        foreach ($cases as [$host, $url]) {
            static::__assert_equals(
                Rsx_FPC::cache_key($host, $url, 'build123'),
                static::__proxy_key('build123', $host, $url),
                "proxy and PHP agree on {$host} {$url}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // The clear lever
    // -------------------------------------------------------------------------

    public static function test_a_bare_path_is_cleared_on_every_host_and_a_full_url_on_its_own()
    {
        $build = Manifest::get_build_key();
        $path = '/test-fpc/cache-key-' . bin2hex(random_bytes(4));

        $app_key = static::__proxy_key($build, 'app.example.test', $path);
        $portal_key = static::__proxy_key($build, 'portal.example.test', $path);
        $other_key = static::__proxy_key($build, 'app.example.test', $path . '-other');

        $redis = static::__redis();

        try {
            foreach ([$app_key, $portal_key, $other_key] as $key) {
                $redis->set($key, json_encode(['html' => 'x']));
            }

            // A full URL clears that host's entry only.
            static::__assert_equals(1, Rsx_FPC::clear_url('https://portal.example.test' . $path));
            static::__assert_false((bool) $redis->exists($portal_key), 'the named host is cleared');
            static::__assert_true((bool) $redis->exists($app_key), 'another host keeps its page');

            $redis->set($portal_key, json_encode(['html' => 'x']));

            // A bare path, through the command an operator types, clears it on every host.
            $exit = Artisan::call('rsx:fpc:clear', ['--url' => $path]);
            $output = Artisan::output();

            static::__assert_equals(0, $exit);
            static::__assert_contains('Cleared 2 cached page(s)', $output);
            static::__assert_false((bool) $redis->exists($app_key), 'the application host is cleared');
            static::__assert_false((bool) $redis->exists($portal_key), 'the portal host is cleared');
            static::__assert_true((bool) $redis->exists($other_key), 'a different path is untouched');
        } finally {
            $redis->del([$app_key, $portal_key, $other_key]);
        }
    }
}
