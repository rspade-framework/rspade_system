<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Env\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The first-run screens accept a submission only from a browser on this machine.
 *
 * The APP_URL screen (system/bootstrap/rsx_first_run.php) runs before the autoloader, so it
 * carries a pre-boot copy of is_loopback_ip(): rsx_first_run_is_loopback(). The first-user
 * screen (Rsx_First_User_Setup) calls is_loopback_ip() itself. These tests pin the copy's
 * answers AND that it agrees with the original on every shape, so the two screens can never
 * disagree about who is local.
 *
 * The bootstrap file is required here; its main closure returns at once under the CLI SAPI.
 */
class First_Run_Loopback_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function setup()
    {
        require_once base_path('bootstrap/rsx_first_run.php');
    }

    public static function teardown()
    {
        // The container must hold an ordinary console request again.
        app()->instance('request', Request::create('/'));
    }

    /**
     * [label, $_SERVER, expected]
     */
    private static function __cases(): array
    {
        return [
            ['bare IPv4 loopback', ['REMOTE_ADDR' => '127.0.0.1'], true],
            ['bare IPv6 loopback', ['REMOTE_ADDR' => '::1'], true],
            ['remote peer', ['REMOTE_ADDR' => '203.0.113.9'], false],
            ['loopback proxy forwarding loopback', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'], true],
            ['loopback proxy forwarding a remote client', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], false],
            ['a remote hop anywhere in the chain', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1, 203.0.113.9'], false],
            ['X-Real-IP remote', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '198.51.100.4'], false],
            ['forwarded but naming no client', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'], false],
            ['forwarded entry with a port', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1:51000'], true],
            ['no peer at all', [], false],
        ];
    }

    public static function test_the_pre_boot_predicate()
    {
        foreach (static::__cases() as [$label, $server, $expected]) {
            static::__assert_equals($expected, rsx_first_run_is_loopback($server), $label);
        }
    }

    public static function test_it_agrees_with_is_loopback_ip()
    {
        foreach (static::__cases() as [$label, $server, $expected]) {
            app()->instance('request', Request::create('/', 'GET', [], [], [], $server + ['REMOTE_ADDR' => '']));
            static::__assert_equals(is_loopback_ip(), rsx_first_run_is_loopback($server), "parity: {$label}");
        }
    }
}
