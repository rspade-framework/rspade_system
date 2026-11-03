<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The proxies in front of the container: one effective list, the same for nginx and the
 * application. .env TRUSTED_PROXIES wins, the container's RSPADE_TRUSTED_PROXIES applies when
 * it is blank, and loopback alone remains when both are.
 *
 * The application half is rsx_trusted_proxy_list(); the container half is
 * resource/docker/trusted-proxies.sh, driven through `apply --root=` against a scratch tree
 * holding pool files - never the files of the box running the test. How a trusted hop
 * resolves to the client is Client_Ip_Test's.
 */
class Trusted_Proxies_Test extends Rsx_Test_Abstract
{
    private static string $root = '';

    public static function setup()
    {
        static::$root = sys_get_temp_dir() . '/rsx-trusted-proxies-' . getmypid();
    }

    public static function teardown()
    {
        static::__clear();
    }

    private static function __clear(): void
    {
        if (is_dir(static::$root)) {
            (new Process(['rm', '-rf', static::$root]))->run();
        }
    }

    /** A fresh scratch tree: two pool files and a .env carrying $env_line. */
    private static function __tree(string $env_line): void
    {
        static::__clear();
        $pools = static::$root . '/etc/php/8.5/fpm/pool.d';
        mkdir($pools, 0755, true);
        file_put_contents($pools . '/www.conf', "[www]\nuser = app\n");
        file_put_contents($pools . '/ajax.conf', "[ajax]\nuser = app\n");
        file_put_contents(static::$root . '/env', $env_line . "\n");
    }

    /** Run the script; answers [exit code, output]. */
    private static function __apply(string $container_value): array
    {
        $process = new Process(
            ['bash', base_path('app/RSpade/resource/docker/trusted-proxies.sh'), 'apply', '--root=' . static::$root, '--env-file=' . static::$root . '/env'],
            null,
            ['RSPADE_TRUSTED_PROXIES' => $container_value, 'PATH' => getenv('PATH')]
        );
        $process->setTimeout(null);
        $process->run();

        return [$process->getExitCode(), trim($process->getOutput() . $process->getErrorOutput())];
    }

    private static function __realip(): string
    {
        return (string) @file_get_contents(static::$root . '/etc/nginx/conf.d/rspade-realip.conf');
    }

    private static function __pool(string $pool): string
    {
        return (string) file_get_contents(static::$root . "/etc/php/8.5/fpm/pool.d/{$pool}.conf");
    }

    /** TP-01 */
    public static function test_the_application_list_prefers_env_then_the_container()
    {
        static::__assert_equals(['192.0.2.1'], rsx_trusted_proxy_list('192.0.2.1', '172.18.0.3'), '.env wins');
        static::__assert_equals(['172.18.0.3', '10.0.0.0/8'], rsx_trusted_proxy_list('', ' 172.18.0.3 , 10.0.0.0/8 '), 'blank .env: the container');
        static::__assert_equals(['172.18.0.3'], rsx_trusted_proxy_list(null, '172.18.0.3'), 'absent .env: the container');
        static::__assert_equals([], rsx_trusted_proxy_list('', ''), 'both blank: none (loopback only)');
        static::__assert_equals(['172.18.0.3'], rsx_trusted_proxy_list(' , ', '172.18.0.3'), 'an .env of only separators is blank');
    }

    /** TP-02 */
    public static function test_the_container_value_reaches_nginx_and_both_pools()
    {
        static::__tree('TRUSTED_PROXIES=');
        [$code, $output] = static::__apply('172.18.0.3, 10.0.0.0/8');

        static::__assert_equals(0, $code, $output);
        static::__assert_contains('(from container)', $output);
        foreach (['127.0.0.1', '::1', '172.18.0.3', '10.0.0.0/8'] as $address) {
            static::__assert_contains("set_real_ip_from {$address};", static::__realip(), "{$address} is trusted");
        }
        static::__assert_contains('real_ip_recursive on;', static::__realip());
        foreach (['www', 'ajax'] as $pool) {
            static::__assert_contains('env[RSPADE_TRUSTED_PROXIES] = "172.18.0.3,10.0.0.0/8"', static::__pool($pool), "{$pool} gets the literal");
        }
    }

    /** TP-03 */
    public static function test_env_wins_for_nginx_and_the_pools_still_carry_the_container_value()
    {
        static::__tree('TRUSTED_PROXIES=192.0.2.1');
        [$code, $output] = static::__apply('172.18.0.3');

        static::__assert_equals(0, $code, $output);
        static::__assert_contains('(from .env)', $output);
        static::__assert_contains('set_real_ip_from 192.0.2.1;', static::__realip());
        static::__assert_false(str_contains(static::__realip(), '172.18.0.3'), 'nginx follows .env');
        static::__assert_contains('env[RSPADE_TRUSTED_PROXIES] = "172.18.0.3"', static::__pool('www'), 'the application applies the precedence itself');
    }

    /** TP-04 */
    public static function test_nothing_declared_leaves_loopback_and_clears_a_stale_start()
    {
        static::__tree('TRUSTED_PROXIES=');
        static::__apply('172.18.0.3');
        [$code, $output] = static::__apply('');

        static::__assert_equals(0, $code, $output);
        static::__assert_contains('loopback only', $output);
        static::__assert_equals(2, substr_count(static::__realip(), 'set_real_ip_from'), 'loopback alone, the earlier proxy gone');
        static::__assert_false(str_contains(static::__pool('www'), 'env['), 'the pool line from the earlier start is removed');
        static::__assert_equals(1, substr_count(static::__pool('ajax'), '[ajax]'), 'the pool is otherwise untouched');
    }

    /** TP-05 */
    public static function test_anything_but_addresses_stops_the_start()
    {
        foreach (['1.2.3.4;', '0.0.0.0/0', '::/0', 'foo', '999.1.1.1', "1.2.3.4\nserver { }", '10.0.0.0/33'] as $value) {
            static::__tree('TRUSTED_PROXIES=');
            [$code, $output] = static::__apply($value);

            static::__assert_true($code !== 0, json_encode($value) . ' is refused');
            static::__assert_contains('ERROR', $output);
            static::__assert_equals('', static::__realip(), json_encode($value) . ' writes nothing');
        }
    }

    /** TP-06 */
    public static function test_nginx_accepts_the_generated_file()
    {
        $nginx = (new ExecutableFinder())->find('nginx', null, ['/usr/sbin', '/usr/local/sbin']);
        if ($nginx === null) {
            static::__skip('nginx is not installed on this box');
        }

        static::__tree('TRUSTED_PROXIES=');
        static::__apply('172.18.0.3,2001:db8::/32');

        $conf = static::$root . '/nginx.conf';
        file_put_contents($conf, "pid " . static::$root . "/nginx.pid;\nerror_log " . static::$root . "/error.log;\n"
            . "events {}\nhttp {\n    access_log off;\n    include " . static::$root . "/etc/nginx/conf.d/*.conf;\n}\n");

        $process = new Process([$nginx, '-t', '-c', $conf, '-p', static::$root]);
        $process->setTimeout(null);
        $process->run();

        static::__assert_equals(0, $process->getExitCode(), trim($process->getErrorOutput()));
    }
}
