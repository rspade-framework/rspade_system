<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Support\RuleDiscovery;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\CodeQuality\Php\App_Rule_Discovery_Fixture_CodeQualityRule;

/**
 * AN APPLICATION ADDS ITS OWN rsx:check RULES, FOUND BY CLASS.
 *
 * Framework rules are walked on disk under CodeQuality/Rules/. Every OTHER concrete class the
 * manifest index says extends CodeQualityRule_Abstract is an application rule - in a real
 * tree a class under rsx/. Under rsx:test the framework test tree is indexed too, so
 * App_Rule_Discovery_Fixture_CodeQualityRule (beside this file) is discovered through the
 * real manifest path exactly as an rsx/ class is; nothing has to be written into rsx/.
 *
 * The refusal of an id without the APP- prefix is pinned through app_rule_id_problem() on a
 * temp-loaded class: a live fixture with a bad id would make discovery throw for every test
 * in the run, and the thrown form poisons the manifest by design.
 */
class App_Rule_Discovery_Test extends Rsx_Test_Abstract
{
    // Manifest and filesystem reads; no database.
    protected static $use_database_transactions = false;

    private const FIXTURE_FQCN = App_Rule_Discovery_Fixture_CodeQualityRule::class;

    private const FIXTURE_ID = 'APP-DISCOVERY-FIXTURE-01';

    private static function __fixture_path(): string
    {
        return base_path('app/RSpade/tests/code_quality/php/App_Rule_Discovery_Fixture_CodeQualityRule.php');
    }

    /**
     * A rule class outside the framework's Rules/ directory is discovered through the
     * manifest, joins the rule set, and is instantiated by the driver's discovery.
     */
    public static function test_a_rule_class_outside_the_framework_rules_directory_is_discovered()
    {
        $app_rules = RuleDiscovery::app_rule_files();

        static::__assert_true(
            isset($app_rules[self::FIXTURE_FQCN]),
            'a concrete CodeQualityRule_Abstract subclass outside Rules/ is an application rule'
        );

        static::__assert_equals(
            static::__fixture_path(),
            $app_rules[self::FIXTURE_FQCN],
            'discovery reports the absolute file the rule lives in'
        );

        static::__assert_false(
            isset(RuleDiscovery::framework_rule_files()[self::FIXTURE_FQCN]),
            'the disk walk of Rules/ does not claim it'
        );

        static::__assert_true(
            isset(RuleDiscovery::rule_files()[self::FIXTURE_FQCN]),
            'the merged rule set carries it'
        );

        static::__assert_greater_than(
            50,
            count(RuleDiscovery::framework_rule_files()),
            'the framework rules are still discovered beside it'
        );

        $ids = [];

        foreach (RuleDiscovery::discover_rules(new ViolationCollector()) as $rule) {
            $ids[] = $rule->get_id();
        }

        static::__assert_true(
            in_array(self::FIXTURE_ID, $ids, true),
            'discover_rules() instantiates the application rule alongside the framework rules'
        );

        static::__assert_equals(
            [static::__fixture_path()],
            RuleDiscovery::files_for(self::FIXTURE_FQCN),
            'the driver fingerprints it by its own file, so editing it retires its verdicts'
        );

        static::__assert_true(
            RuleDiscovery::is_app_rule_source(static::__fixture_path()),
            'its file is recognised as an application rule source'
        );
    }

    /**
     * The selection excludes the framework's own rules (the disk walk owns them) and a class
     * override of a framework rule (it runs as the framework rule it replaces).
     */
    public static function test_selection_excludes_framework_rules_and_class_overrides()
    {
        $records = [
            'NoAnimations_CodeQualityRule' => [
                'file' => 'app/RSpade/CodeQuality/Rules/Scss/NoAnimations_CodeQualityRule.php',
                'fqcn' => 'App\\RSpade\\CodeQuality\\Rules\\Scss\\NoAnimations_CodeQualityRule',
            ],
            'Overriding_CodeQualityRule' => [
                'file' => 'rsx/lib/code_quality/overriding_codequalityrule.php',
                'fqcn' => 'Rsx\\Lib\\CodeQuality\\Overriding_CodeQualityRule',
            ],
            'App_Rule_Discovery_Fixture_CodeQualityRule' => [
                'file' => 'rsx/lib/code_quality/app_rule_discovery_fixture_codequalityrule.php',
                'fqcn' => self::FIXTURE_FQCN,
            ],
        ];

        $overrides = [
            'app/RSpade/CodeQuality/Rules/Scss/Overriding_CodeQualityRule.php' => [
                'by' => 'rsx/lib/code_quality/overriding_codequalityrule.php',
            ],
        ];

        $selected = RuleDiscovery::select_app_rule_files($records, $overrides);

        static::__assert_equals(
            [self::FIXTURE_FQCN => base_path('rsx/lib/code_quality/app_rule_discovery_fixture_codequalityrule.php')],
            $selected,
            'only the class that is neither a framework rule nor an override is an application rule'
        );
    }

    /**
     * An application rule's id must start with APP-, so it can never collide with or shadow a
     * framework rule id; the refusal names the class, the prefix and the file.
     */
    public static function test_an_app_rule_id_without_the_prefix_is_refused()
    {
        $class_name = 'App_Rule_Discovery_Unprefixed_Fixture_CodeQualityRule';
        $path = Rsx_Project_Paths::tmp_path('app_rule_discovery_' . $class_name . '.php');

        file_put_contents($path, "<?php\n"
            . "class {$class_name} extends \\App\\RSpade\\CodeQuality\\Rules\\CodeQualityRule_Abstract\n"
            . "{\n"
            . "    public function get_id(): string { return 'RETIRED-VOCAB-01'; }\n"
            . "    public function get_name(): string { return 'Unprefixed'; }\n"
            . "    public function get_description(): string { return 'Unprefixed'; }\n"
            . "    public function get_file_patterns(): array { return []; }\n"
            . "    public function check(string \$file_path, string \$contents, array \$metadata = []): void {}\n"
            . "}\n");

        try {
            if (!class_exists($class_name, false)) {
                require $path;
            }

            $problem = RuleDiscovery::app_rule_id_problem(new $class_name(new ViolationCollector()), $path);

            static::__assert_not_null($problem, 'an application rule id without the APP- prefix is refused');
            static::__assert_contains($class_name, $problem, 'the refusal names the class');
            static::__assert_contains("'RETIRED-VOCAB-01'", $problem, 'and the id it declared');
            static::__assert_contains("'APP-'", $problem, 'and the prefix it must carry');
            static::__assert_contains($path, $problem, 'and the file');
        } finally {
            @unlink($path);
        }

        $fixture = self::FIXTURE_FQCN;

        static::__assert_null(
            RuleDiscovery::app_rule_id_problem(new $fixture(new ViolationCollector()), static::__fixture_path()),
            'an APP- id is accepted'
        );
    }

    /**
     * rsx:check skips the CodeQuality infrastructure but checks the rules themselves; the
     * test is against the discovered rule set rather than a directory literal.
     */
    public static function test_rule_sources_are_the_discovered_set_not_a_directory()
    {
        static::__assert_true(
            RuleDiscovery::is_rule_source(base_path('app/RSpade/CodeQuality/Rules/Scss/NoAnimations_CodeQualityRule.php')),
            'a framework rule is a rule source'
        );

        static::__assert_true(
            RuleDiscovery::is_rule_source(base_path('app/RSpade/CodeQuality/Rules/CodeQualityRule_Abstract.php')),
            'the contract every rule extends is checked with them'
        );

        static::__assert_true(
            RuleDiscovery::is_rule_source(static::__fixture_path()),
            'an application rule is a rule source, wherever it is filed'
        );

        static::__assert_false(
            RuleDiscovery::is_rule_source(base_path('app/RSpade/CodeQuality/Support/RuleDiscovery.php')),
            'CodeQuality infrastructure is not'
        );

        static::__assert_false(
            RuleDiscovery::is_app_rule_source(base_path('app/RSpade/CodeQuality/Rules/Scss/NoAnimations_CodeQualityRule.php')),
            'a framework rule is not an application rule'
        );
    }
}
