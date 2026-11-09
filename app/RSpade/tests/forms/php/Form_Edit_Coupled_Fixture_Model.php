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
 * Test fixture: the record a Form_Edit_Fixture_Model edit also writes (an activity married to
 * its entry). Backed by the runtime table form_edit_coupled_fixtures, created by
 * Form_Edit_Coupled_Test. start_date + start_hour are one form field ("starts"); note is
 * edited only on its own screen.
 */
class Form_Edit_Coupled_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    public const KIND_MEETING = 1;
    public const KIND_CALL = 2;

    protected $table = 'form_edit_coupled_fixtures';
    protected $fillable = [];

    public static $enums = [
        'kind_id' => [
            self::KIND_MEETING => ['constant' => 'KIND_MEETING', 'label' => 'Meeting'],
            self::KIND_CALL => ['constant' => 'KIND_CALL', 'label' => 'Phone call'],
        ],
    ];

    public static $revisions = true;
}
