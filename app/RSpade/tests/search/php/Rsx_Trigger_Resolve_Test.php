<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Search\Php;

use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx::trigger_resolve() semantics: a chain of handlers each get the SAME input; the FIRST to
 * return a non-null value wins (priority order, lower first); if all decline (null), the result
 * is null and the caller runs its own terminal default.
 *
 * Driven through the private fixture event 'rsx_test.trigger_resolve' (see
 * Rsx_Trigger_Resolve_Fixture_Handler) - no Event_Registry seam needed.
 */
class Rsx_Trigger_Resolve_Test extends Rsx_Test_Abstract
{
    // Pure event-dispatch logic - no database.
    protected static $use_database_transactions = false;

    public static function test_first_non_null_wins()
    {
        $result = Rsx::trigger_resolve('rsx_test.trigger_resolve', ['a' => 'from_a', 'b' => 'from_b']);
        static::__assert_equals('from_a', $result, 'priority-10 handler intercepts first even though the priority-20 handler would also return non-null');
    }

    public static function test_declining_handler_passes_to_next()
    {
        $result = Rsx::trigger_resolve('rsx_test.trigger_resolve', ['b' => 'from_b']);
        static::__assert_equals('from_b', $result, 'first handler declines (null), the chain continues, second intercepts');
    }

    public static function test_all_decline_returns_null()
    {
        $result = Rsx::trigger_resolve('rsx_test.trigger_resolve', []);
        static::__assert_null($result, 'every handler declines -> null (signal for the caller to run its terminal default)');
    }

    public static function test_unknown_event_returns_null()
    {
        $result = Rsx::trigger_resolve('rsx_test.no_such_event_should_never_exist', ['a' => 'x']);
        static::__assert_null($result, 'an event with no registered handlers resolves to null');
    }
}
