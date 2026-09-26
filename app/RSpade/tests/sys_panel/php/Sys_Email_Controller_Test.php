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
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Email\_Sys_Email_Controller;

/**
 * The Email screen's endpoints: the queue headline, the queue grid and its filters, one
 * message's detail (preview wrapping, the development-catcher lookup, not_found) and
 * Resend with the rules of rsx:mail:resend.
 *
 * EVERY SITE: the test runs as user 1 on site 1 and plants its rows under a SECOND site
 * created inside the per-test transaction, so a read that narrowed to the developer's own
 * tenant would miss them. Rows are written with DB::table() - the model's creating hook
 * would stamp the acting site. The catcher lookup reads a Maildir the test builds in a
 * scratch directory, with rsx.mail.catcher_maildir pointed at it; the live catcher is not
 * touched. Resend's drain kick is skipped under the test runner (Rsx_Mail::_kick_drain()),
 * so the row is asserted PENDING, never sent.
 */
class Sys_Email_Controller_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__acting_as_user(1);
    }

    private static function __second_site_id(): int
    {
        $site = new Site_Model();
        $site->slug = 'sys-email-' . uniqid();
        $site->name = 'Sys Email Second Site';
        $site->save();

        return (int) $site->id;
    }

    /**
     * Insert an _email_queue row under $site_id, overridden by $columns.
     */
    private static function __plant(int $site_id, array $columns = []): int
    {
        return (int) DB::table('_email_queue')->insertGetId(array_merge([
            'site_id' => $site_id,
            'to_address' => 'sys-email-' . uniqid() . '@example.com',
            'subject' => 'Sys email probe',
            'email_class' => 'Sys_Email_Probe_Email',
            'category_id' => Email_Queue_Model::CATEGORY_NOTIFICATION,
            'status_id' => Email_Queue_Model::STATUS_SENT,
            'attempt_count' => 1,
            'created_at' => now(),
        ], $columns));
    }

    private static function __endpoint(string $method, array $params = [])
    {
        return _Sys_Email_Controller::$method(Request::create('/'), $params);
    }

    private static function __grid(array $params): array
    {
        $response = static::__endpoint('datagrid_fetch', array_merge(['per_page' => 100], $params));

        return array_column($response['records'], 'id');
    }

    private static function __assert_error(string $code, $response, string $message): void
    {
        static::__assert_true($response instanceof Error_Response, "{$message}: expected an error response");
        static::__assert_equals($code, $response->get_error_code(), $message);
    }

    private static function __row(int $id): object
    {
        return DB::table('_email_queue')->where('id', $id)->first();
    }

    /**
     * RP-EMAIL-01 - summary counts every status across ALL sites, names the oldest
     * pending row wherever it lives, and reports the delivery mode and transport.
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
            'status_id' => Email_Queue_Model::STATUS_PENDING,
            'created_at' => '2000-01-01 00:00:00',
        ]);
        static::__plant($site_id, ['status_id' => Email_Queue_Model::STATUS_FAILED]);

        $after = static::__endpoint('summary');
        $counts_before = array_column($before['counts'], 'count', 'status');
        $counts_after = array_column($after['counts'], 'count', 'status');

        static::__assert_equals($counts_before['pending'] + 1, $counts_after['pending'], 'the second site\'s pending row is counted');
        static::__assert_equals($counts_before['failed'] + 1, $counts_after['failed'], 'and its failed row');
        static::__assert_equals($before['total'] + 2, $after['total'], 'the total follows');
        static::__assert_equals($oldest, $after['oldest_pending']['id'], 'the oldest pending row is found on another site');
        static::__assert_not_empty($after['delivery_mode'], 'the delivery mode is reported');
        static::__assert_not_empty($after['transport'], 'and the transport description');
    }

    /**
     * RP-EMAIL-02 - The queue grid across sites: status, category and site filters by
     * word/id, search on to_address / dev_original_to / subject (LIKE wildcards literal),
     * unknown words filter nothing, the list omits the bodies; site_options lists every
     * site with a row.
     */
    public static function test_grid_filters()
    {
        $site_id = static::__second_site_id();

        $failed = static::__plant($site_id, [
            'status_id' => Email_Queue_Model::STATUS_FAILED,
            'category_id' => Email_Queue_Model::CATEGORY_MARKETING,
            'to_address' => 'distinctive-recipient@example.com',
            'last_error' => "first line\nsecond line",
            'rendered_html' => '<p>body</p>',
        ]);
        $sent = static::__plant($site_id, [
            'subject' => 'Distinctive subject xyzzy',
            'dev_original_to' => 'really-for-someone@example.com',
        ]);

        $ids = static::__grid(['site' => (string) $site_id]);
        static::__assert_equals([$sent, $failed], $ids, 'site narrows to the second site, newest first - it is listed at all only because the grid is cross-site');

        static::__assert_equals([$failed], static::__grid(['site' => (string) $site_id, 'status' => 'failed']), 'status filters by its word');
        static::__assert_equals([$failed], static::__grid(['site' => (string) $site_id, 'category' => 'marketing']), 'category filters by its word');
        static::__assert_equals([$sent, $failed], static::__grid(['site' => (string) $site_id, 'status' => 'bogus', 'category' => 'nope']), 'an unknown word filters nothing');

        static::__assert_equals([$failed], static::__grid(['filter' => 'distinctive-recipient']), 'search matches the recipient');
        static::__assert_equals([$sent], static::__grid(['filter' => 'really-for-someone']), 'search matches the original recipient');
        static::__assert_equals([$sent], static::__grid(['filter' => 'xyzzy']), 'search matches the subject');
        static::__assert_equals([], static::__grid(['site' => (string) $site_id, 'filter' => '%']), 'a LIKE wildcard in the search is literal');

        $record = static::__endpoint('datagrid_fetch', ['filter' => 'distinctive-recipient'])['records'][0];
        static::__assert_equals('failed', $record['status'], 'a row carries its status word');
        static::__assert_equals('marketing', $record['category'], 'and its category word');
        static::__assert_equals('Sys Email Second Site', $record['site_name'], 'and its site name');
        static::__assert_equals('first line', $record['error_excerpt'], 'and the first line of its error');
        static::__assert_false(array_key_exists('rendered_html', $record) || array_key_exists('last_error', $record), 'the list omits the bodies and the full error');

        $sites = array_column(static::__endpoint('site_options'), 'label', 'value');
        static::__assert_equals('#' . $site_id . ' Sys Email Second Site', $sites[$site_id] ?? null, 'site_options lists the second site');
        static::__assert_equals(
            ['pending', 'sending', 'sent', 'failed', 'blocked', 'suppressed'],
            array_column(static::__endpoint('status_options'), 'value'),
            'status_options are the words'
        );
    }

    /**
     * RP-EMAIL-03 - detail returns another site's row whole: the HTML wrapped with the
     * preview CSP, the plain text, what may be done to it; a missing id is not_found.
     */
    public static function test_detail_and_not_found()
    {
        $site_id = static::__second_site_id();
        $id = static::__plant($site_id, [
            'status_id' => Email_Queue_Model::STATUS_BLOCKED,
            'rendered_html' => '<html><head><title>t</title></head><body><img src="https://example.com/pixel.gif"></body></html>',
            'rendered_text' => 'plain body',
        ]);

        $email = static::__endpoint('detail', ['id' => $id])['email'];

        static::__assert_equals($id, $email['id'], 'another site\'s row is found');
        static::__assert_equals('blocked', $email['status'], 'with its status word');
        static::__assert_equals('Sys Email Second Site', $email['site_name'], 'and its site name');
        static::__assert_equals('plain body', $email['rendered_text'], 'the text body rides whole');
        static::__assert_false(array_key_exists('rendered_html', $email), 'the raw HTML is replaced by the preview document');
        static::__assert_true($email['is_rendered'], 'a rendered row says so');
        static::__assert_contains(
            '<head><meta http-equiv="Content-Security-Policy" content="' . _Sys_Email_Controller::PREVIEW_CSP . '">',
            $email['preview_html'],
            'the preview declares its CSP first thing in <head>'
        );
        static::__assert_true($email['can_resend'] && $email['resend_needs_force'], 'a blocked row may be resent, with force');
        static::__assert_equals([], $email['attachments'], 'attachments are listed, even when there are none');

        static::__assert_true(
            str_starts_with(_Sys_Email_Controller::preview_document('<p>no head</p>'), '<head><meta http-equiv="Content-Security-Policy"'),
            'a message with no <head> is given one'
        );

        $unrendered = static::__endpoint('detail', ['id' => static::__plant($site_id, ['status_id' => Email_Queue_Model::STATUS_PENDING])])['email'];
        static::__assert_false($unrendered['is_rendered'], 'an unbuilt row is not rendered');
        static::__assert_null($unrendered['preview_html'], 'and has no preview');
        static::__assert_false($unrendered['can_resend'], 'and a pending row cannot be resent');

        $missing = (int) DB::table('_email_queue')->max('id') + 1000;
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => $missing]), 'a missing id is not_found');
    }

    /**
     * RP-EMAIL-04 - In aiosmtpd mode detail lists the catcher files whose HEADER carries
     * X-RSX-Email-Id for this row (newest first); a body mention and another id do not
     * match; an absent Maildir answers exists=false; other modes carry no catcher block.
     */
    public static function test_detail_catcher_lookup()
    {
        $site_id = static::__second_site_id();
        $id = static::__plant($site_id);
        $maildir = sys_get_temp_dir() . '/sys-email-catcher-' . uniqid();

        $previous_mode = config('rsx.mail.delivery');
        $previous_maildir = config('rsx.mail.catcher_maildir');

        try {
            config(['rsx.mail.delivery' => 'aiosmtpd', 'rsx.mail.catcher_maildir' => $maildir]);

            $absent = static::__endpoint('detail', ['id' => $id])['catcher'];
            static::__assert_false($absent['exists'], 'a Maildir that was never created is reported absent');
            static::__assert_equals([], $absent['files'], 'with nothing in it');

            mkdir($maildir . '/new', 0777, true);
            mkdir($maildir . '/cur', 0777, true);

            file_put_contents($maildir . '/cur/older', "Subject: a\r\nX-RSX-Email-Id: {$id}\r\n\r\nbody\r\n");
            touch($maildir . '/cur/older', time() - 60);
            file_put_contents($maildir . '/new/newer', "x-rsx-email-id:   {$id}\n\nbody\n");
            file_put_contents($maildir . '/new/other', "X-RSX-Email-Id: " . ($id + 1) . "\r\n\r\nbody\r\n");
            file_put_contents($maildir . '/new/body_only', "Subject: b\r\n\r\nX-RSX-Email-Id: {$id}\r\n");

            $catcher = static::__endpoint('detail', ['id' => $id])['catcher'];

            static::__assert_true($catcher['exists'], 'the Maildir exists');
            static::__assert_equals(['new/newer', 'cur/older'], array_column($catcher['files'], 'name'), 'both header matches, newest first; the body mention and the other id are not matched');
            static::__assert_true(str_ends_with($catcher['files'][0]['delivered_at'], 'Z'), 'delivered_at is ISO UTC');

            config(['rsx.mail.delivery' => 'suppressed']);
            static::__assert_null(static::__endpoint('detail', ['id' => $id])['catcher'], 'outside aiosmtpd mode there is no catcher block');
        } finally {
            config(['rsx.mail.delivery' => $previous_mode, 'rsx.mail.catcher_maildir' => $previous_maildir]);

            foreach (['new', 'cur'] as $subdir) {
                foreach (glob($maildir . '/' . $subdir . '/*') ?: [] as $file) {
                    unlink($file);
                }
                if (is_dir($maildir . '/' . $subdir)) {
                    rmdir($maildir . '/' . $subdir);
                }
            }
            if (is_dir($maildir)) {
                rmdir($maildir);
            }
        }
    }

    /**
     * RP-EMAIL-05 - resend follows rsx:mail:resend's rules on any site's row: PENDING
     * and SENDING refused untouched, BLOCKED refused without force and resent with it, a
     * FAILED row reset to PENDING with attempts 0 and the error cleared; a missing id is
     * not_found.
     */
    public static function test_resend_rules()
    {
        $site_id = static::__second_site_id();

        foreach ([Email_Queue_Model::STATUS_PENDING, Email_Queue_Model::STATUS_SENDING] as $status_id) {
            $id = static::__plant($site_id, ['status_id' => $status_id]);
            static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('resend', ['id' => $id]), "status {$status_id} is refused");
            static::__assert_equals($status_id, (int) static::__row($id)->status_id, 'and left as it was');
        }

        $blocked = static::__plant($site_id, ['status_id' => Email_Queue_Model::STATUS_BLOCKED]);
        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('resend', ['id' => $blocked]), 'a blocked row needs force');
        static::__assert_equals(Email_Queue_Model::STATUS_BLOCKED, (int) static::__row($blocked)->status_id, 'and is untouched without it');

        $forced = static::__endpoint('resend', ['id' => $blocked, 'force' => 'true']);
        static::__assert_equals('pending', $forced['status'], 'with force it is queued again');
        static::__assert_equals(Email_Queue_Model::STATUS_PENDING, (int) static::__row($blocked)->status_id, 'on the row');

        $failed = static::__plant($site_id, [
            'status_id' => Email_Queue_Model::STATUS_FAILED,
            'attempt_count' => 3,
            'last_error' => 'it broke',
        ]);
        $result = static::__endpoint('resend', ['id' => $failed]);
        $row = static::__row($failed);

        static::__assert_equals(['id' => $failed, 'status' => 'pending', 'status_label' => 'Pending'], $result, 'the answer names the new status');
        static::__assert_equals(0, (int) $row->attempt_count, 'attempts are reset');
        static::__assert_null($row->last_error, 'the error is cleared');
        static::__assert_not_null($row->next_attempt_at, 'and it is due now');
        static::__assert_equals($site_id, (int) $row->site_id, 'it keeps its own site');

        $missing = (int) DB::table('_email_queue')->max('id') + 1000;
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('resend', ['id' => $missing]), 'a missing id is not_found');
    }
}
