<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Cycle_B_Fixture_Model;

/**
 * Test fixture: half of a touch cycle (A touches B, B touches A). Proves the visited
 * set terminates a cyclic touch graph. In-memory only.
 */
class Realtime_Cycle_A_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_cycle_a_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    public const B_ID = 2;

    public function realtime_touch(): array
    {
        $b = new Realtime_Cycle_B_Fixture_Model();
        $b->id = self::B_ID;

        return [$b];
    }
}
