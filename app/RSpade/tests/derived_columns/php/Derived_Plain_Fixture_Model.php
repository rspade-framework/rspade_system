<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a plain (non-site) model with one derived column, full_name, computed from
 * first_name and last_name. Nothing else about it has a side effect, so a bulk update of it
 * takes the per-record path only because of the derived column. `code` carries a UNIQUE
 * index, which is how a test makes a save fail after the derivation ran. Backed by the
 * runtime table derived_plain_fixtures (Derived_Column_Fixture_Tables).
 */
class Derived_Plain_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'derived_plain_fixtures';
    protected $fillable = [];

    public static $columns = [
        'full_name' => ['derived' => 'derive_full_name', 'from' => ['first_name', 'last_name']],
    ];

    /** How many times derive_full_name() ran, for the tests that prove it did not. */
    public static int $derive_calls = 0;

    public function derive_full_name(): ?string
    {
        static::$derive_calls++;

        $name = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return $name === '' ? null : $name;
    }
}
