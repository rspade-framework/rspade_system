<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a plain model that records revisions, so a companion's write shows who wrote
 * it. Backed by the runtime table companion_fixtures, created by Companion_Realm_Test.
 */
class Companion_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'companion_fixtures';
    protected $fillable = [];

    public static $revisions = true;
}
