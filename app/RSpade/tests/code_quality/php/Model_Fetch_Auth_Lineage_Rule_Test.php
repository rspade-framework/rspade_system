<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\ModelFetchAuthCheck_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Lineage handling in PHP-MODEL-FETCH-01 (ModelFetchAuthCheck).
 *
 * The rule used to compare a class's IMMEDIATE parent by exact string, so any
 * intermediate abstract made it skip the class silently - every site-scoped model
 * (Rsx_Site_Model_Abstract) escaped it entirely. It now walks the lineage via
 * Manifest::php_is_subclass_of().
 *
 * WHAT THE RULE STILL CHECKS. Authorization is declared, not detected: a fetch
 * surface's gate is an #[Auth(...)] attribute and the MANIFEST BUILD fails when one
 * is missing (Auth_ManifestSupport's validation pass, covered by Auth_Validation_Test).
 * The auth-pattern body scan and the @auth-exempt docblock this rule used to honor
 * are retired. What survives is structural: a model-borne #[Ajax_Endpoint], which is
 * unreachable dead security metadata.
 *
 * The rule is driven directly with synthetic fixture files plus hand-built metadata.
 * The CLASS NAME in the metadata must be a real manifest class (that is what the
 * lineage lookup resolves), while the fixture supplies the members the rule reads.
 */
class Model_Fetch_Auth_Lineage_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is manifest lineage + metadata analysis over temp fixture files.
    protected static $use_database_transactions = false;

    private const MODEL_RULE_ID = 'PHP-MODEL-FETCH-01';

    // A framework-core model whose IMMEDIATE parent is Rsx_Site_Model_Abstract,
    // i.e. it reaches Rsx_Model_Abstract only through an intermediate abstract.
    private const SITE_MODEL_CLASS = 'Portal_Notification_Model';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    private static function __write_fixture(string $source): string
    {
        $dir = storage_path('rsx-tmp') . '/auth_lineage_fixture_' . uniqid();
        ensure_directory($dir);

        $path = $dir . '/fixture.php';
        file_put_contents($path, $source);

        return $path;
    }

    private static function __remove_fixture(string $path): void
    {
        @unlink($path);
        @rmdir(dirname($path));
    }

    /**
     * Build a fixture class source containing one method body, and return
     * [source, line_number_of_the_method].
     *
     * @return array{0:string,1:int}
     */
    private static function __fixture_source(string $method_name, array $body_lines): array
    {
        $lines = [
            '<?php',
            '',
            'namespace Fixture_Namespace;',
            '',
            'class Fixture_Class extends Some_Parent',
            '{',
            '    public static function ' . $method_name . '($id)',
            '    {',
        ];

        $method_line = count($lines) - 1;

        foreach ($body_lines as $body_line) {
            $lines[] = '        ' . $body_line;
        }

        $lines[] = '    }';
        $lines[] = '}';

        return [implode("\n", $lines), $method_line];
    }

    /**
     * Run ModelFetchAuthCheck against a fixture and return its violations.
     */
    private static function __run_model_rule(string $class_name, string $extends, array $methods, string $source): array
    {
        $path = self::__write_fixture($source);

        $collector = new ViolationCollector();
        $rule = new ModelFetchAuthCheck_CodeQualityRule($collector);

        $rule->check($path, $source, [
            'class' => $class_name,
            'extends' => $extends,
            'public_static_methods' => $methods,
        ]);

        self::__remove_fixture($path);

        return $collector->get_by_rule(self::MODEL_RULE_ID);
    }

    private static function __fetch_methods(int $line): array
    {
        return [
            'fetch' => [
                'line' => $line,
                'attributes' => ['Ajax_Endpoint_Model_Fetch' => []],
            ],
        ];
    }

    private static function __endpoint_methods(int $line): array
    {
        return [
            'do_something' => [
                'line' => $line,
                'attributes' => ['Ajax_Endpoint' => []],
            ],
        ];
    }

    // =====================================================================
    // The lineage premise this fix rests on
    // =====================================================================

    public static function test_site_model_reaches_model_abstract_only_indirectly()
    {
        $lineage = Manifest::php_get_lineage(self::SITE_MODEL_CLASS);

        static::__assert_equals(
            'Rsx_Site_Model_Abstract',
            $lineage[0] ?? null,
            'the site-scoped fixture class has an intermediate abstract as its immediate parent'
        );

        static::__assert_true(
            Manifest::php_is_subclass_of(self::SITE_MODEL_CLASS, 'Rsx_Model_Abstract'),
            'the lineage walk still resolves it as a model'
        );
    }

    // =====================================================================
    // A model-borne #[Ajax_Endpoint] is dead metadata - and the lineage
    // walk is what brings a site-scoped model into scope at all
    // =====================================================================

    public static function test_model_borne_ajax_endpoint_is_flagged()
    {
        [$source, $line] = self::__fixture_source('do_something', [
            'return static::find($id);',
        ]);

        $violations = self::__run_model_rule(
            self::SITE_MODEL_CLASS,
            'Rsx_Site_Model_Abstract',
            self::__endpoint_methods($line),
            $source
        );

        static::__assert_count(1, $violations, 'an #[Ajax_Endpoint] declared on a site-scoped model is flagged');

        $violation = array_values($violations)[0];
        static::__assert_equals('medium', $violation->severity, 'the dead-metadata violation is medium severity');
        static::__assert_contains('no effect on a model class', $violation->message, 'the message states the attribute does nothing');
    }

    public static function test_model_fetch_attribute_is_not_mistaken_for_an_endpoint()
    {
        [$source, $line] = self::__fixture_source('fetch', [
            'return static::find($id) ?: false;',
        ]);

        $violations = self::__run_model_rule(
            self::SITE_MODEL_CLASS,
            'Rsx_Site_Model_Abstract',
            self::__fetch_methods($line),
            $source
        );

        static::__assert_count(0, $violations, 'the ORM fetch attribute is not treated as a model-borne endpoint');
    }

    public static function test_non_model_class_is_skipped()
    {
        [$source, $line] = self::__fixture_source('do_something', [
            'return static::find($id);',
        ]);

        $violations = self::__run_model_rule(
            'Rsx_Test_Abstract',
            'Rsx_Base_Abstract',
            self::__endpoint_methods($line),
            $source
        );

        static::__assert_count(0, $violations, 'a class outside the model lineage is not checked');
    }

    // =====================================================================
    // The retired half: a gateless fetch() is no longer this rule's problem
    // =====================================================================

    /**
     * The auth heuristic is gone: an unguarded fetch() body raises NOTHING here.
     * Its enforcement moved to the manifest build, where the missing #[Auth] gate
     * fails by file and member (Auth_Validation_Test covers that pass).
     */
    public static function test_unguarded_fetch_body_is_no_longer_flagged_by_this_rule()
    {
        [$source, $line] = self::__fixture_source('fetch', [
            '$record = static::find($id);',
            'return $record ?: false;',
        ]);

        $violations = self::__run_model_rule(
            self::SITE_MODEL_CLASS,
            'Rsx_Site_Model_Abstract',
            self::__fetch_methods($line),
            $source
        );

        static::__assert_count(0, $violations, 'gate presence is a manifest-build fatal, not a lint heuristic');
    }
}
