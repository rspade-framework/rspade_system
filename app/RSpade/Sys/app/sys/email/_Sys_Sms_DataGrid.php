<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Email;

use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Sys\Lib\_Sys_Enum_Words;
use App\RSpade\Sys\Theme\Components\_Sys_DataGrid_Abstract;

/**
 * The SMS tab's queue grid: every _sms_queue row on the install, newest first.
 *
 * The caller (_Sys_Sms_Controller::datagrid_fetch) runs fetch() outside the site scope,
 * so every tenant's rows are here.
 *
 * Filters (each a top-level request param, declared on _Sys_Sms_DataGrid.jqhtml):
 *   status   - a status word (pending, sending, sent, failed, blocked, suppressed)
 *   category - a category word (transactional, notification, marketing)
 *   site     - one site id
 * An unknown word or a non-numeric site filters nothing.
 * Search (filter) matches the recipient - the number and, on a dev host, the number the
 * message was really for (dev_original_to) - or the body, as a substring.
 *
 * The list payload carries the body (an SMS is short, and it is what the row is) but
 * not the transport response - the detail screen reads that.
 */
class _Sys_Sms_DataGrid extends _Sys_DataGrid_Abstract
{
    protected static array $sortable_columns = ['id', 'created_at', 'sent_at', 'last_attempt_at', 'attempt_count'];

    private const LIST_COLUMNS = [
        'id', 'site_id', 'to_number', 'dev_original_to', 'body', 'category_id', 'status_id',
        'attempt_count', 'last_error', 'last_attempt_at', 'sent_at', 'created_at',
    ];

    protected static function __build_query(array $params)
    {
        $query = Sms_Queue_Model::query()->select(self::LIST_COLUMNS);

        $status_id = _Sys_Enum_Words::enum_value(Sms_Queue_Model::class, 'status_id', (string) ($params['status'] ?? ''));
        if ($status_id !== null) {
            $query->where('status_id', $status_id);
        }

        $category_id = _Sys_Enum_Words::enum_value(Sms_Queue_Model::class, 'category_id', (string) ($params['category'] ?? ''));
        if ($category_id !== null) {
            $query->where('category_id', $category_id);
        }

        $site = (string) ($params['site'] ?? '');
        if (ctype_digit($site)) {
            $query->where('site_id', (int) $site);
        }

        $search = trim((string) ($params['filter'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($where) use ($like) {
                $where->where('to_number', 'like', $like)
                    ->orWhere('dev_original_to', 'like', $like)
                    ->orWhere('body', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Each row gains its status and category words, its site's name, and the first line
     * of its last error.
     */
    protected static function __transform_records(array $records, array $params): array
    {
        $names = _Sys_Enum_Words::site_names(array_values(array_unique(array_column($records, 'site_id'))));

        return array_map(function ($row) use ($names) {
            $row['status'] = _Sys_Enum_Words::enum_word(Sms_Queue_Model::class, 'status_id', (int) $row['status_id']);
            $row['category'] = _Sys_Enum_Words::enum_word(Sms_Queue_Model::class, 'category_id', (int) $row['category_id']);
            $row['site_name'] = $names[$row['site_id']] ?? null;
            $row['error_excerpt'] = _Sys_Enum_Words::first_line($row['last_error']);
            unset($row['last_error']);

            return $row;
        }, $records);
    }
}
