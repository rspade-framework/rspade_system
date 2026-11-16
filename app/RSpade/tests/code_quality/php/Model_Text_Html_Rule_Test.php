<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelTextHtmlColumns_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\CodeQuality\Php\Text_Html_Rule_Fixture_Html_Text;
use App\RSpade\Tests\TextTypes\Php\Text_Fixture_Plain_Text;

/**
 * MODEL-TEXT-HTML-01 (rsx:check): a column whose declared text type stores HTML
 * (STORES_HTML true) is named `*_html`, and a column named `*_html` declares a text type that
 * stores HTML.
 *
 * Driven through the rule's evaluate_class() seam over a fixed column map (the rule reads only
 * names and each column's source table). Each fixture model declaring a different set of
 * entries is written to a temporary file and loaded outside the manifest. The HTML type is
 * Text_Html_Rule_Fixture_Html_Text, the plain one Text_Fixture_Plain_Text.
 */
class Model_Text_Html_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE_ID = 'MODEL-TEXT-HTML-01';

    private const BASE = '\\App\\RSpade\\Core\\Database\\Models\\Rsx_Model_Abstract';

    private const TABLE = 'text_html_fixtures';

    private const HTML_TYPE = '\\' . Text_Html_Rule_Fixture_Html_Text::class;

    private const PLAIN_TYPE = '\\' . Text_Fixture_Plain_Text::class;

    /** The table the fixtures stand on: column => Rsx_Schema-shaped entry. */
    private static function __columns(string $table = self::TABLE): array
    {
        $text = ['type' => 'text'];

        return [
            'id' => ['type' => 'bigint'],
            'name' => ['type' => 'varchar'],
            'description' => $text,
            'description_html' => $text,
            'notes' => $text,
            'notes_html' => $text,
            'detail_html' => $text + ['source_table' => 'text_html_fixture_details'],
        ];
    }

    /**
     * A fixture model declaring $columns_source, written to a temporary file, loaded, and judged
     * against the column map on $table.
     *
     * @return array<string, string> column => message
     */
    private static function __findings(string $class_name, string $columns_source, string $table = self::TABLE): array
    {
        $path = Rsx_Project_Paths::tmp_path('model_text_html_rule_' . $class_name . '.php');
        file_put_contents($path, "<?php\nclass {$class_name} extends " . self::BASE . "\n{\n"
            . "    protected \$table = '{$table}';\n"
            . "    public static \$columns = {$columns_source};\n}\n");

        try {
            if (!class_exists($class_name, false)) {
                require $path;
            }

            $collector = new ViolationCollector();
            (new ModelTextHtmlColumns_CodeQualityRule($collector))->evaluate_class($class_name, $path, $table, static::__columns($table));
        } finally {
            @unlink($path);
        }

        $out = [];
        foreach ($collector->get_by_rule(self::RULE_ID) as $violation) {
            static::__assert_true((bool) preg_match("/'([a-z_]+)' of table/", $violation->message, $m), $violation->message);
            $out[$m[1]] = $violation->message;
        }
        ksort($out);

        return $out;
    }

    // MTH-01: an HTML type on description_html, a plain type on notes, and the other *_html
    // column declared HTML: clean. A detail table's merged column is not judged.
    public static function test_html_type_on_an_html_name_and_plain_type_on_a_plain_name_pass()
    {
        $findings = static::__findings('Text_Html_Rule_Fixture_Clean_Model', "[\n"
            . "        'description_html' => ['text_type' => " . self::HTML_TYPE . "::class],\n"
            . "        'notes_html' => ['text_type' => " . self::HTML_TYPE . "::class],\n"
            . "        'notes' => ['text_type' => " . self::PLAIN_TYPE . "::class],\n"
            . "    ]");

        static::__assert_equals([], $findings, implode("\n", $findings));
    }

    // MTH-02: an HTML type on 'description' is reported, naming the expected 'description_html';
    // the suggestion is the forward rename migration.
    public static function test_html_type_on_an_unsuffixed_column_is_reported()
    {
        $findings = static::__findings('Text_Html_Rule_Fixture_Unsuffixed_Model', "[\n"
            . "        'description' => ['text_type' => " . self::HTML_TYPE . "::class],\n"
            . "        'description_html' => ['text_type' => " . self::HTML_TYPE . "::class],\n"
            . "        'notes_html' => ['text_type' => " . self::HTML_TYPE . "::class],\n"
            . "    ]");

        static::__assert_equals(['description'], array_keys($findings));
        static::__assert_contains("declares Text_Html_Rule_Fixture_Html_Text, a text type that stores HTML", $findings['description']);
        static::__assert_contains("Rename the column to 'description_html'", $findings['description']);
    }

    // MTH-03: a plain type on notes_html is reported as a name that lies.
    public static function test_plain_type_on_an_html_name_is_reported()
    {
        $findings = static::__findings('Text_Html_Rule_Fixture_Plain_Html_Model', "[\n"
            . "        'description_html' => ['text_type' => " . self::HTML_TYPE . "::class],\n"
            . "        'notes_html' => ['text_type' => " . self::PLAIN_TYPE . "::class],\n"
            . "    ]");

        static::__assert_equals(['notes_html'], array_keys($findings));
        static::__assert_contains('a text type that does not store HTML', $findings['notes_html']);
    }

    // MTH-04: an undeclared notes_html is reported as an unfiltered markup string; an
    // undeclared column without the suffix is not judged.
    public static function test_undeclared_html_name_is_reported()
    {
        $findings = static::__findings('Text_Html_Rule_Fixture_Undeclared_Model', "[\n"
            . "        'description_html' => ['text_type' => " . self::HTML_TYPE . "::class],\n"
            . "    ]");

        static::__assert_equals(['notes_html'], array_keys($findings));
        static::__assert_contains('declares no text type for it', $findings['notes_html']);
    }

    // MTH-05: a framework-private (`_`-prefixed) table is not judged.
    public static function test_a_framework_private_table_is_not_judged()
    {
        $findings = static::__findings('Text_Html_Rule_Fixture_Private_Model', "[\n"
            . "        'description' => ['text_type' => " . self::HTML_TYPE . "::class],\n"
            . "    ]", '_text_html_fixtures');

        static::__assert_equals([], $findings);
    }

    // MTH-06: the declaration and its reader - the base answers false, an HTML type true.
    public static function test_stores_html_is_declared_on_the_type()
    {
        static::__assert_false(Text_Fixture_Plain_Text::stores_html());
        static::__assert_true(Text_Html_Rule_Fixture_Html_Text::stores_html());
    }
}
