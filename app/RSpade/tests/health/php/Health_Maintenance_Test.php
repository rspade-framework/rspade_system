<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Under a raised maintenance flag, rsx:health reports the services the window stops on
 * purpose - rsx-lockd, the realtime relay and Redis - as INFO rather than FAIL, announces the
 * window, and exits 0 on an otherwise healthy box. Run during a window, the report reads as
 * the operator's own doing instead of two false alarms.
 *
 * The flag is raised through Framework_Maintenance::$force_active_for_tests, which the probes'
 * on-disk reads honour, so no flag file is written and no service is stopped.
 */
class Health_Maintenance_Test extends Rsx_Test_Abstract
{
    public static function teardown()
    {
        Framework_Maintenance::$force_active_for_tests = null;
    }

    public static function test_stopped_services_are_info_and_the_window_is_announced()
    {
        Framework_Maintenance::$force_active_for_tests = true;

        try {
            $exit = Artisan::call('rsx:health', ['--json' => true]);
            $decoded = json_decode(Artisan::output(), true);
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }

        static::__assert_true(is_array($decoded), 'rsx:health --json emits parseable JSON');
        static::__assert_true(!empty($decoded['maintenance']), 'the payload names the maintenance window');

        $by_label = array_column($decoded['checks'], null, 'label');
        foreach (['Lock Server', 'Realtime Relay', 'Redis Connectivity'] as $label) {
            static::__assert_true(isset($by_label[$label]), "the '{$label}' row is present");
            static::__assert_equals('INFO', $by_label[$label]['status'], "'{$label}' is INFO during maintenance: " . json_encode($by_label[$label]));
        }

        static::__assert_equals(0, $exit, 'the window alone does not fail the report');
    }

    public static function test_the_plain_report_carries_a_banner()
    {
        Framework_Maintenance::$force_active_for_tests = true;

        try {
            Artisan::call('rsx:health');
            $output = Artisan::output();
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }

        static::__assert_contains('[WARNING] Maintenance mode is up', $output, 'the window is announced above the table');
    }

    public static function test_no_banner_outside_a_window()
    {
        Framework_Maintenance::$force_active_for_tests = false;

        try {
            Artisan::call('rsx:health', ['--json' => true]);
            $decoded = json_decode(Artisan::output(), true);
        } finally {
            Framework_Maintenance::$force_active_for_tests = null;
        }

        static::__assert_null($decoded['maintenance'], 'no window, no maintenance field value');
    }
}
