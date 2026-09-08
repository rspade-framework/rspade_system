<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a model whose only interesting feature is a DERIVED PROPERTY.
 *
 * $appends plus a getXAttribute() accessor delegating to the public method that defines the
 * value is the sanctioned route for a computed value that must reach JavaScript. The stub
 * generator reads the declaration and names each entry on the generated Base_*_Model.js, which
 * is what Model_Stub_Appends_Test asserts. No table backs this - nothing here touches the DB.
 */
class Appends_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'codegen_appends_fixtures';
    protected $fillable = [];

    public static $enums = [];

    protected $appends = ['display_id', 'is_flagged'];

    /**
     * @return string
     */
    public function display_id()
    {
        return '#CG' . str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return bool
     */
    public function is_flagged()
    {
        return false;
    }

    /**
     * Accessor for the appended `display_id` property. Delegates - never re-computes.
     *
     * @return string
     */
    public function getDisplayIdAttribute(): string
    {
        return $this->display_id();
    }

    /**
     * Accessor for the appended `is_flagged` property. Delegates - never re-computes.
     *
     * @return bool
     */
    public function getIsFlaggedAttribute(): bool
    {
        return $this->is_flagged();
    }
}
