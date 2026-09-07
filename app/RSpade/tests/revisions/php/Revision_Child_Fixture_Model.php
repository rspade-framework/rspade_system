<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Revisions\Php\Revision_Fixture_Model;

/**
 * Test fixture: a recorded child that files its revisions under its parent's history
 * (#[Revision_Parent] on the belongsTo). The "Contact -> Client" analogue.
 * Backed by revision_child_fixtures.
 */
class Revision_Child_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'revision_child_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $revisions = true;

    #[Revision_Parent]
    #[Relationship]
    public function owner()
    {
        return $this->belongsTo(Revision_Fixture_Model::class, 'owner_id');
    }
}
