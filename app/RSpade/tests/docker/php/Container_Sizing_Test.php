<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use App\RSpade\Core\Health\Resource_Sizing_Health_Checks;
use App\RSpade\Core\Health\Rsx_Container_Sizing;
use App\RSpade\Core\Task\Task_Pool;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The framework reads the budget rspade-sizing records: the on-demand task pool is automatic
 * when RSX_TASK_MAX_WORKERS is unset (the container's count, or 2 where nothing sized the
 * box) and an explicit value wins; the rsx:health "Resource Sizing" row reports the budget
 * and marks what a switch set. Driven against a scratch record.
 */
class Container_Sizing_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $file = '';

    private static $on_demand_config = null;

    private static $scheduled_config = null;

    private static string $marker = '';

    public static function setup()
    {
        static::$file = sys_get_temp_dir() . '/sizing_' . uniqid() . '.json';
        static::$on_demand_config = config('rsx.tasks.pools.on_demand.max_workers');
        static::$scheduled_config = config('rsx.tasks.pools.scheduled.max_workers');
        static::$marker = sys_get_temp_dir() . '/sizing_notice_' . uniqid();
        Rsx_Container_Sizing::$notice_marker_for_tests = static::$marker;
        Rsx_Container_Sizing::$file_for_tests = static::$file;
        Rsx_Container_Sizing::_forget();
    }

    public static function teardown()
    {
        config(['rsx.tasks.pools.on_demand.max_workers' => static::$on_demand_config]);
        config(['rsx.tasks.pools.scheduled.max_workers' => static::$scheduled_config]);
        Rsx_Container_Sizing::$notice_marker_for_tests = null;
        @unlink(static::$marker);
        Rsx_Container_Sizing::$file_for_tests = null;
        Rsx_Container_Sizing::_forget();
        @unlink(static::$file);
    }

    /** A record of the shape sizing.sh writes, with $changes merged in. */
    private static function __record(array $changes = []): void
    {
        $budget = array_replace_recursive([
            'target' => 'prod',
            'machine' => ['cores' => 2, 'memory_mb' => 4096, 'swap_mb' => 4096],
            'reserved_mb' => ['total' => 1059, 'fixed' => 600, 'scratch' => 204, 'redis' => 255, 'mysql' => 0, 'extra' => 0],
            'php' => [
                'memory_limit_mb' => 320, 'memory_limit_source' => 'calculated',
                'typical_mb' => 128, 'typical_source' => 'calculated',
                'workers_per_core' => 4, 'workers_per_core_source' => 'calculated',
                'ram_mb' => 3037, 'ram_and_swap_mb' => 7133,
            ],
            'ceilings' => ['ram' => 23, 'ram_and_swap' => 22, 'cpu' => 8, 'processes' => 22, 'process_cap' => 140, 'capped' => false, 'cap_notice' => false, 'winner' => 'cpu'],
            'php_fpm' => ['total' => 8, 'source' => 'calculated', 'web' => 2, 'web_standby' => 1, 'ajax' => 6, 'ajax_standby' => 3, 'pools_sized' => true],
            'tasks' => ['total' => 4, 'scheduled' => 1, 'scheduled_source' => 'calculated', 'on_demand' => 3, 'on_demand_source' => 'calculated'],
            'redis' => ['maxmemory_mb' => 204, 'source' => 'calculated'],
            'mysql' => ['innodb_buffer_pool_mb' => null],
        ], $changes);

        file_put_contents(static::$file, json_encode($budget));
        Rsx_Container_Sizing::_forget();
    }

    /**
     * DOCKER-SIZE-01: unset, the on-demand cap is the container's; with no record it is 2;
     * an explicit value wins over both; the other pools never consult the record.
     */
    public static function test_the_on_demand_pool_follows_the_container()
    {
        config(['rsx.tasks.pools.on_demand.max_workers' => null]);

        @unlink(static::$file);
        Rsx_Container_Sizing::_forget();
        static::__assert_equals(Task_Pool::DEFAULT_ON_DEMAND_WORKERS, Task_Pool::max_workers(Task_Pool::ON_DEMAND), 'no record: the default');

        static::__record();
        static::__assert_equals(3, Task_Pool::max_workers(Task_Pool::ON_DEMAND), 'unset: the container count');

        config(['rsx.tasks.pools.on_demand.max_workers' => '']);
        static::__assert_equals(3, Task_Pool::max_workers(Task_Pool::ON_DEMAND), 'empty is unset');

        config(['rsx.tasks.pools.on_demand.max_workers' => '5']);
        static::__assert_equals(5, Task_Pool::max_workers(Task_Pool::ON_DEMAND), 'an explicit value wins');

        static::__assert_equals((int) config('rsx.tasks.pools.kill.max_workers'), Task_Pool::max_workers(Task_Pool::KILL), 'the kill pool is its config');
    }

    /**
     * DOCKER-SIZE-03: the scheduled pool is automatic the same way: the container's count,
     * 1 with no record, an explicit value wins.
     */
    public static function test_the_scheduled_pool_follows_the_container()
    {
        config(['rsx.tasks.pools.scheduled.max_workers' => null]);

        @unlink(static::$file);
        Rsx_Container_Sizing::_forget();
        static::__assert_equals(Task_Pool::DEFAULT_SCHEDULED_WORKERS, Task_Pool::max_workers(Task_Pool::SCHEDULED), 'no record: the default');

        static::__record(['tasks' => ['total' => 18, 'scheduled' => 6, 'on_demand' => 12]]);
        static::__assert_equals(6, Task_Pool::max_workers(Task_Pool::SCHEDULED), 'unset: the container count');

        config(['rsx.tasks.pools.scheduled.max_workers' => '2']);
        static::__assert_equals(2, Task_Pool::max_workers(Task_Pool::SCHEDULED), 'an explicit value wins');
    }

    /**
     * DOCKER-SIZE-04: a record the process cap held back (with no count set by hand) writes
     * the notice to the Laravel log once per record, and the health row says so; a record
     * that was not capped writes nothing.
     */
    public static function test_a_capped_box_is_noticed_once_per_start()
    {
        static::__record();
        static::__assert_false(Rsx_Container_Sizing::log_cap_notice(), 'not capped: no notice');

        static::__record(['ceilings' => ['processes' => 140, 'capped' => true, 'cap_notice' => true, 'winner' => 'cap']]);
        @unlink(static::$marker);
        static::__assert_true(Rsx_Container_Sizing::log_cap_notice(), 'the first tick writes it');
        static::__assert_false(Rsx_Container_Sizing::log_cap_notice(), 'the next tick does not');

        // A new container start writes a new record.
        touch(static::$file, time() + 5);
        clearstatcache();
        static::__assert_true(Rsx_Container_Sizing::log_cap_notice(), 'a new record is noticed again');

        $row = Resource_Sizing_Health_Checks::resource_sizing();
        static::__assert_contains('limited by the 140-process cap', $row['detail']);
        static::__assert_contains('rsx:man server_sizing', $row['detail']);
    }

    /**
     * DOCKER-SIZE-02: the row reports the budget as INFO, names the ceiling that won, and
     * marks the figures a switch set.
     */
    public static function test_the_health_row_reports_the_budget()
    {
        static::__record();
        $row = Resource_Sizing_Health_Checks::resource_sizing();
        static::__assert_equals('INFO', $row['status']);
        static::__assert_contains('php-fpm 8: web 2, ajax 6, limited by cores', $row['detail']);
        static::__assert_contains('4 task workers (1 scheduled + 3 on demand)', $row['detail']);
        static::__assert_false(str_contains($row['detail'], 'server_sizing'), 'no cap notice when not capped');
        static::__assert_contains('memory_limit 320M;', $row['detail']);
        static::__assert_false(str_contains($row['detail'], '(set)'), 'nothing is marked when nothing was set');

        static::__record([
            'php_fpm' => ['total' => 24, 'source' => 'override', 'pools_sized' => false],
            'ceilings' => ['winner' => 'override'],
            'redis' => ['maxmemory_mb' => 500, 'source' => 'override'],
        ]);
        $row = Resource_Sizing_Health_Checks::resource_sizing();
        static::__assert_contains('php-fpm 24 (set)', $row['detail']);
        static::__assert_contains('(development pools unchanged), limited by PHP_FPM_WORKER_COUNT', $row['detail']);
        static::__assert_contains('redis 500 MB (set)', $row['detail']);
    }
}
