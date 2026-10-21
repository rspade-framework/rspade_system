<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\PortalUsers;

use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\Lib\_Sys_Enum_Words;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * The Portal Users screen's grid: every portal user on the install (portal_users rows not
 * soft-deleted), on every site, newest first.
 *
 * portal_users is site-scoped, so the source is Portal_User_Model's query built inside
 * without_site_scope() and handed on as its base Query\Builder (toBase() applies the scopes
 * there and then - SoftDeletes stays, the site scope does not). The row carries exactly
 * the columns listed below; the password and the metadata bag are never selected.
 *
 * Filters (each a top-level request param, declared on _Sys_Portal_Users_DataGrid.jqhtml):
 *   status - a status WORD ('active' | 'inactive' | 'suspended', the enum labels
 *            lowercased); anything else filters nothing
 *   site   - a site id; anything that is not a positive integer filters nothing
 * Search (filter) matches the email, as a substring.
 *
 * Each row: id, site_id, site_name, email, printed_name (Portal_User_Model::
 * get_printed_name() - the email unless the application's override names the person),
 * status_id, status (the word), status_label, is_verified, password_reset_required,
 * last_login and created_at (ISO UTC).
 *
 * @DB-UNBOUNDED-01-EXCEPTION - __transform_records() loads the models of ONE grid page,
 * by id, for their printed names.
 */
class _Sys_Portal_Users_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static array $sortable_columns = ['id', 'email', 'site_id', 'status_id', 'last_login', 'created_at'];

    protected static function __build_query(array $params)
    {
        $query = Portal_User_Model::without_site_scope(fn () => Portal_User_Model::query()
            ->select(['id', 'site_id', 'email', 'status_id', 'is_verified', 'password_reset_required', 'last_login', 'created_at'])
            ->toBase());

        $status_id = _Sys_Enum_Words::enum_value(Portal_User_Model::class, 'status_id', (string) ($params['status'] ?? ''));
        if ($status_id !== null) {
            $query->where('status_id', $status_id);
        }

        $site = (string) ($params['site'] ?? '');
        if (ctype_digit($site) && (int) $site > 0) {
            $query->where('site_id', (int) $site);
        }

        $search = trim((string) ($params['filter'] ?? ''));
        if ($search !== '') {
            $query->where('email', 'like', '%' . addcslashes($search, '%_\\') . '%');
        }

        return $query;
    }

    protected static function __transform_records(array $records, array $params): array
    {
        $ids = array_map('intval', array_column($records, 'id'));
        $site_names = _Sys_Enum_Words::site_names(array_values(array_unique(array_map('intval', array_column($records, 'site_id')))));

        // Bounded: the page's own portal users, by id.
        $models = $ids === [] ? collect() : Portal_User_Model::without_site_scope(
            fn () => Portal_User_Model::query()->whereIn('id', $ids)->get()->keyBy('id')
        );

        return array_map(function ($row) use ($site_names, $models) {
            $row['id'] = (int) $row['id'];
            $row['site_id'] = (int) $row['site_id'];
            $row['site_name'] = $site_names[$row['site_id']] ?? null;
            $row['printed_name'] = isset($models[$row['id']]) ? $models[$row['id']]->get_printed_name() : $row['email'];
            $row['status_id'] = (int) $row['status_id'];
            $row['status'] = _Sys_Enum_Words::enum_word(Portal_User_Model::class, 'status_id', $row['status_id']);
            $row['status_label'] = Portal_User_Model::$enums['status_id'][$row['status_id']]['label'];
            $row['is_verified'] = (bool) $row['is_verified'];
            $row['password_reset_required'] = (bool) $row['password_reset_required'];
            $row['last_login'] = Rsx_Time::to_iso($row['last_login']);
            $row['created_at'] = Rsx_Time::to_iso($row['created_at']);

            return $row;
        }, $records);
    }
}
