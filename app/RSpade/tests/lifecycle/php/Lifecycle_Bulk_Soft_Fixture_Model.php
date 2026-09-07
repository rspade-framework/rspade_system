<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Lifecycle\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
/**
 * Test fixture: a SOFT-delete model overriding after_update() and after_delete(). A bulk
 * ->delete() on this model becomes an UPDATE of deleted_at (issued through the builder's
 * update() override from inside parent::delete()); the re-entrancy guard must make the bulk op
 * fire after_delete ONCE and NEVER after_update for that internal write — so $update_calls must
 * stay empty. Backed by lifecycle_bulk_soft_fixtures.
 */
class Lifecycle_Bulk_Soft_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    protected $table = 'lifecycle_bulk_soft_fixtures';
    protected $fillable = [];

    public static $enums = [];

    /**
     * @var array<int, array{id: int, changed: array<int, string>}>
     */
    public static array $update_calls = [];

    /**
     * @var array<int, array{id: int}>
     */
    public static array $delete_calls = [];

    public function after_update(array $changed_fields): void
    {
        self::$update_calls[] = ['id' => (int) $this->id, 'changed' => $changed_fields];
    }

    public function after_delete(): void
    {
        self::$delete_calls[] = ['id' => (int) $this->id];
    }

    public static function _reset(): void
    {
        self::$update_calls = [];
        self::$delete_calls = [];
    }
}
