<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\Rsx_Result_Set;

/**
 * Email_Blocked_Address_Model_Abstract - the SITE's email block list (framework core).
 *
 * One row per address a site has ruled that no email it initiates may reach -
 * transactional included. The one exemption is SECURITY: a notice the recipient set in
 * motion (a sign-in code, a password reset) is not the site's correspondence, and blocking
 * it locks a person out of their own account. Rsx_Mail consults it at enqueue, again when the drain claims
 * a row, and at resend; Rsx_Mail::block_address() / unblock_address() /
 * is_address_blocked() / blocked_addresses() are the application's API to it.
 *
 * NOT THE OPT-OUT. _email_recipients.is_blocked_* is the RECIPIENT's choice, set by an
 * unsubscribe link, and a recipient cannot opt out of a password reset. This table is
 * the SITE's choice: a different author, a different lifetime, and a rule only SECURITY
 * escapes. Nothing a recipient can reach writes here, and the framework never writes
 * here on its own - no automatic entry on a bounce, no panel button. The rows are the
 * application's, so an application that keeps the list in sync with its own data may
 * reconcile against the whole list for a site.
 *
 * Every method names its site explicitly and runs outside the ambient site scope: the
 * drain checks every tenant's rows while declaring no tenant of its own.
 *
 * @property integer $id
 * @property integer $site_id
 * @property string $email
 * @property string $reason
 * @property string $created_at
 * @property string $updated_at
 * @mixin \Eloquent
 *
 * THE BASE OF A SPLIT MODEL. Every member lives here; `Email_Blocked_Address_Model.php`
 * beside it is a shell an application replaces by declaring
 * `class Email_Blocked_Address_Model extends Email_Blocked_Address_Model_Abstract` under
 * rsx/models/.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _email_blocked_addresses
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $email
 * @property int $id
 * @property string $reason
 * @property int $site_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
abstract class Email_Blocked_Address_Model_Abstract extends Rsx_Site_Model_Abstract
{
    // Infrastructure table: no UI subscribes to it, so writes must not kick the emitter.
    public static $realtime_silent = true;

    protected $table = '_email_blocked_addresses';
    protected $fillable = [];

    public static $enums = [];

    /**
     * The one spelling of an address on this list, the same as _email_recipients and
     * _email_queue.to_address use: trimmed and lowercased.
     */
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * The reason $email is on $site_id's list, or null when it is not listed.
     */
    public static function reason_for(int $site_id, string $email): ?string
    {
        $listed = static::listed_among($site_id, [$email]);

        return $listed[static::normalize($email)] ?? null;
    }

    /**
     * Which of $emails are on $site_id's list, in ONE query: normalized address => reason.
     * An address not listed is absent from the result.
     *
     * @param array<int, string> $emails
     * @return array<string, string>
     */
    public static function listed_among(int $site_id, array $emails): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($email) => static::normalize((string) $email),
            $emails
        ), fn ($email) => $email !== '')));

        if (empty($normalized)) {
            return [];
        }

        return static::without_site_scope(fn () => static::where('site_id', $site_id)
            ->whereIn('email', $normalized)
            ->pluck('reason', 'email')
            ->all());
    }

    /**
     * Put $email on $site_id's list. IDEMPOTENT: an address already listed keeps its row
     * and takes the new reason.
     */
    public static function block(int $site_id, string $email, string $reason): void
    {
        $email = static::normalize($email);
        $reason = trim($reason);

        if ($email === '') {
            throw new \InvalidArgumentException('An address to block is required.');
        }

        if ($reason === '') {
            throw new \InvalidArgumentException("A reason is required to block {$email}: it is what the Blocked row and its tooltip say.");
        }

        if (mb_strlen($email) > static::field_length('email')) {
            throw new \InvalidArgumentException("Address '{$email}' is longer than " . static::field_length('email') . ' characters.');
        }

        if (mb_strlen($reason) > static::field_length('reason')) {
            throw new \InvalidArgumentException('A block reason may be at most ' . static::field_length('reason') . ' characters.');
        }

        static::without_site_scope(function () use ($site_id, $email, $reason) {
            $row = static::where('site_id', $site_id)->where('email', $email)->first();

            if ($row === null) {
                $row = new static();
                $row->site_id = $site_id;
                $row->email = $email;
            }

            $row->reason = $reason;
            $row->save();
        });
    }

    /**
     * Take $email off $site_id's list. IDEMPOTENT: an address that is not listed is not
     * an error.
     */
    public static function unblock(int $site_id, string $email): void
    {
        $email = static::normalize($email);

        static::without_site_scope(function () use ($site_id, $email) {
            $row = static::where('site_id', $site_id)->where('email', $email)->first();

            if ($row !== null) {
                $row->delete();
            }
        });
    }

    /**
     * Every entry on $site_id's list.
     *
     * A result set rather than a collection: the list is small by nature, but its size is
     * the application's business, not an assumption to build in.
     */
    public static function all_for_site(int $site_id): Rsx_Result_Set
    {
        return static::without_site_scope(fn () => static::where('site_id', $site_id)->result_set());
    }
}
