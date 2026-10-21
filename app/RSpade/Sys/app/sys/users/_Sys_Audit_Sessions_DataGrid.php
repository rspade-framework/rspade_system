<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Users;

use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Sys\Lib\_Sys_Enum_Words;

/**
 * The audit sessions of ONE user session on a session detail screen - every address and
 * browser that sign-in acted from, most recently seen first, EVERY row a page at a time.
 * Shared by the Users screen (_Sys_User_Session_View_Action) and the Portal Users screen
 * (_Sys_Portal_User_Session_View_Action): an audit session reads the same in both realms.
 *
 * THE SOURCE IS THE PUBLISHED READER, Audit_Session::list_page_for_user_session(), not the
 * table: this class only translates the panel grid's request into the reader's display-page
 * query and the reader's answer into the grid's response (the shape
 * _Sys_DataGrid_Abstract.php answers, which is all the JS half reads). The caller has
 * already decided the user session is one the screen may show; this pages it.
 *
 * Request: page, per_page, sort (last_seen_at | first_seen_at | ip_address; anything else
 * sorts by last_seen_at), order, filter (the reader matches the IP address or the user
 * agent). per_page is clamped into [1, MAX_PER_PAGE] and a page past the end is the last
 * page.
 *
 * Response: {records, page, per_page, total, total_pages, sort, order}; each record is the
 * reader's row (ISO datetimes, device_summary) plus `site_id` - the realm's site (the staff
 * site, or the portal site on a portal user session) - and its `site_name`.
 */
class _Sys_Audit_Sessions_DataGrid
{
    /** The reader's published sort names. */
    private const SORTABLE = ['last_seen_at', 'first_seen_at', 'ip_address'];

    private const DEFAULT_SORT = 'last_seen_at';

    private const DEFAULT_ORDER = 'desc';

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * One page of the grid.
     *
     * @param int $user_session_id The user session whose audit sessions are listed
     * @param array $params page, per_page, sort, order, filter
     * @return array {records, page, per_page, total, total_pages, sort, order}
     */
    public static function fetch(int $user_session_id, array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));

        // Clamped, not snapped: 0 is a request for nothing, not for the maximum.
        $per_page = (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
        $per_page = min(max(1, $per_page), self::MAX_PER_PAGE);

        $sort = $params['sort'] ?? self::DEFAULT_SORT;
        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $order = strtolower((string) ($params['order'] ?? self::DEFAULT_ORDER));
        if ($order !== 'asc' && $order !== 'desc') {
            $order = self::DEFAULT_ORDER;
        }

        $query = [
            'user_session_id' => $user_session_id,
            'filter' => trim((string) ($params['filter'] ?? '')),
            'sort' => $sort,
            'order' => $order,
            'limit' => $per_page,
        ];

        $result = Audit_Session::list_page_for_user_session($query + ['offset' => ($page - 1) * $per_page]);
        $total = (int) $result['total'];
        $total_pages = (int) ceil($total / $per_page);

        // A page past the end is the last page: read that one instead.
        $last_page = max(1, $total_pages);
        if ($page > $last_page) {
            $page = $last_page;
            $result = Audit_Session::list_page_for_user_session($query + ['offset' => ($page - 1) * $per_page]);
            $total = (int) $result['total'];
            $total_pages = (int) ceil($total / $per_page);
        }

        $rows = array_map(function ($row) {
            $row['site_id'] = $row['site_id'] ?? $row['portal_site_id'];

            return $row;
        }, $result['rows']);

        $site_names = _Sys_Enum_Words::site_names(array_values(array_unique(array_filter(array_column($rows, 'site_id'), fn ($id) => $id !== null))));

        return [
            'records' => array_map(function ($row) use ($site_names) {
                $row['site_name'] = $row['site_id'] === null ? null : ($site_names[$row['site_id']] ?? null);

                return $row;
            }, $rows),
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
            'sort' => $sort,
            'order' => $order,
        ];
    }
}
