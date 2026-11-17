<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ColumnEquivalence\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a revisioned, realtime model with an after_update() hook, backed by the
 * runtime table column_equivalence_fixtures (Column_Equivalence_Fixture_Tables), so a test can
 * see every effect a dirty answer gates: the write, the revision, the audit stamp, the
 * realtime frame and the lifecycle hook.
 */
class Column_Equivalence_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'column_equivalence_fixtures';

    protected $fillable = [];

    public static $revisions = true;

    public static $realtime = true;

    public static $columns = [
        'code' => ['blank_distinct' => true],
        'hours' => [],
        'budget' => ['money' => true],
        'last_seen_at' => ['revision_exclude' => true],
        'token' => ['never_export' => true],
        'owner_type' => ['type_ref' => true],
    ];

    public function after_update(array $changed_fields): void
    {
    }
}
