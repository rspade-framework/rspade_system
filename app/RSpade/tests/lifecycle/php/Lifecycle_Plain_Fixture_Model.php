<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Lifecycle\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a plain model that overrides NONE of the lifecycle hooks. Proves a model
 * with no lifecycle surface pays zero dispatch cost — a write queues nothing.
 */
class Lifecycle_Plain_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'lifecycle_fixtures';
    protected $fillable = [];

    public static $enums = [];
}
