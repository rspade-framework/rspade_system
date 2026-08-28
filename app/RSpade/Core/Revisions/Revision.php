<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use RuntimeException;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Rsx_Result_Set;
use App\RSpade\Core\Revisions\Revision_Codec;
use App\RSpade\Core\Revisions\Revision_Model;
use App\RSpade\Core\Revisions\Transaction_Model;
use App\RSpade\Core\Session\Session;

/**
 * Revision - the static facade over revision recording.
 *
 * It owns exactly one thing: the CURRENT TRANSACTION, meaning the `_transactions` row
 * that every revision recorded by this process right now belongs to. Everything else here
 * is either a way to influence that row (describe(), without()) or a way to read
 * revisions back (for_transaction(), transactions_for()).
 *
 * THE TRANSACTION IS MINTED LAZILY. Nothing is written until a revisioned model actually
 * saves, so a request that changed nothing leaves no row - and a process that never
 * touches a revisioned model never touches these tables at all.
 *
 * THE STATE IS PER-PROCESS AND MUST BE RESET AT EVERY UNIT BOUNDARY. A web request is one
 * process and one unit, so the two coincide; a task worker, a test runner and an Ajax
 * batch are one process running MANY units, and without a reset every write they make for
 * the rest of their lifetime would be filed under the first unit's transaction. Hence
 * _reset_request_state(), called at each of those boundaries:
 *
 *   - Dispatcher::dispatch() and Api_Dispatcher::dispatch()  (one web / API request)
 *   - Ajax::internal()                                       (one batched or nested call)
 *   - Task_Worker_Command and Task::internal()               (one task)
 *   - Rsx_Test_Abstract                                      (one test)
 *
 * Nothing here is a Laravel database transaction. Revisions are written on the SAME
 * connection as the record write they describe, immediately - so a real DB transaction
 * that rolls back discards its revisions along with the writes they describe, which is
 * exactly right and is why this never defers to DB::afterCommit().
 */
class Revision
{
    /**
     * The `source` name each reset point declares, mapped to its stored enum value. A name
     * outside this map is a programming error and throws: the source is a fixed vocabulary,
     * not a free-form label, and a typo would silently create an unqueryable transaction.
     *
     * @var array<string, int>
     */
    private const SOURCES = [
        'web' => Transaction_Model::SOURCE_WEB,
        'ajax' => Transaction_Model::SOURCE_AJAX,
        'api' => Transaction_Model::SOURCE_API,
        'task' => Transaction_Model::SOURCE_TASK,
        'cli' => Transaction_Model::SOURCE_CLI,
        'test' => Transaction_Model::SOURCE_TEST,
    ];

    /** The current transaction row, or null when nothing has been recorded yet. */
    private static ?Transaction_Model $transaction = null;

    /** The revisions recorded under the current transaction, in order. @var array<int, Revision_Model> */
    private static array $revisions = [];

    /** The stored source_id for this unit of work. */
    private static ?int $source_id = null;

    /** The endpoint this unit of work is serving, for the transaction row. */
    private static ?string $endpoint = null;

    /** The _api_request_log row for this unit, when the source is the API. */
    private static ?int $api_request_log_id = null;

    /** The operator-facing description, applied whether it is set before or after the mint. */
    private static ?string $description = null;

    /** Recording suppressed for the duration of a without() callable. */
    private static bool $suppressed = false;

    /**
     * Re-entrancy guard around the mint. Writing the transaction row is itself a model
     * save, and resolving its actor reads the session (which can write the _sessions row):
     * a revisioned write reached from inside that must not try to mint a second time.
     */
    private static bool $minting = false;

    /**
     * The id of the transaction every revision recorded right now belongs to, MINTING the
     * row on first call.
     *
     * This is a WRITER. Call it only from the recording path - anything that merely wants
     * to look at the current transaction calls current_transaction(), which never creates
     * one.
     */
    public static function transaction_id(): int
    {
        if (self::$transaction !== null) {
            return (int) self::$transaction->id;
        }

        if (self::$minting) {
            throw new RuntimeException('Revision::transaction_id() re-entered while minting the transaction row. A revisioned model write happened inside the mint itself.');
        }

        self::$minting = true;

        try {
            $actor = Rsx_Model_Abstract::_resolve_context_actor();

            $transaction = new Transaction_Model();
            $transaction->site_id = self::_current_site_id();
            $transaction->actor_type = $actor === null ? null : $actor['type'];
            $transaction->actor_id = $actor === null ? null : $actor['id'];
            $transaction->source_id = self::_current_source_id();
            $transaction->endpoint = self::$endpoint === null ? null : substr(self::$endpoint, 0, 255);
            $transaction->ip = self::_current_ip();
            $transaction->api_request_log_id = self::$api_request_log_id;
            $transaction->revision_count = 0;
            $transaction->description = self::$description;
            $transaction->save();

            self::$transaction = $transaction;
        } finally {
            self::$minting = false;
        }

        return (int) self::$transaction->id;
    }

    /**
     * The current transaction row, or null when this unit of work has not recorded
     * anything. A READER: unlike transaction_id() it never mints.
     */
    public static function current_transaction(): ?Transaction_Model
    {
        return self::$transaction;
    }

    /**
     * The revisions recorded under the current transaction so far, in the order they were
     * written. Empty until something is recorded.
     *
     * @return array<int, Revision_Model>
     */
    public static function current_revisions(): array
    {
        return self::$revisions;
    }

    /**
     * Attach a human description to this unit of work ("Imported 40 contacts from CSV").
     *
     * Works before OR after the first revision: called early it is carried into the row at
     * mint time, called later it updates the row that already exists. Purely descriptive -
     * nothing keys on it.
     */
    public static function describe(string $description): void
    {
        self::$description = $description;

        if (self::$transaction !== null) {
            self::$transaction->description = $description;
            self::$transaction->save();
        }
    }

    /**
     * Run $callable with recording SUPPRESSED, and return whatever it returns.
     *
     * For a write that is not a user action and would only be noise in a history: a data
     * migration, a backfill, a denormalized counter being recomputed. Suppression is
     * restored in a finally, so a throw inside the callable cannot leave it off.
     *
     * @param callable $callable
     * @return mixed
     */
    public static function without(callable $callable)
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callable();
        } finally {
            self::$suppressed = $previous;
        }
    }

    /**
     * Whether recording is currently suppressed. Read by the model's write-effect path.
     */
    public static function is_suppressed(): bool
    {
        return self::$suppressed;
    }

    /**
     * Every revision recorded under one transaction, oldest first (its `sequence` order,
     * which is the order the writes happened in).
     */
    public static function for_transaction(int $transaction_id): Rsx_Result_Set
    {
        return Revision_Model::where('transaction_id', $transaction_id)
            ->orderBy('sequence', 'asc')
            ->result_set();
    }

    /**
     * Every transaction that touched one record, newest first.
     *
     * Matches the record pair OR the root pair, so it answers for both directions of the
     * #[Revision_Parent] relationship: asking a parent finds the transactions that wrote
     * its children (they carry the parent as their root), and asking a child finds its own.
     */
    public static function transactions_for(Rsx_Model_Abstract $record): Rsx_Result_Set
    {
        $class = class_basename($record);
        $id = (int) $record->getKey();

        $transaction_ids = Revision_Model::query()
            ->where(function ($query) use ($class, $id) {
                $query->where(function ($inner) use ($class, $id) {
                    $inner->where('record_type', $class)->where('record_id', $id);
                })->orWhere(function ($inner) use ($class, $id) {
                    $inner->where('root_type', $class)->where('root_id', $id);
                });
            })
            ->select('transaction_id');

        return Transaction_Model::whereIn('id', $transaction_ids)
            ->orderBy('id', 'desc')
            ->result_set();
    }

    /**
     * Record ONE revision under the current transaction. The recording path's only entry
     * point; Rsx_Model_Abstract builds the document and calls this.
     *
     * The row is written on the SAME connection, immediately - see the class docblock.
     * `sequence` is the running count within the transaction, so a history screen can
     * replay one action's writes in order.
     *
     * @param Rsx_Model_Abstract $record The record that changed
     * @param int $operation_id A Revision_Model::OPERATION_* value
     * @param array<string, array{0: mixed, 1: mixed}> $diff `{field: [before, after]}`
     * @param array{type: string, id: int} $root The root pair (self, or the #[Revision_Parent] target)
     */
    public static function _record(Rsx_Model_Abstract $record, int $operation_id, array $diff, array $root): Revision_Model
    {
        $transaction_id = self::transaction_id();

        $revision = new Revision_Model();
        $revision->transaction_id = $transaction_id;
        $revision->site_id = self::_record_site_id($record);
        $revision->record_type = class_basename($record);
        $revision->record_id = (int) $record->getKey();
        $revision->root_type = $root['type'];
        $revision->root_id = $root['id'];
        $revision->operation_id = $operation_id;
        $revision->sequence = count(self::$revisions) + 1;
        $revision->changes = Revision_Codec::encode(json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $revision->save();

        self::$revisions[] = $revision;

        // A transaction minted with no site of its own adopts its first revision's - the
        // case that matters is a portal write, whose site is declared on the portal facade
        // rather than on the staff session the mint could read.
        if (self::$transaction->site_id === null && $revision->site_id !== null) {
            self::$transaction->site_id = $revision->site_id;
        }

        // The count lives on the row so a history screen can say "this action changed 4
        // things" without loading them. Incremented in SQL rather than re-saved from the
        // in-memory model: the same process can be inside a DB transaction with other
        // writers, and the increment is the only value that matters.
        self::$transaction->revision_count = self::$transaction->revision_count + 1;
        Transaction_Model::where('id', $transaction_id)
            ->raw_bulk()
            ->update([
                'revision_count' => self::$transaction->revision_count,
                'site_id' => self::$transaction->site_id,
            ]);

        return $revision;
    }

    /**
     * Record the _api_request_log row this unit of work produced.
     *
     * Called by Api_Dispatcher after the log row is written, which is AFTER the endpoint
     * ran - so the transaction usually already exists and the id is back-filled onto it.
     * Stored either way, so a mint that has not happened yet still picks it up.
     */
    public static function _set_api_request_log_id(int $api_request_log_id): void
    {
        self::$api_request_log_id = $api_request_log_id;

        if (self::$transaction !== null) {
            self::$transaction->api_request_log_id = $api_request_log_id;
            self::$transaction->save();
        }
    }

    /**
     * Begin a new unit of work: the next revisioned write mints a fresh transaction.
     *
     * @param string $source One of the SOURCES keys ('web', 'ajax', 'api', 'task', 'cli', 'test')
     * @param string|null $endpoint What this unit is serving, e.g. 'GET /clients' or 'Clients_Controller::save'
     */
    public static function _reset_request_state(string $source, ?string $endpoint = null): void
    {
        if (!isset(self::SOURCES[$source])) {
            throw new RuntimeException('Revision::_reset_request_state(): unknown source "' . $source . '". Known sources: ' . implode(', ', array_keys(self::SOURCES)) . '.');
        }

        self::$transaction = null;
        self::$revisions = [];
        self::$source_id = self::SOURCES[$source];
        self::$endpoint = $endpoint;
        self::$api_request_log_id = null;
        self::$description = null;
    }

    /**
     * The whole per-process state, for a caller that must restore it afterwards
     * (Ajax::internal() gives each nested/batched call its OWN transaction and then hands
     * the calling scope its own back, exactly as it does with the Turnstile latch).
     *
     * @return array<string, mixed>
     */
    public static function _snapshot_request_state(): array
    {
        return [
            'transaction' => self::$transaction,
            'revisions' => self::$revisions,
            'source_id' => self::$source_id,
            'endpoint' => self::$endpoint,
            'api_request_log_id' => self::$api_request_log_id,
            'description' => self::$description,
        ];
    }

    /**
     * Restore a state captured by _snapshot_request_state().
     *
     * @param array<string, mixed> $state
     */
    public static function _restore_request_state(array $state): void
    {
        self::$transaction = $state['transaction'];
        self::$revisions = $state['revisions'];
        self::$source_id = $state['source_id'];
        self::$endpoint = $state['endpoint'];
        self::$api_request_log_id = $state['api_request_log_id'];
        self::$description = $state['description'];
    }

    /**
     * Clear every static, source included. The framework-test seam; production always
     * declares a source through _reset_request_state().
     */
    public static function _testing_reset(): void
    {
        self::$transaction = null;
        self::$revisions = [];
        self::$source_id = null;
        self::$endpoint = null;
        self::$api_request_log_id = null;
        self::$description = null;
        self::$suppressed = false;
        self::$minting = false;
    }

    /**
     * The site this unit of work belongs to, or null.
     *
     * READS a session, never creates one: has_session() first, because minting a session
     * as a side effect of recording a revision would create a _sessions row for every CLI
     * script that happens to write a revisioned model.
     *
     * Deliberately does NOT consult the portal facade. Portal_Session::get_site_id() THROWS
     * when the application has not declared a site, and a recorder is the last place that
     * refusal should surface; the portal writes site-scoped records anyway, so the site
     * arrives from the record itself (see _record_site_id(), and the back-fill in _record()).
     */
    private static function _current_site_id(): ?int
    {
        if (!Session::has_session()) {
            return null;
        }

        $site_id = Session::get_site_id();

        return $site_id === 0 ? null : $site_id;
    }

    /**
     * The site a REVISION row carries: the record's own site when it has one, otherwise
     * the unit's. A revision is filed against the tenant whose data changed, which is the
     * record's, not whoever happened to be signed in.
     */
    private static function _record_site_id(Rsx_Model_Abstract $record): ?int
    {
        $site_id = $record->getAttribute('site_id');

        if ($site_id !== null) {
            return (int) $site_id;
        }

        return self::_current_site_id();
    }

    /**
     * The source for a transaction minted right now.
     *
     * Every reset point declares one, so in service this is always the declared value. The
     * DEFAULT covers a write that happens before any reset point runs - boot code, a one-off
     * artisan command calling straight into a model - and it is derived, not guessed: a CLI
     * process is 'cli', and anything else reached the framework over HTTP and is 'web'.
     * Both are true statements about where the write came from, which is all the column
     * claims.
     */
    private static function _current_source_id(): int
    {
        if (self::$source_id !== null) {
            return self::$source_id;
        }

        return PHP_SAPI === 'cli' ? Transaction_Model::SOURCE_CLI : Transaction_Model::SOURCE_WEB;
    }

    /**
     * The caller's IP, or null off a real request (a task, a CLI run).
     */
    private static function _current_ip(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $request = request();

        return $request === null ? null : $request->ip();
    }
}
