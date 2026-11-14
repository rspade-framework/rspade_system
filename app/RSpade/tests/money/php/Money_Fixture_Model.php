<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Money\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Money\Rsx_Money;

/**
 * Test fixture: a model with money columns, backed by the runtime table money_fixtures
 * (Money_Fixture_Tables). It records revisions, so a test can see what a money change
 * records and Form_Edit can edit it.
 *
 *   amount  - money, nullable
 *   price   - money, NOT NULL
 *   hidden  - money, never exported
 *   doubled - money, derived from amount (assigned through the cast)
 *   score, ratio, hours - declared explicitly not money
 */
class Money_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'money_fixtures';
    protected $fillable = [];

    public static $revisions = true;

    public static $columns = [
        'amount' => ['money' => true],
        'price' => ['money' => true],
        'hidden' => ['money' => true, 'never_export' => true],
        'doubled' => ['money' => true, 'derived' => 'derive_doubled', 'from' => ['amount']],
        'score' => [],
        'ratio' => [],
        'hours' => [],
    ];

    public function derive_doubled(): ?Rsx_Money
    {
        return $this->amount?->multiply(2, Rsx_Money::ROUND_EXACT);
    }
}
