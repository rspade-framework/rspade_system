<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Lifecycle\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a model that OVERRIDES all three lifecycle hooks, recording each call into
 * a process-static so a test can assert what fired (and, for updates, with which changed
 * fields). Backed by the runtime table lifecycle_fixtures (created by Model_Lifecycle_Test).
 */
class Lifecycle_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'lifecycle_fixtures';
    protected $fillable = [];

    public static $enums = [];

    /**
     * Recorded hook calls, in fire order.
     *
     * @var array<int, array{event: string, id: int, changed: array<int, string>}>
     */
    public static array $calls = [];

    public function after_create(): void
    {
        self::$calls[] = ['event' => 'create', 'id' => (int) $this->id, 'changed' => []];
    }

    public function after_update(array $changed_fields): void
    {
        self::$calls[] = ['event' => 'update', 'id' => (int) $this->id, 'changed' => $changed_fields];
    }

    public function after_delete(): void
    {
        self::$calls[] = ['event' => 'delete', 'id' => (int) $this->id, 'changed' => []];
    }

    public static function _reset(): void
    {
        self::$calls = [];
    }
}
