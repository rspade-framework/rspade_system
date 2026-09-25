<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Health\Security_Health_Checks;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The two security-posture rows of rsx:health that guard a third-party component:
 * "Ignition Read-Only" and "ImageMagick Coder Policy".
 */
class Security_Posture_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_ignition_ships_read_only()
    {
        static::__assert_false(config('ignition.enable_runnable_solutions'), 'runnable solutions ship off');
        static::__assert_false(config('ignition.enable_share_button'), 'sharing ships off');

        $row = Security_Health_Checks::ignition_read_only();
        static::__assert_equals('OK', $row['status'], $row['detail']);
    }

    public static function test_ignition_row_fails_when_either_flag_is_on()
    {
        $original = config('ignition.enable_runnable_solutions');
        config(['ignition.enable_runnable_solutions' => true]);

        try {
            $row = Security_Health_Checks::ignition_read_only();
        } finally {
            config(['ignition.enable_runnable_solutions' => $original]);
        }

        static::__assert_equals('FAIL', $row['status']);
        static::__assert_contains('enable_runnable_solutions', $row['detail']);
    }

    /**
     * Under the shipped policy every vector and scripting coder is refused. The policy is
     * installed by the RSpade image; a box without it is what the row exists to flag.
     */
    public static function test_imagemagick_row_passes_under_the_shipped_policy()
    {
        $policy = '/etc/ImageMagick-6/policy.xml';
        if (!is_file($policy) || !str_contains((string) file_get_contents($policy), 'RSpade ImageMagick policy')) {
            static::__skip('the RSpade ImageMagick policy is not installed on this box');
        }

        $row = Security_Health_Checks::imagemagick_coder_policy();
        static::__assert_equals('OK', $row['status'], $row['detail']);
    }
}
