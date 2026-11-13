<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Codegen\Php\Column_Kinds_Fixture_Model;

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
 * Each column's @property line also states the column's declared kind - enum, text type,
 * type ref, derived, never exported, not recorded in revisions - and the type its attribute
 * actually reads as (DECIMAL and uncast JSON as string, a boolean column as bool), pinned
 * against Column_Kinds_Fixture_Model on a table this class creates.
 *
 * The CTI SUBJECT is derived, never named: the framework declares no CTI model of its own, so
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

    private const KINDS_TABLE = 'codegen_column_kinds_fixtures';

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::KINDS_TABLE);
        Rsx_Schema::forget();
    }

    // CODEGEN-KINDS-01..09: one @property line per column kind, exactly as written.
    public static function test_each_column_kind_is_stated_on_its_property_line()
    {
        DB::statement('DROP TABLE IF EXISTS ' . self::KINDS_TABLE);
        DB::statement('CREATE TABLE ' . self::KINDS_TABLE . ' (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            stage_id BIGINT NULL,
            flag_id BIGINT NULL,
            body TEXT NULL,
            note TEXT NULL,
            owner_type BIGINT NULL,
            owner_id BIGINT NULL,
            secret VARCHAR(64) NULL,
            view_count INT NULL,
            amount DECIMAL(10,2) NULL,
            total DECIMAL(10,2) NULL,
            payload JSON NULL,
            is_open TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        // The boolean cast is read from Rsx_Schema's column map: make the new table visible.
        Rsx_Schema::forget();

        [$doc_block] = (new \App\RSpade\Commands\Rsx\Constants_Regenerate_Command())->build_metadata(Column_Kinds_Fixture_Model::class);
        $lines = explode("\n", $doc_block);
        $text_type = '\\App\\RSpade\\Tests\\Codegen\\Php\\Codegen_Fixture_Text';

        $expected = [
            // Enum, more constants than a line names: the first five, then a count.
            ' * @property int $stage_id enum: STAGE_ONE, STAGE_TWO, STAGE_THREE, STAGE_FOUR, STAGE_FIVE and 2 more (also $stage_id__label and the other $stage_id__* properties)',
            // Enum short enough to name in full.
            ' * @property int $flag_id enum: FLAG_ON, FLAG_OFF (also $flag_id__label and the other $flag_id__* properties)',
            // Text type: typed as the value-object class, fully qualified.
            " * @property {$text_type} \$body text type: a value object, filtered on write",
            // Combined kinds on one line.
            " * @property {$text_type} \$note text type: a value object, filtered on write; never exported to the browser",
            // Type ref: reads as a class name.
            ' * @property string $owner_type type ref: a model class name, stored as a type-ref id',
            ' * @property int $owner_id',
            ' * @property string $secret never exported to the browser',
            ' * @property int $view_count not recorded in revisions',
            // Derived: read-only, naming its method and sources; a DECIMAL reads as a string.
            ' * @property-read string $total decimal; derived: computed by derive_total() from amount, view_count; never assigned',
            ' * @property string $amount decimal',
            // JSON with no array cast reads as the JSON string.
            ' * @property string $payload json',
            // A boolean column reads as bool through its automatic cast.
            ' * @property bool $is_open',
        ];

        foreach ($expected as $line) {
            static::__assert_true(in_array($line, $lines, true), "Generated docblock lacks the line:\n{$line}\n\n{$doc_block}");
        }

        // The enum accessor hints are still emitted beside the kind note.
        static::__assert_contains('@property-read string $stage_id__label', $doc_block);
    }

    /**
     * The first model this application declares that has BOTH merged detail columns and an
     * enum column, as [fqcn, detail_tables, enum_column, first_enum_id, first_enum_constant]
     * - or null when it declares none.
     *
     * A merged detail column is recognised by its source_table tag: Rsx_Schema records
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

            $enum_map = $fqcn::enum_columns();

            foreach ($enum_map as $enum_column => $values) {
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
