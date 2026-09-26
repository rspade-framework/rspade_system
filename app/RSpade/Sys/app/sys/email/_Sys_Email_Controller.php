<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Email;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Sys\App\Sys\Email\_Sys_Email_DataGrid;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;
use App\RSpade\Sys\Lib\_Sys_Enum_Words;

/**
 * The Email screen's endpoints: the queue's headline numbers, the queue grid and its
 * filter options, one message whole, and Resend.
 *
 * EVERY SITE. The queue is one table for the whole install and the panel is the
 * operator's view of it, so every read and write here runs inside
 * Email_Queue_Model::without_site_scope() - the developer's own session site says
 * nothing about which tenant queued a message.
 *
 * Status and category travel as lowercase WORDS (pending, failed, marketing), derived
 * from the model's $enums labels by _Sys_Enum_Words (shared with the SMS tab's
 * _Sys_Sms_Controller), so a link can address a filtered grid readably:
 * Rsx.Route('_Sys_Email_Action', {}, {tab: 'email', mail_f_status: 'pending'}).
 */
#[Auth('is_sysadmin')]
class _Sys_Email_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * What the preview iframe's document may load: nothing but inline styles and data:
     * images and fonts. A queued email is untrusted markup, and a remote image in it is
     * as often a tracking pixel as a logo - opening a message in the panel must not tell
     * anybody it was read. The iframe is also sandbox="" (no scripts, opaque origin).
     */
    public const PREVIEW_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:";

    /**
     * The queue's headline: a count per status, the oldest pending row, the delivery
     * mode and the transport.
     *
     * @return array {counts: [{status, label, count}], total, oldest_pending: null|{id,
     *                created_at, to_address}, delivery_mode, transport, transport_label,
     *                catcher_maildir: null|string}
     */
    #[Ajax_Endpoint]
    public static function summary(Request $request, array $params = [])
    {
        return Email_Queue_Model::without_site_scope(function () {
            $counts = _Sys_Enum_Words::status_tiles(Email_Queue_Model::class, Email_Queue_Model::status_counts());
            $oldest = Email_Queue_Model::oldest_pending();
            $mode = Rsx_Mail_Transport::delivery_mode();

            return [
                'counts' => $counts,
                'total' => array_sum(array_column($counts, 'count')),
                'oldest_pending' => $oldest === null ? null : [
                    'id' => (int) $oldest->id,
                    'created_at' => $oldest->created_at,
                    'to_address' => $oldest->to_address,
                ],
                'delivery_mode' => $mode,
                'transport' => Rsx_Mail_Transport::describe(),
                'transport_label' => Rsx_Mail_Transport::transport_label(),
                'catcher_maildir' => $mode === Rsx_Mail_Transport::MODE_AIOSMTPD
                    ? (string) config('rsx.mail.catcher_maildir')
                    : null,
            ];
        });
    }

    /**
     * The queue grid (_Sys_Email_DataGrid).
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return Email_Queue_Model::without_site_scope(fn () => _Sys_Email_DataGrid::fetch($params));
    }

    /**
     * The status filter's options, in the enum's order.
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function status_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::enum_options(Email_Queue_Model::class, 'status_id');
    }

    /**
     * The category filter's options, in the enum's order.
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function category_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::enum_options(Email_Queue_Model::class, 'category_id');
    }

    /**
     * The site filter's options: every site that has a queue row, labelled "#id name".
     *
     * @return array [{value, label}]
     */
    #[Ajax_Endpoint]
    public static function site_options(Request $request, array $params = [])
    {
        return _Sys_Enum_Words::site_options(Email_Queue_Model::class);
    }

    /**
     * One message, whole: every column, the site's name, the attachments, both rendered
     * bodies (the HTML one wrapped for the sandboxed preview), what may be done to it,
     * and - in aiosmtpd mode - its captured copies in the development catcher.
     *
     * @param array $params id
     * @return array {email: {...}, catcher: null|{maildir, exists, files: [{name, path, delivered_at, size}]}}
     */
    #[Ajax_Endpoint]
    public static function detail(Request $request, array $params = [])
    {
        return Email_Queue_Model::without_site_scope(function () use ($params) {
            $record = Email_Queue_Model::find((int) ($params['id'] ?? 0));

            if ($record === null) {
                return response_not_found('No email queue row with that id.');
            }

            $email = $record->toArray();
            unset($email['rendered_html']);

            $status_id = (int) $record->status_id;

            $email['status'] = static::__word('status_id', $status_id);
            $email['category'] = static::__word('category_id', (int) $record->category_id);
            $email['site_name'] = _Sys_Enum_Words::site_names([(int) $record->site_id])[(int) $record->site_id] ?? null;
            $email['attachments'] = $record->attachment_summary();
            $email['is_rendered'] = $record->rendered_html !== null && $record->rendered_html !== '';
            $email['preview_html'] = $email['is_rendered'] ? static::preview_document((string) $record->rendered_html) : null;
            $email['can_resend'] = $status_id !== Email_Queue_Model::STATUS_PENDING
                && $status_id !== Email_Queue_Model::STATUS_SENDING;
            $email['resend_needs_force'] = $status_id === Email_Queue_Model::STATUS_BLOCKED;

            $catcher = Rsx_Mail_Transport::delivery_mode() === Rsx_Mail_Transport::MODE_AIOSMTPD
                ? Rsx_Mail_Transport::catcher_files_for((int) $record->id)
                : null;

            return ['email' => $email, 'catcher' => $catcher];
        });
    }

    /**
     * Resend - the rules of rsx:mail:resend, from the one implementation both call
     * (Rsx_Mail::resend()): a PENDING or SENDING row is refused, a BLOCKED row needs
     * force (the recipient's opt-out is a consent record), anything else goes back on the
     * queue and the drain is kicked.
     *
     * @param array $params id, force (true to resend a BLOCKED row)
     * @return array {id, status, status_label}
     */
    #[Ajax_Endpoint]
    public static function resend(Request $request, array $params = [])
    {
        return Email_Queue_Model::without_site_scope(function () use ($params) {
            $record = Email_Queue_Model::find((int) ($params['id'] ?? 0));

            if ($record === null) {
                return response_not_found('No email queue row with that id.');
            }

            $force = filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $outcome = Rsx_Mail::resend($record, $force);

            if ($outcome === Rsx_Mail::RESEND_ALREADY_QUEUED) {
                return response_error(
                    Ajax::ERROR_VALIDATION,
                    "Email #{$record->id} is already {$record->status_id__label} - the queue has it."
                );
            }

            if ($outcome === Rsx_Mail::RESEND_BLOCKED) {
                return response_error(
                    Ajax::ERROR_VALIDATION,
                    "Email #{$record->id} is Blocked: {$record->to_address} has unsubscribed from "
                    . "{$record->category_id__label} email. Resending it overrides that opt-out and must be confirmed."
                );
            }

            return [
                'id' => (int) $record->id,
                'status' => static::__word('status_id', (int) $record->status_id),
                'status_label' => $record->status_id__label,
            ];
        });
    }

    /**
     * The rendered HTML as the preview iframe's srcdoc: the message with PREVIEW_CSP
     * declared at the top of its <head> (a <head> is created when the message has none),
     * so the document itself refuses every remote load whatever the page around it allows.
     */
    public static function preview_document(string $html): string
    {
        $meta = '<meta http-equiv="Content-Security-Policy" content="' . self::PREVIEW_CSP . '">';

        if (preg_match('/<head\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $at) . $meta . substr($html, $at);
        }

        return '<head>' . $meta . '</head>' . $html;
    }

    /**
     * An _email_queue enum value as its word (_Sys_Enum_Words::enum_word()).
     */
    private static function __word(string $column, int $value): string
    {
        return _Sys_Enum_Words::enum_word(Email_Queue_Model::class, $column, $value);
    }
}
