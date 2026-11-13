<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Tests\Database\Php\Model_Columns_Fixture_Base_Model_Abstract;

/**
 * Test fixture: the middle of a three-class chain. It replaces its parent's status_id entry
 * with a different value map, declares view_count explicitly plain, and adds an enum column
 * of its own. Never instantiated against a table - its table does not exist.
 */
abstract class Model_Columns_Fixture_Child_Model_Abstract extends Model_Columns_Fixture_Base_Model_Abstract
{
    public static $columns = [
        'status_id' => ['enum' => [
            3 => ['label' => 'Archived'],
        ]],
        'view_count' => [],
        'priority_id' => ['enum' => [
            1 => ['label' => 'Low'],
            2 => ['label' => 'High'],
        ]],
    ];
}
