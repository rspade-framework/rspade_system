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
 * Test fixture: a TOUCH-ONLY child — it does NOT opt into its own change emission
 * ($realtime = false) but carries #[Realtime_Touch] on parent(). A write queues the parent
 * emission and NOTHING for itself. This is the Phase 1.2 dead-code trap the attribute fixes:
 * the parent is notified even though the child is not itself watched.
 * Backed by realtime_attr_touch_only.
 */
class Realtime_Attr_Touch_Only_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_attr_touch_only';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = false;

    #[Realtime_Touch]
    public function parent()
    {
        return $this->belongsTo(Realtime_Attr_Parent_Fixture_Model::class, 'parent_id');
    }
}
