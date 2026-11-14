<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Wrapped_Text;

/**
 * Test fixture: a record carrying both special field kinds, backed by the runtime table
 * api_plain_value_fixtures (created by Api_Plain_Values_Test::setup()).
 *
 *   amount - money, nullable
 *   body   - a declared text type (Text_Fixture_Wrapped_Text: '<p>...</p>'), nullable
 *
 * @TEXT-TYPE-01-EXCEPTION - the borrowed text-type fixture has no JavaScript twin; this
 * model's payloads are read by PHP test assertions only and never reach a browser.
 */
class Api_Plain_Values_Fixture_Model extends Rsx_Model_Abstract
{
    public const TABLE = 'api_plain_value_fixtures';

    protected $table = 'api_plain_value_fixtures';
    protected $fillable = [];

    public static $columns = [
        'amount' => ['money' => true],
        'body' => ['text_type' => Text_Fixture_Wrapped_Text::class],
    ];
}
