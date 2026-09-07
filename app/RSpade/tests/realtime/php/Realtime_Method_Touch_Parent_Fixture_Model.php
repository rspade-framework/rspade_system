<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: the parent of the realtime_touch() METHOD cascade. It does NOT opt into its
 * own change emission ($realtime = false) - a touched parent is notified regardless of its
 * own flag, so the cascade must still queue it. Backed by realtime_method_touch_parents.
 */
class Realtime_Method_Touch_Parent_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_method_touch_parents';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = false;
}
