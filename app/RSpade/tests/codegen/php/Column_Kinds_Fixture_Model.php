<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Codegen\Php\Codegen_Fixture_Text;

/**
 * Test fixture: a model declaring one column of every kind $columns knows, plus the column
 * types whose reads differ from their schema type (DECIMAL, uncast JSON, boolean TINYINT(1)).
 * Constants_Regenerate_Metadata_Test creates its table at runtime and asserts the
 * @property line build_metadata() writes for each column.
 *
 *   stage_id   - an enum with more constants than a line names
 *   flag_id    - an enum short enough to name in full
 *   body       - a text type
 *   note       - a text type that is also never exported
 *   owner_type - a type ref (owner_id is its plain partner)
 *   secret     - never exported
 *   view_count - not recorded in revisions
 *   total      - a derived DECIMAL computed from amount and view_count
 */
class Column_Kinds_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'codegen_column_kinds_fixtures';
    protected $fillable = [];

    public static $columns = [
        'stage_id' => ['enum' => [
            1 => ['constant' => 'STAGE_ONE', 'label' => 'One'],
            2 => ['constant' => 'STAGE_TWO', 'label' => 'Two'],
            3 => ['constant' => 'STAGE_THREE', 'label' => 'Three'],
            4 => ['constant' => 'STAGE_FOUR', 'label' => 'Four'],
            5 => ['constant' => 'STAGE_FIVE', 'label' => 'Five'],
            6 => ['constant' => 'STAGE_SIX', 'label' => 'Six'],
            7 => ['constant' => 'STAGE_SEVEN', 'label' => 'Seven'],
        ]],
        'flag_id' => ['enum' => [
            1 => ['constant' => 'FLAG_ON', 'label' => 'On'],
            2 => ['constant' => 'FLAG_OFF', 'label' => 'Off'],
        ]],
        'body' => ['text_type' => Codegen_Fixture_Text::class],
        'note' => ['text_type' => Codegen_Fixture_Text::class, 'never_export' => true],
        'owner_type' => ['type_ref' => true],
        'secret' => ['never_export' => true],
        'view_count' => ['revision_exclude' => true],
        'total' => ['derived' => 'derive_total', 'from' => ['amount', 'view_count']],
    ];

    public function derive_total(): ?string
    {
        if ($this->amount === null) {
            return null;
        }

        return number_format((float) $this->amount * (int) $this->view_count, 2, '.', '');
    }
}
