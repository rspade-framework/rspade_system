<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Polymorphic\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test-only model for Type_Ref_Side_Connection_Test: a class the type registry can register
 * and the test can unregister again. It is never queried, so its table does not exist.
 */
class Type_Ref_Side_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'type_ref_side_fixtures';
    protected $fillable = [];

    public static $enums = [];
}
