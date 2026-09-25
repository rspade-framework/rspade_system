<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Meta\Code_Quality_Meta_Inheritance_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * META-INHERIT-01 and the direct-parent comparison.
 *
 * A code-quality rule that scopes itself with `$metadata['extends'] === 'Some_Base'` sees
 * DIRECT children only, so one intermediate abstract takes every class beneath it out of the
 * rule - the blind spot two security lints once had. The meta rule flags the comparison in
 * either operand order, and lets it stand only as a fast path that a *_is_subclass_of() call
 * follows within a few lines.
 *
 * The meta rule inspects files under a CodeQuality/Rules/ directory only, so each fixture is
 * written beneath one in the scratch tree.
 */
class Meta_Inherit_Extends_Comparison_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'META-INHERIT-01';

    private static function __run(array $body_lines): array
    {
        $dir = Rsx_Project_Paths::tmp_path('meta_inherit_fixture_' . uniqid());
        $rules_dir = $dir . '/CodeQuality/Rules';
        ensure_directory($rules_dir);

        $source = implode("\n", array_merge([
            '<?php',
            '',
            'class Fixture_CodeQualityRule',
            '{',
            '    public function check(string $file_path, string $contents, array $metadata = []): void',
            '    {',
        ], array_map(fn ($line) => '        ' . $line, $body_lines), [
            '    }',
            '}',
        ]));

        $path = $rules_dir . '/Fixture_CodeQualityRule.php';
        file_put_contents($path, $source);

        $collector = new ViolationCollector();
        $rule = new Code_Quality_Meta_Inheritance_CodeQualityRule($collector);
        $rule->check($path, $source, []);

        rmdir_recursive($dir);

        return array_values($collector->get_by_rule(self::RULE_ID));
    }

    public static function test_direct_parent_comparison_is_flagged()
    {
        $violations = static::__run([
            "if (!isset(\$metadata['extends']) || \$metadata['extends'] !== 'Some_Base') {",
            '    return;',
            '}',
        ]);

        static::__assert_count(1, $violations, 'comparing the immediate parent against a name is flagged');
        static::__assert_contains('DIRECT children only', $violations[0]->message, 'the message names the blind spot');
    }

    public static function test_reversed_operands_are_flagged()
    {
        $violations = static::__run([
            "if ('Some_Base' === \$metadata['extends']) {",
            '    return;',
            '}',
        ]);

        static::__assert_count(1, $violations, 'the literal-first spelling is the same comparison');
    }

    public static function test_fast_path_before_a_lineage_check_is_allowed()
    {
        $violations = static::__run([
            '$is_subclass = false;',
            "if (\$metadata['extends'] === 'Some_Base') {",
            '    $is_subclass = true;',
            '} else {',
            "    \$is_subclass = Manifest::js_is_subclass_of(\$metadata['class'], 'Some_Base');",
            '}',
        ]);

        static::__assert_count(0, $violations, 'a fast path followed by *_is_subclass_of() is not the blind spot');
    }

    public static function test_lineage_scope_is_clean()
    {
        $violations = static::__run([
            "if (!Manifest::php_is_subclass_of(\$metadata['class'], 'Some_Base')) {",
            '    return;',
            '}',
        ]);

        static::__assert_count(0, $violations, 'a lineage check raises nothing');
    }
}
