<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;

/**
 * Sms_Queue_Model - Database-backed outgoing SMS queue (framework core).
 *
 * Mirrors Email_Queue_Model. Every SMS goes through this table before delivery; the
 * queue processor (Sms_Queue_Service) picks up PENDING records and (once real
 * delivery is wired) sends them. SMS has no template/subject/rendered_html - `body`
 * is the message content. Core infrastructure the Rsx_Sms facade depends on;
 * overridable via the class-override pattern.
 *
 * @property integer $id
 * @property integer $site_id
 * @property string $to_number
 * @property string $body
 * @property integer $category_id
 * @property integer $status_id
 * @property string $last_error
 * @property integer $attempt_count
 * @property string $next_attempt_at
 * @property string $last_attempt_at
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
 * Table: sms_queue
 *
 * @property int $id
 * @property int $site_id
 * @property string $to_number
 * @property string $body
 * @property int $category_id
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
class Sms_Queue_Model extends Rsx_Site_Model_Abstract
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
     * What last_error says on a row reclaim_stranded() rescued. The mail twin.
     */
    const STRANDED_RECLAIM_NOTE = 'reclaimed: previous drain ended mid-send';

    // Infrastructure queue: writes here (queueing, drain status updates) are not
    // user-facing data any UI subscribes to, so they must not kick the emitter engine.
    public static $realtime_silent = true;
    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per SMS the application ever sends.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;


    


    

    protected $table = 'sms_queue';
    protected $fillable = [];

    public static $enums = [
        'status_id' => [
            1 => ['constant' => 'STATUS_PENDING', 'label' => 'Pending', 'badge' => 'bg-warning'],
            2 => ['constant' => 'STATUS_SENDING', 'label' => 'Sending', 'badge' => 'bg-info'],
            3 => ['constant' => 'STATUS_SENT', 'label' => 'Sent', 'badge' => 'bg-success'],
            4 => ['constant' => 'STATUS_FAILED', 'label' => 'Failed', 'badge' => 'bg-danger'],
            5 => ['constant' => 'STATUS_BLOCKED', 'label' => 'Blocked', 'badge' => 'bg-secondary'],
            // Recorded, deliberately NOT handed to a provider: delivery is configured
            // 'suppressed' (the only possible state today - no SMS provider is wired),
            // or a dev host left no deliverable number. Not a failure.
            6 => ['constant' => 'STATUS_SUPPRESSED', 'label' => 'Suppressed', 'badge' => 'bg-dark'],
        ],
        'category_id' => [
            1 => ['constant' => 'CATEGORY_TRANSACTIONAL', 'label' => 'Transactional', 'badge' => 'bg-primary'],
            2 => ['constant' => 'CATEGORY_NOTIFICATION', 'label' => 'Notification', 'badge' => 'bg-info'],
            3 => ['constant' => 'CATEGORY_MARKETING', 'label' => 'Marketing', 'badge' => 'bg-secondary'],
        ],
    ];

    /**
     * Ajax model fetch - load one SMS record for a developer-facing SMS
     * Transaction Log detail view. No aliasing; toArray() includes the enum BEM
     * props (status_id__label/__badge, category_id__label/__badge). Auth: any
     * logged-in staff user for now (narrow to a developer check app-side later).
     */
    #[Ajax_Endpoint_Model_Fetch]
    #[Auth('is_logged_in')]
    public static function fetch($id)
    {
        $sms = static::find($id);
        if (!$sms) {
            return false;
        }

        return $sms->toArray();
    }

    /**
     * Create a queued SMS record.
     */
    public static function enqueue(
        int $site_id,
        string $to_number,
        string $body,
        int $category_id = 2,
        ?string $dev_original_to = null,
        ?int $related_type = null,
        ?int $related_id = null,
        ?string $dedupe_key = null
    ): self {
        $record = new static();
        $record->site_id = $site_id;
        $record->to_number = trim($to_number);
        $record->body = $body;
        $record->category_id = $category_id;
        $record->status_id = self::STATUS_PENDING;
        $record->dev_original_to = $dev_original_to;
        $record->related_type = $related_type;
        $record->related_id = $related_id;
        $record->dedupe_key = $dedupe_key;
        $record->attempt_count = 0;
        $record->save();

        return $record;
    }

    /**
     * Record an SMS that will never be handed to a provider, without queueing it.
     *
     * Mirror of Email_Queue_Model::enqueue_suppressed(): a `.dev.` host with no
     * whitelist match and no catchall has nowhere to send this, it is known now, and
     * the row is written SUPPRESSED in ONE save so a drain can never claim a message
     * whose destination was already invalid.
     */
    public static function enqueue_suppressed(
        int $site_id,
        string $to_number,
        string $body,
        string $reason,
        int $category_id = 2,
        ?int $related_type = null,
        ?int $related_id = null
    ): self {
        $record = new static();
        $record->site_id = $site_id;
        $record->to_number = trim($to_number);
        $record->body = $body;
        $record->category_id = $category_id;
        $record->status_id = self::STATUS_SUPPRESSED;
        $record->sent_at = now();
        $record->last_error = $reason;
        $record->related_type = $related_type;
        $record->related_id = $related_id;
        $record->attempt_count = 0;
        $record->save();

        return $record;
    }

    /**
     * Create a blocked SMS record (audit trail for an opted-out recipient).
     */
    public static function enqueue_blocked(
        int $site_id,
        string $to_number,
        string $body,
        int $category_id = 2
    ): self {
        $record = new static();
        $record->site_id = $site_id;
        $record->to_number = trim($to_number);
        $record->body = $body;
        $record->category_id = $category_id;
        $record->status_id = self::STATUS_BLOCKED;
        $record->last_error = 'Recipient has opted out of this SMS category';
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
     * Take ownership of the next sendable row, atomically. The Email_Queue_Model
     * twin, for the same reason: the conditional UPDATE is the claim, so two drains
     * racing on one row means one UPDATE matches and the other does not.
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

        $claimed = static::where('id', $candidate->id)
            ->where('status_id', self::STATUS_PENDING)
            ->update([
                'status_id' => self::STATUS_SENDING,
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return null;
        }

        return static::find($candidate->id);
    }

    /**
     * The provider accepted the message.
     */
    public function mark_sent(?string $transport_response = null): void
    {
        $this->status_id = self::STATUS_SENT;
        $this->sent_at = now();
        $this->attempt_count = $this->attempt_count + 1;
        $this->transport_response = $transport_response;
        $this->transport = config('rsx.sms.provider');
        $this->last_error = null;
        $this->save();
    }

    /**
     * Recorded, deliberately not delivered. Terminal and NOT a failure.
     */
    public function mark_suppressed(?string $reason = null): void
    {
        $this->status_id = self::STATUS_SUPPRESSED;
        $this->sent_at = now();
        $this->last_error = $reason;
        $this->save();
    }

    /**
     * The provider answered with an error for THIS message: retry on its own clock
     * until the attempt cap, then FAILED with the provider's reply recorded.
     */
    public function mark_server_error(string $reply): void
    {
        $this->attempt_count = $this->attempt_count + 1;
        $this->last_error = $reply;
        $this->transport_response = $reply;
        $this->transport = config('rsx.sms.provider');

        $max_attempts = (int) config('rsx.sms.retry.attempts', 3);
        $delay_minutes = (int) config('rsx.sms.retry.delay_minutes', 3);

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
     * Hand a claimed row back: the provider could not be reached at all. The attempt
     * is NOT counted - an outage is not this message's fault.
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
     * The Email_Queue_Model twin, and the reasoning is identical: the drain is
     * #[Exclusive], so when it starts no other runner exists and a row in SENDING was
     * left by one that died mid-message. No age threshold and no timeout - exclusivity
     * is the proof. The attempt is not counted; the site scope is claim_next()'s.
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
     * How many rows are waiting to be sent.
     *
     * Read by the disabled-mode drain, which says so instead of doing anything.
     */
    public static function pending_count(): int
    {
        return static::where('status_id', self::STATUS_PENDING)->count();
    }

    /**
     * Terminal failure that no retry can fix (a code bug).
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
     * Delete whole queue rows past the retention window.
     */
    public static function cleanup_old(int $days = 30): int
    {
        return static::whereIn('status_id', [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_BLOCKED, self::STATUS_SUPPRESSED])
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}