<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a model that declares neither $table nor $columns, so both are answered
 * only by Rsx_Model_Abstract and the classes above it. Constants_Regenerate_Scope_Test
 * proves rsx:constants:regenerate documents such a model in its own file, never in the
 * framework base. Never instantiated.
 */
abstract class Constants_Regenerate_Bare_Fixture_Model_Abstract extends Rsx_Model_Abstract
{
}
