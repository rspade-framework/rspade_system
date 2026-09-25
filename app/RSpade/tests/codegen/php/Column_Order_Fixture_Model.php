<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Tests\Codegen\Php\Column_Order_Fixture_Detail_Model;

/**
 * Test fixture: a class-table-inheritance base model whose tables Constants_Regenerate_Order_Test
 * creates at runtime in two different physical column orders. One detail type, so the generated
 * docblock carries both a base group and a `(detail: ...)` group.
 */
class Column_Order_Fixture_Model extends Rsx_Model_Abstract
{
    protected $table = 'codegen_column_order_fixtures';
    protected $fillable = [];

    const TYPE_WIDGET = 1;

    public static $enums = [
        'type_id' => [
            1 => ['constant' => 'TYPE_WIDGET', 'label' => 'Widget'],
        ],
    ];

    public static $detail_tables = [
        'type_id' => [
            self::TYPE_WIDGET => Column_Order_Fixture_Detail_Model::class,
        ],
    ];
}
