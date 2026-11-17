<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ColumnEquivalence\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a revisioned model whose save() override rederives after parent::save() - the
 * documented shape for a derived column that reads something outside the row - so a caller's
 * one save() performs two writes of the same instance. Backed by the runtime table
 * recorded_changes_fixtures, created by Recorded_Changes_Nested_Save_Test.
 *
 * `label` is derived from `note` plus $label_suffix, a value outside the row: changing the
 * suffix makes the stored label stale without dirtying any column, so the rederive() the
 * override makes after an edit of `name` has a real write to do.
 */
class Recorded_Changes_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'recorded_changes_fixtures';

    protected $fillable = [];

    public static $revisions = true;

    public static $columns = [
        'label' => ['derived' => 'derive_label', 'from' => ['note']],
    ];

    /** What derive_label() appends to the note: the state outside the row. */
    public static string $label_suffix = '-a';

    /** Whether save() rederives after parent::save(). */
    public static bool $rederive_after_save = true;

    public function derive_label(): string
    {
        return ($this->note ?? '') . static::$label_suffix;
    }

    public function save(array $options = [])
    {
        $result = parent::save($options);

        if ($result && static::$rederive_after_save) {
            $this->rederive();
        }

        return $result;
    }
}
