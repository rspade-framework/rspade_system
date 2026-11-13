<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Forms\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
/**
 * Test fixture: a record whose "when" is ONE fact stored in three columns - a day for an
 * all-day record, a start and end instant for a timed one - declared as a fact group. Backed
 * by the runtime table form_edit_grouped_fixtures, created by Form_Edit_Fact_Groups_Test.
 * title, billing_text and stamp are ordinary columns.
 */
class Form_Edit_Grouped_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    protected $table = 'form_edit_grouped_fixtures';
    protected $fillable = [];

    public static $fact_groups = [
        'when' => ['label' => 'Date and time', 'columns' => ['all_day_date', 'starts_at', 'ends_at']],
    ];

    public static $revisions = true;
}
