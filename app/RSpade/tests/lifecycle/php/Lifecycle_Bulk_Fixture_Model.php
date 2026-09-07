<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Lifecycle\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a HARD-delete model that overrides after_update() and after_delete() (NOT
 * after_create, so seed inserts stay silent). Records each hook call into process-statics so a
 * bulk test can assert what fired. after_delete records $this->name to prove the hard-delete
 * path reconstructs the record from its captured snapshot (the row is gone by then). Backed by
 * the runtime table lifecycle_bulk_fixtures.
 */
class Lifecycle_Bulk_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'lifecycle_bulk_fixtures';
    protected $fillable = [];

    public static $enums = [];

    /**
     * Recorded after_update calls: {id, changed}.
     *
     * @var array<int, array{id: int, changed: array<int, string>}>
     */
    public static array $update_calls = [];

    /**
     * Recorded after_delete calls: {id, name} (name proves snapshot attributes survived).
     *
     * @var array<int, array{id: int, name: mixed}>
     */
    public static array $delete_calls = [];

    public function after_update(array $changed_fields): void
    {
        self::$update_calls[] = ['id' => (int) $this->id, 'changed' => $changed_fields];
    }

    public function after_delete(): void
    {
        self::$delete_calls[] = ['id' => (int) $this->id, 'name' => $this->name];
    }

    public static function _reset(): void
    {
        self::$update_calls = [];
        self::$delete_calls = [];
    }
}
