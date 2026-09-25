<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\PortalModelFetchAuthCheck_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Lineage handling in PORTAL-MODEL-FETCH-01 (PortalModelFetchAuthCheck).
 *
 * The rule used to compare a model's IMMEDIATE parent against Rsx_Model_Abstract, so every
 * site-scoped model (Rsx_Site_Model_Abstract) - every portal-fetchable model a normal
 * application writes - escaped it. It now scopes by lineage, and resolves portal_fetch()
 * and portal_can_read() through the lineage too: a shared intermediate base declaring
 * either one counts for every model beneath it.
 *
 * Driven two ways: with hand-built metadata over a fixture file (the class name must be a
 * real manifest class, since scope and the ancestor walk read the manifest; the metadata
 * supplies what the class itself declares), and with a real framework model's own
 * manifest record.
 */
class Portal_Model_Fetch_Lineage_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is manifest lineage + metadata analysis over temp fixture files.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'PORTAL-MODEL-FETCH-01';

    // A framework model reaching Rsx_Model_Abstract only through Rsx_Site_Model_Abstract,
    // whose abstract base declares portal_can_read() and takes portal_fetch() from the
    // Portal_Authorizable trait.
    private const SITE_MODEL_CLASS = 'Portal_Notification_Model';

    private const SITE_MODEL_BASE = 'Portal_Notification_Model_Abstract';

    private static function __run(string $source, array $metadata): array
    {
        $dir = Rsx_Project_Paths::tmp_path('portal_fetch_lineage_fixture_' . uniqid());
        ensure_directory($dir);
        $path = $dir . '/fixture_model.php';
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new PortalModelFetchAuthCheck_CodeQualityRule($collector);
        $rule->check($path, $source, $metadata);

        rmdir_recursive($dir);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    /**
     * A model file declaring its own portal_fetch() with the fetch attribute, and a body.
     */
    private static function __source(array $body_lines): string
    {
        return implode("\n", array_merge([
            '<?php',
            '',
            'namespace Fixture_Namespace;',
            '',
            'class Fixture_Model extends Some_Parent',
            '{',
            '    public static function portal_fetch($id)',
            '    {',
        ], array_map(fn ($line) => '        ' . $line, $body_lines), [
            '    }',
            '}',
        ]));
    }

    private static function __metadata(string $extends, array $extra = []): array
    {
        return array_merge([
            'class' => self::SITE_MODEL_CLASS,
            'extends' => $extends,
            'public_static_methods' => [
                'portal_fetch' => ['line' => 7, 'attributes' => ['Ajax_Endpoint_Model_Fetch' => []]],
            ],
            'public_instance_methods' => [],
        ], $extra);
    }

    public static function test_site_scoped_model_without_portal_can_read_is_flagged()
    {
        $violations = static::__run(
            static::__source(['return static::find($id) ?: false;']),
            static::__metadata('Rsx_Site_Model_Abstract')
        );

        static::__assert_count(1, $violations, 'a Rsx_Site_Model_Abstract child exposing portal_fetch() is in scope');
        static::__assert_contains('does not define portal_can_read()', $violations[0]->message, 'the missing record rule is named');
    }

    public static function test_portal_can_read_on_an_intermediate_base_counts()
    {
        $violations = static::__run(
            static::__source(['$row = static::find($id);', 'return ($row && $row->portal_can_read()) ? $row->toArray() : false;']),
            static::__metadata(self::SITE_MODEL_BASE)
        );

        static::__assert_count(0, $violations, 'portal_can_read() declared on the base satisfies the model beneath it');
    }

    public static function test_hand_rolled_portal_fetch_must_call_portal_can_read()
    {
        $violations = static::__run(
            static::__source(['return static::find($id) ?: false;']),
            static::__metadata(self::SITE_MODEL_BASE)
        );

        static::__assert_count(1, $violations, 'a body that never calls portal_can_read() is flagged');
        static::__assert_contains('does not call portal_can_read()', $violations[0]->message, 'the uncalled record rule is named');
    }

    public static function test_abstract_base_is_not_required_to_declare_portal_can_read()
    {
        $violations = static::__run(
            static::__source(['$row = static::find($id);', 'return ($row && $row->portal_can_read()) ? $row->toArray() : false;']),
            static::__metadata('Rsx_Site_Model_Abstract', ['abstract' => true])
        );

        static::__assert_count(0, $violations, 'an abstract base is never fetched itself; its concrete children are checked');
    }

    public static function test_real_framework_model_and_its_base_pass()
    {
        foreach ([self::SITE_MODEL_CLASS, self::SITE_MODEL_BASE] as $class_name) {
            $record = Manifest::php_class_metadata($class_name);
            $path = rsx_project_file_path($record['file']);

            $collector = new ViolationCollector();
            $rule = new PortalModelFetchAuthCheck_CodeQualityRule($collector);
            $rule->check($path, (string) file_get_contents($path), Manifest::get_file($record['file']));

            static::__assert_count(0, $collector->get_by_rule(self::RULE_ID),
                "{$class_name} resolves portal_fetch() and portal_can_read() through its lineage");
        }
    }
}
