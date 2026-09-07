<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a model that does NOT opt in to model-change emission
 * ($realtime = false) and is NOT silent ($realtime_silent = false). Proves a
 * normal write publishes no model change yet STILL kicks the emitter engine.
 */
class Realtime_Plain_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_emissions_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = false;
    public static $realtime_silent = false;
}
