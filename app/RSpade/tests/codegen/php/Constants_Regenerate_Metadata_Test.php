<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins the metadata rsx:constants:regenerate emits, exercising the features folded in from
 * the retired rsx:migrate:document_models command:
 *
 *   - Class-Table-Inheritance detail columns are spanned into the base model's docblock,
 *     each tagged `(detail: <table>)`.
 *   - Enum members use BEM double-underscore, typed form (`@property-read string $x__label`,
 *     `@method static array x__enum()`), rather than a single-underscore `mixed` form.
 *   - DATE / DATETIME columns are documented as `string` (framework string-time philosophy),
 *     never `\Carbon\Carbon`.
 *
 * The SUBJECT is derived, never named: the framework declares no CTI model of its own, so
 * the test asks the manifest for a base model carrying an enum and at least one merged
 * detail column, and asserts against what that model actually declares. An application with
 * no such model cannot express the case and skips.
 */
class Constants_Regenerate_Metadata_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function test_detail_spanning_bem_and_string_dates()
    {
        $subject = static::__a_cti_model_with_an_enum();

        if ($subject === null) {
            static::__skip('this application declares no class-table-inheritance model with an enum column');

            return;
        }

        [$fqcn, $detail_tables, $enum_column, $first_enum_id, $first_enum_constant] = $subject;

        $command = new \App\RSpade\Commands\Rsx\Constants_Regenerate_Command();
        [$doc_block, $constants_block] = $command->build_metadata($fqcn);

        // Ported feature 1: CTI detail columns spanned into the base docblock, tagged.
        foreach ($detail_tables as $detail_table) {
            static::__assert_contains("(detail: {$detail_table})", $doc_block);
        }

        // Ported feature 3: BEM double-underscore, typed enum members (not single-underscore mixed).
        static::__assert_contains("@property-read string \${$enum_column}__label", $doc_block);
        static::__assert_contains("@property-read string \${$enum_column}__constant", $doc_block);
        static::__assert_contains("@method static array {$enum_column}__enum()", $doc_block);
        static::__assert_contains("@method static array {$enum_column}__enum_select()", $doc_block);
        static::__assert_true(
            !str_contains($doc_block, "{$enum_column}_enum()"),
            'Retired single-underscore enum method survived'
        );

        // Ported feature 4: DATE/DATETIME columns are string, never Carbon.
        static::__assert_contains('@property string $created_at', $doc_block);
        static::__assert_true(
            !str_contains($doc_block, '\\Carbon\\Carbon'),
            'Datetime column documented as Carbon instead of string'
        );

        // The canonical (B1) enum-constants block is produced.
        static::__assert_contains('_AUTO_GENERATED_ Enum constants', $constants_block);
        static::__assert_contains("const {$first_enum_constant} = {$first_enum_id};", $constants_block);
    }

    /**
     * The first model this application declares that has BOTH merged detail columns and an
     * enum column, as [fqcn, detail_tables, enum_column, first_enum_id, first_enum_constant]
     * - or null when it declares none.
     *
     * A merged detail column is recognised by its source_table tag: the manifest records
     * which physical table each column came from, and a CTI base model's map carries columns
     * from its detail tables. Deterministic - the model index is walked in sorted order.
     *
     * @return array{0: string, 1: array, 2: string, 3: int, 4: string}|null
     */
    private static function __a_cti_model_with_an_enum(): ?array
    {
        $models = Manifest::$data['data']['models'] ?? [];
        ksort($models);

        foreach ($models as $model_class => $model) {
            $fqcn = $model['fqcn'] ?? null;
            $base_table = $model['table'] ?? null;

            if ($fqcn === null || $base_table === null) {
                continue;
            }

            $detail_tables = [];

            foreach (Manifest::php_model_columns($model_class) ?? [] as $meta) {
                $source_table = $meta['source_table'] ?? $base_table;

                if ($source_table !== $base_table) {
                    $detail_tables[$source_table] = true;
                }
            }

            if (empty($detail_tables)) {
                continue;
            }

            $enums = $fqcn::$enums ?? [];

            foreach ($enums as $enum_column => $values) {
                foreach ($values as $id => $definition) {
                    if (isset($definition['constant'])) {
                        return [
                            $fqcn,
                            array_keys($detail_tables),
                            $enum_column,
                            (int) $id,
                            $definition['constant'],
                        ];
                    }
                }
            }
        }

        return null;
    }
}
