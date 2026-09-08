<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelEnums_CodeQualityRule;
use App\RSpade\CodeQuality\Rules\Models\ModelTable_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MODEL-TABLE-01 and MODEL-ENUMS-01 read the LINEAGE, not one file.
 *
 * Both rules were written when every model was one file, so both answered "this file does
 * not declare it" and called that "this model does not have it". A framework model is a base
 * plus a shell now, and an application override of one declares ONLY the members it changes -
 * `class User_Model extends User_Model_Abstract` with the table, the enums and everything
 * else on the base. Under the file-only reading that correct override is two findings telling
 * the developer to copy declarations back out of the framework, which is precisely the frozen
 * clone the split exists to prevent.
 *
 * The regex over the file stays as the fast path; when it misses, the rules walk the
 * manifest `extends` chain and ask whether an ANCESTOR declares the property.
 *
 * Rsx_Model_Abstract is where the walk STOPS for $enums: the framework base declares a
 * default `public static $enums`, and crediting it would retire MODEL-ENUMS-01 for every
 * model in every application at once.
 *
 * The rules are driven directly over synthetic fixture files, the way the sibling rule tests
 * are. A fixture's PATH must contain `/rsx/` (both rules scope themselves to application
 * code) and its CLASS NAME must be a real manifest class, because the lineage lookup resolves
 * against the manifest while the fixture source supplies the declarations.
 */
class Model_Table_Lineage_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const TABLE_RULE_ID = 'MODEL-TABLE-01';
    private const ENUMS_RULE_ID = 'MODEL-ENUMS-01';

    /**
     * The reference app's override of a SPLIT framework model: it extends
     * User_Model_Abstract, which declares both $table and $enums.
     */
    private const SPLIT_OVERRIDE_CLASS = 'User_Model';

    /**
     * An ordinary application model whose ancestry is the framework base machinery only -
     * nothing above it declares a table or enums of its own.
     */
    private const PLAIN_APP_MODEL_CLASS = 'Client_Model';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Under rsx/resource/, which the framework never scans - the fixture must not become
     * indexed source, and both rules only care that the path reads as application code.
     */
    private static function __write_fixture(string $source): string
    {
        $dir = base_path('rsx/resource/model_table_lineage_fixture_' . uniqid());
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
     * @return array{table: array, enums: array}
     */
    private static function __run_rules(array $lines, string $class_name): array
    {
        $source = implode("\n", $lines);
        $path = self::__write_fixture($source);

        $collector = new ViolationCollector();

        try {
            (new ModelTable_CodeQualityRule($collector))->check($path, $source, ['class' => $class_name]);
            (new ModelEnums_CodeQualityRule($collector))->check($path, $source, ['class' => $class_name]);
        } finally {
            self::__remove_fixture($path);
        }

        return [
            'table' => array_values($collector->get_by_rule(self::TABLE_RULE_ID)),
            'enums' => array_values($collector->get_by_rule(self::ENUMS_RULE_ID)),
        ];
    }

    // =====================================================================
    // The lineage
    // =====================================================================

    /**
     * The shape the framework asks an application to write: extend the base, declare only
     * what changes. Neither rule has anything to say about it.
     */
    public static function test_an_override_of_a_split_model_declares_neither_and_passes_both()
    {
        $violations = static::__run_rules([
            '<?php',
            '',
            'namespace Rsx\\Models;',
            '',
            'class User_Model extends User_Model_Abstract',
            '{',
            '    public function get_view_profile_url(): ?string',
            '    {',
            '        return null;',
            '    }',
            '}',
        ], self::SPLIT_OVERRIDE_CLASS);

        static::__assert_count(
            0,
            $violations['table'],
            'the table is declared on the base this class extends'
        );
        static::__assert_count(
            0,
            $violations['enums'],
            'and so are the enums'
        );
    }

    /**
     * The rule has not been retired. A model with no table anywhere in its lineage is still
     * a finding, and so is one with no enums.
     */
    public static function test_a_model_whose_lineage_declares_neither_is_still_two_findings()
    {
        $violations = static::__run_rules([
            '<?php',
            '',
            'namespace Rsx\\Models;',
            '',
            'class Client_Model extends Rsx_Site_Model_Abstract',
            '{',
            '    public function nothing_useful()',
            '    {',
            '    }',
            '}',
        ], self::PLAIN_APP_MODEL_CLASS);

        static::__assert_count(1, $violations['table'], 'no $table anywhere in the lineage');
        static::__assert_contains(
            'missing protected $table',
            $violations['table'][0]->message,
            'and the finding says so'
        );

        static::__assert_count(1, $violations['enums'], 'no $enums the model declares itself');
        static::__assert_contains(
            'missing public static $enums',
            $violations['enums'][0]->message,
            'and Rsx_Model_Abstract\'s own default declaration does not count'
        );
    }

    /**
     * The file remains the fast path: a model that declares both in its own file passes
     * without the lineage being consulted at all.
     */
    public static function test_a_model_that_declares_both_itself_passes()
    {
        $violations = static::__run_rules([
            '<?php',
            '',
            'namespace Rsx\\Models;',
            '',
            'class Client_Model extends Rsx_Site_Model_Abstract',
            '{',
            "    protected \$table = 'clients';",
            '',
            '    public static $enums = [];',
            '}',
        ], self::PLAIN_APP_MODEL_CLASS);

        static::__assert_count(0, $violations['table'], 'the file declares the table');
        static::__assert_count(0, $violations['enums'], 'and the enums');
    }
}
