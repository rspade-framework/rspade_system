<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

/**
 * Test fixture for rsx:db:check_indexes: a SITE-SCOPED model declaring one enum column.
 *
 * Check_Indexes_Advice_Test reads its facts (site scope, the class holding its scope flag,
 * whether its table is a framework table, its enum columns) through
 * Check_Indexes_Command::model_facts(). Nothing queries it, so no table is created.
 */
class Check_Indexes_Site_Fixture_Model extends Rsx_Site_Model_Abstract
{
    protected $table = 'check_indexes_site_fixtures';

    protected $fillable = [];

    public $timestamps = false;

    const STAGE_OPEN = 1;
    const STAGE_CLOSED = 2;

    public static $columns = [
        'stage_id' => ['enum' => [
            1 => ['constant' => 'STAGE_OPEN', 'label' => 'Open'],
            2 => ['constant' => 'STAGE_CLOSED', 'label' => 'Closed'],
        ]],
    ];
}
