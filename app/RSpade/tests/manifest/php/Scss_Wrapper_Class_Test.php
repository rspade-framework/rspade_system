<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Integrations\Scss\Scss_ManifestModule;

/**
 * THE WRAPPER CLASS OF A STYLESHEET (Scss_ManifestModule::wrapper_class_of(), recorded as
 * `scss_wrapper_class`).
 *
 * A stylesheet is wrapped in a class only when the brace opened after its FIRST top-level
 * selector is the one that closes at the end of the file. The closing brace is found by
 * depth, with comments and quoted strings ignored, so a file of several top-level rules is
 * never read as wrapped however its braces happen to balance - the misreading a downstream
 * field report (2026-10-15) found, where `.A { x } .B { y }` was recorded as wrapped in `A`
 * because the text between the first `{` and the last `}` had as many of one as the other.
 *
 * The SCSS-SCOPE-01 scoping rule and the bundle component shaker both read the answer.
 * Pure string work.
 */
class Scss_Wrapper_Class_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Several top-level rules: no wrapper class, whatever the second rule names.
     */
    public static function test_a_stylesheet_of_several_top_level_rules_has_no_wrapper()
    {
        foreach ([
            '.Alpha { } .Beta { }',
            '.Alpha { } html.x .Alpha { }',
            ".Alpha { color: red; }\n// a comment between the rules\n.Beta { color: blue; }",
            ".Alpha { &__error { &:not(:empty) { margin-bottom: 10px; } } }\nhtml.rsx-dark .Alpha .Alpha__loading { background: rgba(0, 0, 0, 0.6); }",
            ".Alpha { display: block; /* error dress */ }\n.cp-btn.btn--submitting { /* the submitting dots */ }",
            ".Alpha { }\n@keyframes spin { to { transform: rotate(360deg); } }",
            '.Alpha { a: b; } }',
            '.Alpha { a: b;',
        ] as $scss) {
            static::__assert_null(Scss_ManifestModule::wrapper_class_of($scss), 'no wrapper: ' . json_encode($scss));
        }
    }

    /**
     * One rule holding everything: wrapped, whatever the braces inside it look like - nested
     * rules, a brace in a comment, a brace in a string, interpolation, a url() - and with
     * comments or variable declarations around it.
     */
    public static function test_a_stylesheet_held_in_one_rule_is_wrapped()
    {
        foreach ([
            '.Alpha { }',
            '.Alpha { x { } }',
            '.Alpha { /* } */ }',
            '.Alpha { content: "}"; }',
            ".Alpha { content: '{'; }",
            ".Alpha { content: 'it\\'s }'; }",
            "// header\n.Alpha { }",
            "/* header { */\n.Alpha { color: red; }",
            ".Alpha { }\n// trailing comment",
            ".Alpha { }\n/* trailing } comment */",
            "\$gap: 4px;\n.Alpha { margin: \$gap; }",
            '.Alpha { #{$side}: 0; &__x { y: 1; } }',
            '.Alpha { background: url(data:image/svg+xml;utf8,<svg>}</svg>); }',
        ] as $scss) {
            static::__assert_equals('Alpha', Scss_ManifestModule::wrapper_class_of($scss), 'wrapped in Alpha: ' . json_encode($scss));
        }
    }

    /**
     * The accepted selector shape is unchanged: a class beginning with a capital, after an
     * optional framework `_`. Anything else at the top is not a wrapper.
     */
    public static function test_only_a_component_shaped_class_is_a_wrapper()
    {
        static::__assert_equals('_Sys_Probe', Scss_ManifestModule::wrapper_class_of('._Sys_Probe { }'));

        foreach (['.alpha { }', '#Alpha { }', 'div { }', '.Alpha .Beta { }', '.Alpha, .Beta { }', ''] as $scss) {
            static::__assert_null(Scss_ManifestModule::wrapper_class_of($scss), 'no wrapper: ' . json_encode($scss));
        }
    }
}
