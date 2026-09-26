<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * A _Sys_DataGrid_Abstract over an in-memory row list, for Sys_DataGrid_Fetch_Test.
 * 'bad' makes __build_query() return something that is no source at all.
 */
class Sys_DataGrid_Rows_Fixture extends _Sys_DataGrid_Abstract
{
    protected static int $default_per_page = 2;

    protected static int $max_per_page = 4;

    protected static array $sortable_columns = ['id', 'name', 'size'];

    protected static function __build_query(array $params)
    {
        if (!empty($params['bad'])) {
            return 'not a source';
        }

        $rows = [
            ['id' => 1, 'name' => 'b', 'size' => 10],
            ['id' => 2, 'name' => 'a', 'size' => 9],
            ['id' => 3, 'name' => 'a', 'size' => 100],
            ['id' => 4, 'name' => 'c', 'size' => 2],
            ['id' => 5, 'name' => null, 'size' => 50],
        ];

        if (!empty($params['filter'])) {
            $rows = array_filter($rows, fn ($row) => $row['name'] === $params['filter']);
        }

        return $rows;
    }

    protected static function __transform_records(array $records, array $params): array
    {
        foreach ($records as &$record) {
            $record['label'] = strtoupper((string) $record['name']);
        }

        return $records;
    }
}
