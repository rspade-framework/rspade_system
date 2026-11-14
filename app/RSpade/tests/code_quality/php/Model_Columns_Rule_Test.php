<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelColumnsRead_CodeQualityRule;
use App\RSpade\CodeQuality\Rules\Models\ModelColumns_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MODEL-COLUMNS-01: a model's `$columns` declaration follows its grammar, and a replacing
 * entry never drops an inherited protection. MODEL-COLUMNS-02: `::$columns` is never read
 * directly outside Rsx_Model_Abstract.
 *
 * MODEL-COLUMNS-01 reflects real classes, so each fixture model is written to a temporary
 * file and loaded - outside the manifest, so a fixture can never fail a build - and handed
 * to the rule's evaluate_class() seam. Every fixture class name is unique to its case, and
 * a class already loaded in this process is reused rather than redeclared.
 *
 * MODEL-COLUMNS-02 is a per-file token rule, driven through check() over temp files.
 */
class Model_Columns_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MODEL-COLUMNS-01';

    private const READ_RULE_ID = 'MODEL-COLUMNS-02';

    private const BASE = '\\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract';

    /**
     * Load the fixture source (one or more class declarations) once, evaluate $class_name,
     * and return the MODEL-COLUMNS-01 violations.
     *
     * @return array<int, object>
     */
    private static function __evaluate(string $class_name, string $classes_source): array
    {
        $path = Rsx_Project_Paths::tmp_path('model_columns_rule_' . $class_name . '.php');
        file_put_contents($path, "<?php\n" . $classes_source . "\n");

        try {
            if (!class_exists($class_name, false)) {
                require $path;
            }

            $collector = new ViolationCollector();
            (new ModelColumns_CodeQualityRule($collector))->evaluate_class($class_name, $path);

            return array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            @unlink($path);
        }
    }

    /**
     * A single fixture model extending the base, declaring `$columns` as $columns_source.
     *
     * @return array<int, object>
     */
    private static function __evaluate_columns(string $class_name, string $columns_source): array
    {
        return static::__evaluate($class_name, "class {$class_name} extends " . self::BASE . "\n{\n"
            . "    protected \$table = 'fixture';\n"
            . "    public static \$columns = {$columns_source};\n}");
    }

    private static function __messages(array $violations): string
    {
        return implode("\n", array_map(fn ($v) => $v->message, $violations));
    }

    // =====================================================================
    // The grammar
    // =====================================================================

    public static function test_a_well_formed_declaration_passes()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Valid_Model', "[\n"
            . "        'status_id' => ['enum' => [1 => ['constant' => 'STATUS_OPEN', 'label' => 'Open', 'badge' => 'x'], 2 => ['label' => 'Closed']]],\n"
            . "        'body' => ['text_type' => 'Some_Text_Type', 'never_export' => true],\n"
            . "        'owner_type' => ['type_ref' => true, 'revision_exclude' => true],\n"
            . "        'notes' => [],\n"
            . "    ]");

        static::__assert_count(0, $violations, 'every kind, a flag combination and an explicitly plain column: ' . static::__messages($violations));
    }

    public static function test_the_base_declaration_passes()
    {
        $collector = new ViolationCollector();
        $rule = new ModelColumns_CodeQualityRule($collector);
        $rule->evaluate_class(Rsx_Model_Abstract::class, (new \ReflectionClass(Rsx_Model_Abstract::class))->getFileName());

        static::__assert_count(0, $collector->get_by_rule(self::RULE_ID), 'Rsx_Model_Abstract is judged like any declaring class, and is clean');
    }

    public static function test_a_class_that_declares_nothing_is_not_judged()
    {
        $violations = static::__evaluate('Columns_Rule_Fixture_Silent_Model', "class Columns_Rule_Fixture_Silent_Model extends " . self::BASE . "\n{\n"
            . "    protected \$table = 'fixture';\n}");

        static::__assert_count(0, $violations, 'the inherited declaration is judged where it is declared');
    }

    public static function test_an_unknown_key_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Unknown_Key_Model', "['status_id' => ['enmu' => [1 => ['label' => 'Open']]]]");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains("unknown key 'enmu'", $violations[0]->message, 'naming the key');
        static::__assert_contains("\$columns['status_id']", $violations[0]->message, 'and the column');
    }

    public static function test_the_allowed_keys_are_exactly_the_declared_kinds()
    {
        static::__assert_equals(
            ['enum', 'text_type', 'type_ref', 'money', 'never_export', 'revision_exclude', 'derived', 'from'],
            ModelColumns_CodeQualityRule::ALLOWED_KEYS,
            'the one list a new kind extends'
        );
    }

    public static function test_a_false_flag_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_False_Flag_Model', "['token' => ['never_export' => false]]");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains("['never_export'] is false", $violations[0]->message, 'a kind is removed by replacing the entry');
    }

    public static function test_a_truthy_flag_that_is_not_true_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Truthy_Flag_Model', "['owner_type' => ['type_ref' => 1]]");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains('literal true', $violations[0]->message);
    }

    public static function test_two_kinds_on_one_column_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Two_Kinds_Model', "['owner_type' => ['type_ref' => true, 'enum' => [1 => ['label' => 'A']]]]");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains('declares enum and type_ref', $violations[0]->message);
    }

    public static function test_an_entry_that_is_not_an_array_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Scalar_Entry_Model', "['token' => true]");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains("\$columns['token'] is bool", $violations[0]->message);
    }

    public static function test_a_key_that_is_not_a_column_name_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_List_Model', "[['never_export' => true]]");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains('every key is a column name', $violations[0]->message);
    }

    public static function test_a_declaration_that_is_not_an_array_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_String_Declaration_Model', "'status_id'");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains('not an array of column => entry', $violations[0]->message);
    }

    public static function test_malformed_enum_maps_are_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Bad_Enum_Model', "[\n"
            . "        'empty_id' => ['enum' => []],\n"
            . "        'string_id' => ['enum' => ['open' => ['label' => 'Open']]],\n"
            . "        'scalar_id' => ['enum' => [1 => 'Open']],\n"
            . "        'blank_id' => ['enum' => [1 => ['constant' => '', 'label' => 'Open']]],\n"
            . "    ]");

        $messages = static::__messages($violations);

        static::__assert_count(4, $violations, 'one finding per malformed map: ' . $messages);
        static::__assert_contains("\$columns['empty_id']['enum'] must be a non-empty map", $messages);
        static::__assert_contains("has the value 'open': enum values are integers", $messages);
        static::__assert_contains("\$columns['scalar_id']['enum'][1] is string", $messages);
        static::__assert_contains("['blank_id']['enum'][1]['constant'] must be a non-empty string", $messages);
    }

    public static function test_a_text_type_that_is_not_a_class_name_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Bad_Text_Type_Model', "['body' => ['text_type' => true]]");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains("['text_type'] is bool", $violations[0]->message);
    }

    // =====================================================================
    // A replacing entry never drops an inherited protection
    // =====================================================================

    private static function __protected_parent(string $parent): string
    {
        return "abstract class {$parent} extends " . self::BASE . "\n{\n"
            . "    protected \$table = 'fixture';\n"
            . "    public static \$columns = ['api_secret' => ['never_export' => true], 'status_id' => ['enum' => [1 => ['label' => 'A']]]];\n}\n";
    }

    public static function test_dropping_an_inherited_never_export_is_fatal()
    {
        $parent = 'Columns_Rule_Fixture_Drop_Parent_Model_Abstract';
        $child = 'Columns_Rule_Fixture_Drop_Child_Model';

        $violations = static::__evaluate($child, static::__protected_parent($parent)
            . "class {$child} extends {$parent}\n{\n    public static \$columns = ['api_secret' => ['revision_exclude' => true]];\n}");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains('replaces an inherited entry that is never_export, and drops it', $violations[0]->message);
    }

    public static function test_restating_an_inherited_never_export_passes()
    {
        $parent = 'Columns_Rule_Fixture_Keep_Parent_Model_Abstract';
        $child = 'Columns_Rule_Fixture_Keep_Child_Model';

        $violations = static::__evaluate($child, static::__protected_parent($parent)
            . "class {$child} extends {$parent}\n{\n"
            . "    public static \$columns = ['api_secret' => ['never_export' => true, 'revision_exclude' => true], 'status_id' => []];\n}");

        static::__assert_count(0, $violations, 'the protection is restated; un-kinding an enum is legal: ' . static::__messages($violations));
    }

    public static function test_dropping_an_audit_type_ref_is_fatal()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Audit_Drop_Model', "['created_by_type' => []]");

        static::__assert_count(1, $violations, 'one finding: ' . static::__messages($violations));
        static::__assert_contains('replaces the audit type column Rsx_Model_Abstract declares type_ref, and drops it', $violations[0]->message);
    }

    public static function test_restating_an_audit_type_ref_passes()
    {
        $violations = static::__evaluate_columns('Columns_Rule_Fixture_Audit_Keep_Model', "['deleted_by_type' => ['type_ref' => true, 'revision_exclude' => true]]");

        static::__assert_count(0, $violations, 'the audit entry keeps type_ref: ' . static::__messages($violations));
    }

    // =====================================================================
    // MODEL-COLUMNS-02 - no direct ::$columns read
    // =====================================================================

    /**
     * @return array<int, object>
     */
    private static function __read_violations(string $source, string $basename = 'columns_read_fixture.php'): array
    {
        $dir = Rsx_Project_Paths::tmp_path('model_columns_read_' . uniqid());
        ensure_directory($dir);
        $path = $dir . '/' . $basename;
        file_put_contents($path, $source);

        try {
            $collector = new ViolationCollector();
            (new ModelColumnsRead_CodeQualityRule($collector))->check($path, $source, []);

            return array_values($collector->get_by_rule(self::READ_RULE_ID));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public static function test_a_direct_read_is_flagged()
    {
        $violations = static::__read_violations("<?php\nclass Reader\n{\n    public function f(\$fqcn)\n    {\n"
            . "        \$a = Some_Model::\$columns;\n"
            . "        \$b = \$fqcn :: \$columns['status_id'];\n"
            . "        return [\$a, \$b];\n    }\n}\n");

        static::__assert_count(2, $violations, 'both spellings of the read');
        static::__assert_equals(6, $violations[0]->line_number, 'reported on the line of the read');
    }

    public static function test_accessors_strings_and_comments_are_not_reads()
    {
        $violations = static::__read_violations("<?php\nclass Reader\n{\n"
            . "    // Some_Model::\$columns in a comment\n"
            . "    public static \$columns = [];\n"
            . "    public function f()\n    {\n"
            . "        \$source = 'Some_Model::\$columns';\n"
            . "        return [Some_Model::columns(), \$this->columns, \$source];\n    }\n}\n");

        static::__assert_count(0, $violations, 'a declaration, an accessor call, a property and a mention are not reads');
    }

    public static function test_the_base_itself_may_read_the_property()
    {
        $violations = static::__read_violations("<?php\nclass Rsx_Model_Abstract\n{\n    public function f(\$c)\n    {\n"
            . "        return \$c::\$columns;\n    }\n}\n", 'Rsx_Model_Abstract.php');

        static::__assert_count(0, $violations, 'the accessors are implemented there');
    }
}
