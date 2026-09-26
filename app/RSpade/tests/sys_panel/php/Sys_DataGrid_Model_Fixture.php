<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * A _Sys_DataGrid_Abstract over an Eloquent Builder, for Sys_DataGrid_Fetch_Test.
 */
class Sys_DataGrid_Model_Fixture extends _Sys_DataGrid_Abstract
{
    protected static int $default_per_page = 2;

    protected static array $sortable_columns = ['id', 'email'];

    protected static function __build_query(array $params)
    {
        return Login_User_Model::query()->where('email', 'LIKE', $params['prefix'] . '%');
    }
}
