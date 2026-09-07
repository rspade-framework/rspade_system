<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Fixture_Model;

/**
 * Test fixture: a child whose change touches a parent (Realtime_Fixture_Model id 900).
 * Exercised in-memory (never saved) — realtime_touch() returns a freshly constructed
 * parent instance the way a real child would return $this->client().
 */
class Realtime_Touch_Child_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_touch_child_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    public const PARENT_ID = 900;

    public function realtime_touch(): array
    {
        $parent = new Realtime_Fixture_Model();
        $parent->id = self::PARENT_ID;

        return [$parent];
    }
}
