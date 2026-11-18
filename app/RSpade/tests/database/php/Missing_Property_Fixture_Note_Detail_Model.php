<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Model_Abstract;
use App\RSpade\Tests\Database\Php\Missing_Property_Fixture_Model;

/**
 * The NOTE detail of Missing_Property_Fixture_Model, read through its detail accessor
 * (`missing_property_fixture_note`). Tables are created and dropped by
 * Model_Missing_Property_Test.
 */
class Missing_Property_Fixture_Note_Detail_Model extends Rsx_Detail_Model_Abstract
{
    protected $table = 'mp_fixture_notes';

    protected $fillable = [];

    protected static $parent_model = Missing_Property_Fixture_Model::class;
}
