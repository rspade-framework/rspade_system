<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture for rsx:db:check_indexes: a model with NO site scope and no enum column.
 *
 * Check_Indexes_Advice_Test reads its facts through Check_Indexes_Command::model_facts().
 * Nothing queries it, so no table is created.
 */
class Check_Indexes_Plain_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'check_indexes_plain_fixtures';

    protected $fillable = [];

    public $timestamps = false;
}
