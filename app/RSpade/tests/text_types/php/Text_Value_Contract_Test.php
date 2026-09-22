<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Request_Value;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Bare_Text;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Loud_Text;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Plain_Text;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Wrapped_Text;

/**
 * The text-value contract: construction, the trust boundary, the wire envelope, and the
 * refusals.
 *
 * The refusals are the point of the feature and get the most coverage here. A text type
 * that quietly accepted the wrong thing would be worse than no text type at all: the whole
 * proposition is that a value which reaches the wrong sink FAILS instead of rendering
 * incorrectly, so every one of those failures is pinned.
 *
 * The request side is pinned from the other direction: that hydration resolves NOTHING. A
 * `__TEXT` naming a class that does not exist must produce a wrapper, not an error, because
 * an error would prove the name was looked at - and a name from the wire is never looked at.
 *
 * The fixtures below are local to this test rather than the shipped Rich_Text/Raw_Text,
 * which live in the reference application and are the application's to change - a
 * framework test that asserted on their filtering would break the moment a downstream
 * developer adapted them, which is exactly what they are there to be.
 */
class Text_Value_Contract_Test extends Rsx_Test_Abstract
{
    public static function test_from_storage_is_trusted_and_unfiltered()
    {
        $value = Text_Fixture_Loud_Text::from_storage('  hello  ');

        // The database is trusted: no filter ran, so the stored bytes came back untouched.
        static::__assert_equals('  hello  ', $value->to_storage());
    }

    public static function test_from_untrusted_applies_the_type_filter()
    {
        $value = Text_Fixture_Loud_Text::from_untrusted('  hello  ');

        static::__assert_equals('HELLO', $value->to_storage(), 'the type filter ran exactly once');
    }

    public static function test_from_storage_passes_null_through()
    {
        static::__assert_null(Text_Fixture_Loud_Text::from_storage(null));
    }

    public static function test_string_coercion_is_refused()
    {
        $value = Text_Fixture_Loud_Text::from_storage('<b>hi</b>');

        // A typed value used as a string is a typed value used wrongly. The case that
        // settles it is a write: `$copy->x = $orig->x . 'more'` would silently strip the
        // markup and store something plausible. The exception is the signal.
        static::__assert_throws(\LogicException::class, fn () => (string) $value, 'cannot be used as a string');
        static::__assert_throws(\LogicException::class, fn () => str_contains($value, 'h'));

        // The named methods are how you say what you mean.
        static::__assert_equals('hi', $value->to_text());
    }

    // ------------------------------------------------------------------
    // The request wrapper: typeless, inert, resolved only at a column or a type
    // ------------------------------------------------------------------

    public static function test_request_hydration_produces_a_typeless_wrapper_and_resolves_nothing()
    {
        // The claimed type is a class that does NOT exist. If hydration resolved names,
        // this would fail here; the whole point is that it cannot, because it never tries.
        $params = Rsx_Text_Abstract::hydrate_request_value([
            'body' => ['__TEXT' => 'No_Such_Class_Anywhere', 'raw' => 'x', 'empty' => false],
        ]);

        static::__assert_instance_of(Rsx_Text_Request_Value::class, $params['body']);
        static::__assert_equals('No_Such_Class_Anywhere', $params['body']->claimed_type(), 'the claim is carried opaquely');
    }

    public static function test_request_wrapper_refuses_to_be_a_string()
    {
        $params = Rsx_Text_Abstract::hydrate_request_value([
            'body' => ['__TEXT' => 'Text_Fixture_Loud_Text', 'raw' => 'shout', 'empty' => false],
        ]);

        static::__assert_throws(\LogicException::class, fn () => (string) $params['body'], 'cannot be used as a string');
        static::__assert_throws(\LogicException::class, fn () => 'prefix ' . $params['body']);
    }

    public static function test_request_wrapper_answers_emptiness_from_the_client_after_its_own_checks()
    {
        $wrap = fn ($raw, $empty) => Rsx_Text_Abstract::hydrate_request_value([
            'v' => ['__TEXT' => 'Text_Fixture_Plain_Text', 'raw' => $raw, 'empty' => $empty],
        ])['v'];

        // '' needs no trust.
        static::__assert_true($wrap('', false)->is_empty(), 'an empty string is empty regardless of the flag');

        // Encoding-empty content is the client's call: the browser had the real type.
        static::__assert_true($wrap('<p><br></p>', true)->is_empty());
        static::__assert_false($wrap('<p>x</p>', false)->is_empty());

        // A missing flag means not-empty; the flag can only ADD emptiness.
        static::__assert_false(Rsx_Text_Abstract::hydrate_request_value([
            'v' => ['__TEXT' => 'Text_Fixture_Plain_Text', 'raw' => 'x'],
        ])['v']->is_empty());
    }

    public static function test_request_wrapper_echoes_the_claim_back_unresolved()
    {
        $params = Rsx_Text_Abstract::hydrate_request_value([
            'v' => ['__TEXT' => 'Whatever_The_Client_Said', 'raw' => 'r', 'empty' => false],
        ]);

        $envelope = $params['v']->jsonSerialize();
        static::__assert_equals('Whatever_The_Client_Said', $envelope['__TEXT']);
        static::__assert_equals('r', $envelope['raw']);
        static::__assert_false($envelope['empty']);
    }

    // ------------------------------------------------------------------
    // from_request(): the programmer names the type
    // ------------------------------------------------------------------

    public static function test_from_request_resolves_a_wrapper_through_the_named_types_filter()
    {
        $params = Rsx_Text_Abstract::hydrate_request_value([
            'body' => ['__TEXT' => 'Text_Fixture_Loud_Text', 'raw' => '  shout  ', 'empty' => false],
        ]);

        $value = Text_Fixture_Loud_Text::from_request($params['body']);

        static::__assert_instance_of(Text_Fixture_Loud_Text::class, $value);
        static::__assert_equals('SHOUT', $value->to_storage(), 'the named type\'s filter ran on the way in');
    }

    public static function test_from_request_refuses_a_wrapper_whose_claim_disagrees()
    {
        $params = Rsx_Text_Abstract::hydrate_request_value([
            'body' => ['__TEXT' => 'Text_Fixture_Plain_Text', 'raw' => 'x', 'empty' => false],
        ]);

        // A consistency check, not a security control: the form and the endpoint disagree
        // about what this field is, and that is a bug to surface.
        static::__assert_throws(
            \LogicException::class,
            fn () => Text_Fixture_Loud_Text::from_request($params['body']),
            'disagree'
        );
    }

    public static function test_from_request_accepts_its_own_type_and_refuses_another()
    {
        $own = Text_Fixture_Loud_Text::from_storage('x');
        static::__assert_true(Text_Fixture_Loud_Text::from_request($own) === $own, 'already typed, passed through');

        static::__assert_throws(
            \LogicException::class,
            fn () => Text_Fixture_Loud_Text::from_request(Text_Fixture_Plain_Text::from_storage('x')),
            'do not convert'
        );
    }

    public static function test_from_request_accepts_a_bare_string_and_null()
    {
        static::__assert_equals('[BARE]', Text_Fixture_Loud_Text::from_request('bare')->to_storage(), 'a bare string is plain text: escaped, then filtered');
        static::__assert_null(Text_Fixture_Loud_Text::from_request(null));
    }

    public static function test_from_string_escapes_then_filters()
    {
        // A bare string is PLAIN TEXT: escape_string() converts it into the encoding and
        // filter_set() still runs on the result, so the plain-text door cannot skip the filter.
        static::__assert_equals('[  HELLO  ]', Text_Fixture_Loud_Text::from_string('  hello  ')->to_storage());
        static::__assert_equals('<p>a &lt;b&gt; c</p>', Text_Fixture_Wrapped_Text::from_string('a <b> c')->to_storage());
    }

    public static function test_a_type_is_complete_with_only_its_filter()
    {
        // filter_set() and escape_string() are the requirements. A type declaring nothing else can be
        // constructed, stored, and asked the one question every endpoint asks.
        $value = Text_Fixture_Bare_Text::from_untrusted('  content  ');

        static::__assert_equals('  content  ', $value->to_storage());
        static::__assert_false($value->is_empty());
        static::__assert_true(Text_Fixture_Bare_Text::from_storage('   ')->is_empty(), 'the default emptiness test is the raw check');
    }

    public static function test_the_optional_renditions_throw_unless_the_type_defines_them()
    {
        $value = Text_Fixture_Bare_Text::from_storage('x');

        // to_text() and to_html() are CONVENTIONS - names application code can rely on
        // when a type chooses to offer them - not requirements. Asking a type that never
        // defined one must fail loudly, never guess a rendition.
        static::__assert_throws(\LogicException::class, fn () => $value->to_text(), 'does not define to_text()');
        static::__assert_throws(\LogicException::class, fn () => $value->to_html(), 'does not define to_html()');
    }

    public static function test_is_empty_reads_the_plain_rendition_not_the_raw_form()
    {
        $blank = Text_Fixture_Wrapped_Text::from_storage('<p><br></p>');

        // The raw form is a non-empty string and the document is empty. A `=== ''` check
        // on the column would call this non-empty, which is the trap is_empty() exists for.
        static::__assert_true($blank->is_empty(), 'a wrapper with no content is empty');
        static::__assert_false(Text_Fixture_Wrapped_Text::from_storage('<p>x</p>')->is_empty());
    }

    public static function test_the_wire_envelope_names_the_type_by_simple_name()
    {
        $envelope = Text_Fixture_Plain_Text::from_storage('body')->jsonSerialize();

        static::__assert_equals('Text_Fixture_Plain_Text', $envelope['__TEXT']);
        static::__assert_equals('body', $envelope['raw']);
        static::__assert_false($envelope['empty']);

        // No plain-text rendition travels: it would roughly double every text column's
        // payload to serve a browser that renders through the PRINTER component instead.
        static::__assert_false(array_key_exists('text', $envelope), 'only raw and empty travel');
    }

    public static function test_request_hydration_wraps_recursively_and_leaves_everything_else_alone()
    {
        $params = Rsx_Text_Abstract::hydrate_request_value([
            'name' => 'an ordinary string',
            'body' => ['__TEXT' => 'Text_Fixture_Loud_Text', 'raw' => 'shout'],
            'nested' => [['__TEXT' => 'Text_Fixture_Plain_Text', 'raw' => 'deep']],
        ]);

        static::__assert_equals('an ordinary string', $params['name'], 'untyped values are untouched');
        static::__assert_instance_of(Rsx_Text_Request_Value::class, $params['body']);
        static::__assert_instance_of(Rsx_Text_Request_Value::class, $params['nested'][0], 'the walk is recursive');

        // NOT filtered on arrival - nothing is typed yet. The filter runs at the column.
        static::__assert_equals('shout', $params['body']->_raw());
    }

    public static function test_request_hydration_passes_a_null_raw_through()
    {
        $params = Rsx_Text_Abstract::hydrate_request_value([
            'body' => ['__TEXT' => 'Text_Fixture_Plain_Text', 'raw' => null],
        ]);

        static::__assert_null($params['body']);
    }

    public static function test_request_hydration_refuses_a_non_string_raw_form()
    {
        static::__assert_throws(
            \InvalidArgumentException::class,
            function () {
                Rsx_Text_Abstract::hydrate_request_value([
                    'body' => ['__TEXT' => 'Text_Fixture_Plain_Text', 'raw' => ['nested' => 'object']],
                ]);
            },
            'non-string raw form'
        );
    }

    public static function test_request_hydration_refuses_a_malformed_envelope_instead_of_passing_it_through()
    {
        // A key named __TEXT is a claim to be an envelope. A claim that fails the shape is
        // refused - never walked as an ordinary array and never demoted to plain text.
        $malformed = [
            'no raw' => ['__TEXT' => 'Text_Fixture_Plain_Text'],
            'non-string type' => ['__TEXT' => 7, 'raw' => 'x'],
            'empty type' => ['__TEXT' => '', 'raw' => 'x'],
            'extra key' => ['__TEXT' => 'Text_Fixture_Plain_Text', 'raw' => 'x', 'html' => 'y'],
            'non-bool empty' => ['__TEXT' => 'Text_Fixture_Plain_Text', 'raw' => 'x', 'empty' => 'no'],
        ];

        foreach ($malformed as $envelope) {
            static::__assert_throws(
                \InvalidArgumentException::class,
                fn () => Rsx_Text_Abstract::hydrate_request_value(['body' => $envelope])
            );
        }
    }

    public static function test_a_request_string_is_an_envelope_only_when_it_is_a_json_object_with_a_text_key()
    {
        $envelope = json_encode(['__TEXT' => 'Text_Fixture_Loud_Text', 'raw' => '<b>x</b>', 'empty' => false]);
        $wrapped = Rsx_Text_Abstract::hydrate_request_string($envelope);

        static::__assert_instance_of(Rsx_Text_Request_Value::class, $wrapped);
        static::__assert_equals('<b>x</b>', $wrapped->_raw(), 'unfiltered until it reaches a column');

        // Each of these is an ordinary string - plain text to a declared column.
        foreach (['plain', '{hello}', '{"raw": "x"}', ' ' . $envelope, '[' . $envelope . ']'] as $ordinary) {
            static::__assert_equals($ordinary, Rsx_Text_Abstract::hydrate_request_string($ordinary));
        }

        static::__assert_null(Rsx_Text_Abstract::hydrate_request_string('{"__TEXT": "Text_Fixture_Plain_Text", "raw": null}'));
    }

    public static function test_a_request_string_identified_as_an_envelope_faces_the_ajax_shape_check()
    {
        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => Rsx_Text_Abstract::hydrate_request_string('{"__TEXT": "Text_Fixture_Plain_Text", "raw": ["x"]}'),
            'non-string raw form'
        );
        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => Rsx_Text_Abstract::hydrate_request_string('{"__TEXT": "Text_Fixture_Plain_Text"}'),
            'no raw form'
        );
    }
}
