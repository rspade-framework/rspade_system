<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Time\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture for the external API's timestamp companions: a table with one DATETIME and
 * one DATE column, which no framework table has both of.
 *
 * Rsx_Timezone_Companion_Date_Test creates and drops the table, and tells Rsx_Schema about
 * each change itself (plain DDL moves no state key).
 */
class Timezone_Companion_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'timezone_companion_fixtures';

    protected $fillable = [];

    public $timestamps = false;

    public static $enums = [];
}
