<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a model whose table NOTHING ever creates, so the manifest's model registry
 * can be asked about a model the database knows nothing of.
 */
class Registry_Absent_Table_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'manifest_registry_absent_table_fixtures';

    protected $fillable = [];

    public static $enums = [];
}
