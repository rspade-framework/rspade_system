<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: the opt-out control. Same table shape as Revision_Fixture_Model, no
 * $revisions declaration - so writes to it must record nothing at all.
 * Backed by revision_plain_fixtures.
 */
class Revision_Plain_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'revision_plain_fixtures';
    protected $fillable = [];

    public static $enums = [];
}
