<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Tests\Database\Php\Model_Columns_Fixture_Child_Model_Abstract;

/**
 * Test fixture: the leaf of a three-class chain. It declares body explicitly plain, gives
 * api_secret a second kind beside never_export, and adds a type-ref column of its own. Never
 * instantiated against a table - its table does not exist.
 */
class Model_Columns_Fixture_Grandchild_Model extends Model_Columns_Fixture_Child_Model_Abstract
{
    public static $columns = [
        'body' => [],
        'api_secret' => ['never_export' => true, 'revision_exclude' => true],
        'owner_type' => ['type_ref' => true],
    ];
}
