<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Users;

use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\Lib\_Sys_Enum_Words;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * The Users screen's grid: every login identity on the install (login_users rows not
 * soft-deleted), newest first.
 *
 * A login identity is CROSS-SITE - one email and password, reaching any number of site
 * memberships (users rows). The source is Login_User_Model's query handed on as its base
 * Query\Builder (toBase() applies SoftDeletes), so the row carries exactly the columns
 * listed below and nothing a model's toArray() would add or drop.
 *
 * Filters (each a top-level request param, declared on _Sys_Users_DataGrid.jqhtml):
 *   status    - a status WORD ('active' | 'inactive' | 'suspended', the enum labels
 *               lowercased); anything else filters nothing
 *   developer - 'yes' | 'no' (login_users.is_developer)
 * Search (filter) matches the email, as a substring.
 *
 * Each row: id, email, status_id, status (the word), status_label, is_developer,
 * is_verified, is_activated, last_login and created_at (ISO UTC), membership_count (live
 * users rows, enabled or not, counted for the whole page in ONE grouped query).
 *
 * @DB-UNBOUNDED-01-EXCEPTION - membership_counts() plucks one grouped row per login
 * identity id it is handed (a grid page), never the users table.
 */
class _Sys_Users_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static array $sortable_columns = ['id', 'email', 'status_id', 'last_login', 'created_at'];

    protected static function __build_query(array $params)
    {
        $query = Login_User_Model::query()
            ->select(['id', 'email', 'status_id', 'is_developer', 'is_verified', 'is_activated', 'last_login', 'created_at'])
            ->toBase();

        $status_id = _Sys_Enum_Words::enum_value(Login_User_Model::class, 'status_id', (string) ($params['status'] ?? ''));
        if ($status_id !== null) {
            $query->where('status_id', $status_id);
        }

        $developer = (string) ($params['developer'] ?? '');
        if ($developer === 'yes' || $developer === 'no') {
            $query->where('is_developer', $developer === 'yes');
        }

        $search = trim((string) ($params['filter'] ?? ''));
        if ($search !== '') {
            $query->where('email', 'like', '%' . addcslashes($search, '%_\\') . '%');
        }

        return $query;
    }

    protected static function __transform_records(array $records, array $params): array
    {
        $counts = static::membership_counts(array_map('intval', array_column($records, 'id')));

        return array_map(function ($row) use ($counts) {
            $row['id'] = (int) $row['id'];
            $row['status_id'] = (int) $row['status_id'];
            $row['status'] = _Sys_Enum_Words::enum_word(Login_User_Model::class, 'status_id', $row['status_id']);
            $row['status_label'] = Login_User_Model::$enums['status_id'][$row['status_id']]['label'];
            $row['is_developer'] = (bool) $row['is_developer'];
            $row['is_verified'] = (bool) $row['is_verified'];
            $row['is_activated'] = (bool) $row['is_activated'];
            $row['last_login'] = Rsx_Time::to_iso($row['last_login']);
            $row['created_at'] = Rsx_Time::to_iso($row['created_at']);
            $row['membership_count'] = $counts[$row['id']] ?? 0;

            return $row;
        }, $records);
    }

    /**
     * Live memberships per login identity: users rows that are not soft-deleted, whatever
     * their own switch or their site's. users is site-scoped and every site is counted,
     * so the read runs in without_site_scope() - the developer's own session site
     * narrows nothing.
     *
     * @param int[] $login_user_ids
     * @return array<int, int> login_user_id => count; an identity with none is absent
     */
    public static function membership_counts(array $login_user_ids): array
    {
        if ($login_user_ids === []) {
            return [];
        }

        // Bounded: one grouped row per login identity id handed in.
        return User_Model::without_site_scope(fn () => User_Model::query()
            ->whereIn('login_user_id', $login_user_ids)
            ->groupBy('login_user_id')
            ->selectRaw('login_user_id, COUNT(*) AS membership_count')
            ->pluck('membership_count', 'login_user_id')
            ->map(fn ($count) => (int) $count)
            ->all());
    }
}
