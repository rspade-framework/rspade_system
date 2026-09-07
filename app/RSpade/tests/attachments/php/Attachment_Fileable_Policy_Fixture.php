<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use App\RSpade\Core\Auth\Staff_Authorizable;
/**
 * A model-shaped fixture that adopts the staff trait AND states a policy of its own. It
 * extends nothing and touches no database: the only property under test is which
 * declaration PHP resolves.
 */
#[Instantiatable]
class Attachment_Fileable_Policy_Fixture
{
    use Staff_Authorizable;

    public function can_view($user = null): bool
    {
        return false;
    }

    public static function scope_can_view($query, $user = null)
    {
        return 'constrained';
    }
}
