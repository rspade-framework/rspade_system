<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Health\Rsx_Container_Sizing;
use App\RSpade\Core\Metrics\Rsx_Metrics;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Performance\_Sys_Performance_Controller;

/**
 * The Performance screen's endpoints: the snapshot carries every kind's newest entries and
 * summary, the last hour minute by minute, and the budget derived from the container's
 * record (or null with none); the pool status parses php-fpm's JSON per pool and answers
 * null for a pool that gave none. Driven against a scratch sizing record, the metrics scope
 * of the test database, and the fetcher seam.
 */
class Sys_Performance_Controller_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $file = '';

    private static $enabled = null;

    public static function setup()
    {
        static::__acting_as_user(1);
        static::$enabled = config('rsx.metrics.enabled');
        config(['rsx.metrics.enabled' => true]);
        static::$file = sys_get_temp_dir() . '/sizing_perf_' . uniqid() . '.json';
        Rsx_Container_Sizing::$file_for_tests = static::$file;
        Rsx_Container_Sizing::_forget();
    }

    public static function teardown()
    {
        config(['rsx.metrics.enabled' => static::$enabled]);
        Rsx_Container_Sizing::$file_for_tests = null;
        Rsx_Container_Sizing::_forget();
        _Sys_Performance_Controller::$fpm_fetcher_for_tests = null;
        Rsx_Metrics::_reset();
        @unlink(static::$file);
    }

    private static function __ajax_request(): Request
    {
        return Request::create('/_ajax/_Sys_Performance_Controller/get_snapshot', 'POST');
    }

    /**
     * RP-PERF-01 - The snapshot: every kind with its recent entries and summary, sixty
     * minutes, and no budget on a box nothing sized.
     */
    public static function test_the_snapshot_carries_every_kind_and_the_hour()
    {
        Rsx_Metrics::_reset();
        @unlink(static::$file);
        Rsx_Container_Sizing::_forget();

        $now = (float) time();
        Rsx_Metrics::record(Rsx_Metrics::KIND_PAGE, '_Sys_Performance_Action', 30 * 1024, $now - 0.2, $now);
        Rsx_Metrics::record(Rsx_Metrics::KIND_TASK, 'A_Service::run', 64 * 1024, $now - 2, $now);

        $snapshot = _Sys_Performance_Controller::get_snapshot(static::__ajax_request());

        static::__assert_true($snapshot['enabled']);
        static::__assert_equals(Rsx_Metrics::KINDS, array_keys($snapshot['kinds']));
        static::__assert_equals('_Sys_Performance_Action', $snapshot['kinds']['page']['recent'][0]['label']);
        static::__assert_equals(1, $snapshot['kinds']['task']['summary']['count']);
        static::__assert_equals(0, $snapshot['kinds']['api']['summary']['count']);
        static::__assert_count(60, $snapshot['minutes']);
        static::__assert_null($snapshot['budget'], 'nothing sized this box');
    }

    /**
     * RP-PERF-02 - The budget from the container's record: the RAM ceilings, the pools, the
     * planned typical total (php-fpm + task workers at the typical size) and the worker ceiling.
     */
    public static function test_the_budget_follows_the_container_record()
    {
        file_put_contents(static::$file, json_encode([
            'php' => ['memory_limit_mb' => 320, 'typical_mb' => 128, 'ram_mb' => 3037, 'ram_and_swap_mb' => 7133],
            'php_fpm' => ['total' => 8, 'web' => 2, 'ajax' => 6, 'pools_sized' => true],
            'tasks' => ['total' => 3, 'scheduled' => 1, 'on_demand' => 2],
            'ceilings' => ['winner' => 'cpu'],
        ]));
        Rsx_Container_Sizing::_forget();

        $budget = _Sys_Performance_Controller::get_snapshot(static::__ajax_request())['budget'];

        static::__assert_equals(3037, $budget['php_ram_mb']);
        static::__assert_equals(7133, $budget['php_ram_and_swap_mb']);
        static::__assert_equals(8, $budget['php_fpm_total']);
        static::__assert_equals(11, $budget['worker_ceiling']);
        static::__assert_equals(11 * 128, $budget['planned_typical_mb']);
        static::__assert_equals(320, $budget['memory_limit_mb']);
        static::__assert_equals('cpu', $budget['ceiling']);
    }

    /**
     * RP-PERF-03 - The pool status: php-fpm's fields per pool; a pool that gave no answer,
     * or something that is not a status page, is null.
     */
    public static function test_the_pool_status_is_parsed_per_pool()
    {
        _Sys_Performance_Controller::$fpm_fetcher_for_tests = function (string $pool): ?string {
            if ($pool === 'ajax') {
                return null;
            }

            return json_encode([
                'pool' => 'www', 'process manager' => 'dynamic', 'start since' => 600, 'accepted conn' => 1234,
                'listen queue' => 2, 'max listen queue' => 5, 'idle processes' => 1, 'active processes' => 3,
                'total processes' => 4, 'max active processes' => 4, 'max children reached' => 7,
            ]);
        };

        $pools = _Sys_Performance_Controller::get_fpm_status(static::__ajax_request())['pools'];

        static::__assert_equals(['web', 'ajax'], array_keys($pools));
        static::__assert_equals(
            ['active' => 3, 'idle' => 1, 'total' => 4, 'queue' => 2, 'max_queue' => 5, 'max_children_reached' => 7, 'accepted' => 1234, 'since_seconds' => 600],
            $pools['web']
        );
        static::__assert_null($pools['ajax'], 'no answer');
        static::__assert_null(_Sys_Performance_Controller::parse_fpm_status('<html>not a status page</html>'));
    }
}
