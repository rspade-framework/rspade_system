<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Lifecycle\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a hookless model on the bulk table. Proves a model with no lifecycle surface
 * captures nothing on a mass update/delete (the builder's gate short-circuits before any
 * pre-select). Backed by lifecycle_bulk_fixtures.
 */
class Lifecycle_Bulk_Plain_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'lifecycle_bulk_fixtures';
    protected $fillable = [];

    public static $enums = [];
}
