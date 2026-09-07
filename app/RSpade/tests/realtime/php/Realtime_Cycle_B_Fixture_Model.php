<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Cycle_A_Fixture_Model;

/**
 * Test fixture: the other half of the touch cycle (B touches A). In-memory only.
 */
class Realtime_Cycle_B_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_cycle_b_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    public const A_ID = 1;

    public function realtime_touch(): array
    {
        $a = new Realtime_Cycle_A_Fixture_Model();
        $a->id = self::A_ID;

        return [$a];
    }
}
