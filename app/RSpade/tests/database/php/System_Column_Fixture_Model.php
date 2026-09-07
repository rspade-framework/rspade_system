<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture for the __get()/__isset() fast path: a model whose table carries BOTH a
 * SYSTEM column (a single leading underscore) and an enum column.
 *
 * A system column is the one name that looks like it might be caught by the double-
 * underscore discriminator and is not: `_flag` contains no `__`, so it takes the fast
 * path's short exit and must still read back exactly like any other column. No model in
 * the shipped tree declares one yet, which is why this fixture exists.
 *
 * The table is created and dropped by Model_Attribute_Read_Test, so it is absent from the
 * manifest's schema metadata - nothing here depends on an automatic cast.
 */
class System_Column_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'model_attribute_read_fixtures';

    protected $fillable = [];

    public $timestamps = false;

    public static $enums = [
        'state_id' => [
            1 => ['constant' => 'STATE_OPEN', 'label' => 'Open', 'tone' => 'green'],
            2 => ['constant' => 'STATE_SHUT', 'label' => 'Shut', 'tone' => 'red'],
        ],
    ];
}
