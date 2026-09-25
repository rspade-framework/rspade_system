<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\PHP\RealtimeTopicAuthCheck_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Lineage handling in REALTIME-AUTH-01 (RealtimeTopicAuthCheck).
 *
 * Realtime::subscribe_token() accepts ANY Realtime_Topic_Abstract subclass, so the rule
 * reaches every one, however many intermediate bases stand between it and the abstract, and
 * judges each class on what it DECLARES: a declared can_subscribe() has its body checked
 * unless the nearest $requires_auth declaration is false; a declared $requires_auth = false
 * is flagged for review; a class declaring neither reports nothing (the verdict sits on the
 * ancestor that declared them).
 *
 * The fixtures are real classes in this directory (the test tree is indexed while the suite
 * runs), and the rule is driven with each fixture's own manifest record - exactly what the
 * rsx:check driver hands it.
 */
class Realtime_Topic_Lineage_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is manifest lineage + source reads over the fixture files.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'REALTIME-AUTH-01';

    private static function __run_rule(string $class_name): array
    {
        $record = Manifest::php_class_metadata($class_name);
        static::__assert_true($record !== null, "fixture {$class_name} is indexed while the suite runs");

        $metadata = Manifest::get_file($record['file']);
        $path = rsx_project_file_path($record['file']);

        $collector = new ViolationCollector();
        $rule = new RealtimeTopicAuthCheck_CodeQualityRule($collector);
        $rule->check($path, (string) file_get_contents($path), $metadata);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    public static function test_intermediate_base_does_not_hide_its_subclasses()
    {
        static::__assert_true(
            Manifest::php_is_subclass_of('Realtime_Lineage_Fixture_Override_Topic', 'Realtime_Topic_Abstract'),
            'the three-level fixture chain reaches Realtime_Topic_Abstract'
        );
    }

    public static function test_checked_base_passes()
    {
        static::__assert_count(0, static::__run_rule('Realtime_Lineage_Fixture_Checked_Topic_Abstract'),
            'an intermediate base with a real auth check is clean');
    }

    public static function test_leaf_inheriting_a_checked_can_subscribe_reports_nothing()
    {
        static::__assert_count(0, static::__run_rule('Realtime_Lineage_Fixture_Inheriting_Topic'),
            'a topic declaring nothing inherits its base\'s verdict');
    }

    public static function test_leaf_overriding_with_an_open_body_is_flagged()
    {
        $violations = static::__run_rule('Realtime_Lineage_Fixture_Override_Topic');

        static::__assert_count(1, $violations, 'an override below a checked base is checked on its own body');
        static::__assert_equals('high', $violations[0]->severity, 'a missing auth check is high severity');
        static::__assert_contains('no recognizable auth check', $violations[0]->message, 'the message names the missing check');
    }

    public static function test_public_intermediate_is_flagged_for_review()
    {
        $violations = static::__run_rule('Realtime_Lineage_Fixture_Public_Topic_Abstract');

        static::__assert_count(1, $violations, 'the base that declares $requires_auth = false is flagged');
        static::__assert_equals('medium', $violations[0]->severity, 'the public-topic review is medium severity');
        static::__assert_contains('PUBLIC', $violations[0]->message, 'the message names the public declaration');
    }

    public static function test_leaf_under_a_public_base_is_not_missing_auth()
    {
        static::__assert_count(0, static::__run_rule('Realtime_Lineage_Fixture_Public_Leaf_Topic'),
            'an inherited $requires_auth = false makes the leaf public, not unchecked');
    }
}
