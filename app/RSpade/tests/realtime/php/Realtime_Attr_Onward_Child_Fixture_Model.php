<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Onward_Parent_Fixture_Model;

/**
 * Test fixture: a child whose #[Realtime_Touch] parent (Realtime_Attr_Onward_Parent) itself
 * has onward touches. A write must queue: this child (own), the onward parent (hydrated, so
 * its realtime_touch() runs), AND the grandparent the onward parent touches.
 * Backed by realtime_attr_onward_children.
 */
class Realtime_Attr_Onward_Child_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_attr_onward_children';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    #[Realtime_Touch]
    public function parent()
    {
        return $this->belongsTo(Realtime_Attr_Onward_Parent_Fixture_Model::class, 'parent_id');
    }
}
