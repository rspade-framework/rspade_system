<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Category_Fixture_Model;

/**
 * Test fixture: a derived column whose method reads ANOTHER table - category_name, the name
 * of the category row category_id points at. A rename of the category changes nothing on
 * this record, so only rederive() brings it up to date. Backed by the runtime table
 * derived_lookup_fixtures (Derived_Column_Fixture_Tables).
 */
class Derived_Lookup_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'derived_lookup_fixtures';
    protected $fillable = [];

    public static $columns = [
        'category_name' => ['derived' => 'derive_category_name', 'from' => ['category_id']],
    ];

    public function derive_category_name(): ?string
    {
        if ($this->category_id === null) {
            return null;
        }

        return Derived_Category_Fixture_Model::find($this->category_id)?->name;
    }
}
