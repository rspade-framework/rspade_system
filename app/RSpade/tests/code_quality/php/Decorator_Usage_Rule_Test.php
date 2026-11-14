<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\JavaScript\DecoratorUsage_CodeQualityRule;
use App\RSpade\CodeQuality\RuntimeChecks\YoureDoingItWrongException;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\JsParsers\Js_Parser;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for JS-DECORATOR-01 (DecoratorUsage_CodeQualityRule) - the manifest-build FATAL
 * refusing a decorator whose definition is not marked /** @decorator *\/, on a class and on a
 * method alike.
 *
 * Each case writes JavaScript fixtures outside the manifest, extracts their records with the
 * real parser (Js_Parser::extract_metadata(), through the node service) and hands them to
 * the rule's evaluate_files() seam, so the whitelist is exactly what the fixtures declare.
 */
class Decorator_Usage_Rule_Test extends Rsx_Test_Abstract
{
    // Pure file inspection - no database access.
    protected static $use_database_transactions = false;

    /**
     * The fixtures defining the whitelist: two global decorator functions, and a static
     * decorator method (whitelisted by the @decorator decorator on it - rsx:man js_decorators).
     */
    private const DEFINITIONS = "/** @decorator */\n"
        . "function probe_route(value, context) {\n"
        . "    return value;\n"
        . "}\n"
        . "\n"
        . "/** @decorator */\n"
        . "function probe_title(value, context) {\n"
        . "    return value;\n"
        . "}\n";

    private const DEFINITIONS_CLASS = "class Probe_Decorators {\n"
        . "    @decorator\n"
        . "    static probe_mark(value, context) {\n"
        . "        return value;\n"
        . "    }\n"
        . "}\n";

    /**
     * Write $source beside the definitions and run the rule over all three files. Returns
     * [the FATAL message or null, the probe file's path].
     *
     * @return array{0: ?string, 1: string}
     */
    private static function __run(string $source): array
    {
        $dir = Rsx_Project_Paths::tmp_path() . '/decorator_usage_rule_fixture_' . uniqid();
        ensure_directory($dir);

        $paths = [
            $dir . '/probe_definitions.js' => self::DEFINITIONS,
            $dir . '/Probe_Decorators.js' => self::DEFINITIONS_CLASS,
            $dir . '/Probe_Subject.js' => $source,
        ];

        try {
            $files = [];
            foreach ($paths as $path => $content) {
                file_put_contents($path, $content);
                $files[$path] = ['extension' => 'js'] + Js_Parser::extract_metadata($path);
            }

            $rule = new DecoratorUsage_CodeQualityRule(new ViolationCollector());

            try {
                $rule->evaluate_files($files);
            } catch (YoureDoingItWrongException $e) {
                return [$e->getMessage(), $dir . '/Probe_Subject.js'];
            }

            return [null, $dir . '/Probe_Subject.js'];
        } finally {
            foreach (array_keys($paths) as $path) {
                @unlink($path);
            }
            @rmdir($dir);
        }
    }

    // =====================================================================
    // Class decorators
    // =====================================================================

    public static function test_whitelisted_class_decorators_pass()
    {
        [$message] = static::__run(
            "@probe_route('/probe')\n"
            . "@probe_route('/probe/:id')\n"
            . "@probe_title('Probe')\n"
            . "@probe_mark\n"
            . "class Probe_Subject {\n"
            . "}\n"
        );

        static::__assert_null($message, 'whitelisted class decorators, with arguments, bare and stacked, pass');
    }

    public static function test_jsdoc_marker_is_not_a_decorator()
    {
        [$message] = static::__run(
            "/**\n"
            . " * A probe class.\n"
            . " *\n"
            . " * @Instantiatable\n"
            . " */\n"
            . "@probe_route('/probe')\n"
            . "class Probe_Subject {\n"
            . "}\n"
        );

        static::__assert_null($message, 'a JSDoc marker in the class docblock is not judged as a decorator');
    }

    public static function test_unknown_class_decorator_is_fatal()
    {
        [$message, $path] = static::__run(
            "@probe_rout('/probe')\n"
            . "class Probe_Subject {\n"
            . "}\n"
        );

        static::__assert_not_null($message, 'an unknown class decorator is FATAL');
        static::__assert_contains('FATAL: Class decorator not whitelisted', (string) $message);
        static::__assert_contains("File: {$path}\nLine: 1\nClass: Probe_Subject\nDecorator: @probe_rout\n", (string) $message);
        static::__assert_contains("The decorator '@probe_rout' applied to class 'Probe_Subject' is not whitelisted.", (string) $message);
        static::__assert_contains("WHITELISTED DECORATORS:\n  @probe_mark, @probe_route, @probe_title\n", (string) $message);
    }

    public static function test_stacked_with_one_unknown_names_that_one()
    {
        [$message] = static::__run(
            "@probe_route('/probe')\n"
            . "@probe_title('Probe')\n"
            . "@probe_spa('Probe_Controller::index')\n"
            . "@probe_mark\n"
            . "class Probe_Subject {\n"
            . "}\n"
        );

        static::__assert_not_null($message, 'a stack holding one unknown decorator is FATAL');
        static::__assert_contains("Line: 3\nClass: Probe_Subject\nDecorator: @probe_spa\n", (string) $message);
        static::__assert_false(str_contains((string) $message, 'Decorator: @probe_route'), 'only the unknown decorator is named');
    }

    public static function test_bare_unknown_class_decorator_is_fatal()
    {
        [$message] = static::__run(
            "@probe_unknown\n"
            . "class Probe_Subject {\n"
            . "}\n"
        );

        static::__assert_not_null($message, 'an unknown bare class decorator is FATAL');
        static::__assert_contains('Decorator: @probe_unknown', (string) $message);
    }

    public static function test_member_expression_class_decorator_is_fatal()
    {
        [$message] = static::__run(
            "@Probe_Decorators.probe_mark\n"
            . "class Probe_Subject {\n"
            . "}\n"
        );

        static::__assert_not_null($message, 'a member-expression class decorator is FATAL, as on a method');
        static::__assert_contains('Decorator: @Probe_Decorators.probe_mark', (string) $message);
    }

    // =====================================================================
    // Method decorators
    // =====================================================================

    public static function test_whitelisted_method_decorator_passes()
    {
        [$message] = static::__run(
            "class Probe_Subject {\n"
            . "    @probe_mark\n"
            . "    static run() {\n"
            . "    }\n"
            . "}\n"
        );

        static::__assert_null($message, 'a whitelisted method decorator passes');
    }

    public static function test_unknown_method_decorator_is_fatal()
    {
        [$message, $path] = static::__run(
            "class Probe_Subject {\n"
            . "    @probe_unknown\n"
            . "    static run() {\n"
            . "    }\n"
            . "}\n"
        );

        static::__assert_not_null($message, 'an unknown method decorator is FATAL');
        static::__assert_contains('FATAL: Decorator not whitelisted', (string) $message);
        static::__assert_contains("File: {$path}\nClass: Probe_Subject\nMethod: run\nDecorator: @probe_unknown\n", (string) $message);
    }
}
