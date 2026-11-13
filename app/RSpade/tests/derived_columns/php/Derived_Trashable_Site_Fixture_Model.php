<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Site_Fixture_Model;
/**
 * Test fixture: a site-scoped, soft-deleting model with two derived columns - label (the
 * upper-cased name) and site_fixture_count, the number of Derived_Site_Fixture_Model rows on
 * the record's own site. The second is a site-scoped READ inside a derive method: it throws
 * with no site declared and counts the wrong site's rows when the wrong one is, so a correct
 * value proves the rederive walk declared each record's site. Backed by the runtime table
 * derived_trashable_fixtures (Derived_Column_Fixture_Tables).
 */
class Derived_Trashable_Site_Fixture_Model extends Rsx_Site_Model_Abstract
{
    use SoftDeletes;

    protected $table = 'derived_trashable_fixtures';
    protected $fillable = [];

    public static $columns = [
        'label' => ['derived' => 'derive_label', 'from' => ['name']],
        'site_fixture_count' => ['derived' => 'derive_site_fixture_count', 'from' => []],
    ];

    public function derive_label(): ?string
    {
        return $this->name === null ? null : strtoupper($this->name);
    }

    public function derive_site_fixture_count(): int
    {
        return Derived_Site_Fixture_Model::query()->count();
    }
}
