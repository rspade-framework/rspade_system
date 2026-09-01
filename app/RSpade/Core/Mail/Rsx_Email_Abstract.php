<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Email_Queue_Model;

/**
 * Rsx_Email_Abstract - one email is one class.
 *
 * An email that an application sends is a class in rsx/emails/, co-located with the
 * blade template that renders it. The class carries the recipient's data as typed
 * constructor arguments, answers subject() and data(), and declares its category once.
 * Nothing about a send is passed around as loose strings.
 *
 * ```php
 * // rsx/emails/welcome_email.php  (+ welcome_email.blade.php, @rsx_id('Welcome_Email'))
 * class Welcome_Email extends Rsx_Email_Abstract
 * {
 *     const CATEGORY = self::TRANSACTIONAL;
 *
 *     public function __construct(public User_Model $user, public string $login_url) {}
 *
 *     public function subject(): string { return 'Welcome to ' . config('rsx.name'); }
 *     public function data(): array { return ['name' => $this->user->get_printed_name()]; }
 *     public static function sample(): static { ... }
 * }
 *
 * (new Welcome_Email($user, $url))->to($user)->send();
 * ```
 *
 * THE CLASS NAME IS THE IDENTITY. It is the blade's @rsx_id, it is what lands in
 * email_queue.email_class, and it is the key in the manifest's baked email table.
 *
 * WHY `const CATEGORY` IS NOT DECLARED HERE: a default would silently decide, for
 * every email an application ever writes, whether an unsubscribed recipient still
 * receives it. That is a per-email editorial decision and there is no safe default -
 * TRANSACTIONAL over-delivers to people who opted out, NOTIFICATION silently drops
 * mail somebody needed. So the abstract declares nothing and Email_ManifestSupport
 * FATALs the build when a concrete subclass omits it. Same reasoning for sample():
 * an email nobody can preview is an email nobody reviews.
 *
 * The fluent methods below accumulate an envelope; send() freezes subject() and
 * data() into a queue row and hands the row to the drain. Nothing renders here.
 */
#[Instantiatable]
abstract class Rsx_Email_Abstract
{
    /** Always delivered - the recipient asked for this by acting. Ignores the blocklist. */
    const TRANSACTIONAL = 1;

    /** Delivered unless the recipient unsubscribed from notifications. */
    const NOTIFICATION = 2;

    /** Delivered unless the recipient unsubscribed from marketing. */
    const MARKETING = 3;

    /** Attachment dispositions, mirroring Email_Attachment_Model::DISPOSITION_*. */
    const DISPOSITION_ATTACHMENT = 1;
    const DISPOSITION_INLINE = 2;

    /** @var array{address: string, name: ?string}|null */
    protected ?array $_to = null;

    /** @var array<int, array{address: string, name: ?string}> */
    protected array $_cc = [];

    /** @var array<int, array{address: string, name: ?string}> */
    protected array $_bcc = [];

    /** @var array{address: string, name: ?string}|null */
    protected ?array $_reply_to = null;

    /**
     * Pending attachment specs, in declaration order. Each is
     * ['source' => File_Attachment_Model|string path|null, 'bytes' => ?string,
     *  'name' => ?string, 'mime' => ?string, 'disposition' => int, 'cid' => ?string].
     *
     * @var array<int, array>
     */
    protected array $_attachments = [];

    protected ?string $_dedupe_key = null;

    protected ?string $_send_at = null;

    protected ?int $_related_type = null;

    protected ?int $_related_id = null;

    // =========================================================================
    // THE CONTRACT EVERY EMAIL IMPLEMENTS
    // =========================================================================

    /**
     * The subject line, frozen into the queue row at send() time.
     */
    abstract public function subject(): string;

    /**
     * The template variables, frozen into the queue row at send() time.
     *
     * Return plain scalars and arrays - this is JSON in the database, so a model
     * instance here is a bug waiting for the day the record changes underneath it.
     *
     * @return array
     */
    abstract public function data(): array;

    /**
     * A fully-constructed instance carrying plausible values, for previews and tests.
     *
     * Build it WITHOUT touching the database wherever the data allows (an unsaved
     * model instance with the handful of fields the template reads is enough).
     */
    abstract public static function sample(): static;

    // =========================================================================
    // IDENTITY
    // =========================================================================

    /**
     * This email's category, from the mandatory `const CATEGORY`.
     */
    public static function category(): int
    {
        return (int) static::CATEGORY;
    }

    /**
     * The class basename - the blade @rsx_id and the email_queue.email_class value.
     */
    public static function view_id(): string
    {
        return class_basename(static::class);
    }

    // =========================================================================
    // ENVELOPE (fluent)
    // =========================================================================

    /**
     * The recipient. A later to() REPLACES the earlier one - an email has one To.
     *
     * @param object|string $recipient An address, or anything exposing ->email.
     * @param string|null $name Display name; resolved from the object when omitted.
     */
    public function to($recipient, ?string $name = null): static
    {
        $this->_to = static::_resolve_recipient($recipient, $name, 'to');

        return $this;
    }

    /**
     * Add a CC recipient. Repeated calls ACCUMULATE.
     *
     * @param object|string $recipient
     */
    public function cc($recipient, ?string $name = null): static
    {
        $this->_cc[] = static::_resolve_recipient($recipient, $name, 'cc');

        return $this;
    }

    /**
     * Add a BCC recipient. Repeated calls ACCUMULATE.
     *
     * @param object|string $recipient
     */
    public function bcc($recipient, ?string $name = null): static
    {
        $this->_bcc[] = static::_resolve_recipient($recipient, $name, 'bcc');

        return $this;
    }

    /**
     * Where a reply goes. A later reply_to() REPLACES the earlier one.
     *
     * @param object|string $recipient
     */
    public function reply_to($recipient, ?string $name = null): static
    {
        $this->_reply_to = static::_resolve_recipient($recipient, $name, 'reply_to');

        return $this;
    }

    // =========================================================================
    // ATTACHMENTS
    // =========================================================================

    /**
     * Attach a stored file or a file on disk.
     *
     * A File_Attachment_Model reuses its existing blob (nothing is copied). A string
     * is an ABSOLUTE path on this box - its bytes enter the content-addressed store,
     * so mailing the same file to a thousand people stores it once.
     *
     * @param File_Attachment_Model|string $source
     * @param string|null $name Filename the recipient sees; defaults to the source's.
     * @param string|null $mime Defaults to the source's recorded/sniffed type.
     */
    public function attach(File_Attachment_Model|string $source, ?string $name = null, ?string $mime = null): static
    {
        $this->_attachments[] = [
            'source' => $source,
            'bytes' => null,
            'name' => $name,
            'mime' => $mime,
            'disposition' => self::DISPOSITION_ATTACHMENT,
            'cid' => null,
        ];

        return $this;
    }

    /**
     * Attach bytes the caller generated (a CSV, a rendered PDF).
     *
     * Name and mime are REQUIRED here: there is no source to infer them from, and an
     * attachment with a guessed type is an attachment the recipient cannot open.
     */
    public function attach_bytes(string $bytes, string $name, string $mime): static
    {
        $this->_attachments[] = [
            'source' => null,
            'bytes' => $bytes,
            'name' => $name,
            'mime' => $mime,
            'disposition' => self::DISPOSITION_ATTACHMENT,
            'cid' => null,
        ];

        return $this;
    }

    /**
     * Embed an image the template references as `<img src="cid:{$cid}">`.
     *
     * @param string $cid The content id the template uses.
     * @param File_Attachment_Model|string $source Stored attachment or absolute path.
     */
    public function embed(string $cid, File_Attachment_Model|string $source): static
    {
        $this->_attachments[] = [
            'source' => $source,
            'bytes' => null,
            'name' => null,
            'mime' => null,
            'disposition' => self::DISPOSITION_INLINE,
            'cid' => $cid,
        ];

        return $this;
    }

    // =========================================================================
    // SCHEDULING AND IDENTITY OF THE SEND
    // =========================================================================

    /**
     * Declare this send idempotent under an application-chosen key.
     *
     * The key is unique per site. send() returns the EXISTING row - in whatever state
     * it reached - and enqueues nothing when the key has been used before, so a retried
     * webhook or a re-run import cannot double-mail anybody.
     */
    public function dedupe_key(string $key): static
    {
        $this->_dedupe_key = $key;

        return $this;
    }

    /**
     * Hold the message until this moment (an ISO datetime string).
     *
     * This is the EARLIEST it may go out, not a guarantee of when: it writes
     * next_attempt_at, and the drain picks the row up on its next pass afterwards.
     */
    public function send_at(string $iso): static
    {
        $this->_send_at = $iso;

        return $this;
    }

    /**
     * Record what this email is ABOUT, as a polymorphic reference on the queue row.
     */
    public function about(?int $related_type, ?int $related_id): static
    {
        $this->_related_type = $related_type;
        $this->_related_id = $related_id;

        return $this;
    }

    // =========================================================================
    // SEND
    // =========================================================================

    /**
     * Queue this email. Never sends inline - queueing IS the send.
     */
    public function send(): Email_Queue_Model
    {
        return Rsx_Mail::enqueue($this);
    }

    // =========================================================================
    // FRAMEWORK-INTERNAL READERS (Rsx_Mail::enqueue reads the built envelope)
    // =========================================================================

    /**
     * The whole envelope this instance accumulated.
     *
     * @return array
     */
    public function _envelope(): array
    {
        return [
            'to' => $this->_to,
            'cc' => $this->_cc,
            'bcc' => $this->_bcc,
            'reply_to' => $this->_reply_to,
            'attachments' => $this->_attachments,
            'dedupe_key' => $this->_dedupe_key,
            'send_at' => $this->_send_at,
            'related_type' => $this->_related_type,
            'related_id' => $this->_related_id,
        ];
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Turn a recipient argument into ['address' => ..., 'name' => ...].
     *
     * A string is an address. An object must expose ->email; its display name comes
     * from get_printed_name() when the method exists, else a trimmed first/last pair,
     * else null. Anything else throws - a silently dropped recipient is an email
     * nobody notices never arrived.
     *
     * @param object|string $recipient
     * @param string|null $name Explicit name; always wins when given.
     * @param string $field Which envelope field is being set (for the error message).
     * @return array{address: string, name: ?string}
     */
    private static function _resolve_recipient($recipient, ?string $name, string $field): array
    {
        if (is_string($recipient)) {
            $address = strtolower(trim($recipient));

            if ($address === '') {
                throw new \InvalidArgumentException(
                    static::class . "::{$field}() was given an empty address."
                );
            }

            return ['address' => $address, 'name' => $name !== null ? trim($name) : null];
        }

        if (!is_object($recipient)) {
            throw new \InvalidArgumentException(
                static::class . "::{$field}() takes an email address or an object exposing ->email, got "
                . gettype($recipient) . '.'
            );
        }

        $address = strtolower(trim((string) ($recipient->email ?? '')));

        if ($address === '') {
            throw new \InvalidArgumentException(
                static::class . "::{$field}() was given a " . get_class($recipient)
                . ' with no ->email value.'
            );
        }

        if ($name === null) {
            $name = static::_resolve_recipient_name($recipient);
        }

        $name = $name !== null ? trim($name) : null;

        return ['address' => $address, 'name' => $name !== '' ? $name : null];
    }

    /**
     * The display name for a recipient object, or null when it has none to give.
     */
    private static function _resolve_recipient_name(object $recipient): ?string
    {
        if (method_exists($recipient, 'get_printed_name')) {
            $printed = trim((string) $recipient->get_printed_name());

            return $printed !== '' ? $printed : null;
        }

        $composed = trim(
            trim((string) ($recipient->first_name ?? '')) . ' ' . trim((string) ($recipient->last_name ?? ''))
        );

        return $composed !== '' ? $composed : null;
    }
}
