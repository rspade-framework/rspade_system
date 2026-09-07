<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use Illuminate\Support\Facades\Schema;
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
 * Proven against the template app's Party demo (the reference detail-table entity). A
 * framework-only install without the template tables skips - the feature is inherently
 * template-data-backed.
 */
class Constants_Regenerate_Metadata_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    private const PARTY_MODEL = 'Rsx\\Models\\Party_Model';

    public static function test_detail_spanning_bem_and_string_dates()
    {
        // The Party demo is template-app data; its detail table's presence proves the template
        // app (and its Party_Model) is installed. A framework-only install skips.
        if (!Schema::hasTable('party_person_details')) {
            static::__skip('Template app Party detail tables not present (framework-only install)');
            return;
        }

        $command = new \App\RSpade\Commands\Rsx\Constants_Regenerate_Command();
        [$doc_block, $constants_block] = $command->build_metadata(self::PARTY_MODEL);

        // Ported feature 1: CTI detail columns spanned into the base docblock, tagged.
        static::__assert_contains('(detail: party_person_details)', $doc_block);
        static::__assert_contains('(detail: party_company_details)', $doc_block);

        // Ported feature 3: BEM double-underscore, typed enum members (not single-underscore mixed).
        static::__assert_contains('@property-read string $type_id__label', $doc_block);
        static::__assert_contains('@property-read string $type_id__constant', $doc_block);
        static::__assert_contains('@method static array type_id__enum()', $doc_block);
        static::__assert_contains('@method static array type_id__enum_select()', $doc_block);
        static::__assert_true(
            !str_contains($doc_block, 'type_id_enum()'),
            'Legacy single-underscore enum method survived'
        );

        // Ported feature 4: DATE/DATETIME columns are string, never Carbon.
        static::__assert_contains('@property string $created_at', $doc_block);
        static::__assert_true(
            !str_contains($doc_block, '\\Carbon\\Carbon'),
            'Datetime column documented as Carbon instead of string'
        );

        // The canonical (B1) enum-constants block is produced.
        static::__assert_contains('_AUTO_GENERATED_ Enum constants', $constants_block);
        static::__assert_contains('const TYPE_PERSON = 1;', $constants_block);
    }
}
