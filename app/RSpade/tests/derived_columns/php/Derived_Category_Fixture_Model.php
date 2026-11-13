<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: the other table Derived_Lookup_Fixture_Model's derive method reads. Backed
 * by the runtime table derived_category_fixtures (Derived_Column_Fixture_Tables).
 */
class Derived_Category_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'derived_category_fixtures';
    protected $fillable = [];
}
