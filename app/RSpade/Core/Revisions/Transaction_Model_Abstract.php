<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Revisions\Revision_Model;
use App\RSpade\Core\Session\Ip_Address;

/**
 * Transaction_Model_Abstract - one row per RUN that produced at least one recorded revision.
 *
 * A "run" is whatever the framework treats as one unit of work: a web request, one call
 * inside an Ajax batch, one background task, one CLI invocation, one test. The row is
 * minted LAZILY by Revision::transaction_id() on the first revisioned write, so a unit
 * that changed nothing leaves nothing behind - except a WRITER REQUEST (#[Writer], or a
 * #[Manual_Lock] that took a site write lock), whose row the site lock boundary mints
 * eagerly inside its transaction: every committed writer request has one.
 *
 * THE WRITER REQUEST HISTORY (Request_History) lives on the same row: request_ref (the
 * client's _request_ref, kept with a successful answer), request_fingerprint (sha256 of the
 * canonical validated params), request_payload (what was sent, redacted and capped),
 * response_status / response_body (the answer as sent, kept only with a reference),
 * duration_ms and completed_at. The payload and response columns are nulled after
 * rsx.request_history.payload_retention_days (Revision_Cleanup_Service::
 * prune_request_payloads()); request_history_held is a VIRTUAL column, true while any of
 * the three still holds a value, indexed so the prune reads only the rows it owes. A
 * replay's identity is (site_id, actor_type, actor_id, request_ref), UNIQUE.
 *
 * WHY THE GROUPING EXISTS. A revision on its own answers "this field changed". The
 * question an activity screen actually asks is "what did that one action change" - a
 * client save that also wrote three contact rows is ONE thing the user did, and the
 * transaction is what says so. It also carries the facts that belong to the action rather
 * than to any one record: who, from where, over which endpoint, from which IP, and (for
 * an API call) which _api_request_log row.
 *
 * NOT a database transaction. The name describes the unit of work as the application
 * understands it. Revisions are written on the same connection as the record write they
 * describe, so a real DB transaction that rolls back takes its revisions with it - which
 * is the behavior you want and is why nothing here defers to afterCommit.
 *
 * The actor is the polymorphic pair actor_type/actor_id, resolved by the same stamp
 * matrix the audit columns use (see Rsx_Model_Abstract::_resolve_context_actor()).
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's Transaction_Model lives here;
 * `Transaction_Model.php` beside it is a shell an application replaces by declaring
 * `class Transaction_Model extends Transaction_Model_Abstract` under rsx/models/ - so an
 * application's customization is only the members it actually changes, and a frozen
 * clone can never miss the members the framework adds next.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _transactions
 *
 * @property int $actor_id
 * @property int $actor_type
 * @property int $api_request_log_id
 * @property int $audit_session_id
 * @property string $completed_at
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $description
 * @property int $duration_ms
 * @property string $endpoint
 * @property int $id
 * @property int $ip_address_id
 * @property string $request_fingerprint
 * @property int $request_history_held
 * @property string $request_payload
 * @property string $request_ref
 * @property string $response_body
 * @property int $response_status
 * @property int $revision_count
 * @property int $site_id
 * @property int $source_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $source_id__label
 * @property-read string $source_id__constant
 *
 * @method static array source_id__enum() Get all enum definitions with full metadata
 * @method static array source_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array source_id__enum_labels() Get simple id => label map
 * @method static array source_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
abstract class Transaction_Model_Abstract extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const SOURCE_WEB = 1;
    const SOURCE_AJAX = 2;
    const SOURCE_API = 3;
    const SOURCE_TASK = 4;
    const SOURCE_CLI = 5;
    const SOURCE_TEST = 6;
    const SOURCE_COMPANION = 7;
    /**
     * _AUTO_GENERATED_ Enum constants
     */

    /**
     * UNBOUNDED: one row per revisioned run, so the row count grows with customer
     * activity rather than with the codebase. Consumed by DB-UNBOUNDED-01.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_transactions';

    /**
     * What a request was sent and what it answered are kept to serve a replay and for
     * debugging, never shown: a screen that lists transactions reads the columns it needs.
     *
     * @var string[]
     */
    public static $neverExport = ['request_payload', 'response_body'];

    protected $fillable = []; // No mass assignment - always explicit

    /**
     * actor_type is the polymorphic half of the actor pair: a BIGINT type-ref id in the
     * database, read and written as the class basename.
     */
    protected static $type_ref_columns = ['actor_type'];

    /**
     * Where the run came from. This is the CONTEXT of the write, not the identity - a
     * task and a CLI script can both be running as the same user.
     *
     * @var array
     */
    public static $enums = [
        'source_id' => [
            1 => [
                'constant' => 'SOURCE_WEB',
                'label' => 'Web',
                'order' => 1,
            ],
            2 => [
                'constant' => 'SOURCE_AJAX',
                'label' => 'Ajax',
                'order' => 2,
            ],
            3 => [
                'constant' => 'SOURCE_API',
                'label' => 'API',
                'order' => 3,
            ],
            4 => [
                'constant' => 'SOURCE_TASK',
                'label' => 'Task',
                'order' => 4,
            ],
            5 => [
                'constant' => 'SOURCE_CLI',
                'label' => 'CLI',
                'order' => 5,
            ],
            6 => [
                'constant' => 'SOURCE_TEST',
                'label' => 'Test',
                'order' => 6,
            ],
            7 => [
                'constant' => 'SOURCE_COMPANION',
                'label' => 'Companion',
                'order' => 7,
            ],
        ],
    ];

    /**
     * The revisions recorded under this transaction, in the order they happened.
     *
     * REPLACES Rsx_Model_Abstract::revisions() (which answers "the revisions ABOUT this
     * record"). A transaction is never itself a revisioned model, so the inherited
     * meaning is vacuous here and the FK relation is the only useful one.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    #[Relationship]
    public function revisions()
    {
        return $this->hasMany(Revision_Model::class, 'transaction_id');
    }

    /**
     * Who performed the run: a User_Model, Portal_User_Model or Login_User_Model, or null
     * when nobody was signed in. Read it as a property ($transaction->actor).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    #[Relationship]
    public function actor()
    {
        return $this->morphTo('actor', 'actor_type', 'actor_id');
    }

    /**
     * The client address of the run - `$transaction->ip`, null off a request (a task, a
     * CLI run). The row stores ip_address_id; the address lives once in _ip_addresses
     * (see Ip_Address).
     *
     * @return string|null
     */
    public function getIpAttribute(): ?string
    {
        return Ip_Address::address($this->attributes['ip_address_id'] ?? null);
    }

    /**
     * Assigning `$transaction->ip = $string` records the address in _ip_addresses and stores
     * its id.
     *
     * @param string|null $ip
     * @return void
     */
    public function setIpAttribute(?string $ip): void
    {
        $this->attributes['ip_address_id'] = Ip_Address::id($ip);
    }
}
