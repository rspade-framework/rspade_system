<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelFetchTrashed_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MODEL-FETCH-TRASHED-01 (ModelFetchTrashed): withTrashed() inside a model's
 * fetch()/portal_fetch() body.
 *
 * The rule is driven directly with synthetic fixture files. It reads the file from DISK
 * (the checker hands it comment-stripped contents, and the exception marker is a comment),
 * so every fixture is written out before the rule runs. The CLASS NAME in the metadata must
 * be a real manifest model - that is what the lineage lookup resolves - while the fixture
 * source supplies the method bodies the rule parses.
 */
class Model_Fetch_Trashed_Rule_Test extends Rsx_Test_Abstract
{
    // Detection is AST analysis over temp fixture files.
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MODEL-FETCH-TRASHED-01';

    // A framework-core model, so the rule's lineage check puts the fixture in scope.
    private const MODEL_CLASS = 'Portal_Notification_Model';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    private static function __write_fixture(string $source): string
    {
        $dir = storage_path('rsx-tmp') . '/fetch_trashed_fixture_' . uniqid();
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
     * Run the rule against a fixture source and return its violations.
     */
    private static function __run_rule(array $lines, string $class_name = self::MODEL_CLASS): array
    {
        $source = implode("\n", $lines);
        $path = self::__write_fixture($source);

        $collector = new ViolationCollector();
        $rule = new ModelFetchTrashed_CodeQualityRule($collector);

        $rule->check($path, $source, ['class' => $class_name]);

        self::__remove_fixture($path);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    /**
     * The 1-based line number of the fixture line containing $needle.
     */
    private static function __line_of(array $lines, string $needle): int
    {
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }

        static::__fail("fixture has no line containing '{$needle}'");

        return 0;
    }

    /**
     * Wrap method bodies in a fixture class.
     */
    private static function __fixture(array $body_lines): array
    {
        return array_merge(
            [
                '<?php',
                '',
                'namespace Fixture_Namespace;',
                '',
                'class Fixture_Class extends Some_Parent',
                '{',
            ],
            $body_lines,
            ['}']
        );
    }

    // =====================================================================
    // Violations
    // =====================================================================

    public static function test_withtrashed_in_fetch_is_flagged()
    {
        $lines = self::__fixture([
            '    public static function fetch($id)',
            '    {',
            '        $record = static::withTrashed()->find($id);',
            '',
            '        return $record ? $record->toArray() : false;',
            '    }',
        ]);

        $violations = self::__run_rule($lines);

        static::__assert_count(1, $violations, 'a withTrashed() lookup in fetch() is flagged');

        $violation = $violations[0];
        static::__assert_equals(
            self::__line_of($lines, 'withTrashed'),
            $violation->line_number,
            'the violation points at the withTrashed() call line'
        );
        static::__assert_equals('high', $violation->severity, 'the rule reports at high severity');
        static::__assert_contains('withTrashed() inside fetch() is not allowed', $violation->message);
        static::__assert_contains('fetch_deleted', $violation->suggestion, 'the suggestion names the worked example');
    }

    public static function test_withtrashed_in_portal_fetch_is_flagged()
    {
        $lines = self::__fixture([
            '    public static function portal_fetch($id)',
            '    {',
            '        $record = static::withTrashed()->find($id);',
            '',
            '        return $record ? $record->toArray() : false;',
            '    }',
        ]);

        $violations = self::__run_rule($lines);

        static::__assert_count(1, $violations, 'the portal fetch surface is checked too');
        static::__assert_contains('withTrashed() inside portal_fetch() is not allowed', $violations[0]->message);
    }

    public static function test_withtrashed_inside_a_closure_in_the_body_is_flagged()
    {
        $lines = self::__fixture([
            '    public static function fetch($id)',
            '    {',
            '        $record = static::without_site_scope(function () use ($id) {',
            '            return static::withTrashed()->find($id);',
            '        });',
            '',
            '        return $record ? $record->toArray() : false;',
            '    }',
        ]);

        $violations = self::__run_rule($lines);

        static::__assert_count(1, $violations, 'the record leaves through the same return, however it was loaded');
    }

    // =====================================================================
    // Clean code
    // =====================================================================

    public static function test_plain_find_in_fetch_is_clean()
    {
        $lines = self::__fixture([
            '    public static function fetch($id)',
            '    {',
            '        $record = static::find($id);',
            '',
            '        return $record ? $record->toArray() : false;',
            '    }',
        ]);

        $violations = self::__run_rule($lines);

        static::__assert_count(0, $violations, 'a default-scoped lookup is what the contract asks for');
    }

    public static function test_withtrashed_outside_the_fetch_surfaces_is_not_flagged()
    {
        $lines = self::__fixture([
            '    public static function fetch($id)',
            '    {',
            '        $record = static::find($id);',
            '',
            '        return $record ? $record->toArray() : false;',
            '    }',
            '',
            '    public static function resolve_deleted_parent($id)',
            '    {',
            '        return static::withTrashed()->find($id);',
            '    }',
        ]);

        $violations = self::__run_rule($lines);

        static::__assert_count(0, $violations, 'server-side trashed lookups elsewhere on the model are legitimate');
    }

    public static function test_non_model_class_is_skipped()
    {
        $lines = self::__fixture([
            '    public static function fetch($id)',
            '    {',
            '        return static::withTrashed()->find($id);',
            '    }',
        ]);

        $violations = self::__run_rule($lines, 'Rsx_Test_Abstract');

        static::__assert_count(0, $violations, 'a class outside the model lineage has no ORM fetch surface');
    }

    // =====================================================================
    // Exception marker
    // =====================================================================

    public static function test_exception_annotation_on_the_previous_line_suppresses()
    {
        $lines = self::__fixture([
            '    public static function fetch($id)',
            '    {',
            '        // @MODEL-FETCH-TRASHED-01-EXCEPTION - fixture rationale',
            '        $record = static::withTrashed()->find($id);',
            '',
            '        return $record ? $record->toArray() : false;',
            '    }',
        ]);

        $violations = self::__run_rule($lines);

        static::__assert_count(0, $violations, 'the marker on the line above suppresses the call');
    }

    public static function test_exception_annotation_on_the_same_line_suppresses()
    {
        $lines = self::__fixture([
            '    public static function fetch($id)',
            '    {',
            '        $record = static::withTrashed()->find($id); // @MODEL-FETCH-TRASHED-01-EXCEPTION - fixture rationale',
            '',
            '        return $record ? $record->toArray() : false;',
            '    }',
        ]);

        $violations = self::__run_rule($lines);

        static::__assert_count(0, $violations, 'the marker on the call line suppresses the call');
    }
}
