<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\Theme\Components;

use Illuminate\Database\Eloquent\Builder as Eloquent_Builder;
use Illuminate\Database\Query\Builder as Query_Builder;

/**
 * _Sys_DataGrid_Abstract - the server half of a panel grid: sort validation, paging
 * math and the response shape. A concrete grid implements __build_query() and nothing
 * else is required.
 *
 * THE SOURCE. __build_query() returns one of three things, and fetch() pages whichever
 * it gets the same way:
 *   - an Eloquent Builder     - records serialize through the model's toArray(), so
 *                               system columns are stripped exactly as on any payload;
 *   - a Query\Builder         - DB::table() over a table with no model (_tasks);
 *                               each row arrives as an associative array;
 *   - a list of rows (array)  - a small IN-MEMORY set the grid built itself (the log
 *                               directory listing). The whole list is sorted and sliced
 *                               in PHP, so this is for sets that are small by nature,
 *                               never for a table.
 * __build_query() applies its own filters in every case, reading them off $params.
 *
 * SORTING is fail-closed: a sort key outside $sortable_columns falls back to
 * $default_sort, and an empty allow-list means "the default order only". The order
 * is 'asc' or 'desc' and nothing else. $secondary_sort breaks ties so paging is
 * stable under a low-cardinality primary sort.
 *
 * The response is {records, page, per_page, total, total_pages, sort, order}, which
 * is what the JS half (_Sys_DataGrid_Abstract.js / _Sys_DataGrid_Body.js) reads.
 *
 *     class _Sys_Tasks_DataGrid extends _Sys_DataGrid_Abstract
 *     {
 *         protected static array $sortable_columns = ['id', 'status', 'created_at'];
 *         protected static ?string $secondary_sort = 'id';
 *
 *         protected static function __build_query(array $params)
 *         {
 *             $query = DB::table('_tasks');
 *             if (!empty($params['status'])) {
 *                 $query->where('status', $params['status']);
 *             }
 *             return $query;
 *         }
 *     }
 *
 * The controller forwards one Ajax endpoint to fetch($params).
 */
abstract class _Sys_DataGrid_Abstract
{
    protected static int $default_per_page = 25;

    protected static int $max_per_page = 100;

    protected static ?string $default_sort = 'id';

    protected static string $default_order = 'desc';

    /**
     * Sort keys a request may name. Anything else falls back to $default_sort.
     *
     * @var string[]
     */
    protected static array $sortable_columns = [];

    /**
     * Tie-breaker applied after the primary sort (skipped when it IS the primary sort),
     * so rows sharing a primary value keep one order across pages.
     */
    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'desc';

    /**
     * The source: an Eloquent Builder, a Query\Builder, or a list of associative rows.
     * Filtering happens here; sorting and paging never do.
     *
     * @param array $params The request: page, per_page, sort, order, filter, plus the
     *                      grid's own filter keys as top-level entries
     * @return Eloquent_Builder|Query_Builder|array
     */
    abstract protected static function __build_query(array $params);

    /**
     * The SQL column a sort key orders by. Override when the query joins or aliases,
     * so the key the browser sends stays a plain name. Not consulted for a row list,
     * whose sort key is the row key.
     *
     * @param string $key A key from $sortable_columns
     * @return string
     */
    #[Replaceable]
    protected static function __map_sort_column(string $key): string
    {
        return $key;
    }

    /**
     * Shape the page's records after they are read - labels, computed fields.
     *
     * @param array $records The page's rows, each an associative array
     * @param array $params The request
     * @return array
     */
    #[Replaceable]
    protected static function __transform_records(array $records, array $params): array
    {
        return $records;
    }

    /**
     * One page of the grid.
     *
     * @param array $params page, per_page, sort, order, filter, and the grid's filter keys
     * @return array {records, page, per_page, total, total_pages, sort, order}
     */
    public static function fetch(array $params = []): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));

        // Clamped, not snapped: 0 is a request for nothing, not for the maximum.
        $per_page = (int) ($params['per_page'] ?? static::$default_per_page);
        $per_page = min(max(1, $per_page), static::$max_per_page);

        $sort = $params['sort'] ?? static::$default_sort;
        if ($sort !== static::$default_sort && !in_array($sort, static::$sortable_columns, true)) {
            $sort = static::$default_sort;
        }

        $order = strtolower((string) ($params['order'] ?? static::$default_order));
        if ($order !== 'asc' && $order !== 'desc') {
            $order = static::$default_order;
        }

        $source = static::__build_query($params);

        if (is_array($source)) {
            [$records, $total] = self::__page_rows($source, $sort, $order, $page, $per_page);
        } elseif ($source instanceof Eloquent_Builder || $source instanceof Query_Builder) {
            [$records, $total] = self::__page_query($source, $sort, $order, $page, $per_page);
        } else {
            shouldnt_happen(static::class . '::__build_query() must return an Eloquent Builder, a Query\\Builder or an array of rows; got ' . get_debug_type($source));
        }

        $total_pages = (int) ceil($total / $per_page);

        return [
            'records' => static::__transform_records($records, $params),
            'page' => min($page, max(1, $total_pages)),
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
            'sort' => $sort,
            'order' => $order,
        ];
    }

    /**
     * Count, order and slice a query. The count runs on the unordered base query
     * (getCountForPagination, so a grouped query counts its groups), and a page past
     * the end is pulled back to the last page.
     */
    private static function __page_query($query, ?string $sort, string $order, int $page, int $per_page): array
    {
        $base = $query instanceof Eloquent_Builder ? $query->toBase() : $query;
        $total = (clone $base)->getCountForPagination();

        if ($sort !== null) {
            $query->orderBy(static::__map_sort_column($sort), $order);
        }

        if (static::$secondary_sort !== null && static::$secondary_sort !== $sort) {
            $query->orderBy(static::__map_sort_column(static::$secondary_sort), static::$secondary_order);
        }

        $page = min($page, max(1, (int) ceil($total / $per_page)));
        $query->offset(($page - 1) * $per_page)->limit($per_page);

        if ($query instanceof Eloquent_Builder) {
            $records = $query->get()->toArray();
        } else {
            $records = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return [$records, $total];
    }

    /**
     * Order and slice an in-memory row list with the same rules a query gets.
     */
    private static function __page_rows(array $rows, ?string $sort, string $order, int $page, int $per_page): array
    {
        $rows = array_values($rows);
        $total = count($rows);

        $keys = [];
        if ($sort !== null) {
            $keys[] = [$sort, $order];
        }
        if (static::$secondary_sort !== null && static::$secondary_sort !== $sort) {
            $keys[] = [static::$secondary_sort, static::$secondary_order];
        }

        if (!empty($keys)) {
            usort($rows, function ($a, $b) use ($keys) {
                foreach ($keys as [$key, $direction]) {
                    $cmp = self::__compare_values($a[$key] ?? null, $b[$key] ?? null);

                    if ($cmp !== 0) {
                        return $direction === 'desc' ? -$cmp : $cmp;
                    }
                }

                return 0;
            });
        }

        $page = min($page, max(1, (int) ceil($total / $per_page)));

        return [array_slice($rows, ($page - 1) * $per_page, $per_page), $total];
    }

    /**
     * Numbers compare as numbers, everything else as case-insensitive natural text;
     * null sorts first, as it does in MySQL.
     */
    private static function __compare_values($a, $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) - ($b === null ? 0 : 1);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }

        return strnatcasecmp((string) $a, (string) $b);
    }
}
