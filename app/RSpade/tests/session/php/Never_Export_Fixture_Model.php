<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use App\RSpade\Tests\Session\Php\Never_Export_Fixture_Base_Model_Abstract;

/**
 * Test fixture: the leaf of a three-class chain. Its own $columns never-exports one column;
 * the effective declaration must still carry its parent's entry for the other. Built unsaved
 * only - its table does not exist.
 */
class Never_Export_Fixture_Model extends Never_Export_Fixture_Base_Model_Abstract
{
    public static $columns = [
        'leaf_secret_column' => ['never_export' => true],
    ];
}
