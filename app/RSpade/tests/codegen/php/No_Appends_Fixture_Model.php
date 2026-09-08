<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: the negative case for Model_Stub_Appends_Test - a model declaring no derived
 * properties, whose stub must therefore declare none. No table backs it.
 */
class No_Appends_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'codegen_no_appends_fixtures';
    protected $fillable = [];

    public static $enums = [];
}
