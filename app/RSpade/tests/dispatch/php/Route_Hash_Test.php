<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Dispatch\Php;

use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx::Route()'s third argument, the hash state: a fragment encoded exactly as
 * Rsx._serialize_hash() encodes it, so Rsx.url_hash_get() reads every value back unchanged.
 *
 * PARITY: the PARITY_URL literal below is ALSO the expected output of the JS twin in
 * playwright/route_hash.js, which feeds it to Rsx.url_hash_get() - so the byte-identical
 * encoding in the two languages is pinned against one string.
 */
class Route_Hash_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const TARGET = 'Dispatch_Page_Fixture_Controller::page';

    private const PARITY_VALUE = "a b&c=d#e/\u{00E9}+~!*'()";

    private const PARITY_URL = "/test-dispatch/page?q=1#k=a%20b%26c%3Dd%23e%2F%C3%A9%2B~!*'()&n=42&at=sec%201";

    public static function test_hash_state_is_encoded_as_the_js_serializer_encodes_it()
    {
        static::__assert_equals(
            self::PARITY_URL,
            Rsx::Route(self::TARGET, ['q' => 1, 'at' => 'sec 1'], ['k' => self::PARITY_VALUE, 'n' => 42, 'empty' => '', 'gone' => null]),
            'query first, then the hash keys in order, then the anchor; null and empty values dropped'
        );
    }

    public static function test_no_hash_and_an_all_empty_hash_add_no_fragment()
    {
        static::__assert_equals('/test-dispatch/page', Rsx::Route(self::TARGET));
        static::__assert_equals('/test-dispatch/page', Rsx::Route(self::TARGET, null, []));
        static::__assert_equals('/test-dispatch/page', Rsx::Route(self::TARGET, null, ['a' => null, 'b' => '']));
    }

    public static function test_the_anchor_alone_is_unchanged()
    {
        static::__assert_equals('/test-dispatch/page#at=sec%201', Rsx::Route(self::TARGET, ['at' => 'sec 1']));
        static::__assert_equals('/test-dispatch/page#tab=x&at=top', Rsx::Route(self::TARGET, ['at' => 'top'], ['tab' => 'x']));
    }

    public static function test_a_placeholder_route_ignores_the_hash()
    {
        static::__assert_equals('#', Rsx::Route('Anything::#index', null, ['tab' => 'x']));
    }

    public static function test_a_non_scalar_value_throws()
    {
        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Rsx::Route(self::TARGET, null, ['tab' => ['a']]);
        }, "'tab' must be a string or an int");
        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Rsx::Route(self::TARGET, null, ['tab' => true]);
        }, 'got bool');
        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Rsx::Route(self::TARGET, null, ['tab' => 1.5]);
        }, 'got float');
        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Rsx::Route(self::TARGET, ['at' => ['x']]);
        }, "'at' must be a string or an int");
    }

    public static function test_a_list_or_an_integer_key_throws()
    {
        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Rsx::Route(self::TARGET, null, ['a', 'b']);
        }, 'non-integer');
        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Rsx::Route(self::TARGET, null, ['5' => 'x']);
        }, 'non-integer');
    }

    public static function test_the_anchor_key_in_the_hash_throws()
    {
        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Rsx::Route(self::TARGET, null, ['at' => 'x']);
        }, 'reserved anchor key');
    }

    public static function test_invalid_utf8_throws()
    {
        static::__assert_throws(Rsx_Caller_Exception::class, function () {
            Rsx::Route(self::TARGET, null, ['k' => "\xC3"]);
        }, 'UTF-8');
    }
}
