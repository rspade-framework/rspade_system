<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: #[Realtime_Touch] placed on a method that returns a hasMany (NOT a belongsTo).
 * The attribute is belongsTo-only in this phase, so resolving its touch metadata must fail
 * loud (the escape hatch is realtime_touch() for non-belongsTo parents).
 */
class Realtime_Attr_Bad_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_attr_bad';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    #[Realtime_Touch]
    public function children()
    {
        return $this->hasMany(Realtime_Attr_Bad_Fixture_Model::class, 'parent_id');
    }
}
