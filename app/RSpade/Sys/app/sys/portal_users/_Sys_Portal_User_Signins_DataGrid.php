<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\PortalUsers;

use App\RSpade\Core\Session\Portal_Login_History;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\App\Sys\Users\_Sys_User_Signins_DataGrid;

/**
 * One portal user's sign-in history on the portal user detail screen: its
 * _portal_login_history rows, newest first, EVERY row a page at a time.
 *
 * THE SOURCE IS THE PUBLISHED READER, Portal_Login_History::list_page(), not the table:
 * the reader already pages, sorts, searches and presents (present_record()), so this class
 * only translates the panel grid's request into the reader's display-page query and the
 * reader's answer into the grid's response. That is why it does not extend
 * _Sys_DataGrid_Abstract - the base pages a query it builds itself, and here the reader
 * owns the query - while answering exactly the shape the base answers, which is all the
 * JS half (_Sys_DataGrid_Abstract.js) reads.
 *
 * What the table holds is Portal_Login_History's business: successes, post-password
 * refusals (a required password reset), passkey enrollment ceremony outcomes and any
 * outcome an application records itself, for rsx.sessions.login_history_retention_days -
 * sign-in failures are counters, never rows. The portal table records no location.
 *
 * Request: portal_user_id (the grid's $base_params, required), page, per_page, sort, order,
 * filter (the reader matches the email given, the IP address or the user agent).
 * Sorting is fail-closed like the base's: a key the reader does not publish sorts by
 * created_at. per_page is clamped into [1, MAX_PER_PAGE] and a page past the end is the
 * last page.
 *
 * Response: {records, page, per_page, total, total_pages, sort, order}; each record is
 * present_record()'s keys, id as an int, created_at as ISO UTC, plus `tone` - the
 * _Sys_Status_Badge tone of its outcome (_Sys_User_Signins_DataGrid::TONES, the staff
 * grid's map: one vocabulary, one drawing).
 */
class _Sys_Portal_User_Signins_DataGrid
{
    /**
     * The reader's published sort names (Login_History_Abstract::list_page()).
     */
    private const SORTABLE = ['created_at', 'email', 'ip_address', 'status'];

    private const DEFAULT_SORT = 'created_at';

    private const DEFAULT_ORDER = 'desc';

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * One page of the grid.
     *
     * @param array $params portal_user_id, page, per_page, sort, order, filter
     * @return array {records, page, per_page, total, total_pages, sort, order}
     */
    public static function fetch(array $params): array
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
            'portal_user_id' => (int) ($params['portal_user_id'] ?? 0),
            'filter' => trim((string) ($params['filter'] ?? '')),
            'sort' => $sort,
            'order' => $order,
            'limit' => $per_page,
        ];

        $result = Portal_Login_History::list_page($query + ['offset' => ($page - 1) * $per_page]);
        $total = (int) $result['total'];
        $total_pages = (int) ceil($total / $per_page);

        // A page past the end is the last page: read that one instead.
        $last_page = max(1, $total_pages);
        if ($page > $last_page) {
            $page = $last_page;
            $result = Portal_Login_History::list_page($query + ['offset' => ($page - 1) * $per_page]);
            $total = (int) $result['total'];
            $total_pages = (int) ceil($total / $per_page);
        }

        return [
            'records' => array_map(function ($row) {
                $row['id'] = (int) $row['id'];
                $row['created_at'] = Rsx_Time::to_iso($row['created_at']);
                $row['tone'] = _Sys_User_Signins_DataGrid::TONES[$row['status']] ?? 'fail';

                return $row;
            }, $result['rows']),
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages,
            'sort' => $sort,
            'order' => $order,
        ];
    }
}
