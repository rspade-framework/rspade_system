<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Users;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * One login identity's sign-in history on the user detail screen: its _login_history
 * rows, newest first, EVERY row a page at a time.
 *
 * Login_History::get_history_for_user() answers the newest N; this grid pages the whole
 * table for the identity instead, and presents each row through the same
 * Login_History::present_record(). What the table holds is Login_History's business:
 * successes (and any outcome an application records itself), pruned on
 * rsx.sessions.login_history_retention_days - framework failures are ephemeral counters
 * and never rows.
 *
 * The identity is the fixed request param login_user_id (the grid's $base_params).
 * Search (filter) matches the IP address or the user agent.
 *
 * Each row: Login_History::present_record()'s keys, created_at as ISO UTC.
 */
class _Sys_User_Signins_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static array $sortable_columns = ['id', 'created_at', 'ip_address'];

    protected static ?string $default_sort = 'created_at';

    protected static function __build_query(array $params)
    {
        $query = DB::table('_login_history')
            ->where('login_user_id', (int) ($params['login_user_id'] ?? 0));

        $search = trim((string) ($params['filter'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($where) use ($like) {
                $where->where('ip_address', 'like', $like)->orWhere('user_agent', 'like', $like);
            });
        }

        return $query;
    }

    protected static function __transform_records(array $records, array $params): array
    {
        return array_map(function ($row) {
            $presented = Login_History::present_record((object) $row);
            $presented['id'] = (int) $presented['id'];
            $presented['created_at'] = Rsx_Time::to_iso($presented['created_at']);

            return $presented;
        }, $records);
    }
}
