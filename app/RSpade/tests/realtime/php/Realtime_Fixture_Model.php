<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a plain (non-site) model that OPTS IN to realtime emission
 * ($realtime = true). Backed by the runtime table realtime_emissions_fixtures
 * (created by Realtime_Emissions_Hook_Test). Doubles as the in-memory target of
 * touch-cascade tests (constructed with an id, never saved).
 */
class Realtime_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_emissions_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;
}
