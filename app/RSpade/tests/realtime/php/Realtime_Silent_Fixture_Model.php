<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a silent infrastructure model ($realtime_silent = true). Proves a
 * write does NOT kick the emitter engine.
 */
class Realtime_Silent_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_emissions_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = false;
    public static $realtime_silent = true;
}
