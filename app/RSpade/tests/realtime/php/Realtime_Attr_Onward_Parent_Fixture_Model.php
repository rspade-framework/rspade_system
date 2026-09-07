<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Parent_Fixture_Model;

/**
 * Test fixture: a MID parent that itself has onward touches — it overrides realtime_touch()
 * (the method escape hatch) to touch a grandparent (Realtime_Attr_Parent_Fixture_Model id
 * GRANDPARENT_ID). When an onward child touches THIS model, the cascade must HYDRATE this
 * row (it has onward touches) and walk it — queuing the grandparent too.
 * Backed by realtime_attr_onward_parents.
 */
class Realtime_Attr_Onward_Parent_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_attr_onward_parents';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    public const GRANDPARENT_ID = 970;

    public function realtime_touch(): array
    {
        $grandparent = new Realtime_Attr_Parent_Fixture_Model();
        $grandparent->id = self::GRANDPARENT_ID;

        return [$grandparent];
    }
}
