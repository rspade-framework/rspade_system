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
 * Test fixture: a record edited through Form_Edit. Backed by the runtime table
 * form_edit_fixtures, created by Form_Edit_Test.
 */
class Form_Edit_Fixture_Model extends Rsx_Model_Abstract
{
    use SoftDeletes;

    public const LEVEL_LOW = 1;
    public const LEVEL_HIGH = 2;

    protected $table = 'form_edit_fixtures';
    protected $fillable = [];

    public static $columns = [
        'level_id' => ['enum' => [
            self::LEVEL_LOW => ['constant' => 'LEVEL_LOW', 'label' => 'Low'],
            self::LEVEL_HIGH => ['constant' => 'LEVEL_HIGH', 'label' => 'High'],
        ]],
        'secret_token' => ['never_export' => true],
    ];

    public static $revisions = true;
}
