<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Email_Attachment_Model;

/**
 * Email_Queue_Model - Database-backed email queue (framework core).
 *
 * Every email goes through this table before delivery. The row is the whole message:
 * the envelope, the frozen subject and template data, both rendered parts once the
 * builder has run, and what the transport answered. Mail_Queue_Service claims rows
 * one at a time with claim_next() and drives them to a terminal status.
 *
 * `email_class` names an Rsx_Email SUBCLASS (which is also the blade's @rsx_id), not a
 * template path. `attempt_count` counts delivery attempts, and a first send is an
 * attempt - a row at the cap has been tried that many times, not that many times MORE.
 *
 * This is CORE infrastructure the Rsx_Mail facade hard-depends on; apps may still
 * override it via the class-override pattern (rsx/models/email_queue_model.php).
 *
 * @property integer $id
 * @property integer $site_id
 * @property string $to_address
 * @property string $to_name
 * @property string $subject
 * @property string $email_class
 * @property array $template_data
 * @property integer $category_id
 * @property string $reply_to
 * @property string $reply_to_name
 * @property array $cc
 * @property array $bcc
 * @property array $headers
 * @property string $rendered_html
 * @property string $rendered_text
 * @property integer $status_id
 * @property string $last_error
 * @property integer $attempt_count
 * @property string $next_attempt_at
 * @property string $last_attempt_at
 * @property string $message_id_header
 * @property string $transport
 * @property string $transport_response
 * @property string $dedupe_key
 * @property string $dev_original_to
 * @property string $sent_at
 * @property integer $related_type
 * @property integer $related_id
 * @property string $created_at
 * @property string $updated_at
 * @mixin \Eloquent
 */


/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: email_queue
 *
 * @property int $id
 * @property int $site_id
 * @property string $to_address
 * @property string $to_name
 * @property string $subject
 * @property string $email_class
 * @property array $template_data
 * @property int $category_id
 * @property string $rendered_html
 * @property int $status_id
 * @property string $last_error
 * @property int $attempt_count
 * @property string $next_attempt_at
 * @property string $dev_original_to
 * @property string $sent_at
 * @property int $related_type
 * @property int $related_id
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $reply_to
 * @property string $reply_to_name
 * @property array $cc
 * @property array $bcc
 * @property array $headers
 * @property string $rendered_text
 * @property string $message_id_header
 * @property string $transport
 * @property string $transport_response
 * @property string $last_attempt_at
 * @property string $dedupe_key
 *
 * @property-read string $status_id__label
 * @property-read string $status_id__constant
 * @property-read string $category_id__label
 * @property-read string $category_id__constant
 *
 * @method static array status_id__enum() Get all enum definitions with full metadata
 * @method static array status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array status_id__enum_labels() Get simple id => label map
 * @method static array status_id__enum_ids() Get array of all valid enum IDs
 * @method static array category_id__enum() Get all enum definitions with full metadata
 * @method static array category_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array category_id__enum_labels() Get simple id => label map
 * @method static array category_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Email_Queue_Model extends Rsx_Site_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const STATUS_PENDING = 1;
    const STATUS_SENDING = 2;
    const STATUS_SENT = 3;
    const STATUS_FAILED = 4;
    const STATUS_BLOCKED = 5;
    const STATUS_SUPPRESSED = 6;
    const CATEGORY_TRANSACTIONAL = 1;
    const CATEGORY_NOTIFICATION = 2;
    const CATEGORY_MARKETING = 3;

    /**
     * What last_error says on a row reclaim_stranded() rescued.
     *
     * A constant because the drain narrates it, the man page quotes it, and a test
     * asserts it - three readers of one string.
     */
    const STRANDED_RECLAIM_NOTE = 'reclaimed: previous drain ended mid-send';

    /**
     * What last_error says on a row the drain refused to send because it is too old.
     *
     * A constant because the drain narrates it, the man page quotes it, a test asserts
     * it and an operator greps for it. It names its own remedy on purpose: the row is
     * not destroyed, it is handed to a HUMAN with the command that puts it back, because
     * whether a month-old notice is still worth sending is a human's call.
     */
    const STALE_ERROR = "Timed out - email was queued too far in the past and never sent,"
        . " use 'php artisan rsx:mail:resend <id>' to resend";

    // Infrastructure queue: writes here (queueing, drain status updates) are not
    // user-facing data any UI subscribes to, so they must not kick the emitter engine.
    public static $realtime_silent = true;
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per email the application ever sends.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;


    


    

    protected $table = 'email_queue';
    protected $fillable = [];

    protected $casts = [
        'template_data' => 'array',
        'cc' => 'array',
        'bcc' => 'array',
        'headers' => 'array',
    ];

    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_PENDING', 'label' => 'Pending', 'badge' => 'bg-warning'],
            2 => ['constant' => 'STATUS_SENDING', 'label' => 'Sending', 'badge' => 'bg-info'],
            3 => ['constant' => 'STATUS_SENT', 'label' => 'Sent', 'badge' => 'bg-success'],
            4 => ['constant' => 'STATUS_FAILED', 'label' => 'Failed', 'badge' => 'bg-danger'],
            5 => ['constant' => 'STATUS_BLOCKED', 'label' => 'Blocked', 'badge' => 'bg-secondary'],
            // Rendered and recorded, deliberately NOT handed to a transport: delivery
            // is configured 'suppressed', or a dev host left no deliverable address.
            // Distinct from a genuine Sent and from a Failure - nothing went wrong.
            6 => ['constant' => 'STATUS_SUPPRESSED', 'label' => 'Suppressed', 'badge' => 'bg-dark'],
        ],
        'category_id' => [
            1 => ['constant' => 'CATEGORY_TRANSACTIONAL', 'label' => 'Transactional', 'badge' => 'bg-primary'],
            2 => ['constant' => 'CATEGORY_NOTIFICATION', 'label' => 'Notification', 'badge' => 'bg-info'],
            3 => ['constant' => 'CATEGORY_MARKETING', 'label' => 'Marketing', 'badge' => 'bg-secondary'],
        ],
    ];

    /**
     * Ajax model fetch - load one email record (subject, rendered_html, status,
     * category, to/dev_original_to, error, timestamps, polymorphic related_*) for a
     * developer-facing Email Transaction Log detail view. toArray() includes the
     * enum BEM props (status_id__label/__badge, category_id__label/__badge); no
     * aliasing - field names pass straight through DB -> PHP -> JSON -> JS.
     *
     * Auth: any logged-in staff user for now; the app should narrow the gate to a
     * developer-level check once its Permission class defines one.
     */
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        $email = static::find($id);
        if (!$email) {
            return false;
        }

        return $email->toArray();
    }

    /**
     * Create a queued email record from a frozen descriptor.
     *
     * Frozen is the operative word: subject and template_data are the values the
     * CALLER had, not values the email class will be asked for again when the drain
     * finally runs. Rsx_Mail::enqueue() is the only caller.
     *
     * @param array $descriptor
     * @return static
     */
    public static function enqueue(array $descriptor): self
    {
        $record = static::_from_descriptor($descriptor);
        $record->status_id = self::STATUS_PENDING;
        $record->save();

        return $record;
    }

    /**
     * Record an email that will never be handed to a transport, without queueing it.
     *
     * The one caller today is the dev-site gate: a `.dev.` host with no whitelist match
     * and no catchall has NOWHERE to send this message, and that is known at enqueue
     * time, not at drain time. The row is written SUPPRESSED in ONE save - it is never
     * momentarily PENDING, so a drain running right now cannot claim a message whose
     * envelope was already invalid.
     *
     * The row exists because the fact matters: "we had something to tell them and this
     * host was not allowed to". Terminal, and not a failure.
     */
    public static function enqueue_suppressed(array $descriptor, string $reason): self
    {
        $record = static::_from_descriptor($descriptor);
        $record->status_id = self::STATUS_SUPPRESSED;
        $record->next_attempt_at = null;
        $record->sent_at = now();
        $record->last_error = $reason;
        $record->save();

        return $record;
    }

    /**
     * The unsaved row a frozen descriptor describes, with no status decided yet.
     */
    private static function _from_descriptor(array $descriptor): self
    {
        $record = new static();
        $record->site_id = $descriptor['site_id'];
        $record->to_address = strtolower(trim($descriptor['to_address']));
        $record->to_name = $descriptor['to_name'] ?? null;
        $record->subject = $descriptor['subject'];
        $record->email_class = $descriptor['email_class'];
        $record->template_data = $descriptor['template_data'] ?? [];
        $record->category_id = $descriptor['category_id'];
        $record->reply_to = $descriptor['reply_to'] ?? null;
        $record->reply_to_name = $descriptor['reply_to_name'] ?? null;
        $record->cc = $descriptor['cc'] ?? [];
        $record->bcc = $descriptor['bcc'] ?? [];
        $record->dedupe_key = $descriptor['dedupe_key'] ?? null;
        $record->next_attempt_at = $descriptor['next_attempt_at'] ?? null;
        $record->dev_original_to = $descriptor['dev_original_to'] ?? null;
        $record->related_type = $descriptor['related_type'] ?? null;
        $record->related_id = $descriptor['related_id'] ?? null;
        $record->attempt_count = 0;

        return $record;
    }

    /**
     * Create a blocked email record (for audit trail).
     *
     * The row exists precisely BECAUSE nothing was sent: "we had something to tell you
     * and you had asked us not to" is the fact somebody will need six months from now.
     */
    public static function enqueue_blocked(
        int $site_id,
        string $to_address,
        string $subject,
        string $email_class,
        array $template_data = [],
        int $category_id = 2,
        ?string $to_name = null
    ): self {
        $record = new static();
        $record->site_id = $site_id;
        $record->to_address = strtolower(trim($to_address));
        $record->to_name = $to_name;
        $record->subject = $subject;
        $record->email_class = $email_class;
        $record->template_data = $template_data;
        $record->category_id = $category_id;
        $record->status_id = self::STATUS_BLOCKED;
        $record->last_error = 'Recipient has unsubscribed from this email category';
        $record->attempt_count = 0;
        $record->save();

        return $record;
    }

    /**
     * The row already queued under this tenant's dedupe key, or null.
     */
    public static function find_by_dedupe_key(int $site_id, string $dedupe_key): ?self
    {
        return static::where('site_id', $site_id)
            ->where('dedupe_key', $dedupe_key)
            ->first();
    }

    /**
     * Take ownership of the next sendable row, atomically.
     *
     * The conditional UPDATE is the claim: two drains racing on the same row means one
     * UPDATE matches and the other does not, so a message can never be sent twice.
     * Reading the row back afterwards is what makes the claim observable.
     *
     * Ordered by created_at so the queue is FIFO, filtered on next_attempt_at so a row
     * serving a retry delay - or one the caller scheduled with send_at() - is skipped
     * until its moment arrives.
     *
     * @return static|null
     */
    public static function claim_next(): ?self
    {
        $candidate = static::where('status_id', self::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('created_at', 'asc')
            ->first();

        if ($candidate === null) {
            return null;
        }

        if (!$candidate->claim()) {
            // Somebody else took it between the read and the update.
            return null;
        }

        return $candidate;
    }

    /**
     * Take ownership of THIS row: PENDING -> SENDING, atomically.
     *
     * The conditional UPDATE is the claim; false means somebody else already has it.
     * claim_next() uses this after picking a candidate, and the drain uses it directly
     * to re-take the row it just released when a transport failure sent it back to
     * PENDING for one reconnect-and-retry - that retry is of THE SAME MESSAGE, not of
     * whatever happens to be at the head of the queue.
     */
    public function claim(): bool
    {
        $claimed = static::where('id', $this->id)
            ->where('status_id', self::STATUS_PENDING)
            ->update([
                'status_id' => self::STATUS_SENDING,
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        // Mirror the two columns the UPDATE just wrote rather than re-reading the row:
        // a refresh() reloads relations too, and lazy eager loading is prohibited here.
        $this->status_id = self::STATUS_SENDING;
        $this->last_attempt_at = now();
        $this->syncOriginal();

        return true;
    }

    /**
     * The transport accepted the message.
     */
    public function mark_sent(?string $message_id_header = null, ?string $transport_response = null): void
    {
        $this->status_id = self::STATUS_SENT;
        $this->sent_at = now();
        $this->attempt_count = $this->attempt_count + 1;
        $this->message_id_header = $message_id_header;
        $this->transport_response = $transport_response;
        $this->transport = config('rsx.mail.transport.driver');
        $this->last_error = null;
        $this->save();
    }

    /**
     * Rendered and recorded, deliberately not delivered.
     *
     * Delivery is configured 'suppressed', or a dev host left no deliverable address.
     * Terminal and NOT a failure: nothing went wrong, and nothing is retried.
     */
    public function mark_suppressed(?string $reason = null): void
    {
        $this->status_id = self::STATUS_SUPPRESSED;
        $this->sent_at = now();
        $this->last_error = $reason;
        $this->save();
    }

    /**
     * The SMTP server answered with an error for THIS message.
     *
     * The message is the problem, not the connection, so it gets its own clock: back to
     * PENDING with next_attempt_at pushed out, until the attempt cap, then FAILED with
     * the server's own reply recorded. A connection-level failure is NOT this - see
     * release_to_pending().
     */
    public function mark_server_error(string $reply): void
    {
        $this->attempt_count = $this->attempt_count + 1;
        $this->last_error = $reply;
        $this->transport_response = $reply;
        $this->transport = config('rsx.mail.transport.driver');

        $max_attempts = (int) config('rsx.mail.retry.attempts', 3);
        $delay_minutes = (int) config('rsx.mail.retry.delay_minutes', 3);

        if ($this->attempt_count >= $max_attempts) {
            $this->status_id = self::STATUS_FAILED;
            $this->next_attempt_at = null;
        } else {
            $this->status_id = self::STATUS_PENDING;
            $this->next_attempt_at = now()->addMinutes($delay_minutes);
        }

        $this->save();
    }

    /**
     * Hand a claimed row back: the transport could not be reached at all.
     *
     * The attempt is NOT counted. Nothing about this message was rejected - the mail
     * host was unavailable - so counting it would burn a message's retry budget on an
     * outage that has nothing to do with it.
     */
    public function release_to_pending(?string $note = null): void
    {
        $this->status_id = self::STATUS_PENDING;
        $this->last_error = $note;
        $this->save();
    }

    /**
     * Put every row still marked SENDING back to PENDING, and say how many there were.
     *
     * WHY THIS NEEDS NO AGE THRESHOLD AND NO TIMEOUT. The drain is #[Exclusive]: when
     * send_pending_queue starts, no other runner exists anywhere in the cluster. So a
     * row sitting in SENDING at that moment was claimed by a runner that no longer
     * runs - it was killed, the box rebooted, or the worker was reaped mid-message.
     * Exclusivity is the proof, and a "how old is old enough" number would be exactly
     * the arbitrary failure injector the timeout mandate forbids.
     *
     * The attempt is NOT counted, for the same reason release_to_pending() does not
     * count one: nothing about the message was rejected. It may already have reached
     * the SMTP server - a duplicate is the acceptable outcome here, and a message
     * silently stuck in SENDING forever is not.
     *
     * SCOPE: the ordinary site scope applies, exactly as it does to claim_next(). A row
     * this process could never claim is not a row it should be repairing.
     *
     * @return int How many stranded rows were reclaimed.
     */
    public static function reclaim_stranded(): int
    {
        return static::where('status_id', self::STATUS_SENDING)
            ->update([
                'status_id' => self::STATUS_PENDING,
                'last_error' => self::STRANDED_RECLAIM_NOTE,
                'updated_at' => now(),
            ]);
    }

    /**
     * FAIL every PENDING row that should have gone out more than $days ago.
     *
     * QUEUE HYGIENE, NOT A TIMEOUT (rsx.mail.stale_after_days, whose scenario is written
     * beside the value in system/config/rsx.php). Nothing here bounds a wait: it
     * safeguards against one system-configuration failure - the queue silently stops
     * working for a month, and the moment somebody repairs it the drain floods a month
     * of stale notices that are out of date, irrelevant and impossible to retract.
     *
     * So the drain refuses to send them and says so on the row, naming the command that
     * puts one back. THE DECISION TO SEND OLD MAIL BELONGS TO A HUMAN.
     *
     * COALESCE(next_attempt_at, created_at) is the due date, not created_at: a message
     * the caller scheduled with send_at() three weeks out is not late until three weeks
     * from now, and must not be born stale.
     *
     * ONE conditional UPDATE, and it is deliberately not a claim: these rows are not
     * being sent, they are being set aside, and each survives with the reason and the
     * resend command on it.
     *
     * @param int $days
     * @return int How many rows were marked.
     */
    public static function mark_stale(int $days): int
    {
        return static::where('status_id', self::STATUS_PENDING)
            ->whereRaw('COALESCE(next_attempt_at, created_at) < ?', [now()->subDays($days)])
            ->update([
                'status_id' => self::STATUS_FAILED,
                'last_error' => self::STALE_ERROR,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Put a terminal row back on the queue as if it had never been attempted.
     *
     * The row keeps its identity, its frozen subject and data, its attachments and its
     * history of having failed - what is cleared is the state that stops the drain
     * looking at it. Only rsx:mail:resend calls this, and only for a row that has
     * finished: a PENDING or SENDING row is already the queue's business.
     *
     * next_attempt_at IS SET TO NOW, NOT NULLED, AND THAT IS LOAD-BEARING. The stale
     * sweep ages a row from COALESCE(next_attempt_at, created_at), so a nulled column
     * would hand a stale row straight back to a created_at from days ago and the very
     * next drain would fail it again - making the resend the stale message itself names
     * a permanent no-op. `now()` says what the resend means: attempt it immediately, and
     * start its patience over from this moment.
     */
    public function reset_for_resend(): void
    {
        $this->status_id = self::STATUS_PENDING;
        $this->attempt_count = 0;
        $this->next_attempt_at = now();
        $this->last_error = null;
        $this->save();
    }

    /**
     * How many rows are waiting to be sent.
     *
     * Read by the disabled-mode drain, which says so instead of doing anything.
     */
    public static function pending_count(): int
    {
        return static::where('status_id', self::STATUS_PENDING)->count();
    }

    /**
     * Terminal failure that no retry can fix (a render or build error - a code bug).
     */
    public function mark_failed(string $error): void
    {
        $this->status_id = self::STATUS_FAILED;
        $this->attempt_count = $this->attempt_count + 1;
        $this->last_error = $error;
        $this->next_attempt_at = null;
        $this->save();
    }

    /**
     * This email's attachments and inline images, in the order they were declared.
     */
    #[Relationship]
    public function attachments()
    {
        return $this->hasMany(Email_Attachment_Model::class, 'email_queue_id')->orderBy('sort_order');
    }

    /**
     * Delete whole queue rows past the retention window.
     *
     * Every terminal status goes: the row IS the record, and once it is old enough to
     * drop there is nothing to keep about how it ended. Attachments follow by FK
     * cascade; the blobs themselves are File_Disposal_Service's business.
     */
    public static function cleanup_old(int $days = 30): int
    {
        return static::whereIn('status_id', [
                self::STATUS_SENT,
                self::STATUS_FAILED,
                self::STATUS_BLOCKED,
                self::STATUS_SUPPRESSED,
            ])
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}