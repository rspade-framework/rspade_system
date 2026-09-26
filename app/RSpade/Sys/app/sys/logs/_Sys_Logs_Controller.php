<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Logs;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Session\Session;
use App\RSpade\Sys\App\Sys\Logs\_Sys_Log_Reader;
use App\RSpade\Sys\App\Sys\Logs\_Sys_Logs_DataGrid;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Logs screen's endpoints: the file list, a window of one file, and Follow.
 *
 * READ-ONLY: no delete, no download. A file is named by the name the listing gives it
 * and resolved through _Sys_Log_Reader::resolve(), which accepts nothing else - a name
 * that is not a listed file is not_found, whatever it spells.
 *
 * Opening a window (read) is itself logged at debug level with the developer's
 * login_user_id; a Follow poll continues a read already logged and is not, or a
 * followed laravel.log would log its own polling.
 */
#[Auth('is_sysadmin')]
class _Sys_Logs_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The file list grid (_Sys_Logs_DataGrid).
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return _Sys_Logs_DataGrid::fetch($params);
    }

    /**
     * A window of lines of one file: the tail (no before), or the page ending where
     * the previous one started (before = its start).
     *
     * @param array $params file, before (byte offset, optional), lines (default 200,
     *                      at most 1000), filter (text, optional)
     * @return array {file, size_human, modified, ...read_window()}
     */
    #[Ajax_Endpoint]
    public static function read(Request $request, array $params = [])
    {
        $name = (string) ($params['file'] ?? '');
        $path = _Sys_Log_Reader::resolve($name);

        if ($path === null) {
            return response_not_found('No log file by that name.');
        }

        $before = self::__offset($params['before'] ?? null);
        $lines = _Sys_Log_Reader::clamp_lines($params['lines'] ?? null);
        $filter = (string) ($params['filter'] ?? '');

        Log::debug('sys_panel: log read', [
            'file' => $name,
            'before' => $before,
            'lines' => $lines,
            'filter' => $filter,
            'login_user_id' => Session::get_login_user_id(),
        ]);

        $listing = array_column(_Sys_Log_Reader::list_files(), null, 'name')[$name];

        return array_merge(
            ['file' => $name, 'file_size' => $listing['size'], 'size_human' => $listing['size_human'], 'modified' => $listing['modified']],
            _Sys_Log_Reader::read_window($path, $name, $before, $lines, $filter)
        );
    }

    /**
     * Follow: the complete lines appended at or after offset, or {rotated: true} when
     * the file is no longer the one identity describes (the viewer then re-reads the
     * tail). A .gz is not followed.
     *
     * @param array $params file, offset, identity (as read answered it), lines, filter
     * @return array read_forward()
     */
    #[Ajax_Endpoint]
    public static function follow(Request $request, array $params = [])
    {
        $name = (string) ($params['file'] ?? '');
        $path = _Sys_Log_Reader::resolve($name);

        if ($path === null) {
            return response_not_found('No log file by that name.');
        }

        if (str_ends_with($name, '.gz')) {
            return response_error(Ajax::ERROR_VALIDATION, 'A compressed log is not followed.');
        }

        $offset = self::__offset($params['offset'] ?? null);
        $identity = $params['identity'] ?? null;

        if ($offset === null || !is_array($identity)) {
            return response_error(Ajax::ERROR_VALIDATION, 'Follow needs the offset and identity a read answered.');
        }

        return _Sys_Log_Reader::read_forward(
            $path,
            $name,
            $offset,
            $identity,
            _Sys_Log_Reader::clamp_lines($params['lines'] ?? null),
            (string) ($params['filter'] ?? '')
        );
    }

    /**
     * A byte offset param: a non-negative integer, or null when absent.
     */
    private static function __offset($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
