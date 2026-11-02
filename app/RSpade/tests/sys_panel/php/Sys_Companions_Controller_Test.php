<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Companion\Companion_Store;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Companions\_Sys_Companions_Controller;

/**
 * The Companions screen's endpoints, as a developer: the overview lists every declared
 * companion with what each verb the viewer may do; the detail carries status, instances and
 * endpoint traffic; act refuses an unknown verb and an undeclared companion before any daemon
 * is asked. The companion state is hand-written under the test database's Redis prefix, which
 * no live daemon reads or writes.
 */
class Sys_Companions_Controller_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const NAME = 'companion_fixture';

    public static function setup()
    {
        static::__acting_as_user(1);
        Companion_Store::connection()->del(Companion_Store::history_key(self::NAME), Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi')));
    }

    public static function teardown()
    {
        Companion_Store::connection()->del(Companion_Store::history_key(self::NAME), Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi')));
    }

    private static function __endpoint(string $method, array $params = [])
    {
        return _Sys_Companions_Controller::$method(Request::create('/'), $params);
    }

    private static function __assert_error(string $code, $response, string $message): void
    {
        static::__assert_true($response instanceof Error_Response, "{$message}: expected an error response");
        static::__assert_equals($code, $response->get_error_code(), $message);
    }

    /** RP-COMP-01 - the overview lists every declared companion, with the verbs a developer may use */
    public static function test_the_overview_lists_every_declared_companion()
    {
        $overview = static::__endpoint('overview');
        $names = array_column($overview['companions'], 'name');

        static::__assert_true(in_array(self::NAME, $names, true), 'the fixture companion is listed');
        static::__assert_true(array_key_exists('daemon', $overview), 'the daemon is answered, null when none listens');
        static::__assert_true(array_key_exists('maintenance', $overview));

        $fixture = $overview['companions'][array_search(self::NAME, $names, true)];
        foreach (['pause', 'resume', 'stop', 'start', 'restart'] as $verb) {
            static::__assert_true($fixture['can'][$verb], "a developer may {$verb}");
        }
    }

    /** RP-COMP-02 - the detail carries status, instances and the last hour's endpoint traffic */
    public static function test_the_detail_carries_status_instances_and_traffic()
    {
        Companion_Store::connection()->lPush(Companion_Store::history_key(self::NAME), json_encode([
            'instance_id' => 'panel-ended', 'name' => self::NAME, 'status' => 'exited', 'how_ended' => 'stopped',
            'exit_code' => '0', 'started_at' => '1700000000000', 'ended_at' => '1700000001000',
        ]));
        Companion_Store::connection()->hMSet(Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi')), ['ping:calls' => 2, 'ping:ms' => 10]);

        $detail = static::__endpoint('detail', ['name' => self::NAME]);

        static::__assert_equals(self::NAME, $detail['status']['name']);
        static::__assert_equals('panel-ended', $detail['instances']['history'][0]['instance_id']);
        static::__assert_equals(0, $detail['instances']['history'][0]['exit_code']);
        static::__assert_equals(['calls' => 2, 'errors' => 0, 'avg_ms' => 5], $detail['stats']['endpoints']['ping']);
        static::__assert_equals(60, $detail['stats']['minutes']);
    }

    /** RP-COMP-03 - an undeclared companion is not found, on every endpoint */
    public static function test_an_undeclared_companion_is_not_found()
    {
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['name' => 'no_such_companion']), 'detail');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('act', ['name' => 'no_such_companion', 'companion_action' => 'pause']), 'act');
    }

    /** RP-COMP-04 - act takes only the five verbs; the desired state is not one of them */
    public static function test_act_refuses_an_unknown_verb()
    {
        foreach (['', 'delete', 'enable', 'set_desired'] as $verb) {
            static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('act', ['name' => self::NAME, 'companion_action' => $verb]), "verb '{$verb}'");
        }
    }
}
