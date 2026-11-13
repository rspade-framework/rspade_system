<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Wrapped_Text;

/**
 * Test fixture: the top of a three-class chain declaring its columns through $columns, one
 * entry of every kind. Model_Columns_Fixture_Child_Model_Abstract replaces, un-kinds and adds
 * entries beneath it; Model_Columns_Test reads the overlay through the accessors. Never
 * instantiated against a table - its table does not exist.
 *
 * @TEXT-TYPE-01-EXCEPTION - the borrowed text-type fixture has no JavaScript twin, and this
 * model is read in PHP only; no payload of it ever reaches a browser.
 */
abstract class Model_Columns_Fixture_Base_Model_Abstract extends Rsx_Model_Abstract
{
    protected $table = 'model_columns_fixtures';

    public static $columns = [
        'status_id' => ['enum' => [
            1 => ['label' => 'Open'],
            2 => ['label' => 'Closed'],
        ]],
        'body' => ['text_type' => Text_Fixture_Wrapped_Text::class],
        'subject_type' => ['type_ref' => true],
        'api_secret' => ['never_export' => true],
        'view_count' => ['revision_exclude' => true],
    ];
}
