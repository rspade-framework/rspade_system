<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Manifest\TestFixtureAuthCheck_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for TEST-AUTH-01 (TestFixtureAuthCheck_CodeQualityRule).
 *
 * The outage this rule exists to prevent: a fixture under tests/ carrying a real
 * `#[Auth('can_view_data', ...)]`, scanned into a downstream manifest, whose
 * closed-by-default validation cannot resolve a check only the reference application
 * declares - so the BUILD fails and the site is hard down.
 *
 * The negative case matters as much as the positive one. This concern's own fixtures
 * write PHP SOURCE containing `#[Auth('x')]` into strings, so a rule that matched text
 * rather than tokens would flag the tests that guard the fixer.
 */
class Test_Fixture_Auth_Check_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is text inspection over in-memory sources - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'TEST-AUTH-01';

    /**
     * Run the rule over $source as though it lived at $path, and return the violations.
     *
     * No file is written: the rule reads only what it is handed, which is itself part of
     * the contract (a rule never touches the filesystem).
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $path, string $source): array
    {
        $collector = new ViolationCollector();
        $rule = new TestFixtureAuthCheck_CodeQualityRule($collector);
        $rule->check($path, $source);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    // =====================================================================
    // The violation
    // =====================================================================

    /**
     * The exact shape of the outage: an application check named by a fixture attribute.
     */
    public static function test_application_check_in_a_fixture_attribute_is_a_violation()
    {
        $violations = self::__run(
            'app/RSpade/tests/auth_gates/php/Surface_Fixture.php',
            "<?php\n\nclass Surface_Fixture\n{\n    #[Ajax_Endpoint]\n"
                . "    #[Auth('can_view_data', 'is_logged_in')]\n"
                . "    public static function m() { return 1; }\n}\n"
        );

        static::__assert_count(1, $violations, 'only the application check is flagged');
        static::__assert_contains('can_view_data', $violations[0]->message);
        static::__assert_contains(
            "may only name checks the framework itself provides",
            $violations[0]->suggestion
        );
    }

    /**
     * A class-level attribute is the same declaration, and is reported on its own line.
     */
    public static function test_class_level_attribute_is_checked_and_located()
    {
        $violations = self::__run(
            'app/RSpade/tests/auth_gates/php/Surface_Fixture.php',
            "<?php\n\n#[Auth('can_admin_role')]\nclass Surface_Fixture\n{\n}\n"
        );

        static::__assert_count(1, $violations);
        static::__assert_equals(3, $violations[0]->line_number, 'reported on the attribute line');
    }

    /**
     * The JS decorator half: a `@route` action fixture is a scanned surface too.
     */
    public static function test_application_check_in_a_js_decorator_is_a_violation()
    {
        $violations = self::__run(
            'app/RSpade/tests/dispatch/asset/fixture_action.js',
            "@route('/fixture')\n@auth('can_export_data')\nclass Fixture_Action extends Spa_Action {}\n"
        );

        static::__assert_count(1, $violations);
        static::__assert_contains('can_export_data', $violations[0]->message);
    }

    /**
     * An application test tree is in scope on the same terms as the framework's.
     */
    public static function test_application_tests_directory_is_in_scope()
    {
        $violations = self::__run(
            '/var/www/html/rsx/tests/Fixture_Surface.php',
            "<?php\n\n#[Auth('can_view_data')]\nclass Fixture_Surface\n{\n}\n"
        );

        static::__assert_count(1, $violations);
    }

    // =====================================================================
    // The clean cases
    // =====================================================================

    /**
     * Every framework-provided check is accepted, including the two-name merge shape the
     * auth_gates concern's live fixture relies on.
     */
    public static function test_framework_checks_are_clean()
    {
        $violations = self::__run(
            'app/RSpade/tests/auth_gates/php/Surface_Fixture.php',
            "<?php\n\n#[Auth('is_logged_in')]\nclass Surface_Fixture\n{\n"
                . "    #[Auth('is_sysadmin', 'is_logged_in')]\n    public static function a() { return 1; }\n"
                . "    #[Auth('public')]\n    public static function b() { return 1; }\n"
                . "    #[Auth('closed')]\n    public static function c() { return 1; }\n}\n"
        );

        static::__assert_count(0, $violations, 'the framework vocabulary is always allowed');
    }

    /**
     * THE NEGATIVE THAT MATTERS: `#[Auth('x')]` inside a PHP STRING is fixture source a
     * validation test writes to a temp file, not a declaration. Tokenizing is what tells
     * the two apart - Php_Fixer_Import_Safety_Test is full of exactly this.
     */
    public static function test_attribute_inside_a_string_literal_is_not_a_declaration()
    {
        $violations = self::__run(
            'app/RSpade/tests/codegen/php/Fixer_Test.php',
            "<?php\n\nclass Fixer_Test\n{\n"
                . "    public static function t()\n    {\n"
                . "        \$source = \"<?php\\nclass M {\\n    #[Auth('x')]\\n    public function r() {}\\n}\\n\";\n"
                . "        return \$source;\n    }\n}\n"
        );

        static::__assert_count(0, $violations, 'a string is source under test, not a surface');
    }

    /**
     * A name written in a docblock is prose, not a declaration.
     */
    public static function test_attribute_inside_a_comment_is_not_a_declaration()
    {
        $violations = self::__run(
            'app/RSpade/tests/auth_gates/php/Auth_Index_Test.php',
            "<?php\n\nclass Auth_Index_Test\n{\n"
                . "    /**\n     * #[Auth('alpha','beta')] arrives as one instance with two arguments.\n     */\n"
                . "    public static function t() { return 1; }\n}\n"
        );

        static::__assert_count(0, $violations, 'a docblock names nothing');
    }

    /**
     * Scope is the test trees only. Application code names application checks - that is
     * what an application's Permission class is for.
     */
    public static function test_non_test_code_is_out_of_scope()
    {
        $violations = self::__run(
            'rsx/app/frontend/clients/frontend_clients_controller.php',
            "<?php\n\n#[Auth('can_view_data')]\nclass Frontend_Clients_Controller\n{\n}\n"
        );

        static::__assert_count(0, $violations, 'only fixtures are constrained');
    }

    /**
     * A second attribute group in the same declaration is not swept into Auth's argument
     * list - only Auth's own arguments are check names.
     */
    public static function test_other_attribute_arguments_are_not_check_names()
    {
        $violations = self::__run(
            'app/RSpade/tests/dispatch/php/Fixture_Controller.php',
            "<?php\n\nclass Fixture_Controller\n{\n"
                . "    #[Route('/fixture/path')]\n    #[Auth('public')]\n"
                . "    public static function index() { return 1; }\n}\n"
        );

        static::__assert_count(0, $violations, 'a route path is not a check name');
    }
}
