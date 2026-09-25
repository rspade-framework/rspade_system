<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Model_Abstract;
use App\RSpade\Tests\Codegen\Php\Column_Order_Fixture_Model;

/**
 * Test fixture: the WIDGET detail for Column_Order_Fixture_Model. Its table is created at
 * runtime by Constants_Regenerate_Order_Test (not a shipped migration).
 */
class Column_Order_Fixture_Detail_Model extends Rsx_Detail_Model_Abstract
{
    protected $table = 'codegen_column_order_fixture_details';
    protected $fillable = [];

    public static $enums = [];

    protected static $parent_model = Column_Order_Fixture_Model::class;
}
