<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Database\MigrationModelReference_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for MIGRATION-MODEL-01 (MigrationModelReference_CodeQualityRule).
 *
 * A migration is a forward-only historical record that must replay from scratch forever;
 * a model class is current code that gets renamed and deleted. The rule keeps the two
 * apart - and the property that makes it usable at all is that a class name written as a
 * STRING (which is what the resilient _type_refs pattern writes) is never a violation.
 *
 * Fixtures are written to temp files and the rule is driven through
 * check_migration_file(), the same per-file seam check_migrations() drives for every
 * file the migration runner would run.
 */
class Migration_Model_Reference_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is pure token parsing over temp fixture files - no database access.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MIGRATION-MODEL-01';

    /**
     * Write a fixture migration, run the rule over it, return the violations.
     *
     * @return array<int,\App\RSpade\CodeQuality\CodeQuality_Violation>
     */
    private static function __run(string $source): array
    {
        $dir = storage_path('rsx-tmp') . '/migration_model_fixture_' . uniqid();
        $path = $dir . '/2026_01_01_000000_probe_migration.php';
        ensure_directory($dir);
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new MigrationModelReference_CodeQualityRule($collector);
        $rule->check_migration_file($path);

        $violations = $collector->get_by_rule(self::RULE_ID);

        @unlink($path);
        @rmdir($dir);

        return array_values($violations);
    }

    /** Wrap fixture statements in the shape a real migration has. */
    private static function __migration(string $body, string $header = '', string $docblock = ''): string
    {
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n"
            . "use Illuminate\\Support\\Facades\\DB;\n"
            . $header
            . "\n"
            . $docblock
            . "return new class extends Migration\n{\n    public function up()\n    {\n"
            . $body
            . "    }\n};\n";
    }

    // =====================================================================
    // What must be flagged
    // =====================================================================

    public static function test_a_model_import_is_flagged()
    {
        // The import alone is the coupling: the file no longer parses into a runnable
        // migration once that class is deleted.
        $source = static::__migration(
            "        DB::statement(\"ALTER TABLE clients ADD COLUMN nickname VARCHAR(64) NULL\");\n",
            "use Rsx\\Models\\Client_Model;\n"
        );

        $violations = static::__run($source);

        static::__assert_count(1, $violations, 'a use import of a model class is flagged');
        static::__assert_true(
            str_contains($violations[0]->message, 'Client_Model'),
            'the violation names the imported class'
        );
    }

    public static function test_a_type_ref_registry_call_is_flagged()
    {
        // The archetype from the field: class_to_id() validates against the LIVE manifest,
        // so a retired model turns every from-scratch replay into a hard failure.
        $source = static::__migration(
            "        \$id = Type_Ref_Registry::class_to_id('Event_Model');\n"
            . "        DB::statement(\"UPDATE activities SET eventable_type = {\$id}\");\n",
            "use App\\RSpade\\Core\\Database\\TypeRefs\\Type_Ref_Registry;\n"
        );

        $violations = static::__run($source);

        // The import and the static call are each their own coupling.
        static::__assert_count(2, $violations, 'the registry import and its call are both flagged');
    }

    public static function test_a_static_model_call_is_flagged()
    {
        $source = static::__migration("        \$client = Foo_Model::find(1);\n");

        $violations = static::__run($source);

        static::__assert_count(1, $violations, 'a static call on a model class symbol is flagged');
        static::__assert_true(
            str_contains($violations[0]->message, 'Foo_Model'),
            'the violation names the model'
        );
    }

    public static function test_new_and_instanceof_and_class_constant_are_flagged()
    {
        $cases = [
            'new'        => "        \$row = new Foo_Model();\n",
            'instanceof' => "        \$ok = \$thing instanceof Foo_Model;\n",
            '::class'    => "        \$name = Foo_Model::class;\n",
            'fqcn'       => "        \$row = \\Rsx\\Models\\Foo_Model::find(1);\n",
        ];

        foreach ($cases as $label => $body) {
            $violations = static::__run(static::__migration($body));
            static::__assert_count(1, $violations, "the {$label} form is flagged");
        }
    }

    public static function test_every_occurrence_reports_on_its_own_line()
    {
        $source = static::__migration(
            "        \$a = Foo_Model::find(1);\n"
            . "        \$b = Bar_Model::find(2);\n"
        );

        $violations = static::__run($source);

        static::__assert_count(2, $violations, 'both references report');
        static::__assert_equals(
            $violations[0]->line_number + 1,
            $violations[1]->line_number,
            'the two violations land on consecutive lines'
        );
    }

    // =====================================================================
    // What must NOT be flagged - the resilient pattern itself
    // =====================================================================

    public static function test_the_type_refs_closure_and_string_literals_are_clean()
    {
        // This is the pattern the rule's own remediation prescribes. If it flagged, the
        // rule would be unusable: there would be nothing left to convert TO.
        $body = "        \$type_ref_id = function (string \$class_name, string \$table_name): int {\n"
            . "            \$existing = DB::select(\"SELECT id FROM _type_refs WHERE class_name = ?\", [\$class_name]);\n"
            . "            if (!empty(\$existing)) {\n"
            . "                return (int) \$existing[0]->id;\n"
            . "            }\n"
            . "            DB::statement(\n"
            . "                \"INSERT INTO _type_refs (class_name, table_name, created_at, updated_at) VALUES (?, ?, NOW(3), NOW(3))\",\n"
            . "                [\$class_name, \$table_name]\n"
            . "            );\n"
            . "            return (int) DB::getPdo()->lastInsertId();\n"
            . "        };\n"
            . "        \$id = \$type_ref_id('Foo_Model', 'foos');\n"
            . "        DB::statement(\"UPDATE things SET owner_type = {\$id} WHERE owner_type = 'Foo_Model'\");\n";

        $violations = static::__run(static::__migration($body));

        static::__assert_empty(
            $violations,
            'a class name as a STRING is data, not a symbol - the whole point of the pattern'
        );
    }

    public static function test_a_commented_out_reference_is_not_a_reference()
    {
        // Migrations routinely explain what they are converting AWAY from. Documenting the
        // old call must never be the same thing as making it.
        $body = "        // \$id = Type_Ref_Registry::class_to_id('Foo_Model');\n"
            . "        /* Foo_Model::find(1) was the original shape - see the docblock. */\n"
            . "        DB::statement(\"UPDATE things SET owner_type = 1\");\n";

        $violations = static::__run(static::__migration($body));

        static::__assert_empty($violations, 'only real code is a violation, never text about it');
    }

    public static function test_an_object_property_of_the_same_name_is_not_a_class()
    {
        $body = "        \$name = \$row->Foo_Model;\n";

        $violations = static::__run(static::__migration($body));

        static::__assert_empty($violations, 'a property access is not a class symbol');
    }

    // =====================================================================
    // The exception marker
    // =====================================================================

    public static function test_the_exception_marker_with_a_rationale_suppresses()
    {
        // The sanctioned case: data seeding that needs model behaviour raw SQL cannot
        // reproduce (the template app's sample-document import is the real instance).
        $docblock = "/**\n"
            . " * @MIGRATION-MODEL-01-EXCEPTION - seed data that must go through the attachment\n"
            . " * pipeline; raw SQL cannot reproduce the dedup and extraction behaviour.\n"
            . " */\n";

        $source = static::__migration(
            "        \$row = Foo_Model::create(['name' => 'x']);\n",
            "use Rsx\\Models\\Foo_Model;\n",
            $docblock
        );

        $violations = static::__run($source);

        static::__assert_empty($violations, 'a rationale\'d marker suppresses the whole file');
    }

    public static function test_the_exception_marker_without_a_rationale_is_itself_a_violation()
    {
        $docblock = "/**\n * @MIGRATION-MODEL-01-EXCEPTION\n */\n";

        $source = static::__migration(
            "        \$row = Foo_Model::create(['name' => 'x']);\n",
            "use Rsx\\Models\\Foo_Model;\n",
            $docblock
        );

        $violations = static::__run($source);

        static::__assert_count(1, $violations, 'a bare marker is an unexplained hole in the rule');
        static::__assert_true(
            str_contains($violations[0]->message, 'no rationale'),
            'the violation says what is missing'
        );
    }

    // =====================================================================
    // The tree itself
    // =====================================================================

    public static function test_no_migration_in_the_tree_violates_the_rule()
    {
        // check_migrations() is the real entry point and reads the same file list the
        // migration RUNNER uses, so this covers the enumeration as well as the tree.
        $collector = new ViolationCollector();
        $rule = new MigrationModelReference_CodeQualityRule($collector);
        $rule->check_migrations();

        static::__assert_empty(
            $collector->get_by_rule(self::RULE_ID),
            'every migration in both directories replays without application code'
        );
    }
}
