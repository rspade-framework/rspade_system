<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

/**
 * Test fixture: a site-scoped model with revision history, realtime emission and an
 * after_update() hook, carrying one derived column (label, the upper-cased name). The
 * model every write-effect assertion runs against. Backed by the runtime table
 * derived_site_fixtures (Derived_Column_Fixture_Tables).
 */
class Derived_Site_Fixture_Model extends Rsx_Site_Model_Abstract
{
    protected $table = 'derived_site_fixtures';
    protected $fillable = [];

    public static $revisions = true;

    public static $realtime = true;

    public static $columns = [
        'label' => ['derived' => 'derive_label', 'from' => ['name']],
    ];

    public function derive_label(): ?string
    {
        return $this->name === null ? null : strtoupper($this->name);
    }

    public function after_update(array $changed_fields): void
    {
    }
}
