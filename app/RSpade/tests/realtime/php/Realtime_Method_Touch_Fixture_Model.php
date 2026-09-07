<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Method_Touch_Parent_Fixture_Model;

/**
 * Test fixture: a TOUCH-ONLY child that reaches its parent through the realtime_touch()
 * METHOD escape hatch (the polymorphic/conditional case a plain belongsTo attribute cannot
 * express). It does NOT set $realtime and carries NO #[Realtime_Touch] attribute, so its only
 * realtime surface is the overridden method. A write must walk the cascade and queue the
 * PARENT, publishing NOTHING for the child itself. This is the exact dead-code trap the
 * method-rung decoupling fixes (mirrors Entity_Association_Model). Backed by
 * realtime_method_touch_children.
 */
class Realtime_Method_Touch_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_method_touch_children';
    protected $fillable = [];

    public static $enums = [];

    public function realtime_touch(): array
    {
        if (empty($this->parent_id)) {
            return [];
        }

        $parent = Realtime_Method_Touch_Parent_Fixture_Model::find((int) $this->parent_id);

        return $parent ? [$parent] : [];
    }
}
