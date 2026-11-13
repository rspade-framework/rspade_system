<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use RuntimeException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a derived column that never settles - tick answers a new number on every
 * call, the shape of a derive method that reads something non-deterministic. Every rederive
 * pass changes every record, which is how the pass cap and its warning are reached. With
 * $fail set, the derive method throws instead. Backed by the runtime table
 * derived_unstable_fixtures (Derived_Column_Fixture_Tables).
 */
class Derived_Unstable_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'derived_unstable_fixtures';
    protected $fillable = [];

    public static $columns = [
        'tick' => ['derived' => 'derive_tick', 'from' => []],
    ];

    /** The last number derive_tick() answered. */
    public static int $ticks = 0;

    /** When true, derive_tick() throws. */
    public static bool $fail = false;

    public function derive_tick(): int
    {
        if (static::$fail) {
            throw new RuntimeException('Derived_Unstable_Fixture_Model::derive_tick() failed on purpose.');
        }

        return ++static::$ticks;
    }
}
