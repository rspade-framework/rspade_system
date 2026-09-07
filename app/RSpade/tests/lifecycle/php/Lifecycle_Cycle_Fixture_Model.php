<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Lifecycle\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a MISBEHAVING model whose after_update() unconditionally writes its own
 * record again (dirtying the counter each time), which would recurse forever without the
 * buffer's flush-depth cap. Proves the cap breaks the write cycle instead of overflowing
 * the stack. Every hook call is recorded so the test can assert a BOUNDED number of runs.
 */
class Lifecycle_Cycle_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'lifecycle_fixtures';
    protected $fillable = [];

    public static $enums = [];

    /**
     * Number of times after_update() fired.
     */
    public static int $update_calls = 0;

    public function after_update(array $changed_fields): void
    {
        self::$update_calls++;

        // Always dirty a column so the re-save is a real UPDATE that re-queues this hook —
        // the pathological cycle the depth cap must contain.
        $this->counter = (int) $this->counter + 1;
        $this->save();
    }

    public static function _reset(): void
    {
        self::$update_calls = 0;
    }
}
