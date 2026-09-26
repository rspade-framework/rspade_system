<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Tasks;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Sys\App\Sys\Tasks\_Sys_Tasks_Controller;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * The Tasks screen's history grid: every _tasks row, newest first.
 *
 * Filters (each a top-level request param, declared on _Sys_Tasks_DataGrid.jqhtml):
 *   status - one Task_Status value; anything else is ignored
 *   class  - one fully-qualified task class
 *   since  - a FINISHED-within window over completed_at: 1h | 24h | 7d | 30d. It dates
 *            a row the way the Dashboard's failed-tasks tile does, so status=failed +
 *            since=24h is exactly that tile's count.
 * Search (filter) matches class or method, as a substring.
 *
 * The list payload omits params, result, logs and error - the detail screen reads them.
 */
class _Sys_Tasks_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static array $sortable_columns = ['id', 'started_at', 'completed_at'];

    /** since value => how far back it reaches. */
    public const SINCE_WINDOWS = [
        '1h' => 'PT1H',
        '24h' => 'P1D',
        '7d' => 'P7D',
        '30d' => 'P30D',
    ];

    private const STATUSES = [
        Task_Status::PENDING, Task_Status::RUNNING, Task_Status::COMPLETED,
        Task_Status::FAILED, Task_Status::KILLED,
    ];

    protected static function __build_query(array $params)
    {
        $query = DB::table('_tasks')->select([
            'id', 'class', 'method', 'queue', 'status', 'status_reason', 'next_run_at',
            'started_at', 'completed_at', 'created_at', 'consecutive_failures',
        ]);

        $status = (string) ($params['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        $class = (string) ($params['class'] ?? '');
        if ($class !== '') {
            $query->where('class', $class);
        }

        $since = (string) ($params['since'] ?? '');
        if (isset(self::SINCE_WINDOWS[$since])) {
            $query->where('completed_at', '>=', now()->sub(new \DateInterval(self::SINCE_WINDOWS[$since])));
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
        return array_map(fn ($row) => _Sys_Tasks_Controller::present_row($row), $records);
    }
}
