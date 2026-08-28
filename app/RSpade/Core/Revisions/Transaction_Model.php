<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Revisions\Revision_Model;

/**
 * Transaction_Model - one row per RUN that produced at least one recorded revision.
 *
 * A "run" is whatever the framework treats as one unit of work: a web request, one call
 * inside an Ajax batch, one background task, one CLI invocation, one test. The row is
 * minted LAZILY by Revision::transaction_id() on the first revisioned write, so a request
 * that changed nothing leaves nothing behind.
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
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _transactions
 *
 * @property int $id
 * @property int $site_id
 * @property int $actor_id
 * @property int $actor_type
 * @property int $source_id
 * @property string $endpoint
 * @property string $ip
 * @property int $api_request_log_id
 * @property int $revision_count
 * @property string $description
 * @property string $created_at
 * @property string $updated_at
 * @property int $created_by_id
 * @property int $created_by_type
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
class Transaction_Model extends Rsx_Model_Abstract
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
}
