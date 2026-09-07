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
 * Test fixture: a child that opts into its own change emission AND carries #[Realtime_Touch]
 * on its parent() belongsTo (the "Contact -> Client" analogue). A write queues its own change
 * plus a by-identity emission for the parent (the parent has no onward touches).
 * Backed by realtime_attr_children.
 */
class Realtime_Attr_Child_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_attr_children';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    #[Realtime_Touch]
    public function parent()
    {
        return $this->belongsTo(Realtime_Attr_Parent_Fixture_Model::class, 'parent_id');
    }
}
