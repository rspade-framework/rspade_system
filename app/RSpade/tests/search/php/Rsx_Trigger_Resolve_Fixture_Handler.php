<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Search\Php;

/**
 * Fixture handlers for Rsx_Trigger_Resolve_Test.
 *
 * These #[OnEvent] handlers are manifest-discovered and therefore LIVE in dev - but they are
 * registered on the private event name 'rsx_test.trigger_resolve', which NO framework code ever
 * triggers, so being live is harmless. They let the trigger_resolve semantics (priority order,
 * decline-vs-intercept, all-decline) be tested through a real event WITHOUT adding a seam to
 * Event_Registry.
 *
 * resolve_first (priority 10) intercepts when 'a' is present; resolve_second (priority 20)
 * intercepts when 'b' is present. Each returns null (declines) otherwise.
 */
class Rsx_Trigger_Resolve_Fixture_Handler
{
    #[OnEvent('rsx_test.trigger_resolve', priority: 10)]
    public static function resolve_first($data)
    {
        return $data['a'] ?? null;
    }

    #[OnEvent('rsx_test.trigger_resolve', priority: 20)]
    public static function resolve_second($data)
    {
        return $data['b'] ?? null;
    }
}
