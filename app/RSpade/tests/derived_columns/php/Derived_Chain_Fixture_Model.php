<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: two derived columns on one record, one computed from the other. quadrupled
 * is DECLARED FIRST although it reads doubled, so a correct result proves the columns are
 * computed in dependency order rather than declaration order. Backed by the runtime table
 * derived_chain_fixtures (Derived_Column_Fixture_Tables).
 */
class Derived_Chain_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'derived_chain_fixtures';
    protected $fillable = [];

    public static $columns = [
        'quadrupled' => ['derived' => 'derive_quadrupled', 'from' => ['doubled']],
        'doubled' => ['derived' => 'derive_doubled', 'from' => ['base_value']],
    ];

    public function derive_doubled(): ?int
    {
        return $this->base_value === null ? null : (int) $this->base_value * 2;
    }

    public function derive_quadrupled(): ?int
    {
        return $this->doubled === null ? null : (int) $this->doubled * 2;
    }
}
