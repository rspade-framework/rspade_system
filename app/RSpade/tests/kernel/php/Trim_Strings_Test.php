<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Kernel\Php;

use App\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The TrimStrings middleware trims ordinary input, never a field whose name contains
 * "password" (any case, any depth), and never a text-type envelope.
 */
class Trim_Strings_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run the middleware over a POST body and return the request it hands on.
     */
    private static function __trim(array $body): Request
    {
        $request = Request::create('/probe', 'POST', $body);
        $seen = null;

        (new TrimStrings())->handle($request, function ($passed) use (&$seen) {
            $seen = $passed;

            return response('');
        });

        return $seen;
    }

    public static function test_ordinary_input_is_trimmed()
    {
        $request = static::__trim(['name' => '  Ada  ', 'nested' => ['city' => ' Paris ']]);

        static::__assert_equals('Ada', $request->input('name'));
        static::__assert_equals('Paris', $request->input('nested.city'));
    }

    public static function test_any_password_named_field_is_left_alone()
    {
        $request = static::__trim([
            'password' => ' secret ',
            'new_password_confirm' => ' secret ',
            'PassWord2' => ' x ',
            'account' => ['current_password' => ' y '],
        ]);

        static::__assert_equals(' secret ', $request->input('password'));
        static::__assert_equals(' secret ', $request->input('new_password_confirm'));
        static::__assert_equals(' x ', $request->input('PassWord2'));
        static::__assert_equals(' y ', $request->input('account.current_password'));
    }

    public static function test_a_text_envelope_is_left_alone()
    {
        $envelope = ['__TEXT' => 'Text_Fixture_Wrapped_Text', 'raw' => "  <p>indented</p>\n", 'empty' => false];
        $request = static::__trim(['description' => $envelope, 'title' => ' T ']);

        static::__assert_equals($envelope, $request->input('description'));
        static::__assert_equals('T', $request->input('title'));
    }
}
