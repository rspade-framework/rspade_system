<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: an unbounded (never-repeating) touch chain — each instance touches a
 * fresh instance with id + 1, so the visited set never catches it. Proves the depth cap
 * is the backstop that terminates such a chain. In-memory only.
 */
class Realtime_Deep_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'realtime_deep_fixtures';
    protected $fillable = [];

    public static $enums = [];

    public static $realtime = true;

    public function realtime_touch(): array
    {
        $next = new Realtime_Deep_Fixture_Model();
        $next->id = $this->id + 1;

        return [$next];
    }
}
