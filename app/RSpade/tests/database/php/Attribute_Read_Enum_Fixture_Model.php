<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * The SECOND enum-carrying fixture, on its OWN table.
 *
 * The enum magic properties are memoized per class and per class+column, and the only way
 * to prove a memo is not shared is to ask two different classes the same question. This one
 * declares two enum columns (so a second column on the same model resolves independently)
 * and a CUSTOM enum property, while System_Column_Fixture_Model declares one column with a
 * different custom property on a different table.
 *
 * Enum resolution is declared in PHP, never derived from the schema, so these answers hold
 * for a fixture whose table the manifest never saw. The table exists only so a row can be
 * committed and read back; it is created and dropped by Model_Attribute_Read_Test.
 */
class Attribute_Read_Enum_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'model_attribute_read_enum_fixtures';

    protected $fillable = [];

    public $timestamps = false;

    const STATUS_ACTIVE = 1;
    const STATUS_PROSPECT = 2;
    const STATUS_ARCHIVED = 3;
    const STATUS_CLOSED = 4;

    const PRIORITY_HIGH = 1;
    const PRIORITY_MEDIUM = 2;
    const PRIORITY_LOW = 3;

    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_ACTIVE', 'label' => 'Active', 'badge' => 'bg-success'],
            2 => ['constant' => 'STATUS_PROSPECT', 'label' => 'Prospect', 'badge' => 'bg-info'],
            3 => ['constant' => 'STATUS_ARCHIVED', 'label' => 'Archived', 'badge' => 'bg-warning'],
            4 => ['constant' => 'STATUS_CLOSED', 'label' => 'Closed', 'badge' => 'bg-secondary'],
        ],
        'priority_id' => [
            1 => ['constant' => 'PRIORITY_HIGH', 'label' => 'High'],
            2 => ['constant' => 'PRIORITY_MEDIUM', 'label' => 'Medium'],
            3 => ['constant' => 'PRIORITY_LOW', 'label' => 'Low'],
        ],
    ];
}
