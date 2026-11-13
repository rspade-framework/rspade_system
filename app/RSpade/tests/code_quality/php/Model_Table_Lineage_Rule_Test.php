<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\CodeQuality\Php;

use App\RSpade\CodeQuality\Rules\Models\ModelTable_CodeQualityRule;
use App\RSpade\CodeQuality\Support\ViolationCollector;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * MODEL-TABLE-01 reads the LINEAGE, not one file.
 *
 * "This file does not declare a table" is not "this model has no table". A framework model is
 * a base plus a shell, and an application override of one declares ONLY the members it
 * changes - `class User_Model extends User_Model_Abstract` with the table and everything else
 * on the base. Under a file-only reading that correct override is a finding telling the
 * developer to copy the declaration back out of the framework, which is precisely the frozen
 * clone the split exists to prevent.
 *
 * The regex over the file stays as the fast path; when it misses, the rule walks the manifest
 * `extends` chain and asks whether an ANCESTOR declares the property.
 *
 * The rule is driven directly over synthetic fixture files, the way the sibling rule tests
 * are. A fixture's PATH must contain `/rsx/` (the rule scopes itself to application code) and
 * its CLASS NAME must be a real manifest class, because the lineage lookup resolves against
 * the manifest while the fixture source supplies the declarations.
 */
class Model_Table_Lineage_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const TABLE_RULE_ID = 'MODEL-TABLE-01';

    /**
     * The reference app's override of a SPLIT framework model: it extends
     * User_Model_Abstract, which declares $table.
     */
    private const SPLIT_OVERRIDE_CLASS = 'User_Model';

    /**
     * An ordinary application model whose ancestry is the framework base machinery only -
     * nothing above it declares a table of its own.
     */
    private const PLAIN_APP_MODEL_CLASS = 'Client_Model';

    // =====================================================================
    // Fixture infrastructure
    // =====================================================================

    /**
     * Under rsx/resource/, which the framework never scans - the fixture must not become
     * indexed source, and the rule only cares that the path reads as application code.
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
     * @return array<int, object>
     */
    private static function __run_rule(array $lines, string $class_name): array
    {
        $source = implode("\n", $lines);
        $path = self::__write_fixture($source);

        $collector = new ViolationCollector();

        try {
            (new ModelTable_CodeQualityRule($collector))->check($path, $source, ['class' => $class_name]);
        } finally {
            self::__remove_fixture($path);
        }

        return array_values($collector->get_by_rule(self::TABLE_RULE_ID));
    }

    // =====================================================================
    // The lineage
    // =====================================================================

    /**
     * The shape the framework asks an application to write: extend the base, declare only
     * what changes. The rule has nothing to say about it.
     */
    public static function test_an_override_of_a_split_model_inherits_its_table_and_passes()
    {
        $violations = static::__run_rule([
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
            $violations,
            'the table is declared on the base this class extends'
        );
    }

    /**
     * A model with no table anywhere in its lineage is still a finding.
     */
    public static function test_a_model_whose_lineage_declares_no_table_is_a_finding()
    {
        $violations = static::__run_rule([
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

        static::__assert_count(1, $violations, 'no $table anywhere in the lineage');
        static::__assert_contains(
            'missing protected $table',
            $violations[0]->message,
            'and the finding says so'
        );
    }

    /**
     * The file remains the fast path: a model that declares its table in its own file passes
     * without the lineage being consulted at all.
     */
    public static function test_a_model_that_declares_its_table_itself_passes()
    {
        $violations = static::__run_rule([
            '<?php',
            '',
            'namespace Rsx\\Models;',
            '',
            'class Client_Model extends Rsx_Site_Model_Abstract',
            '{',
            "    protected \$table = 'clients';",
            '}',
        ], self::PLAIN_APP_MODEL_CLASS);

        static::__assert_count(0, $violations, 'the file declares the table');
    }
}
