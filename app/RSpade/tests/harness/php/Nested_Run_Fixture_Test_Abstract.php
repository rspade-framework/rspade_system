<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Harness\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Inner test class used by Nested_Run_Isolation_Test to exercise a run() INSIDE a run().
 *
 * Declared abstract on purpose: the runner discovers test classes with
 * Manifest::php_get_extending('Rsx_Test_Abstract') and skips abstract ones, which is the
 * existing convention for a class that must never auto-run on its own. Every member is
 * static, so Nested_Run_Isolation_Test can still call ::run() on it directly.
 *
 * It deliberately reports one pass and one failure so the caller can prove the nested
 * results came back intact rather than being read off the caller's own array.
 */
abstract class Nested_Run_Fixture_Test_Abstract extends Rsx_Test_Abstract
{
    // No DB work here, and nesting a transaction inside the caller's would prove nothing.
    protected static $use_database_transactions = false;

    public static function test_fixture_passes()
    {
        static::__pass('inner fixture passed');
    }

    public static function test_fixture_fails()
    {
        static::__fail('inner fixture failed on purpose');
    }
}
