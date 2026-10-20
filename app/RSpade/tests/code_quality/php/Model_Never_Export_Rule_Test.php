<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelNeverExport_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MODEL-NEVER-EXPORT-01: a secret-shaped column must be in the model's effective never-export
 * list.
 *
 * The rule reads columns from the live schema in production; its evaluate_class() seam takes
 * the column list directly, so each fixture model is written to a temporary file, loaded
 * outside the manifest, and judged against a list the test states.
 */
class Model_Never_Export_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MODEL-NEVER-EXPORT-01';

    /**
     * Load a fixture model (extending $parent) and return the rule's violations for $columns.
     *
     * @param array<int,string> $columns
     */
    private static function __evaluate(string $class_name, string $body, array $columns, string $parent = '\\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract'): array
    {
        $path = Rsx_Project_Paths::tmp_path('never_export_rule_' . $class_name . '.php');

        file_put_contents($path, "<?php\n"
            . "class {$class_name} extends {$parent}\n"
            . "{\n    protected \$table = 'fixture';\n{$body}\n}\n");

        try {
            if (!class_exists($class_name, false)) {
                require $path;
            }

            $collector = new ViolationCollector();
            $rule = new ModelNeverExport_CodeQualityRule($collector);
            $rule->evaluate_class($class_name, $path, $columns);

            return array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            @unlink($path);
        }
    }

    public static function test_an_unlisted_secret_column_is_flagged()
    {
        $violations = static::__evaluate('Never_Export_Rule_Fixture_Unlisted_Model', '', ['id', 'name', 'api_token']);

        static::__assert_count(1, $violations, 'the one secret column is reported');
        static::__assert_contains("'api_token'", $violations[0]->message, 'naming the column');
        static::__assert_contains('$neverExport', $violations[0]->suggestion, 'and the declaration that fixes it');
    }

    public static function test_a_listed_secret_column_passes()
    {
        $violations = static::__evaluate(
            'Never_Export_Rule_Fixture_Listed_Model',
            "    public static \$neverExport = ['api_token'];",
            ['id', 'name', 'api_token']
        );

        static::__assert_count(0, $violations, 'a declared column is clean');
    }

    public static function test_a_column_the_parent_lists_passes_through_the_union()
    {
        // Login_User_Model_Abstract never-exports password and remember_token; the subclass
        // declares only its own column and is judged against the union.
        $violations = static::__evaluate(
            'Never_Export_Rule_Fixture_Override_Model',
            "    public static \$neverExport = ['backup_secret'];",
            ['id', 'email', 'password', 'remember_token', 'backup_secret'],
            '\\App\\RSpade\\Core\\Models\\Login_User_Model'
        );

        static::__assert_count(0, $violations, 'the inherited and the added columns are both covered');
    }

    public static function test_the_patterns_are_narrow()
    {
        $columns = [
            'password', 'old_password', 'token', 'reset_token', 'secret', 'client_secret',
            'is_secret', 'has_password', '_ext_token', 'token_hash', 'invite_code', 'api_key', 'passwords_changed_at',
        ];

        $violations = static::__evaluate('Never_Export_Rule_Fixture_Patterns_Model', '', $columns);

        $flagged = [];
        foreach ($violations as $violation) {
            preg_match("/column '([^']+)'/", $violation->message, $match);
            $flagged[] = $match[1];
        }
        sort($flagged);

        static::__assert_equals(
            ['client_secret', 'old_password', 'password', 'reset_token', 'secret', 'token'],
            $flagged,
            'exactly the password/token/secret names; never a flag, a system column, a hash, a code or a key'
        );
    }

    public static function test_a_marker_naming_the_column_with_a_reason_excuses_it()
    {
        $violations = static::__evaluate(
            'Never_Export_Rule_Fixture_Excused_Model',
            "    // @MODEL-NEVER-EXPORT-01-EXCEPTION display_token - a public rotating badge token",
            ['id', 'display_token', 'api_token']
        );

        static::__assert_count(1, $violations, 'only the column the marker names is excused');
        static::__assert_contains("'api_token'", $violations[0]->message, 'the other one is still reported');
    }

    public static function test_a_marker_without_a_reason_excuses_nothing()
    {
        $violations = static::__evaluate(
            'Never_Export_Rule_Fixture_Bare_Marker_Model',
            "    // @MODEL-NEVER-EXPORT-01-EXCEPTION display_token",
            ['id', 'display_token']
        );

        static::__assert_count(1, $violations, 'a bare marker is not a declaration');
    }
}
