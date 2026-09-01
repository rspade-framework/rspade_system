<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use App\RSpade\Core\Mail\Mail_Transport_Unavailable_Exception;
use App\RSpade\Core\Mail\Rsx_Mail_Builder;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;

/**
 * Mail_Queue_Service - the outgoing email queue drain (framework core).
 */
class Mail_Queue_Service extends Rsx_Service_Abstract
{
    /**
     * Send every sendable row, then stop.
     *
     * THE CLAIM IS THE CONCURRENCY STORY: claim_next() moves a row PENDING -> SENDING
     * with a conditional UPDATE, so two drains can never take the same message.
     * #[Exclusive] additionally coalesces the drains themselves - every enqueue kicks
     * this task, and the schedule is only a sweeper for rows that a retry delay or a
     * send_at() held back.
     *
     * THE TWO FAILURE CLASSES ARE NOT THE SAME FAILURE, and conflating them is how a
     * queue quietly destroys mail:
     *
     *   The SERVER ANSWERED WITH AN ERROR for this message (UnexpectedResponseException,
     *   raised by SmtpTransport::assertResponseCode() for any reply code the command did
     *   not expect - 4xx and 5xx alike). The connection is fine; this MESSAGE is the
     *   problem. It gets its own clock: attempt counted, next_attempt_at pushed out, and
     *   FAILED at the cap with the server's own words recorded.
     *
     *   The TRANSPORT COULD NOT BE REACHED (any other TransportExceptionInterface -
     *   SocketStream could not connect, AbstractStream found the connection closed).
     *   Nothing about the message was rejected, so the row goes back to PENDING with its
     *   attempt NOT counted. We rebuild the transport and retry THE SAME message once;
     *   if that fails too the runner THROWS and the whole drain dies loudly, leaving
     *   every remaining row PENDING for the next kick or the next minute's sweep. A mail
     *   host being down is an outage to see, not a budget for messages to burn.
     *
     * A build or render error is neither: it is a code bug, no retry can fix it, and the
     * row is FAILED immediately with the message.
     *
     * DELIVERY MODE DECIDES WHETHER ANY OF THIS RUNS. 'disabled' returns immediately -
     * the queue is FROZEN, so nothing is claimed, nothing is reclaimed, nothing is marked
     * stale and every row keeps the state it had. The other three modes drain; only
     * 'aiosmtpd' and 'live' hand anything to a transport.
     *
     * IN 'aiosmtpd' MODE THE GREETING IS CHECKED FIRST. That mode promises mail lands in
     * a Maildir on this box and reaches nobody, and the only thing that could break the
     * promise is something else listening on 127.0.0.1:1025. A connection whose 220 line
     * does not say `aiosmtpd` is refused per message as a SERVER ERROR - the message is
     * the one being refused, so it gets the ordinary retry clock and eventually FAILS
     * with what the imposter actually said recorded on it.
     */
    #[Task('Send pending outgoing email queue')]
    #[Exclusive]
    #[Schedule('every minute')]
    public static function send_pending_queue(Task_Instance $task, array $params = []): array
    {
        $counts = [
            'sent' => 0,
            'server_errors' => 0,
            'failed' => 0,
            'suppressed' => 0,
            'reclaimed' => 0,
            'stale' => 0,
        ];

        $mode = Rsx_Mail_Transport::delivery_mode();

        // FROZEN. Not "drain and suppress" - the rows are not touched at all, so turning
        // delivery back on sends exactly what was queued while it was off. Saying how
        // many are waiting is the whole output: an operator who forgot the switch is on
        // needs the number, not silence.
        if ($mode === Rsx_Mail_Transport::MODE_DISABLED) {
            $pending = Email_Queue_Model::pending_count();
            $task->info("mail delivery is disabled - {$pending} message(s) left pending");

            return $counts;
        }

        // #[Exclusive] means no other runner exists right now, so anything still in
        // SENDING was claimed by a runner that died mid-message. Nothing else will ever
        // free those rows: they are not PENDING, so claim_next() cannot see them.
        $counts['reclaimed'] = Email_Queue_Model::reclaim_stranded();

        if ($counts['reclaimed'] > 0) {
            $task->info(
                "Reclaimed {$counts['reclaimed']} stranded message(s) left SENDING by a drain that ended mid-send"
            );
        }

        // AFTER the reclaim, so a message stranded by a dead worker is judged on when it
        // was DUE rather than being rescued into PENDING and immediately set aside on the
        // next pass. This is queue hygiene, not a timeout: see rsx.mail.stale_after_days
        // for the configuration failure it guards against.
        $counts['stale'] = Email_Queue_Model::mark_stale(
            (int) config('rsx.mail.stale_after_days', 2)
        );

        if ($counts['stale'] > 0) {
            $task->error(
                "Refused to send {$counts['stale']} message(s) that were due more than "
                . config('rsx.mail.stale_after_days', 2) . " day(s) ago - resend any that "
                . "are still wanted with: php artisan rsx:mail:resend <id>"
            );
        }

        $delivery_suppressed = $mode === Rsx_Mail_Transport::MODE_SUPPRESSED;
        $transport = Rsx_Mail_Transport::make();
        $banner_error = Rsx_Mail_Transport::aiosmtpd_banner_error();
        $reconnected = false;

        while (true) {
            $row = Email_Queue_Model::claim_next();

            if (!$row) {
                break;
            }

            // The inner loop runs a row TWICE at most: once, and once more after the
            // one permitted reconnect. Every other path breaks out of it.
            while (true) {
                try {
                    if ($delivery_suppressed) {
                        // Build anyway: the rendered bodies are recorded on the row, so
                        // a suppressed send is still reviewable.
                        Rsx_Mail_Builder::build($row);
                        $row->mark_suppressed('delivery mode is suppressed');
                        $task->info("Suppressed #{$row->id} to {$row->to_address}: delivery mode is suppressed");
                        $counts['suppressed']++;
                        break;
                    }

                    // Not the message's fault, but it is answered as a server error on
                    // purpose: the connection is up, so this is not the transport-outage
                    // path, and every message under this connection gets the same refusal
                    // with its own retry clock rather than the whole drain dying.
                    if ($banner_error !== null) {
                        throw new UnexpectedResponseException($banner_error);
                    }

                    $message = Rsx_Mail_Builder::build($row);
                    $sent = $transport->send($message);

                    $message_id = $message->getHeaders()->get('Message-ID')->getBodyAsString();
                    $row->mark_sent($message_id, static::_transport_response($sent));

                    static::_recipient($row)->increment_sent();

                    $task->info("Sent #{$row->id} to {$row->to_address} ({$message_id})");
                    $counts['sent']++;
                    break;
                } catch (UnexpectedResponseException $e) {
                    $reply = $e->getMessage();
                    $row->mark_server_error($reply);
                    static::_recipient($row)->increment_failed();

                    $attempts = (int) config('rsx.mail.retry.attempts', 3);
                    $task->error("Server error #{$row->id} attempt {$row->attempt_count}/{$attempts}: {$reply}");
                    $counts['server_errors']++;
                    break;
                } catch (TransportExceptionInterface $e) {
                    $row->release_to_pending($e->getMessage());

                    if ($reconnected) {
                        $task->error(
                            "Mail transport still unreachable after reconnecting to "
                            . Rsx_Mail_Transport::describe() . " - queue left PENDING."
                        );

                        throw new Mail_Transport_Unavailable_Exception(
                            Rsx_Mail_Transport::describe(),
                            $e->getMessage()
                        );
                    }

                    $reconnected = true;
                    $task->error(
                        "Mail transport failure on #{$row->id} (" . $e->getMessage()
                        . ") - reconnecting to " . Rsx_Mail_Transport::describe() . " and retrying this message."
                    );

                    $transport = Rsx_Mail_Transport::make();
                    $banner_error = Rsx_Mail_Transport::aiosmtpd_banner_error();

                    if (!$row->claim()) {
                        // Somebody else has it now; the queue is still being drained.
                        break;
                    }

                    continue;
                } catch (\Throwable $e) {
                    $row->mark_failed($e->getMessage());
                    $task->error("Failed to build #{$row->id}: " . $e->getMessage());
                    $counts['failed']++;
                    break;
                }
            }
        }

        return $counts;
    }

    /**
     * Delete whole email queue rows past the retention window (attachments cascade),
     * and prune the development catcher's Maildir on the same schedule.
     */
    #[Task('Clean up old email queue records')]
    #[Schedule('daily at 3am')]
    public static function cleanup(Task_Instance $task, array $params = []): array
    {
        $days = $params['days'] ?? config('rsx.mail.retention_days', 30);
        $deleted = Email_Queue_Model::cleanup_old($days);
        $task->info("Deleted {$deleted} email records older than {$days} days");

        $pruned = static::_prune_catcher_maildir((int) $days);

        if ($pruned > 0) {
            $task->info("Pruned {$pruned} captured messages from the development mail catcher");
        }

        return ['deleted' => $deleted, 'catcher_pruned' => $pruned];
    }

    /**
     * Delete captured messages older than the retention window from the dev catcher.
     *
     * The catcher is a development convenience with no lifecycle of its own; without
     * this its Maildir grows forever. Absent directory = nothing to do (a production
     * host has no catcher).
     */
    private static function _prune_catcher_maildir(int $days): int
    {
        $maildir = config('rsx.mail.catcher_maildir');

        if (!$maildir || !is_dir($maildir)) {
            return 0;
        }

        $cutoff = time() - ($days * 86400);
        $pruned = 0;

        foreach (['new', 'cur', 'tmp'] as $subdir) {
            $path = rtrim($maildir, '/') . '/' . $subdir;

            if (!is_dir($path)) {
                continue;
            }

            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $file = $path . '/' . $entry;

                if (is_file($file) && filemtime($file) < $cutoff) {
                    unlink($file);
                    $pruned++;
                }
            }
        }

        return $pruned;
    }

    /**
     * The recipient stats row for the person this message was really for.
     *
     * The ORIGINAL address, not the envelope's: on a dev host the envelope may point at
     * a catchall, and counting a developer's inbox as the recipient would make the
     * per-recipient history meaningless on exactly the boxes people look at it on.
     */
    private static function _recipient(Email_Queue_Model $row): Email_Recipient_Model
    {
        return Email_Recipient_Model::find_or_create_by_email(
            (int) $row->site_id,
            $row->dev_original_to ?: $row->to_address
        );
    }

    /**
     * The SMTP conversation, for the row's transport_response column.
     *
     * Capped at the column's usable width: a chatty server's debug log is diagnostic
     * detail, and losing its tail is better than losing the whole row to an overflow.
     */
    private static function _transport_response(?object $sent): ?string
    {
        if ($sent === null || !method_exists($sent, 'getDebug')) {
            return null;
        }

        $debug = trim((string) $sent->getDebug());

        if ($debug === '') {
            return null;
        }

        return mb_substr($debug, 0, 60000);
    }
}
