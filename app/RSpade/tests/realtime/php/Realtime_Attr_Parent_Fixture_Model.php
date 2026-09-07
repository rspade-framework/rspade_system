<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: the TOP parent of the #[Realtime_Touch] chain (the "Client" analogue).
 * Opts into its own change emission; declares no touches, so it is queued by identity when
 * a child touches it. Backed by realtime_attr_parents (created by the attribute test).
 */
class Realtime_Attr_Parent_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_attr_parents';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;
}
