<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Tests\Session\Php\Never_Export_Fixture_Base_Model_Abstract;

/**
 * Test fixture: the leaf of a three-class chain. Its own $neverExport names one column; the
 * effective list must still carry its parent's. Built unsaved only - its table does not exist.
 */
class Never_Export_Fixture_Model extends Never_Export_Fixture_Base_Model_Abstract
{
    public static $neverExport = ['leaf_secret_column'];
}
