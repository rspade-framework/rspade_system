<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Sms\Rsx_Sms;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Email\_Sys_Sms_Controller;

/**
 * The Email & SMS screen's SMS tab endpoints: the queue headline, the queue grid and its
 * filters, and one message's detail (not_found for a missing id).
 *
 * EVERY SITE: the test runs as user 1 on site 1 and plants its rows under a SECOND site
 * created inside the per-test transaction, so a read that narrowed to the developer's own
 * tenant would miss them. Rows are written with DB::table() - the model's creating hook
 * would stamp the acting site.
 */
class Sys_Sms_Controller_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__acting_as_user(1);
    }

    private static function __second_site_id(): int
    {
        $site = new Site_Model();
        $site->slug = 'sys-sms-' . uniqid();
        $site->name = 'Sys Sms Second Site';
        $site->save();

        return (int) $site->id;
    }

    /**
     * Insert an _sms_queue row under $site_id, overridden by $columns.
     */
    private static function __plant(int $site_id, array $columns = []): int
    {
        return (int) DB::table('_sms_queue')->insertGetId(array_merge([
            'site_id' => $site_id,
            'to_number' => '+1555' . random_int(1000000, 9999999),
            'body' => 'Sys sms probe',
            'category_id' => Sms_Queue_Model::CATEGORY_NOTIFICATION,
            'status_id' => Sms_Queue_Model::STATUS_SUPPRESSED,
            'attempt_count' => 0,
            'created_at' => now(),
        ], $columns));
    }

    private static function __endpoint(string $method, array $params = [])
    {
        return _Sys_Sms_Controller::$method(Request::create('/'), $params);
    }

    private static function __grid(array $params): array
    {
        $response = static::__endpoint('datagrid_fetch', array_merge(['per_page' => 100], $params));

        return array_column($response['records'], 'id');
    }

    /**
     * RP-SMS-01 - summary counts every status across ALL sites, names the oldest pending
     * row wherever it lives, and reports Rsx_Sms::delivery_mode().
     */
    public static function test_summary_is_install_wide()
    {
        $before = static::__endpoint('summary');
        $site_id = static::__second_site_id();

        static::__assert_equals(
            ['pending', 'sending', 'sent', 'failed', 'blocked', 'suppressed'],
            array_column($before['counts'], 'status'),
            'one count per status, in the enum order, as words'
        );

        $oldest = static::__plant($site_id, [
            'status_id' => Sms_Queue_Model::STATUS_PENDING,
            'created_at' => '2000-01-01 00:00:00',
        ]);
        static::__plant($site_id, ['status_id' => Sms_Queue_Model::STATUS_FAILED]);

        $after = static::__endpoint('summary');
        $counts_before = array_column($before['counts'], 'count', 'status');
        $counts_after = array_column($after['counts'], 'count', 'status');

        static::__assert_equals($counts_before['pending'] + 1, $counts_after['pending'], 'the second site\'s pending row is counted');
        static::__assert_equals($counts_before['failed'] + 1, $counts_after['failed'], 'and its failed row');
        static::__assert_equals($before['total'] + 2, $after['total'], 'the total follows');
        static::__assert_equals($oldest, $after['oldest_pending']['id'], 'the oldest pending row is found on another site');
        static::__assert_equals(Rsx_Sms::delivery_mode(), $after['delivery_mode'], 'the delivery mode is Rsx_Sms\'s');
    }

    /**
     * RP-SMS-02 - The SMS grid across sites: site / status word / category word filters,
     * unknown words filter nothing, search on to_number, dev_original_to and body (LIKE
     * wildcards literal); rows carry status/category words, site name and the error's
     * first line; site_options lists every site with a row.
     */
    public static function test_grid_filters()
    {
        $site_id = static::__second_site_id();

        $failed = static::__plant($site_id, [
            'status_id' => Sms_Queue_Model::STATUS_FAILED,
            'category_id' => Sms_Queue_Model::CATEGORY_MARKETING,
            'to_number' => '+15550009876',
            'last_error' => "first line\nsecond line",
        ]);
        $suppressed = static::__plant($site_id, [
            'body' => 'Distinctive body xyzzy',
            'dev_original_to' => '+15550001234',
        ]);

        static::__assert_equals([$suppressed, $failed], static::__grid(['site' => (string) $site_id]), 'site narrows to the second site, newest first - it is listed at all only because the grid is cross-site');
        static::__assert_equals([$failed], static::__grid(['site' => (string) $site_id, 'status' => 'failed']), 'status filters by its word');
        static::__assert_equals([$failed], static::__grid(['site' => (string) $site_id, 'category' => 'marketing']), 'category filters by its word');
        static::__assert_equals([$suppressed, $failed], static::__grid(['site' => (string) $site_id, 'status' => 'bogus', 'category' => 'nope']), 'an unknown word filters nothing');

        static::__assert_equals([$failed], static::__grid(['filter' => '+15550009876']), 'search matches the number');
        static::__assert_equals([$suppressed], static::__grid(['filter' => '+15550001234']), 'search matches the original number');
        static::__assert_equals([$suppressed], static::__grid(['filter' => 'xyzzy']), 'search matches the body');
        static::__assert_equals([], static::__grid(['site' => (string) $site_id, 'filter' => '%']), 'a LIKE wildcard in the search is literal');

        $record = static::__endpoint('datagrid_fetch', ['filter' => '+15550009876'])['records'][0];
        static::__assert_equals('failed', $record['status'], 'a row carries its status word');
        static::__assert_equals('marketing', $record['category'], 'and its category word');
        static::__assert_equals('Sys Sms Second Site', $record['site_name'], 'and its site name');
        static::__assert_equals('first line', $record['error_excerpt'], 'and the first line of its error');
        static::__assert_false(array_key_exists('last_error', $record) || array_key_exists('transport_response', $record), 'the list omits the full error and the transport response');

        $sites = array_column(static::__endpoint('site_options'), 'label', 'value');
        static::__assert_equals('#' . $site_id . ' Sys Sms Second Site', $sites[$site_id] ?? null, 'site_options lists the second site');
        static::__assert_equals(
            ['transactional', 'notification', 'marketing'],
            array_column(static::__endpoint('category_options'), 'value'),
            'category_options are the words'
        );
    }

    /**
     * RP-SMS-03 - detail returns another site's row whole with its words and site name; a
     * missing id is not_found.
     */
    public static function test_detail_and_not_found()
    {
        $site_id = static::__second_site_id();
        $id = static::__plant($site_id, [
            'status_id' => Sms_Queue_Model::STATUS_BLOCKED,
            'body' => 'the whole body',
            'last_error' => 'Recipient has opted out of this SMS category',
        ]);

        $sms = static::__endpoint('detail', ['id' => $id])['sms'];

        static::__assert_equals($id, $sms['id'], 'another site\'s row is found');
        static::__assert_equals('blocked', $sms['status'], 'with its status word');
        static::__assert_equals('notification', $sms['category'], 'and its category word');
        static::__assert_equals('Sys Sms Second Site', $sms['site_name'], 'and its site name');
        static::__assert_equals('the whole body', $sms['body'], 'the body rides whole');
        static::__assert_equals('Recipient has opted out of this SMS category', $sms['last_error'], 'and the full error');

        $missing = (int) DB::table('_sms_queue')->max('id') + 1000;
        $response = static::__endpoint('detail', ['id' => $missing]);
        static::__assert_true($response instanceof Error_Response, 'a missing id is an error response');
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $response->get_error_code(), 'and it is not_found');
    }
}
