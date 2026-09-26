<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * A _Sys_DataGrid_Abstract over a Query\Builder (DB::table(), no model), for
 * Sys_DataGrid_Fetch_Test. Rows are the login_users whose email starts with the
 * 'prefix' param, so a test sees only the rows it wrote.
 */
class Sys_DataGrid_Table_Fixture extends _Sys_DataGrid_Abstract
{
    protected static int $default_per_page = 2;

    protected static int $max_per_page = 3;

    protected static array $sortable_columns = ['id', 'email', 'status_id'];

    protected static function __build_query(array $params)
    {
        return DB::table('login_users')->where('email', 'LIKE', $params['prefix'] . '%');
    }
}
