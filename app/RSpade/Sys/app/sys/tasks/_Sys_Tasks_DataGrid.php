<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Tasks;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Sys\App\Sys\Tasks\_Sys_Tasks_Controller;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * The Tasks screen's grid: every run, active ones on top - running runs (oldest start
 * first), then queued runs (earliest due first) - and finished runs below them, newest
 * first.
 *
 * That order is one computed key (lifecycle_order), the only order the grid offers:
 *   0<started_at><id>              running
 *   1<scheduled_for><id>           queued
 *   2<inverted finish time><id>    finished (completed, failed, stopped, killed, cancelled)
 *
 * Filters (each a top-level request param, declared on _Sys_Tasks_DataGrid.jqhtml):
 *   scope  - active (running + queued) | completed (every finished status); blank is both
 *   status - one status id
 *   class  - one fully-qualified task class
 *   origin - dispatched | scheduled | inline (the id)
 *   since  - FINISHED within 1h | 24h | 7d | 30d, by completed_at. It dates a run the way
 *            the Dashboard's failed-tasks tile does, so status=failed + since=24h is exactly
 *            that tile's count.
 * Search (filter) matches class or method, as a substring.
 *
 * @DB-UNBOUNDED-01-EXCEPTION - present_rows() loads the runs of ONE grid page, by id.
 */
class _Sys_Tasks_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static ?string $default_sort = 'lifecycle_order';
    protected static string $default_order = 'asc';
    protected static array $sortable_columns = [];
    protected static ?string $secondary_sort = null;

    /** since value => how far back it reaches. */
    public const SINCE_WINDOWS = [
        '1h' => 'PT1H',
        '24h' => 'P1D',
        '7d' => 'P7D',
        '30d' => 'P30D',
    ];

    protected static function __build_query(array $params)
    {
        // The status ids are written into the expression, not bound: the grid's count query
        // drops the select list but would keep a select binding, shifting every later one.
        $running = (int) Task_Run_Model::STATUS_RUNNING;
        $pending = (int) Task_Run_Model::STATUS_PENDING;

        $query = DB::table('_tasks')
            ->select(['id'])
            ->selectRaw(
                "CASE status_id"
                . " WHEN {$running} THEN CONCAT('0', DATE_FORMAT(COALESCE(started_at, created_at), '%Y%m%d%H%i%s%f'), LPAD(id, 20, '0'))"
                . " WHEN {$pending} THEN CONCAT('1', DATE_FORMAT(COALESCE(scheduled_for, created_at), '%Y%m%d%H%i%s%f'), LPAD(id, 20, '0'))"
                . " ELSE CONCAT('2', LPAD(99999999999999 - FLOOR(UNIX_TIMESTAMP(COALESCE(completed_at, created_at)) * 1000), 14, '0'), LPAD(999999999999999999 - id, 20, '0'))"
                . " END AS lifecycle_order"
            );

        $scope = (string) ($params['scope'] ?? '');
        if ($scope === 'active') {
            $query->whereIn('status_id', Task_Run_Model::LIVE_STATUSES);
        } elseif ($scope === 'completed') {
            $query->whereNotIn('status_id', Task_Run_Model::LIVE_STATUSES);
        }

        $status = (int) ($params['status'] ?? 0);
        if (isset(Task_Run_Model::$enums['status_id'][$status])) {
            $query->where('status_id', $status);
        }

        $origin = (int) ($params['origin'] ?? 0);
        if (isset(Task_Run_Model::$enums['origin_id'][$origin])) {
            $query->where('origin_id', $origin);
        }

        $since = (string) ($params['since'] ?? '');
        if (isset(self::SINCE_WINDOWS[$since])) {
            $query->where('completed_at', '>=', now()->sub(new \DateInterval(self::SINCE_WINDOWS[$since])));
        }

        $class = (string) ($params['class'] ?? '');
        if ($class !== '') {
            $query->where('class', $class);
        }

        $search = trim((string) ($params['filter'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($where) use ($like) {
                $where->where('class', 'like', $like)->orWhere('method', 'like', $like);
            });
        }

        return $query;
    }

    protected static function __transform_records(array $records, array $params): array
    {
        return static::present_rows($records);
    }

    /**
     * A page of grid rows (each carrying at least its id) as the panel presents a run, in the
     * page's order.
     *
     * @param array $records
     * @return array
     */
    public static function present_rows(array $records): array
    {
        $ids = array_map(fn ($row) => (int) $row['id'], $records);
        $tasks = Task_Run_Model::whereIn('id', $ids)->get()->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            if (isset($tasks[$id])) {
                $rows[] = _Sys_Tasks_Controller::present($tasks[$id], false);
            }
        }

        return $rows;
    }
}
