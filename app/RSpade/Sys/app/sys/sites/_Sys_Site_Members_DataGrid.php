<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Sites;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * One site's members on the site detail screen: its live memberships (users rows not
 * soft-deleted), each beside the login identity it belongs to.
 *
 * The site is the fixed request param site_id (the grid's $base_params). users is
 * site-scoped and the panel reads any tenant's, so the source is User_Model's query
 * built inside without_site_scope() and handed on as its base Query\Builder (toBase()
 * applies the model's scopes then - SoftDeletes stays, the session's site does not): a
 * join whose rows are plain arrays carrying exactly the selected columns.
 *
 * @DB-UNBOUNDED-01-EXCEPTION - the one whole-set read here (__transform_records) is
 * narrowed to the ids of the page being rendered, at most $max_per_page rows.
 *
 * Filters (declared on _Sys_Site_Members_DataGrid.jqhtml):
 *   enabled - 'enabled' | 'disabled' (the membership's own users.is_enabled)
 * Search (filter) matches the identity's email, the membership's email or its name.
 *
 * Each row: id (the membership), login_user_id (null for an invitation not yet
 * accepted), email (the identity's, else the membership's), first_name, last_name,
 * role_id + role_label, is_enabled (the membership's switch), is_active (User_Model's
 * scopeActive() - the framework's one definition, which also needs the SITE enabled),
 * last_login (the identity's, ISO UTC).
 */
class _Sys_Site_Members_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static array $sortable_columns = ['id', 'email', 'role_id', 'last_login'];

    protected static ?string $default_sort = 'role_id';

    protected static string $default_order = 'asc';

    protected static ?string $secondary_sort = 'id';

    protected static string $secondary_order = 'asc';

    protected static function __build_query(array $params)
    {
        $query = User_Model::without_site_scope(fn () => User_Model::query()
            ->leftJoin('login_users', 'login_users.id', '=', 'users.login_user_id')
            ->where('users.site_id', (int) ($params['site_id'] ?? 0))
            ->select([
                'users.id', 'users.login_user_id', 'users.first_name', 'users.last_name',
                'users.role_id', 'users.is_enabled', 'login_users.last_login',
                DB::raw('COALESCE(login_users.email, users.email) AS email'),
            ])
            ->toBase());

        $enabled = (string) ($params['enabled'] ?? '');
        if ($enabled === 'enabled' || $enabled === 'disabled') {
            $query->where('users.is_enabled', $enabled === 'enabled');
        }

        $search = trim((string) ($params['filter'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($where) use ($like) {
                $where->where('login_users.email', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhere('users.first_name', 'like', $like)
                    ->orWhere('users.last_name', 'like', $like);
            });
        }

        return $query;
    }

    protected static function __map_sort_column(string $key): string
    {
        return match ($key) {
            'email' => 'email',
            'last_login' => 'login_users.last_login',
            default => 'users.' . $key,
        };
    }

    protected static function __transform_records(array $records, array $params): array
    {
        $ids = array_map('intval', array_column($records, 'id'));
        // Bounded: narrowed to this page's ids.
        $active = $ids === [] ? [] : array_flip(User_Model::without_site_scope(
            fn () => User_Model::query()->active()->whereIn('users.id', $ids)->pluck('users.id')->map(fn ($id) => (int) $id)->all()
        ));
        $roles = User_Model::role_id__enum();

        return array_map(function ($row) use ($active, $roles) {
            $row['id'] = (int) $row['id'];
            $row['login_user_id'] = $row['login_user_id'] === null ? null : (int) $row['login_user_id'];
            $row['role_id'] = (int) $row['role_id'];
            $row['role_label'] = $roles[$row['role_id']]['label'] ?? ('Role ' . $row['role_id']);
            $row['is_enabled'] = (bool) $row['is_enabled'];
            $row['is_active'] = isset($active[$row['id']]);
            $row['last_login'] = Rsx_Time::to_iso($row['last_login']);

            return $row;
        }, $records);
    }
}
