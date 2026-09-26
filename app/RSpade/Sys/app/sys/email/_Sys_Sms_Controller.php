<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Email;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Sms\Rsx_Sms;
use App\RSpade\Sys\App\Sys\Email\_Sys_Sms_DataGrid;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;
use App\RSpade\Sys\Lib\_Sys_Enum_Words;

/**
 * The Email & SMS screen's SMS tab: the SMS queue's headline numbers, the queue grid and
 * its filter options, and one message whole.
 *
 * READ-ONLY. The SMS queue has no resend contract (there is no SMS provider to hand a
 * row to), so nothing here writes.
 *
 * EVERY SITE, as _Sys_Email_Controller: every read runs inside
 * Sms_Queue_Model::without_site_scope(). Status and category travel as the same
 * lowercase words the mail queue uses (_Sys_Enum_Words), so a link addresses a
 * filtered grid as Rsx.Route('_Sys_Email_Action', {}, {tab: 'sms', sms_f_status: 'failed'}).
 */
#[Auth('is_sysadmin')]
class _Sys_Sms_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The SMS queue's headline: a count per status, the oldest pending row and the
     * delivery mode.
     *
     * @return array {counts: [{status, label, count}], total, oldest_pending: null|{id,
     *                created_at, to_number}, delivery_mode}
     */
    #[Ajax_Endpoint]
    public static function summary(Request $request, array $params = [])
    {
        return Sms_Queue_Model::without_site_scope(function () {
            $counts = _Sys_Enum_Words::status_tiles(Sms_Queue_Model::class, Sms_Queue_Model::status_counts());
            $oldest = Sms_Queue_Model::oldest_pending();

            return [
                'counts' => $counts,
                'total' => array_sum(array_column($counts, 'count')),
                'oldest_pending' => $oldest === null ? null : [
                    'id' => (int) $oldest->id,
                    'created_at' => $oldest->created_at,
                    'to_number' => $oldest->to_number,
                ],
                'delivery_mode' => Rsx_Sms::delivery_mode(),
            ];
        });
    }

    /**
     * The SMS queue grid (_Sys_Sms_DataGrid).
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Sms_Queue_Model::without_site_scope(fn () => _Sys_Sms_DataGrid::fetch($params));
    }

    /**
     * The status filter's options, in the enum's order.
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function status_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::enum_options(Sms_Queue_Model::class, 'status_id');
    }

    /**
     * The category filter's options, in the enum's order.
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function category_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::enum_options(Sms_Queue_Model::class, 'category_id');
    }

    /**
     * The site filter's options: every site that has an SMS queue row.
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function site_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::site_options(Sms_Queue_Model::class);
    }

    /**
     * One SMS, whole: every column plus its status and category words and its site's
     * name.
     *
     * @param array $params id
     * @return array {sms: {...}}
     */
    #[Ajax_Endpoint]
    public static function detail(Request $request, array $params = [])
    {
        return Sms_Queue_Model::without_site_scope(function () use ($params) {
            $record = Sms_Queue_Model::find((int) ($params['id'] ?? 0));

            if ($record === null) {
                return response_not_found('No SMS queue row with that id.');
            }

            $site_id = (int) $record->site_id;
            $sms = $record->toArray();

            $sms['status'] = _Sys_Enum_Words::enum_word(Sms_Queue_Model::class, 'status_id', (int) $record->status_id);
            $sms['category'] = _Sys_Enum_Words::enum_word(Sms_Queue_Model::class, 'category_id', (int) $record->category_id);
            $sms['site_name'] = _Sys_Enum_Words::site_names([$site_id])[$site_id] ?? null;

            return ['sms' => $sms];
        });
    }
}
