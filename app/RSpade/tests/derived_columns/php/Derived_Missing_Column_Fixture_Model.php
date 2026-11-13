<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a derived column whose `from` names a column its table does not have
 * (`ghost`). Well-formed as a declaration, wrong against the schema - the first-use check
 * and MODEL-ENUM-01 refuse it. Its table, derived_missing_fixtures, exists only while
 * Derived_Column_Schema_Check_Test creates it.
 */
class Derived_Missing_Column_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'derived_missing_fixtures';
    protected $fillable = [];

    public static $revisions = true;

    public static $columns = [
        'shown' => ['derived' => 'derive_shown', 'from' => ['ghost']],
    ];

    public function derive_shown(): ?string
    {
        return 'shown';
    }
}
