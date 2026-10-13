<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a model whose table NOTHING ever creates.
 *
 * A model is a code fact and its table is a database fact, and the two are allowed to
 * disagree - a model whose migration has not run yet is the ordinary case. This is that
 * case held still: registered in the manifest's model registry like any other model, absent
 * from every database.
 */
class Schema_Absent_Table_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'rsx_schema_absent_table_fixtures';

    protected $fillable = [];

    public static $enums = [];
}
