<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Harness\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Harness\Php\Nested_Run_Fixture_Test_Abstract;

/**
 * Rsx_Test_Abstract::run() must survive being called from inside a running test.
 *
 * $results and $current_test are declared on the abstract base, so late static binding gives
 * every subclass the SAME storage - a nested run() used to reset the caller's results to []
 * and leave $current_test pointing at the inner class's last method. run() now saves and
 * restores both around its body.
 */
class Nested_Run_Isolation_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Runs first (declaration order) so the caller's results array is non-empty by the time
     * the nested run happens - a wipe is then observable as a lost entry, not just a count.
     */
    public static function test_a_first_result_is_recorded()
    {
        static::__pass('first result recorded');
    }

    public static function test_nested_run_leaves_the_caller_intact()
    {
        static::__pass('marker recorded before the nested run');

        $results_before = static::$results;
        static::__assert_array_has_key('test_a_first_result_is_recorded', $results_before);
        static::__assert_equals('test_nested_run_leaves_the_caller_intact', static::$current_test);

        $nested = Nested_Run_Fixture_Test_Abstract::run();

        // The nested run reported its OWN results, not the caller's.
        static::__assert_count(2, $nested, 'the nested run returns exactly its own two results');
        static::__assert_equals('passed', $nested['test_fixture_passes']['status']);
        static::__assert_equals('inner fixture passed', $nested['test_fixture_passes']['message']);
        static::__assert_equals('failed', $nested['test_fixture_fails']['status']);

        // And the caller's results survived untouched.
        static::__assert_equals($results_before, static::$results, 'a nested run() must not mutate the caller results');
        static::__assert_array_has_key('test_a_first_result_is_recorded', static::$results);
        static::__assert_equals(
            'marker recorded before the nested run',
            static::$results['test_nested_run_leaves_the_caller_intact']['message']
        );

        // The inner run's last test name must not be left behind as the current test.
        static::__assert_equals(
            'test_nested_run_leaves_the_caller_intact',
            static::$current_test,
            'current_test is restored after a nested run'
        );
    }
}
