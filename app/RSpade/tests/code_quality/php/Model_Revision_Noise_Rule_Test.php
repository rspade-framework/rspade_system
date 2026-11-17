<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelColumns_CodeQualityRule;
use App\RSpade\CodeQuality\Rules\Models\ModelRevisionNoise_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MODEL-REVISION-NOISE-01: on a model with $revisions, a bookkeeping-shaped column is
 * revision_exclude, derived, or carries revision_include with a reason.
 *
 * The rule reads columns from the live schema in production; its evaluate_class() seam takes
 * the column list directly, so each fixture model is written to a temporary file, loaded
 * outside the manifest, and judged against a list the test states. The revision_include
 * grammar (a non-empty reason, never beside revision_exclude or derived) is MODEL-COLUMNS-01's
 * and is driven through that rule's seam here too.
 */
class Model_Revision_Noise_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MODEL-REVISION-NOISE-01';

    /**
     * Write and load a fixture model; returns its path. The caller unlinks it.
     */
    private static function __load(string $class_name, string $body): string
    {
        $path = Rsx_Project_Paths::tmp_path('revision_noise_rule_' . $class_name . '.php');

        file_put_contents($path, "<?php\n"
            . "class {$class_name} extends \\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract\n"
            . "{\n    protected \$table = 'fixture';\n{$body}\n}\n");

        if (!class_exists($class_name, false)) {
            require $path;
        }

        return $path;
    }

    /**
     * The columns MODEL-REVISION-NOISE-01 reports for a fixture model judged against $columns.
     *
     * @param array<int,string> $columns
     * @return array<int,string>
     */
    private static function __flagged(string $class_name, string $body, array $columns): array
    {
        $path = static::__load($class_name, $body);

        try {
            $collector = new ViolationCollector();
            $rule = new ModelRevisionNoise_CodeQualityRule($collector);
            $rule->evaluate_class($class_name, $path, $columns);

            $flagged = [];
            foreach ($collector->get_by_rule(self::RULE_ID) as $violation) {
                preg_match("/column '([^']+)'/", $violation->message, $match);
                $flagged[] = $match[1];
            }
            sort($flagged);

            return $flagged;
        } finally {
            @unlink($path);
        }
    }

    public static function test_undeclared_bookkeeping_columns_are_flagged()
    {
        $flagged = static::__flagged(
            'Revision_Noise_Rule_Fixture_Undeclared_Model',
            '    public static $revisions = true;',
            ['id', 'name', 'last_emailed_at', 'sort_order']
        );

        static::__assert_equals(['last_emailed_at', 'sort_order'], $flagged, 'both bookkeeping columns are reported, nothing else');
    }

    public static function test_the_patterns_match_exactly_the_cr_list()
    {
        $bookkeeping = [
            'totals_cache', 'summary_cache_id', 'last_emailed_at', 'last_modified_by_id', 'last_seen',
            'calendar_synced_at', 'message_read_at', 'page_viewed_at', 'comment_count', 'sort_order',
            'position', 'column_position', 'reset_token', 'invite_code', 'dedupe_key', 'content_hash', 'last_seen_by',
        ];
        $ordinary = [
            'last_name', 'last', 'lastname', 'position_title', 'code', 'key', 'hash',
            'token', 'count', 'cache', 'positions', 'zip_code_label', 'sort_order_label', 'read_at',
            'viewed_at', 'synced_at', '_sort_order', '_last_seen', 'is_last_at', 'first_name',
        ];

        $flagged = static::__flagged(
            'Revision_Noise_Rule_Fixture_Patterns_Model',
            '    public static $revisions = true;',
            array_merge($bookkeeping, $ordinary)
        );

        $expected = $bookkeeping;
        sort($expected);

        static::__assert_equals($expected, $flagged, 'every listed pattern and none of the near misses');
    }

    public static function test_a_model_without_revisions_is_not_judged()
    {
        $flagged = static::__flagged(
            'Revision_Noise_Rule_Fixture_No_Revisions_Model',
            '',
            ['id', 'last_emailed_at', 'sort_order']
        );

        static::__assert_equals([], $flagged, 'nothing is recorded, so nothing is noise');
    }

    public static function test_each_of_the_three_answers_satisfies_the_rule()
    {
        $flagged = static::__flagged(
            'Revision_Noise_Rule_Fixture_Answered_Model',
            "    public static \$revisions = true;\n"
            . "    public static \$columns = [\n"
            . "        'last_emailed_at' => ['revision_exclude' => true],\n"
            . "        'comment_count' => ['derived' => 'derive_comment_count', 'from' => []],\n"
            . "        'sort_order' => ['revision_include' => 'staff arrange the list by hand and want to see who moved what'],\n"
            . "    ];\n"
            . "    public function derive_comment_count() { return 0; }",
            ['id', 'last_emailed_at', 'comment_count', 'sort_order', 'content_hash']
        );

        static::__assert_equals(['content_hash'], $flagged, 'excluded, derived and included columns pass; the unanswered one does not');
    }

    public static function test_a_blank_reason_is_no_answer()
    {
        $flagged = static::__flagged(
            'Revision_Noise_Rule_Fixture_Blank_Reason_Model',
            "    public static \$revisions = true;\n"
            . "    public static \$columns = ['sort_order' => ['revision_include' => '  ']];",
            ['id', 'sort_order']
        );

        static::__assert_equals(['sort_order'], $flagged, 'a reason is required');
    }

    public static function test_the_finding_names_the_three_answers()
    {
        $path = static::__load('Revision_Noise_Rule_Fixture_Message_Model', '    public static $revisions = true;');

        try {
            $collector = new ViolationCollector();
            (new ModelRevisionNoise_CodeQualityRule($collector))->evaluate_class('Revision_Noise_Rule_Fixture_Message_Model', $path, ['last_emailed_at']);
            $violations = array_values($collector->get_by_rule(self::RULE_ID));
        } finally {
            @unlink($path);
        }

        static::__assert_count(1, $violations, 'one finding');
        static::__assert_contains("'last_emailed_at' => ['revision_exclude' => true]", $violations[0]->suggestion, 'exclude');
        static::__assert_contains("'derived' =>", $violations[0]->suggestion, 'derive');
        static::__assert_contains("'last_emailed_at' => ['revision_include' => '<reason>']", $violations[0]->suggestion, 'include with a reason');
    }

    public static function test_revision_include_grammar_is_checked_by_model_columns()
    {
        $path = static::__load(
            'Revision_Noise_Rule_Fixture_Grammar_Model',
            "    public static \$columns = [\n"
            . "        'a_code' => ['revision_include' => 'typed by staff from the supplier invoice'],\n"
            . "        'b_code' => ['revision_include' => ''],\n"
            . "        'c_code' => ['revision_include' => true],\n"
            . "        'd_code' => ['revision_include' => 'entered by staff', 'revision_exclude' => true],\n"
            . "    ];"
        );

        try {
            $collector = new ViolationCollector();
            (new ModelColumns_CodeQualityRule($collector))->evaluate_class('Revision_Noise_Rule_Fixture_Grammar_Model', $path);
            $messages = array_map(fn ($violation) => $violation->message, array_values($collector->get_by_rule('MODEL-COLUMNS-01')));
        } finally {
            @unlink($path);
        }

        static::__assert_count(3, $messages, 'the blank, the non-string and the contradiction; the reasoned entry is legal');
        static::__assert_contains("['b_code']['revision_include']", $messages[0], 'blank reason');
        static::__assert_contains("['c_code']['revision_include']", $messages[1], 'non-string reason');
        static::__assert_contains("both 'revision_include' and 'revision_exclude'", $messages[2], 'contradiction');
    }
}
