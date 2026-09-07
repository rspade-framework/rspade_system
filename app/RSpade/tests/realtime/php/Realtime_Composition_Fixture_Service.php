<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

/**
 * Test fixture: an #[Emitter] COMPOSED onto the shared Model_Changed_Topic, MODEL-CONSTRAINED
 * to Realtime_Fixture_Model (the optional second attribute arg). This is the aggregate-emitter
 * composition pattern — a derived value delivered through the page's ONE model subscription.
 *
 * The constraint is what keeps the no-churn property: the emitter engine + dispatch gate only
 * consider registry entries whose filter.model is 'Realtime_Fixture_Model', so watchers on
 * Model_Changed_Topic for any OTHER model never run this emitter and never open the gate for
 * it. In production no page watches Realtime_Fixture_Model, so this fixture is inert there —
 * exactly like the existing Realtime_Emitter_Fixture_Service.
 *
 * NOTE: #[Emitter] is reflection metadata only — no backing attribute class; the linter
 * intentionally removes any `use` for it.
 */
class Realtime_Composition_Fixture_Service
{
    /**
     * When set, the emitter returns this instead of the id-derived value — lets a test drive
     * seed / unchanged / changed behavior through the hash-diff engine deterministically.
     */
    public static ?int $forced_value = null;

    #[Emitter('Model_Changed_Topic', 'Realtime_Fixture_Model')]
    public static function fixture_derived(int $site_id, array $filter): mixed
    {
        if (self::$forced_value !== null) {
            return self::$forced_value;
        }

        // A stand-in aggregate derived from the watched record's id.
        return (int) ($filter['id'] ?? 0) * 10;
    }
}
