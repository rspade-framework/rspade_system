<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Tests\Codegen\Php\Split_Fixture_Model_Abstract;

/**
 * Test fixture: the CONCRETE half of a split model - a shell that declares nothing.
 *
 * This is the shape every framework model ships in and the shape an application replaces.
 * Model_Stub_Appends_Test generates the stub for THIS class and requires the base's members
 * to be on it.
 */
class Split_Fixture_Model extends Split_Fixture_Model_Abstract
{
}
