<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: the BASE half of a split model.
 *
 * A framework model carries every member here - table, enums, constants, derived properties -
 * and ships a three-line concrete an application replaces. Everything the build generates
 * from a model is therefore a function of this file, not of the concrete's, which is what
 * Model_Stub_Appends_Test asserts on both sides: the stub REFLECTS these members, and the
 * staleness key that decides whether to regenerate it COVERS this file.
 *
 * No table backs it - nothing here touches the DB.
 */
abstract class Split_Fixture_Model_Abstract extends Rsx_Model_Abstract
{
    const SPLIT_FIXTURE_STATE_OPEN = 1;
    const SPLIT_FIXTURE_STATE_CLOSED = 2;

    protected $table = 'codegen_split_fixtures';
    protected $fillable = [];

    public static $enums = [];

    protected $appends = ['base_display_id'];

    /**
     * @return string
     */
    public function base_display_id()
    {
        return '#SP' . str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Accessor for the appended `base_display_id` property. Delegates - never re-computes.
     *
     * @return string
     */
    public function getBaseDisplayIdAttribute(): string
    {
        return $this->base_display_id();
    }
}
