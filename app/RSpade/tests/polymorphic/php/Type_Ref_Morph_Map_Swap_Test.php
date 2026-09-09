<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Polymorphic\Php;

use Illuminate\Database\Eloquent\Relations\Relation;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The morph map must not keep another database's type-ref ids.
 *
 * THE DEFECT (a downstream field report, 2026-09-09). register_morph_map() runs ONCE at
 * boot, against whatever connection the process booted on. The test runner then drops,
 * recreates and restores the test database and switches the default connection to it. The
 * registry recovers on its own, but Relation::morphMap is a separate Eloquent static that
 * nothing re-registered - so every INTEGER alias in it stayed the boot database's id.
 *
 * It stayed invisible for as long as the two databases happened to agree: a provisioning
 * snapshot dumped from a long-lived database carries that database's id history, gaps
 * included, so a restored test schema had the same ids by coincidence. Rebuild the snapshot
 * from zero, the ids compact, the coincidence ends, and every polymorphic read fails with
 * "Class name must be a valid object or a string" - morphTo() resolves an alias the map does
 * not have, getActualClassNameForMorph() hands the raw integer back, and `new 26` throws.
 *
 * TWO THINGS ARE PINNED, and the first is the one a merge cannot give you:
 * Relation::morphMap() merges by default, so re-registering could only ever ADD the new
 * ids beside the stale ones - the stale alias would still resolve.
 */
class Type_Ref_Morph_Map_Swap_Test extends Rsx_Test_Abstract
{
    /** A class name no registry will ever hold, used as the stale alias to be evicted. */
    private const PHANTOM_CLASS = 'Type_Ref_Swap_Phantom_Model';

    /** An id far outside any real _type_refs sequence. */
    private const PHANTOM_ID = '987654321';

    public static function teardown(): void
    {
        // Leave the process with a map that matches this database.
        Type_Ref_Registry::_reload_for_database_swap();
    }

    /**
     * A reload EVICTS an alias the current database did not issue, rather than leaving it
     * beside the correct one. This is the whole failure: a stale INTEGER alias that still
     * resolves is what makes a row carrying that id read as the wrong class - or, when the
     * id is absent entirely, throw.
     */
    public static function test_a_reload_evicts_an_alias_this_database_never_issued()
    {
        // Stand in for the boot database's leftovers: a class-name alias and an integer one.
        Relation::morphMap([
            self::PHANTOM_CLASS => self::PHANTOM_CLASS,
            self::PHANTOM_ID => self::PHANTOM_CLASS,
        ]);

        $polluted = Relation::morphMap();
        static::__assert_true(
            isset($polluted[self::PHANTOM_ID]),
            'the stale alias is in force before the reload - otherwise this test proves nothing'
        );

        Type_Ref_Registry::_reload_for_database_swap();

        $map = Relation::morphMap();

        static::__assert_false(
            isset($map[self::PHANTOM_ID]),
            'the stale INTEGER alias is gone - a merge would have kept it, and a row carrying '
            . 'that id would still resolve through it'
        );

        static::__assert_false(
            isset($map[self::PHANTOM_CLASS]),
            'the stale class-name alias is gone too'
        );
    }

    /**
     * After a reload, every integer alias agrees with the registry reading THIS database.
     * That is the invariant the swap broke.
     */
    public static function test_every_integer_alias_matches_the_registry()
    {
        // Guarantee the registry has at least one ref before the reload. A freshly
        // provisioned test schema mints them lazily, so an empty _type_refs is the normal
        // starting state here and would leave nothing to compare.
        Type_Ref_Registry::class_to_id('Site_Model');

        Type_Ref_Registry::_reload_for_database_swap();

        $checked = 0;

        foreach (Relation::morphMap() as $alias => $class) {
            if (!is_int($alias) && !ctype_digit((string) $alias)) {
                continue;
            }

            // The map stores the resolved FQCN; the registry answers the simple name.
            $simple_name = class_basename($class);

            // A retired ref resolves to a poison class whose name is not the registered one.
            if (!Type_Ref_Registry::class_resolves($simple_name)) {
                continue;
            }

            static::__assert_equals(
                (int) $alias,
                Type_Ref_Registry::class_to_id($simple_name),
                'morph map alias ' . $alias . ' agrees with the registry id for ' . $simple_name
            );

            $checked++;
        }

        static::__assert_true($checked > 0, 'there are integer aliases to check');
    }
}
