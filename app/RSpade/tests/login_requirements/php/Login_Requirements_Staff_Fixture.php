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
 * The first staff fixture requirement. INERT unless a test names a login_users id in
 * $unsatisfied: the test trees are in the manifest for the whole run, so a fixture that
 * applied to anybody else would sign every other test's identity out.
 */
class Login_Requirements_Staff_Fixture extends Login_Requirement_Abstract
{
    const REALM = 'staff';
    const ORDER = 10;

    /** @var int[] login_users ids that have NOT met this requirement */
    public static array $unsatisfied = [];

    public static bool $while_impersonating = false;

    public static function is_satisfied(Rsx_Model_Abstract $user): bool
    {
        return !in_array((int) $user->login_user_id, static::$unsatisfied, true);
    }

    public static function screen(): string
    {
        return 'Login_Requirements_Fixture_Controller::screen';
    }

    public static function surfaces(): array
    {
        return ['Login_Requirements_Fixture_Controller::listed'];
    }

    public static function applies_while_impersonating(): bool
    {
        return static::$while_impersonating;
    }
}
