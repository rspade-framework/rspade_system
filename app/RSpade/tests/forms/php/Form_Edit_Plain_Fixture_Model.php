<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Forms\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a model with no revision history, which Form_Edit refuses. No table: the
 * refusal comes before any query.
 */
class Form_Edit_Plain_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'form_edit_fixtures';
    protected $fillable = [];
}
