<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Realtime\Php\Realtime_Attr_Parent_Fixture_Model;
/**
 * Test fixture: a SoftDeletes child with #[Realtime_Touch] on parent(). A bulk ->delete()
 * on this model is an UPDATE of deleted_at issued through the builder's update() override
 * from inside parent::delete(); the re-entrancy guard must make that a SINGLE pre-select
 * (no double-queue). Backed by realtime_attr_soft_children.
 */
class Realtime_Attr_Soft_Child_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    protected $table = 'realtime_attr_soft_children';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    #[Realtime_Touch]
    public function parent()
    {
        return $this->belongsTo(Realtime_Attr_Parent_Fixture_Model::class, 'parent_id');
    }
}
