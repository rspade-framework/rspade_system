<?php

namespace App\RSpade\Core\Database;

use InvalidArgumentException;

/**
 * Rsx_Page_Query - the one reading of a DISPLAY-PAGE query.
 *
 * A `_`-prefixed table is private to the framework, so an application shows its rows
 * through a reader the framework publishes. Readers that put a page in front of a person -
 * a grid with column sorting, a search box and a numbered pager - all take the same
 * argument and give the same answer, and this class is what makes that true:
 *
 *     Reader::list_page(array $query): array
 *
 *     $query   scope keys the reader declares (login_user_id, ...)
 *              filter   free text; the READER decides which columns it searches
 *              sort     one of the names the reader publishes
 *              order    'asc' | 'desc'
 *              offset   rows to skip, >= 0
 *              limit    rows to return, 1..the reader's maximum
 *
 *     answer   ['rows' => array, 'total' => int, 'offset' => int, 'limit' => int]
 *              total is the count BEFORE the page is cut - what a pager needs.
 *
 * VALIDATION IS THE READER'S, NEVER THE CALLER'S. A reader is a function that turns
 * strings from a request into a query, so every one of them is refused here rather than
 * trusted: a sort name is looked up in the reader's own map and never reaches SQL as
 * written; order is exactly asc or desc; limit and offset are bounded integers; a key the
 * reader does not declare is an error. Anything wrong THROWS InvalidArgumentException -
 * a bad value is never quietly replaced with a default, because that is what hides the
 * day a column was renamed.
 *
 * This is for SHOWING a page. WALKING a whole table - an export, a sweep - is a different
 * job with a different shape (a keyset cursor, no total, no caller sort): see
 * rsx:man database_schema_architecture, READERS OVER SYSTEM TABLES.
 */
class Rsx_Page_Query
{
    /**
     * Validate a display-page query against what one reader accepts.
     *
     * @param array $query The caller's query
     * @param string $reader The reader's name, for messages ('Login_History::list_page')
     * @param array<string, string> $sortable Published sort name => column
     * @param string $default_sort One of $sortable's names
     * @param string $default_order 'asc' or 'desc'
     * @param int $max_limit The most rows one page may hold
     * @param int $default_limit Rows per page when the caller names none
     * @param string[] $scope_keys Every other key the reader accepts
     * @return array{sort_column: string, sort: string, order: string, offset: int, limit: int, filter: string}
     */
    public static function parse(
        array $query,
        string $reader,
        array $sortable,
        string $default_sort,
        string $default_order,
        int $max_limit,
        int $default_limit,
        array $scope_keys = []
    ): array {
        $accepted = array_merge(['filter', 'sort', 'order', 'offset', 'limit'], $scope_keys);
        $unknown = array_diff(array_keys($query), $accepted);

        if (!empty($unknown)) {
            throw new InvalidArgumentException(
                "{$reader}: unknown query key '" . implode("', '", $unknown) . "'. Accepted: " . implode(', ', $accepted) . '.'
            );
        }

        $sort = $query['sort'] ?? $default_sort;

        if (!is_string($sort) || !isset($sortable[$sort])) {
            throw new InvalidArgumentException(
                "{$reader}: cannot sort by '" . (is_scalar($sort) ? $sort : gettype($sort)) . "'. Sortable: " . implode(', ', array_keys($sortable)) . '.'
            );
        }

        $order = $query['order'] ?? $default_order;

        if ($order !== 'asc' && $order !== 'desc') {
            throw new InvalidArgumentException("{$reader}: order must be 'asc' or 'desc'.");
        }

        $offset = self::__integer($query['offset'] ?? 0);

        if ($offset === null || $offset < 0) {
            throw new InvalidArgumentException("{$reader}: offset must be a non-negative integer.");
        }

        $limit = self::__integer($query['limit'] ?? $default_limit);

        if ($limit === null || $limit < 1 || $limit > $max_limit) {
            throw new InvalidArgumentException("{$reader}: limit must be an integer from 1 to {$max_limit}.");
        }

        $filter = $query['filter'] ?? '';

        if (!is_string($filter)) {
            throw new InvalidArgumentException("{$reader}: filter must be a string.");
        }

        return [
            'sort_column' => $sortable[$sort],
            'sort' => $sort,
            'order' => $order,
            'offset' => $offset,
            'limit' => $limit,
            'filter' => trim($filter),
        ];
    }

    /**
     * A required integer scope value: the id that bounds what the reader may answer. It is
     * never defaulted - a reader that scoped to "everything" by omission would hand one
     * caller another's rows.
     */
    public static function require_id(array $query, string $key, string $reader): int
    {
        $id = self::__integer($query[$key] ?? null);

        if ($id === null || $id < 1) {
            throw new InvalidArgumentException("{$reader}: {$key} is required and must be a positive integer.");
        }

        return $id;
    }

    /**
     * A LIKE pattern matching $filter anywhere, with the wildcard characters in it taken
     * literally.
     */
    public static function like(string $filter): string
    {
        return '%' . addcslashes($filter, '%_\\') . '%';
    }

    /**
     * The answer every display-page reader gives.
     *
     * @return array{rows: array, total: int, offset: int, limit: int}
     */
    public static function result(array $rows, int $total, array $parsed): array
    {
        return [
            'rows' => array_values($rows),
            'total' => $total,
            'offset' => $parsed['offset'],
            'limit' => $parsed['limit'],
        ];
    }

    private static function __integer($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }
}
