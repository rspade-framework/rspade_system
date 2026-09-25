<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Ajax::internal() rehydrates text envelopes, as the direct browser path does.
 *
 * A batched Ajax call reaches its endpoint through Ajax::internal(), and batching is on in
 * every mode but development. An envelope that reached a declared column still as an array
 * failed the save ("... is Rich_Text, got array"), so every rich-text edit broke outside
 * development while working inside it.
 */
class Text_Ajax_Hydration_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_an_internal_call_receives_the_request_wrapper_not_the_envelope()
    {
        $result = Ajax::internal('Text_Ajax_Fixture_Controller', 'describe_body', [
            'body' => ['__TEXT' => 'Text_Fixture_Wrapped_Text', 'raw' => '<p>x</p>', 'empty' => false],
        ]);

        static::__assert_equals('Rsx_Text_Request_Value', $result['type']);
    }

    public static function test_an_ordinary_param_is_untouched()
    {
        $result = Ajax::internal('Text_Ajax_Fixture_Controller', 'describe_body', ['body' => 'plain']);

        static::__assert_equals('string', $result['type']);
    }
}
