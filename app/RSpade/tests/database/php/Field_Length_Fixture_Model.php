<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture for the field-length script: a model whose table carries an ordinary varchar
 * column, a SYSTEM varchar column (one leading underscore) and a column with no length.
 *
 * Field_Length_Script_Test creates and drops the table, and tells Rsx_Schema about each
 * change itself (plain DDL moves no state key).
 */
class Field_Length_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'field_length_script_fixtures';

    protected $fillable = [];

    public $timestamps = false;

    public static $enums = [];
}
