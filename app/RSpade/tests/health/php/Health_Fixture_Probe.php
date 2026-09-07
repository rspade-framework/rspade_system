<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

/**
 * Health_Fixture_Probe - a plain probe used by Health_Normalization_Test.
 *
 * DELIBERATELY carries NO #[Health_Check] attribute: a manifest-discovered health check
 * fixture would pollute the real `rsx:health` inventory (see the catalog's deferred row).
 * These are ordinary static methods the test hands to Health_Check_Runner::run_one() to
 * exercise the throw-to-FAIL and happy paths without touching discovery.
 */
class Health_Fixture_Probe
{
    /**
     * A check that throws - run_one() must convert it into a single FAIL row.
     *
     * @return array
     */
    public static function boom(): array
    {
        throw new \RuntimeException('probe exploded');
    }

    /**
     * A well-formed single OK row.
     *
     * @return array
     */
    public static function ok_row(): array
    {
        return ['status' => 'OK', 'detail' => 'fine'];
    }
}
