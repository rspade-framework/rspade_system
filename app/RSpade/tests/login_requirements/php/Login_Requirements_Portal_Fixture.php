<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginRequirements\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Login\Login_Requirement_Abstract;

/**
 * The portal fixture requirement. Inert unless a test names a portal_users id.
 */
class Login_Requirements_Portal_Fixture extends Login_Requirement_Abstract
{
    const REALM = 'portal';

    /** @var int[] portal_users ids that have NOT met this requirement */
    public static array $unsatisfied = [];

    public static function is_satisfied(Rsx_Model_Abstract $user): bool
    {
        return !in_array((int) $user->id, static::$unsatisfied, true);
    }

    public static function screen(): string
    {
        return 'Login_Requirements_Portal_Fixture_Controller::screen';
    }
}
