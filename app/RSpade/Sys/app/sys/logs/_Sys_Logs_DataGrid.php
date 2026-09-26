<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Logs;

use App\RSpade\Sys\App\Sys\Logs\_Sys_Log_Reader;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * The Logs screen's file list: _Sys_Log_Reader::list_files() as a row list, newest
 * first. Search (filter) matches the file name as a substring.
 */
class _Sys_Logs_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static int $default_per_page = 50;

    protected static ?string $default_sort = 'modified';

    protected static array $sortable_columns = ['name', 'size', 'modified'];

    protected static ?string $secondary_sort = 'name';

    protected static string $secondary_order = 'asc';

    protected static function __build_query(array $params)
    {
        $rows = _Sys_Log_Reader::list_files();
        $search = trim((string) ($params['filter'] ?? ''));

        if ($search !== '') {
            $rows = array_filter($rows, fn ($row) => stripos($row['name'], $search) !== false);
        }

        return array_values($rows);
    }
}
