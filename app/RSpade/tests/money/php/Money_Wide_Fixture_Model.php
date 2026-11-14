<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;

/**
 * Test fixture: a second model over money_fixtures declaring money on the columns
 * MODEL-MONEY-01 refuses - wide (DECIMAL(19,2), precision past 18) and rate (DECIMAL(8,3),
 * scale 3) - beside amount (DECIMAL(15,2)), which it accepts. Only the rule reads it; a read
 * of rate through the cast would throw, which is what the rule exists to report first.
 */
class Money_Wide_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'money_fixtures';
    protected $fillable = [];

    public static $columns = [
        'amount' => ['money' => true],
        'wide' => ['money' => true],
        'rate' => ['money' => true],
    ];
}
