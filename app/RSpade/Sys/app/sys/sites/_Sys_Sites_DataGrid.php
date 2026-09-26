<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Sites;

use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * The Sites screen's grid: every tenant on the install, newest first.
 *
 * Site 0 is never listed. It is the Default site - the FK target of sessionless (CLI,
 * task) site-scoped writes, not a tenant (Site_Model_Abstract::booted()). Soft-deleted
 * sites are not listed either: the model's SoftDeletes scope hides them.
 *
 * Filters (each a top-level request param, declared on _Sys_Sites_DataGrid.jqhtml):
 *   enabled - 'enabled' | 'disabled' (sites.is_enabled); anything else filters nothing
 * Search (filter) matches the name or the slug, as a substring.
 *
 * Each row gains member_count: its live memberships (users rows not soft-deleted),
 * counted for the whole page in ONE grouped query; created_at leaves as ISO UTC.
 *
 * @DB-UNBOUNDED-01-EXCEPTION - member_counts() plucks one grouped row per site id it is
 * handed (a grid page, or one site), never the users table.
 */
class _Sys_Sites_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static array $sortable_columns = ['id', 'name', 'slug', 'created_at'];

    protected static function __build_query(array $params)
    {
        $query = Site_Model::query()
            ->select(['id', 'name', 'slug', 'is_enabled', 'timezone', 'created_at'])
            ->where('id', '!=', 0);

        $enabled = (string) ($params['enabled'] ?? '');
        if ($enabled === 'enabled' || $enabled === 'disabled') {
            $query->where('is_enabled', $enabled === 'enabled');
        }

        $search = trim((string) ($params['filter'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($where) use ($like) {
                $where->where('name', 'like', $like)->orWhere('slug', 'like', $like);
            });
        }

        return $query;
    }

    protected static function __transform_records(array $records, array $params): array
    {
        $counts = static::member_counts(array_column($records, 'id'));

        return array_map(function ($row) use ($counts) {
            $row['member_count'] = $counts[$row['id']] ?? 0;
            $row['created_at'] = Rsx_Time::to_iso($row['created_at']);

            return $row;
        }, $records);
    }

    /**
     * Live memberships per site: users rows that are not soft-deleted, whatever their
     * own switch. users is site-scoped and every tenant is counted, so the read runs in
     * without_site_scope() - the developer's own session site narrows nothing.
     *
     * @param int[] $site_ids
     * @return array<int, int> site_id => count; a site with none is absent
     */
    public static function member_counts(array $site_ids): array
    {
        if ($site_ids === []) {
            return [];
        }

        // Bounded: one grouped row per site id handed in.
        return User_Model::without_site_scope(fn () => User_Model::query()
            ->whereIn('site_id', $site_ids)
            ->groupBy('site_id')
            ->selectRaw('site_id, COUNT(*) AS member_count')
            ->pluck('member_count', 'site_id')
            ->map(fn ($count) => (int) $count)
            ->all());
    }
}
