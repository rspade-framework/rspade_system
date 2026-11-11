<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Locks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\Models\Site_Scoped;
use App\RSpade\Core\Database\Rsx_DatabaseTransactionsManager;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock_Directives;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Revisions\Revision;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Site_Lock - the per-site reader/writer lock, the write section built on it, and the
 * enforcement that a write to a site-scoped model happens only under that site's write lock.
 *
 * THE LOCK. One readers-writer lock per site id, served by rsx-lockd (RsxLocks::site_*_lock,
 * cluster:SITE_<id>). Any number of readers run together; a writer waits until every reader
 * has closed, holds back new readers while it waits, and runs alone. There is NO upgrade from
 * read to write: two readers upgrading would each wait for the other forever, so code decides
 * at its start whether it reads or writes - code that reads and then writes based on what it
 * read takes the WRITE lock first and does the read inside it.
 *
 * NESTING IS COUNTED (RsxLocks reentrancy, per process). A nested acquisition of a hold this
 * process already has never reaches the daemon; it bumps a count, and the real lock is let go
 * only when every acquisition has been released:
 *
 *   - read inside read     -> the read count; N acquisitions need N releases;
 *   - write inside write   -> the write count, likewise;
 *   - read inside write    -> a nested hold of the WRITE lock (a write hold satisfies a read);
 *                             its release unwinds that one level and never the write itself;
 *   - write inside read    -> refused: no upgrade (above).
 *
 * So a library that takes and releases RsxLocks::site_write_lock() / site_read_lock() inside
 * a caller's hold - a #[Writer] endpoint's automatic lock, a Site_Lock::write() section -
 * leaves the caller's lock held. The closure API (write() / read()) nests on the same counts.
 *
 * THE PUBLIC API
 *
 *   Site_Lock::write(int $site_id, callable $work, ?string $description = null): mixed
 *       write lock -> revision unit of work -> DB transaction -> $work -> commit -> release.
 *       A throw rolls back, then releases, then rethrows. Reentrant: already inside a write
 *       section (or holding the write lock) for $site_id, $work just runs - no second lock,
 *       no second transaction, no new unit of work.
 *
 *   Site_Lock::read(int $site_id, callable $work): mixed
 *       read lock -> $work -> release. Holding ANY lock on $site_id (read, or write - a
 *       write hold satisfies a read) just runs $work.
 *
 *   Site_Lock::bypass(int $site_id, string $reason, callable $work): mixed
 *       Writes to $site_id are permitted WITHOUT a lock for the duration of $work. No
 *       transaction is opened (a bypass must never become a long transaction holding row
 *       locks against every writer). The reason is mandatory and logged. Default-closed:
 *       outside the callable, enforcement is back.
 *
 *   Site_Lock::bypass_all_sites(string $reason, callable $work): mixed
 *       The same, for a framework or maintenance pass that walks EVERY site's rows outside
 *       the site scope (a queue drain, a retention sweep) and so cannot name one site.
 *
 *   Site_Lock::write_exception(string $reason, callable $work): mixed
 *   Site_Lock::acquire_write_exception(string $reason): string   (the token form)
 *   Site_Lock::release_write_exception(string $token): void
 *       A WRITE EXCEPTION: a STATE, not a lock. It takes nothing in rsx-lockd, blocks nobody
 *       and waits for nothing; while one is open, site-scoped writes are permitted whether or
 *       not a write lock is held. For a view, audit or telemetry record - a row nothing reads
 *       to decide a value it writes - so a read endpoint that records stays a reader. It opens
 *       NO transaction: inside a writer or a write section its writes ride the open
 *       transaction (a later throw rolls them back with the rest); anywhere else each write
 *       commits at once (autocommit). Counted (nested acquisitions; writes stay permitted
 *       until the last release), valid anywhere, the reason mandatory (logged at DEBUG only:
 *       it runs on ordinary page views), and the site never changes while one is open. An
 *       enforcement window that closes with one still open (an endpoint, a hook, a task, a
 *       command) releases it and fails naming it - unless the window is already failing.
 *
 *   Site_Lock::held_mode(?int $site_id = null): ?string   'read' | 'write' | null
 *   Site_Lock::in_write_exception(): bool
 *   Site_Lock::in_bypass(?int $site_id = null): bool
 *       What this process holds right now - for a library that must refuse to run under a
 *       write lock (a client for a remote service). held_mode() with no site answers for the
 *       one site whose lock this process holds (rule 5: never more than one).
 *
 * ENFORCEMENT. A process is either inside an ENFORCEMENT WINDOW or not. Inside one (a task
 * body, an artisan command or a system/script.php script (Site_Lock_Cli), an application
 * hook, and - wired by the dispatchers - an Ajax / API / companion / page-route handler), a
 * save() / delete() / per-record bulk write / raw bulk update or delete of a Site_Scoped model
 * for site X FATALS unless this process has WRITE AUTHORITY for X: it holds X's write lock
 * (a write section, a #[Writer] endpoint, or a raw RsxLocks::site_write_lock()), it is
 * inside a bypass covering X, or it has a write exception open. Outside every window (a the SITE_LOCK_EXEMPT constant provisioning
 * command - migrations, seeders, the test runner and its fixture code, the database reset)
 * nothing is checked. A raw DB::table() / SQL write is never seen - that is the documented
 * limit, not a hole to close.
 *
 * EXEMPT MODELS. A Site_Scoped model whose table is ONLY ever an append-only view, audit or
 * telemetry record - what happened, never tenant data a user edits and never a row read to
 * decide a business write - declares the class attribute #[Site_Lock_Exempt('<reason>')]
 * (attribute-only metadata; the reason is required). Its writes are never checked. A table
 * that also holds business data takes a write exception at the recording call site instead.
 * See Site_Scoped.
 *
 * THE HARD RULES (each a RuntimeException naming its remedy)
 *
 *   1. A site lock is never ACQUIRED while a database transaction the lock layer did not open
 *      is already open: waiting in rsx-lockd while holding MySQL row locks is a cross-system
 *      cycle neither deadlock detector can see. (The test harness's per-test transaction is a
 *      registered baseline, in the suite process only.)
 *   2. The automatic per-request lock is taken only at the endpoint boundary. There is no
 *      lazy path here: nothing acquires a lock because a write happened.
 *   3. A write lock is released only after its transaction committed or rolled back.
 *   4. A child process is spawned through Rsx_Artisan (lock groups carry the parent's hold).
 *   5. One site's lock at a time per process: acquiring site B's lock while holding any lock
 *      on site A fatals. A process serves one site at a time.
 *
 * And: the site is never changed while a site lock is held or a bypass is open
 * (_assert_site_change_permitted(), called by the Session / Portal_Session setters).
 *
 * AFTER THE COMMIT. Once a writer's (or a write section's, or a #[Manual_Lock] request's)
 * transaction has committed, nothing that throws afterwards fails it or claims a rollback: the
 * after-commit callbacks are contained by Rsx_DatabaseTransactionsManager, and a throw that still
 * reaches _commit() / __close_manual_transaction() with the level below the transaction's is
 * reported and the request answers what it returned. The one exception is an infrastructure
 * outage (Rsx_DatabaseTransactionsManager::_is_contained()): it ends the request, still without
 * claiming a rollback. A commit that itself fails leaves the level where it was: rolled back,
 * and the request fails.
 *
 * HOLD-TIME WARNINGS. Releasing a lock held longer than rsx.locks.warn_write_hold_seconds /
 * warn_read_hold_seconds logs a WARNING naming the site, the holder, the time held and the
 * time waited for the grant. 0 disables. Nothing is capped.
 *
 * THE DISPATCHER API (framework-internal; what an automatic reader / writer boundary calls)
 *
 *   $token = Site_Lock::_acquire(int $site_id, string $mode, string $holder_label)
 *       Take the site's lock (MODE_READ | MODE_WRITE) under every rule above, waiting
 *       forever, and record it as THE lock this process holds: the label names the holder in
 *       warnings and enforcement messages.
 *   Site_Lock::_begin_transaction(string $token)   writer: right after the grant
 *   Site_Lock::_commit(string $token)              writer: right before the release
 *   Site_Lock::_rollback(string $token)            writer: on any throw, before the release
 *   Site_Lock::_release(string $token)             always, last; rule 3 is checked here
 *   Site_Lock::_enter_enforcement(string $label) / _leave_enforcement()
 *       Bracket exactly the handler function. Windows nest (a task run inline from an
 *       endpoint opens its own); every enter is paired with a leave in a finally.
 *
 * A reader: _acquire(READ) -> _enter_enforcement -> handler -> _leave_enforcement -> _release.
 * A writer: _acquire(WRITE) -> _begin_transaction -> _enter_enforcement -> handler ->
 * _leave_enforcement -> _commit (or _rollback on a throw) -> _release.
 *
 * THE APPLICATION HOOKS. Main::init() / Portal_Main::init(), Main::pre_dispatch() /
 * Portal_Main::pre_dispatch(), a controller's own pre_dispatch() and Main::unhandled_route()
 * run through _run_hook(): NO automatic lock - like a task body, they are not a reader or a
 * writer surface - but inside an ENFORCEMENT WINDOW, so a write to a Site_Scoped model there
 * fatals unless the hook took the lock itself (Site_Lock::write()), opened a bypass, or the
 * model is #[Site_Lock_Exempt]. A hook hands over CLEAN: a site lock or database transaction
 * it acquired and did not release is let go / rolled back and the request fails naming the
 * hook. The site may change in a hook window (init() declares a command's site), under the
 * ordinary rule that it never changes under a lock or bypass.
 *
 * THE REQUEST BOUNDARY. Every dispatcher (Ajax::execute(), Api_Dispatcher, the companion
 * dispatcher, Dispatcher::__run_matched() for page routes) runs, per call:
 *
 *   Main::pre_dispatch (_run_hook) -> controller pre_dispatch (_run_hook) ->
 *   Site_Lock::_run_request(_surface_mode($target), $label, fn () => [
 *       Site_Lock::_enforce($label, handler) -> rsx.post_dispatch -> (API) response], ?$site_id)
 *
 * so the lock (and a writer's transaction) is taken AFTER both pre_dispatch hooks and spans
 * the handler through the post_dispatch event (a post_dispatch throw rolls a writer back),
 * while the ENFORCEMENT WINDOW is exactly the handler. Modes: 'reader', 'writer', 'manual'
 * (#[Manual_Lock]: no lock; its first raw RsxLocks::site_write_lock() begins a REQUEST
 * TRANSACTION committed when the handler returns, rolled back on a throw, held past an early
 * release - which is logged, after which rule 1 refuses another site lock until the handler
 * returns), null (a page route that declares none). The site is the experience's current one
 * (_request_site_id()); site 0 (no tenant) is locked like any other. A process already holding the site's lock runs
 * a nested reader under any hold and a nested writer under a write hold (a writer under a read
 * hold fatals). A transaction the handler leaves open is rolled back and fails the request.
 * Every Ajax call of a batch takes its own lock: a lock shared across calls would be held
 * while the next call's pre_dispatch hooks run, which must run with no lock.
 *
 * A REQUEST WINDOW (_enforce(), or _enter_enforcement($label, true)) also refuses every
 * change of the site - Session::set_site_id() / set_temporary_site_id() /
 * clear_temporary_site_id() / impersonate() / reset_impersonation() - except through
 * RsxAuth::enter_site() (_enter_site(): no lock held -> that site's write section -> the
 * setter -> commit -> release). A task window does not: a task changes site between sections.
 *
 * See: php artisan rsx:man site_locks
 */
class Site_Lock
{
    public const MODE_READ = 'read';
    public const MODE_WRITE = 'write';

    /** The attribute a view / audit / telemetry Site_Scoped model declares to be exempt from enforcement. */
    public const EXEMPT_ATTRIBUTE = 'Site_Lock_Exempt';

    /**
     * The one site lock this process holds through the lock layer, or null.
     * [token, site_id, mode, label, granted_at (hrtime ns), waited (seconds), transaction_level (?int)]
     */
    private static ?array $held = null;

    /** Database transactions the lock layer opened and has not yet closed. */
    private static int $owned_transactions = 0;

    /** Enforcement windows, innermost last: [label, request (an endpoint / route handler window)]. */
    private static array $enforcement = [];

    /** Request handler windows open (a subset of $enforcement): the site never changes inside one. */
    private static int $request_windows = 0;

    /** RsxAuth::enter_site() is setting the session's site under that site's write lock. */
    private static bool $entering_site = false;

    /** Site_Lock::_acquire() is inside RsxLocks: its grant is never a #[Manual_Lock] request transaction. */
    private static bool $acquiring = false;

    /**
     * The #[Manual_Lock] request in progress, or null. Its REQUEST TRANSACTION begins at the
     * endpoint's first raw RsxLocks::site_write_lock() grant and is closed by _run_request()
     * when the endpoint returns.
     * [label, site_id (?int), transaction_level (?int), counted (bool: holds a slot of $owned_transactions),
     *  wrote (bool: a fresh site write lock was granted during the request - it is a WRITER request)]
     */
    private static ?array $manual_request = null;

    /** Open bypasses, innermost last: [site_id (null = every site), reason]. */
    private static array $bypasses = [];

    /**
     * Open write exceptions, in acquisition order: [token, reason, window]. window is the
     * number of enforcement windows open when it was acquired - the window that closes below
     * that depth is the one it leaked from.
     */
    private static array $write_exceptions = [];

    /** The transaction level the test harness opened per test (suite process only). */
    private static ?int $test_transaction_baseline = null;

    /** class => exempt?  (manifest attribute index, once per class) */
    private static array $exempt_cache = [];

    /** class => uses Site_Scoped? */
    private static array $site_scoped_cache = [];

    // =============================================================================================
    // PUBLIC API
    // =============================================================================================

    /**
     * Run $work as a WRITE SECTION on $site_id: write lock, revision unit of work, database
     * transaction, commit, release. See the class docblock.
     *
     * The revision unit of work is begun OUTSIDE the database transaction and restored to the
     * caller's in a finally, so a rolled-back section can never leave the unit pointing at a
     * _transactions row the rollback removed (Revision::begin_unit_of_work()).
     *
     * @param int $site_id
     * @param callable $work
     * @param string|null $description The revision unit's operator-facing description
     * @return mixed Whatever $work returns
     */
    public static function write(int $site_id, callable $work, ?string $description = null): mixed
    {
        // Reentrant: a section (or a raw write hold) on this site is already open.
        if (RsxLocks::holds(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . $site_id, RsxLocks::WRITE_LOCK)) {
            return $work();
        }

        $token = static::_acquire($site_id, self::MODE_WRITE, static::__section_label('Site_Lock::write()', $description));

        try {
            $revision_state = Revision::_snapshot_request_state();

            try {
                Revision::begin_unit_of_work($description);
                static::_begin_transaction($token);

                try {
                    $result = $work();
                } catch (Throwable $e) {
                    static::_rollback($token);

                    throw $e;
                }

                static::_commit($token);

                return $result;
            } finally {
                Revision::_restore_request_state($revision_state);
            }
        } finally {
            static::_release($token);
        }
    }

    /**
     * Run $work under $site_id's READ lock. Holding any lock on the site already just runs it.
     *
     * @param int $site_id
     * @param callable $work
     * @return mixed Whatever $work returns
     */
    public static function read(int $site_id, callable $work): mixed
    {
        if (RsxLocks::holds(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . $site_id, RsxLocks::READ_LOCK)) {
            return $work();
        }

        $token = static::_acquire($site_id, self::MODE_READ, static::__section_label('Site_Lock::read()', null));

        try {
            return $work();
        } finally {
            static::_release($token);
        }
    }

    /**
     * Permit writes to $site_id without its lock for the duration of $work. No transaction.
     *
     * For a pass that tolerates concurrent writers and must not hold a lock against them.
     * The reason is mandatory and written to the log; changing the site inside still fatals.
     *
     * @param int $site_id
     * @param string $reason Why this pass may write unlocked (logged)
     * @param callable $work
     * @return mixed Whatever $work returns
     */
    public static function bypass(int $site_id, string $reason, callable $work): mixed
    {
        return static::__run_bypass($site_id, $reason, $work, "Site_Lock::bypass({$site_id})");
    }

    /**
     * bypass() for EVERY site: a framework or maintenance pass that walks all tenants' rows
     * outside the site scope (a queue drain, a retention sweep) and cannot name one site.
     *
     * @param string $reason Why this pass may write unlocked (logged)
     * @param callable $work
     * @return mixed Whatever $work returns
     */
    public static function bypass_all_sites(string $reason, callable $work): mixed
    {
        return static::__run_bypass(null, $reason, $work, 'Site_Lock::bypass_all_sites()');
    }

    /**
     * Run $work inside a WRITE EXCEPTION: site-scoped writes are permitted for its duration,
     * with no lock taken and no transaction opened. See the class docblock.
     *
     * For a view, audit or telemetry record - an append-only row nothing reads to decide a
     * value it writes. NEVER for business data, nor for a row read to decide a later write
     * (two requests under exceptions both see "not yet" and both act): that is a writer, or
     * a task. Whatever the record triggers that writes business data goes to Task::dispatch().
     *
     * @param string $reason What is being recorded (mandatory; logged at DEBUG)
     * @param callable $work
     * @return mixed Whatever $work returns
     */
    public static function write_exception(string $reason, callable $work): mixed
    {
        $token = static::acquire_write_exception($reason);

        try {
            return $work();
        } finally {
            static::release_write_exception($token);
        }
    }

    /**
     * Open a write exception and return its token - the form for code whose acquire and
     * release are in different places. Release it in a finally:
     *
     *     $token = Site_Lock::acquire_write_exception('record the download');
     *     try { ... } finally { Site_Lock::release_write_exception($token); }
     *
     * An enforcement window (an endpoint, a hook, a task, a command) that closes with it
     * still open releases it and fails naming the reason.
     *
     * @param string $reason What is being recorded (mandatory; logged at DEBUG)
     * @return string The token for release_write_exception()
     */
    public static function acquire_write_exception(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException(
                'Site_Lock::write_exception() requires a reason: say what is being recorded '
                . "('record the document view'). A write exception is for view, audit and telemetry records only."
            );
        }

        $token = 'write_exception_' . bin2hex(random_bytes(8));

        self::$write_exceptions[] = ['token' => $token, 'reason' => $reason, 'window' => count(self::$enforcement)];

        Log::debug("Site lock write exception: '{$reason}' in " . static::__current_label());

        return $token;
    }

    /**
     * Release the write exception $token names. Releases may come in any order; writes stay
     * permitted while any exception is open.
     *
     * @param string $token What acquire_write_exception() returned
     */
    public static function release_write_exception(string $token): void
    {
        foreach (self::$write_exceptions as $index => $exception) {
            if ($exception['token'] === $token) {
                array_splice(self::$write_exceptions, $index, 1);

                return;
            }
        }

        throw new RuntimeException(
            'Site_Lock::release_write_exception(): no write exception with this token is open. It was released '
            . 'already, or the enforcement window it was acquired in closed with it open and released it.'
        );
    }

    /** Is a write exception open in this process? */
    public static function in_write_exception(): bool
    {
        return self::$write_exceptions !== [];
    }

    /**
     * Is a bypass open covering $site_id (an all-sites bypass covers every site)? With no
     * site: is any bypass open?
     */
    public static function in_bypass(?int $site_id = null): bool
    {
        foreach (self::$bypasses as $bypass) {
            if ($site_id === null || $bypass['site_id'] === null || $bypass['site_id'] === $site_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site lock this process holds on $site_id: 'write' (Site_Lock::MODE_WRITE), 'read'
     * (MODE_READ) or null. Counts every hold - an automatic request lock, a write or read
     * section, a raw RsxLocks::site_write_lock() / site_read_lock(). A write exception or a
     * bypass is not a lock: ask in_write_exception() / in_bypass().
     *
     * $site_id null asks about THE site whose lock this process holds, whichever it is: a
     * process holds one site's lock at a time (rule 5), so the answer is that lock's mode,
     * or null when it holds none. This is the question a library asks before a slow call
     * ("am I under a write lock?") without knowing which site its caller locked.
     *
     * @param int|null $site_id
     * @return string|null
     */
    public static function held_mode(?int $site_id = null): ?string
    {
        $held = RsxLocks::held_site_locks();

        if ($site_id === null) {
            if ($held === []) {
                return null;
            }

            $site_id = array_key_first($held);
        }

        return match ($held[$site_id] ?? null) {
            RsxLocks::WRITE_LOCK => self::MODE_WRITE,
            RsxLocks::READ_LOCK => self::MODE_READ,
            default => null,
        };
    }

    /**
     * Does this process have WRITE AUTHORITY for $site_id right now: its write lock held, a
     * bypass covering it open, or a write exception open?
     */
    public static function has_write_authority(int $site_id): bool
    {
        if (RsxLocks::holds(RsxLocks::CLUSTER_LOCK, RsxLocks::LOCK_SITE_PREFIX . $site_id, RsxLocks::WRITE_LOCK)) {
            return true;
        }

        return self::$write_exceptions !== [] || static::in_bypass($site_id);
    }

    // =============================================================================================
    // DISPATCHER API (framework-internal)
    // =============================================================================================

    /**
     * Take $site_id's lock as THE site lock of this process. Waits forever.
     *
     * @param int $site_id
     * @param string $mode MODE_READ | MODE_WRITE
     * @param string $holder_label Who holds it ('Ajax Clients_Controller::save', 'task X::y (run #12)')
     * @return string The lock token, for _begin_transaction() / _commit() / _rollback() / _release()
     */
    public static function _acquire(int $site_id, string $mode, string $holder_label): string
    {
        if (!in_array($mode, [self::MODE_READ, self::MODE_WRITE], true)) {
            shouldnt_happen("Site_Lock::_acquire(): unknown mode '{$mode}'");
        }

        if (self::$held !== null) {
            if (self::$held['site_id'] === $site_id && self::$held['mode'] === self::MODE_READ && $mode === self::MODE_WRITE) {
                static::__throw_no_upgrade($site_id);
            }

            if (self::$held['site_id'] !== $site_id) {
                static::__throw_other_site($site_id, self::$held['site_id']);
            }

            shouldnt_happen(
                "Site_Lock::_acquire({$site_id}, {$mode}) while this process already holds that site's "
                . self::$held['mode'] . ' lock for ' . self::$held['label'] . '. The boundary acquires once; '
                . 'a nested section uses Site_Lock::write() / read(), which are reentrant.'
            );
        }

        $started = hrtime(true);
        self::$acquiring = true;

        try {
            $token = $mode === self::MODE_WRITE
                ? RsxLocks::site_write_lock($site_id)
                : RsxLocks::site_read_lock($site_id);
        } finally {
            self::$acquiring = false;
        }

        $granted = hrtime(true);

        self::$held = [
            'token' => $token,
            'site_id' => $site_id,
            'mode' => $mode,
            'label' => $holder_label,
            'granted_at' => $granted,
            'waited' => ($granted - $started) / 1e9,
            'transaction_level' => null,
        ];

        return $token;
    }

    /**
     * Open the write lock's database transaction. Right after the grant, before the handler.
     */
    public static function _begin_transaction(string $token): void
    {
        $held = static::__require_held($token, '_begin_transaction');

        if ($held['mode'] !== self::MODE_WRITE) {
            shouldnt_happen('Site_Lock::_begin_transaction(): only a WRITE lock carries a transaction (site ' . $held['site_id'] . ' is held for read)');
        }

        if ($held['transaction_level'] !== null) {
            shouldnt_happen('Site_Lock::_begin_transaction(): the write lock on site ' . $held['site_id'] . ' already has its transaction open');
        }

        DB::beginTransaction();

        self::$held['transaction_level'] = DB::transactionLevel();
        self::$owned_transactions++;
    }

    /**
     * Commit the write lock's transaction. Right before the release.
     *
     * A transaction the work opened inside it and left open is a defect in the work: every
     * level is rolled back, ours included, and this throws - nothing half-done is committed.
     */
    public static function _commit(string $token): void
    {
        $held = static::__require_open_transaction($token, '_commit');
        $level = $held['transaction_level'];
        $current = DB::transactionLevel();

        if ($current > $level) {
            static::_rollback($token);

            throw new RuntimeException(
                'Site write section on site ' . $held['site_id'] . ' (' . $held['label'] . ') returned with '
                . ($current - $level) . ' database transaction(s) of its own still open. Everything it wrote was '
                . 'rolled back. Close every DB::beginTransaction() you open (DB::transaction(fn) does it for you).'
            );
        }

        if ($current < $level) {
            self::$held['transaction_level'] = null;
            self::$owned_transactions--;

            throw new RuntimeException(
                'Site write section on site ' . $held['site_id'] . ' (' . $held['label'] . '): the section\'s own '
                . 'database transaction was committed or rolled back by the code inside it. Never commit or roll '
                . 'back a transaction you did not open; throw to abandon the section.'
            );
        }

        try {
            DB::commit();
        } catch (Throwable $e) {
            // At or above the section's level the commit itself failed: nothing committed, and
            // _release() rolls it back (rule 3). Below it the data COMMITTED (Laravel decrements
            // the level only after PDO::commit() returned) and what threw ran after the commit.
            if (DB::transactionLevel() >= $level) {
                throw $e;
            }

            self::$held['transaction_level'] = null;
            self::$owned_transactions--;

            static::__after_commit_failure($e, $held['label']);

            return;
        }

        self::$held['transaction_level'] = null;
        self::$owned_transactions--;
    }

    /**
     * Roll back the write lock's transaction, and any the work opened inside it. On a throw,
     * before the release.
     *
     * @return bool Whether a rollback ran (false: the transaction was already closed by code
     *              that did not open it)
     */
    public static function _rollback(string $token): bool
    {
        $held = static::__require_open_transaction($token, '_rollback');
        $level = $held['transaction_level'];
        $rolled_back = DB::transactionLevel() >= $level;

        if ($rolled_back) {
            DB::rollBack($level - 1);
        }

        self::$held['transaction_level'] = null;
        self::$owned_transactions--;

        return $rolled_back;
    }

    /**
     * Release THE site lock. Rule 3: a write lock whose transaction is still open is rolled
     * back first and this throws - the lock is never let go with the transaction pending.
     */
    public static function _release(string $token): void
    {
        $held = static::__require_held($token, '_release');
        $open_transaction = $held['transaction_level'] !== null;
        $rolled_back = $open_transaction && static::_rollback($token);

        $held_seconds = (hrtime(true) - $held['granted_at']) / 1e9;
        self::$held = null;

        RsxLocks::release_lock($token);

        static::__warn_if_held_too_long($held, $held_seconds);

        if ($open_transaction) {
            throw new RuntimeException(
                'Site lock rule 3: the WRITE lock on site ' . $held['site_id'] . ' (' . $held['label'] . ') was released '
                . 'with its transaction still open. '
                . ($rolled_back
                    ? 'It was rolled back. '
                    : 'Nothing was rolled back here: code that did not open the transaction had already closed it. ')
                . 'Call Site_Lock::_commit() or _rollback() before _release().'
            );
        }
    }

    /**
     * Open an enforcement window: until the matching _leave_enforcement(), a write to a
     * Site_Scoped model needs write authority for its site.
     *
     * A REQUEST window ($request true: an Ajax / API / companion endpoint or a page-route
     * handler) also refuses every change of the site while it is open (A4): the framework
     * owns a request's site, and the one sanctioned way to enter a site from a handler is
     * RsxAuth::enter_site(). A task window does not: a task switches site between its
     * sections.
     *
     * @param string $label The holder, for the refusal message ('Ajax X::y', 'task X::y (run #5)')
     * @param bool $request A request handler's window (the dispatchers), not a task body's
     */
    public static function _enter_enforcement(string $label, bool $request = false): void
    {
        self::$enforcement[] = ['label' => $label, 'request' => $request];

        if ($request) {
            self::$request_windows++;
        }
    }

    /**
     * Close the innermost enforcement window. A write exception acquired inside it and still
     * open is released, and - when $fail_on_leak (the window's code completed: it returned
     * rather than threw) - this throws naming it: the endpoint, hook, task or command fails,
     * as one that leaked a lock does. A window whose code threw keeps its own exception; the
     * leak is cleaned up all the same.
     *
     * @param bool $fail_on_leak False when the window's code threw
     */
    public static function _leave_enforcement(bool $fail_on_leak = true): void
    {
        $closed = static::__close_window();

        if ($fail_on_leak && $closed['leaked'] !== []) {
            throw new RuntimeException(static::__leaked_exceptions_message($closed['label'], $closed['leaked']));
        }
    }

    /**
     * Close the innermost enforcement window, which must be the one opened as $label - for a
     * window opened and closed by two separate events rather than one try / finally (a
     * console command's, Site_Lock_Cli). A mismatch is a broken pairing, never tolerated.
     *
     * @param string $label
     * @param bool $fail_on_leak False when the window's code failed (_leave_enforcement())
     */
    public static function _leave_enforcement_labelled(string $label, bool $fail_on_leak = true): void
    {
        $innermost = self::$enforcement === [] ? null : self::$enforcement[array_key_last(self::$enforcement)]['label'];

        if ($innermost !== $label) {
            shouldnt_happen(
                "Site_Lock::_leave_enforcement_labelled('{$label}'): the innermost enforcement window is "
                . ($innermost === null ? 'none' : "'{$innermost}'") . ' - a window opened inside it was never closed'
            );
        }

        static::_leave_enforcement($fail_on_leak);
    }

    /**
     * Run a request handler inside its enforcement window: exactly the handler function -
     * the window opens immediately before it is called and closes the moment it returns or
     * throws. The dispatchers wrap ONLY the endpoint / action call in this; the controller's
     * pre_dispatch, rsx.post_dispatch handlers and response shaping run outside it.
     *
     * @param string $label The holder ('Ajax Clients_Controller::save')
     * @param callable $handler The endpoint / action call
     * @return mixed What the handler returned
     */
    public static function _enforce(string $label, callable $handler): mixed
    {
        static::_enter_enforcement($label, true);

        try {
            $result = $handler();
        } catch (Throwable $e) {
            static::_leave_enforcement(false);

            throw $e;
        }

        // A write exception the handler left open fails it here - inside the boundary, so a
        // writer rolls back what the exception's writes rode in on.
        static::_leave_enforcement();

        return $result;
    }

    /**
     * Has the #[Manual_Lock] request in progress taken a site write lock (raw, a section, or
     * RsxAuth::enter_site())? Such a request is a WRITER request. False outside one.
     */
    public static function _manual_request_wrote(): bool
    {
        return self::$manual_request !== null && self::$manual_request['wrote'];
    }

    /** Is a request handler window open (the site may not change)? */
    public static function _in_request_window(): bool
    {
        return self::$request_windows > 0;
    }

    /**
     * The lock this process holds through the lock layer, or null: [token, site_id, mode,
     * label]. A diagnostic read (the dispatch tests' probe).
     */
    public static function _held(): ?array
    {
        if (self::$held === null) {
            return null;
        }

        return [
            'token' => self::$held['token'],
            'site_id' => self::$held['site_id'],
            'mode' => self::$held['mode'],
            'label' => self::$held['label'],
        ];
    }

    // =============================================================================================
    // THE APPLICATION HOOKS (init, pre_dispatch, unhandled_route)
    // =============================================================================================

    /**
     * Run an application hook - Main::init() / Portal_Main::init(), Main::pre_dispatch() /
     * Portal_Main::pre_dispatch(), a controller's pre_dispatch(), Main::unhandled_route() -
     * with NO automatic lock, inside an ENFORCEMENT WINDOW.
     *
     * A hook is not a reader or a writer surface (SITE-LOCK-01 refuses #[Writer] / #[Reader]
     * on one): it runs before the request's automatic lock is taken, exactly as a task body
     * runs with none. A site-scoped write in it therefore needs a lock the hook takes itself -
     * Site_Lock::write($site_id, fn () => ...) - or a Site_Lock::write_exception() for a view,
     * audit or telemetry record, a Site_Lock::bypass() for bookkeeping that tolerates concurrent
     * writers, or a #[Site_Lock_Exempt] model.
     *
     * THE HAND-OVER. A hook ends holding exactly what it began with: the dispatcher acquires
     * the request's lock right after it, and a hold the hook leaked would be carried into -
     * and collide with - that acquisition. A site lock hold acquired here and not released is
     * released (every level it added), a database transaction opened here and not closed is
     * rolled back (before the release: rule 3), and the hook FAILS naming what it leaked - a
     * write exception it acquired and left open included (released). A hook that throws keeps its own exception; the leak is cleaned up all the same.
     *
     * The window is not a request window: the site may change in it (Main::init() declares a
     * command's site), under the ordinary rule that it never changes while a site lock or
     * bypass is held.
     *
     * @param string $label The hook, for refusals ('Main::pre_dispatch() for Ajax X::y')
     * @param callable $hook The hook call
     * @return mixed What the hook returned
     */
    public static function _run_hook(string $label, callable $hook): mixed
    {
        $holds_before = RsxLocks::site_lock_hold_counts();
        $level = DB::transactionLevel();
        $thrown = null;
        $result = null;

        static::_enter_enforcement($label);

        try {
            $result = $hook();
        } catch (Throwable $e) {
            $thrown = $e;
        } finally {
            $leaked_exceptions = static::__close_window()['leaked'];
        }

        $leaked_transactions = 0;

        while (DB::transactionLevel() > $level) {
            DB::rollBack();
            $leaked_transactions++;
        }

        $leaked_locks = [];

        foreach (RsxLocks::site_lock_hold_counts() as $key => $hold) {
            $extra = $hold['count'] - ($holds_before[$key]['count'] ?? 0);

            if ($extra <= 0) {
                continue;
            }

            $leaked_locks[] = strtoupper($hold['type']) . " lock on site {$hold['site_id']}" . ($extra > 1 ? " ({$extra} holds)" : '');

            for ($index = 0; $index < $extra; $index++) {
                RsxLocks::release_lock($hold['token']);
            }
        }

        if ($thrown !== null) {
            throw $thrown;
        }

        if ($leaked_transactions > 0 || $leaked_locks !== [] || $leaked_exceptions !== []) {
            $leaks = [];

            if ($leaked_locks !== []) {
                $leaks[] = 'still holding the ' . implode(', ', $leaked_locks) . ' (released)';
            }

            if ($leaked_transactions > 0) {
                $leaks[] = "with {$leaked_transactions} database transaction(s) still open (rolled back - what it wrote inside them is gone)";
            }

            if ($leaked_exceptions !== []) {
                $leaks[] = 'with ' . count($leaked_exceptions) . ' Site_Lock write exception(s) still open ('
                    . static::__quote_reasons($leaked_exceptions) . '; released)';
            }

            throw new RuntimeException(
                "{$label} returned " . implode(' and ', $leaks) . '. A hook runs with no automatic lock and must '
                . 'hand over clean: the request\'s own lock is taken right after it. Release every lock and write '
                . 'exception you take and close every transaction you open - Site_Lock::write($site_id, fn () => ...) '
                . 'and Site_Lock::write_exception($reason, fn () => ...) do it for you.'
            );
        }

        return $result;
    }

    // =============================================================================================
    // THE REQUEST BOUNDARY (what every dispatcher calls)
    // =============================================================================================

    /**
     * The resolved site lock mode of a dispatchable surface: 'reader' | 'writer' | 'manual',
     * or null (no automatic lock - a page route that declares none). Read off the auth
     * surface index, where the manifest build records it on every surface.
     *
     * @param string $target 'Simple_Class::method' - the surface index key
     * @return string|null
     */
    public static function _surface_mode(string $target): ?string
    {
        $surface = Auth_Gates::require_surface($target);

        if (!array_key_exists('site_lock', $surface)) {
            shouldnt_happen(
                "Auth surface '{$target}' carries no resolved site lock mode. Every surface is given one at "
                . 'manifest build (Site_Lock_Directives); the build and this request disagree about what is deployed.'
            );
        }

        return $surface['site_lock'];
    }

    /**
     * The site a request's automatic lock names: the experience's current site - the
     * portal's URL-derived site on a portal request, else the staff session's (an API
     * request's is fixed by its key). 0 when there is none.
     */
    public static function _request_site_id(): int
    {
        return Rsx_Site_Model_Abstract::get_current_site_id();
    }

    /**
     * Run one request handler at the site lock boundary - THE ordering every dispatcher
     * shares (Ajax, the external API, companion endpoints, page routes):
     *
     *   'reader'  read lock -> $body -> release
     *   'writer'  write lock -> transaction -> the request's _transactions row minted
     *             (Revision::transaction_id(), so every committed writer request has one,
     *             a request that saved nothing included) -> $body -> commit -> release
     *             (a throw anywhere in $body: rollback, row included -> release -> rethrow)
     *   'manual'  no lock. The REQUEST TRANSACTION begins at the handler's first raw
     *             RsxLocks::site_write_lock() grant and is committed here when $body
     *             returns (rolled back on a throw), even if the handler released that lock
     *             early - which is logged as a warning (Part B). A Site_Lock::write()
     *             section inside keeps its own transaction. A manual request that took any
     *             site write lock is a writer request: its _transactions row is minted when
     *             $body returns, inside the request transaction when one began.
     *   null      no lock, no transaction (a page route that declares no mode)
     *
     * $body is the handler (which $body wraps in _enforce()), the rsx.post_dispatch event
     * and, for the API and companion endpoints, response building - so a post_dispatch
     * handler that throws (the Turnstile completeness guard) rolls the writer back. The
     * pre_dispatch hooks are NOT in $body: they ran before it, unlocked, through _run_hook().
     * A coded RESULT (response_form_error(), ...) is a return, not a throw: what the writer
     * wrote commits - which is what keeps a failed attempt that records itself recorded.
     * A thrown Rollback_Response is a throw here (rolled back); the dispatcher catches it
     * outside this boundary and renders its value as the returned result.
     *
     * NESTING. A process that already holds the site's lock - an endpoint called in-process
     * (Ajax::internal()) from inside another - runs $body under that hold: a reader under any
     * hold, a writer under a WRITE hold (inside the outer transaction: no second lock, no
     * second transaction). A writer under a READ hold fatals - a lock is never upgraded.
     *
     * SITE 0. A request with no tenant names site 0, and site 0 is locked like any other
     * site: its rows are site-scoped data (an identity-level email queued before any site is
     * entered, or an application that runs without tenants), so a reader holds SITE_0's
     * read lock and a writer its write lock and transaction.
     *
     * LEAKED TRANSACTIONS. A database transaction $body opened and did not close is rolled
     * back here and the request fails naming the handler - before the writer's own commit,
     * so nothing half-written is committed or carried into the next call.
     *
     * @param string|null $mode 'reader' | 'writer' | 'manual' | null (_surface_mode())
     * @param string $label The holder, for warnings and refusals ('Ajax Clients_Controller::save')
     * @param callable $body The boundary's work
     * @param int|null $site_id The site to lock; null = _request_site_id()
     * @return mixed What $body returned
     */
    public static function _run_request(?string $mode, string $label, callable $body, ?int $site_id = null): mixed
    {
        $site_id = $site_id ?? static::_request_site_id();
        $guarded = fn () => static::__run_guarding_transactions($label, $body);

        if ($mode === null) {
            return $guarded();
        }

        if ($mode === Site_Lock_Directives::MANUAL) {
            return static::__run_manual($label, $guarded);
        }

        if ($mode !== Site_Lock_Directives::READER && $mode !== Site_Lock_Directives::WRITER) {
            shouldnt_happen("Site_Lock::_run_request(): unknown site lock mode '{$mode}' for {$label}");
        }

        if ($site_id < 0) {
            shouldnt_happen("Site_Lock::_run_request(): site id {$site_id} for {$label}");
        }

        // Site 0 is a site like any other here: a request with no tenant (signed out, or an
        // application that keeps its data in site_id = 0 rows) locks SITE_0, so its site-0
        // writes are serialized exactly as a tenant's are.
        $name = RsxLocks::LOCK_SITE_PREFIX . $site_id;

        if ($mode === Site_Lock_Directives::READER) {
            if (RsxLocks::holds(RsxLocks::CLUSTER_LOCK, $name, RsxLocks::READ_LOCK)) {
                return $guarded();
            }

            $token = static::_acquire($site_id, self::MODE_READ, $label);

            try {
                return $guarded();
            } finally {
                static::_release($token);
            }
        }

        // Writer.
        if (RsxLocks::holds(RsxLocks::CLUSTER_LOCK, $name, RsxLocks::WRITE_LOCK)) {
            return $guarded();
        }

        if (RsxLocks::holds(RsxLocks::CLUSTER_LOCK, $name, RsxLocks::READ_LOCK)) {
            static::__throw_no_upgrade($site_id);
        }

        $token = static::_acquire($site_id, self::MODE_WRITE, $label);

        try {
            // THE EAGER MINT. Every writer request that commits has its _transactions row -
            // one that saved nothing included - so the request history is complete and a
            // retry of a no-change write carrying a _request_ref replays. Minted inside the
            // transaction: a request that rolls back takes its row with it. What the row
            // references is resolved first, outside it (Revision::_prepare_mint()).
            Revision::_prepare_mint();
            static::_begin_transaction($token);
            Revision::transaction_id();

            try {
                $result = $guarded();
            } catch (Throwable $e) {
                static::_rollback($token);

                throw $e;
            }

            static::_commit($token);

            return $result;
        } finally {
            static::_release($token);
        }
    }

    /**
     * RsxLocks::site_write_lock() granted a FRESH hold on $site_id (not a reentrant one).
     * Inside a #[Manual_Lock] request with no request transaction yet, that grant begins it
     * (Part B: the handler's writes are illegal before its first write lock, so this is the
     * start of its first write). A grant taken by Site_Lock::_acquire() - a write section,
     * RsxAuth::enter_site() - is never the request transaction: the section has its own.
     *
     * @param int $site_id
     */
    public static function _on_site_write_lock_granted(int $site_id): void
    {
        // Any write lock - a raw one, a Site_Lock::write() section, RsxAuth::enter_site() -
        // makes a #[Manual_Lock] request a writer request (its _transactions row is minted
        // when it returns, and the request history records it).
        if (self::$manual_request !== null) {
            self::$manual_request['wrote'] = true;
        }

        if (self::$acquiring || self::$manual_request === null || self::$manual_request['transaction_level'] !== null) {
            return;
        }

        DB::beginTransaction();

        self::$manual_request['site_id'] = $site_id;
        self::$manual_request['transaction_level'] = DB::transactionLevel();
        self::$manual_request['counted'] = true;
        self::$owned_transactions++;
    }

    /**
     * RsxLocks released its last hold of $site_id's write lock. A #[Manual_Lock] request
     * whose transaction that grant began has released EARLY: the transaction holds until the
     * endpoint returns (Part B), and another writer may now block on its row locks - logged.
     * From here the transaction is no longer the lock layer's to tolerate: acquiring a site
     * lock again before the endpoint returns would wait in rsx-lockd while holding row locks,
     * and is refused by rule 1.
     *
     * @param int $site_id
     */
    public static function _on_site_write_lock_released(int $site_id): void
    {
        $request = self::$manual_request;

        if ($request === null || !$request['counted'] || $request['site_id'] !== $site_id) {
            return;
        }

        self::$manual_request['counted'] = false;
        self::$owned_transactions--;

        Log::warning(
            "Site lock released early: {$request['label']} released the write lock on site {$site_id} while its "
            . 'request transaction is still open. The transaction commits when the endpoint returns; until then '
            . 'another writer on the site may block on its row locks.'
        );
    }

    /**
     * Enter a site: take its write lock, run $set_site (the session's site setter, which is
     * permitted inside this call only) and whatever site-scoped records signing in to it
     * writes, commit, release. The engine of RsxAuth::enter_site().
     *
     * Refused while this process holds any site lock or bypass: the site never changes under
     * one, and a handler that enters a site took no automatic lock (#[Manual_Lock]).
     *
     * @param int $site_id
     * @param callable $set_site Sets the session's site to $site_id
     */
    public static function _enter_site(int $site_id, callable $set_site): void
    {
        // Site 0 is a site like any other (an application may keep its data there).
        if ($site_id < 0) {
            shouldnt_happen("RsxAuth::enter_site({$site_id}): a site id is never negative");
        }

        $held = RsxLocks::held_site_locks();

        if ($held !== []) {
            $held_site_id = array_key_first($held);

            throw new RuntimeException(
                "RsxAuth::enter_site({$site_id}) refused: this process holds the " . $held[$held_site_id]
                . " lock on site {$held_site_id} (" . static::__current_label() . '). Entering a site takes that '
                . "site's write lock, and the site never changes under a lock. An endpoint that signs in or switches "
                . 'site declares #[Manual_Lock] (no automatic lock) and calls RsxAuth::enter_site() from its body; '
                . 'other code finishes its Site_Lock section first.'
            );
        }

        if (self::$bypasses !== []) {
            throw new RuntimeException(
                "RsxAuth::enter_site({$site_id}) refused inside a Site_Lock bypass (" . static::__current_label()
                . '). Enter the site before the bypass opens or after it closes.'
            );
        }

        if (self::$write_exceptions !== []) {
            throw new RuntimeException(
                "RsxAuth::enter_site({$site_id}) refused inside a Site_Lock write exception ("
                . static::__quote_reasons(self::$write_exceptions) . ', ' . static::__current_label()
                . '). Enter the site before the write exception opens or after it is released.'
            );
        }

        static::write($site_id, function () use ($set_site) {
            self::$entering_site = true;

            try {
                $set_site();
            } finally {
                self::$entering_site = false;
            }
        }, "Sign in to site {$site_id}");
    }

    /** Is a write to a Site_Scoped model checked right now? */
    public static function _is_enforcing(): bool
    {
        return self::$enforcement !== [];
    }

    /**
     * Refuse a single-record write (Site_Scoped::save() / delete()) without write authority.
     *
     * Site 0 is checked like any other site: a row of site 0 (an identity-level email queued
     * before any site is entered, an application's tenantless data) needs site 0's write lock.
     *
     * @param object $model The model being written
     * @param int $site_id The site the write lands in
     * @param string $operation 'save' | 'delete'
     */
    public static function _check_model_write(object $model, int $site_id, string $operation): void
    {
        if (self::$enforcement === []) {
            return;
        }

        $class = get_class($model);

        if (static::_is_exempt($class) || static::has_write_authority($site_id)) {
            return;
        }

        static::__throw_unauthorized_write($class, $site_id, $operation);
    }

    /**
     * Refuse a raw bulk update / delete of a Site_Scoped model without write authority for
     * every site it can touch: the current site when the site scope applies, else every site
     * a matching row belongs to.
     *
     * @param Builder $builder The Eloquent builder about to run the statement
     * @param string $operation 'update' | 'delete'
     */
    public static function _check_bulk_write(Builder $builder, string $operation): void
    {
        if (self::$enforcement === []) {
            return;
        }

        $model = $builder->getModel();
        $class = get_class($model);

        if (!static::_uses_site_scope($class) || static::_is_exempt($class)) {
            return;
        }

        if (self::$write_exceptions !== []) {
            return;
        }

        foreach (self::$bypasses as $bypass) {
            if ($bypass['site_id'] === null) {
                return;
            }
        }

        if ($class::_site_scope_is_applied() && !in_array('site', $builder->removedScopes(), true)) {
            $site_ids = [$class::get_current_site_id()];
        } else {
            // Unscoped: whichever sites the matching rows belong to. ORDER BY / LIMIT dropped -
            // a DISTINCT over the WHERE is the set of sites the statement can reach.
            $query = (clone $builder)->toBase();
            $query->orders = null;
            $query->limit = null;
            $query->offset = null;
            $site_ids = $query->distinct()->pluck($model->getTable() . '.site_id')->map(fn ($id) => (int) $id)->all();
        }

        foreach ($site_ids as $site_id) {
            if (!static::has_write_authority($site_id)) {
                static::__throw_unauthorized_write($class, $site_id, "bulk {$operation}");
            }
        }
    }

    /**
     * The site lock acquisition rules (1, 5 and no-upgrade), for any acquisition that will
     * actually reach the daemon. RsxLocks::site_write_lock() / site_read_lock() call this, so
     * the raw API and Site_Lock are held to the same rules.
     *
     * @param int $site_id
     * @param string $type RsxLocks::READ_LOCK | RsxLocks::WRITE_LOCK
     */
    public static function _assert_may_acquire(int $site_id, string $type): void
    {
        $held = RsxLocks::held_site_locks();

        // Reentrant re-acquisition of what is already held never waits: no rule applies.
        if (($held[$site_id] ?? null) === RsxLocks::WRITE_LOCK || ($held[$site_id] ?? null) === $type) {
            return;
        }

        if (($held[$site_id] ?? null) === RsxLocks::READ_LOCK && $type === RsxLocks::WRITE_LOCK) {
            static::__throw_no_upgrade($site_id);
        }

        foreach (array_keys($held) as $held_site_id) {
            if ($held_site_id !== $site_id) {
                static::__throw_other_site($site_id, $held_site_id);
            }
        }

        $level = DB::transactionLevel();
        $tolerated = self::$owned_transactions + static::__test_baseline();

        if ($level > $tolerated) {
            throw new RuntimeException(
                "Site lock rule 1: refused to acquire the {$type} lock on site {$site_id} inside "
                . ($level - $tolerated) . ' open database transaction(s) the lock layer did not open. Waiting for a '
                . 'site lock while holding row locks is a deadlock neither MySQL nor rsx-lockd can see. Take the '
                . 'lock FIRST: Site_Lock::write() opens the transaction for you - never call it inside '
                . 'DB::transaction().'
            );
        }
    }

    /**
     * Refuse a change of site while a site lock is held or a bypass is open. Called by
     * Session::set_site_id() / set_temporary_site_id() / clear_temporary_site_id() and
     * Portal_Session::set_site_id().
     *
     * @param string $what The setter, for the message
     */
    public static function _assert_site_change_permitted(string $what): void
    {
        // RsxAuth::enter_site(): the one sanctioned change, made under the target site's own
        // write lock (taken by _enter_site() with no other lock held), from any window.
        if (self::$entering_site) {
            return;
        }

        if (self::$request_windows > 0) {
            throw new RuntimeException(
                "{$what} refused inside " . static::__innermost_request_label() . ': the framework manages a '
                . "request's site, so a handler never changes it. To sign in to or switch to a site, declare "
                . '#[Manual_Lock] on the endpoint (no automatic lock) and call RsxAuth::enter_site($site_id), which '
                . "takes that site's write lock, sets the session's site and records the sign-in."
            );
        }

        $held = RsxLocks::held_site_locks();

        if ($held !== []) {
            $site_id = array_key_first($held);

            throw new RuntimeException(
                "{$what} refused: this process holds the " . $held[$site_id] . " lock on site {$site_id} ("
                . static::__current_label() . '). A site lock names a site, so the site never changes under one. '
                . 'Change the site between lock sections: finish the Site_Lock::write() / read() section first.'
            );
        }

        if (self::$bypasses !== []) {
            $bypass = self::$bypasses[array_key_last(self::$bypasses)];
            $scope = $bypass['site_id'] === null ? 'every site' : 'site ' . $bypass['site_id'];

            throw new RuntimeException(
                "{$what} refused inside a Site_Lock bypass for {$scope} (" . static::__current_label() . '). '
                . 'The site never changes inside a bypass; change it before entering one or after it closes.'
            );
        }

        if (self::$write_exceptions !== []) {
            throw new RuntimeException(
                "{$what} refused inside a Site_Lock write exception (" . static::__quote_reasons(self::$write_exceptions)
                . ', ' . static::__current_label() . '). The site never changes while a write exception is open; '
                . 'change it before acquiring one or after releasing it.'
            );
        }
    }

    // =============================================================================================
    // TEST HARNESS
    // =============================================================================================

    /**
     * Register the transaction level the test harness opened for the running test, so rule 1
     * tolerates it. Honoured in the suite process only (Rsx_Test_Abstract::suite_is_running()).
     *
     * @param int|null $level DB::transactionLevel() after the per-test begin; null clears
     * @return int|null The previous registration, for the caller to restore
     */
    public static function _set_test_transaction_baseline(?int $level): ?int
    {
        if ($level !== null && !Rsx_Test_Abstract::suite_is_running()) {
            shouldnt_happen('Site_Lock::_set_test_transaction_baseline() is for the test suite process only');
        }

        $previous = self::$test_transaction_baseline;
        self::$test_transaction_baseline = $level;

        return $previous;
    }

    /**
     * Return to the clean state between tests: windows, bypasses and THE held lock (released,
     * its transaction rolled back) - a test that threw mid-boundary must not hand them on.
     */
    public static function _testing_reset(): void
    {
        self::$enforcement = [];
        self::$request_windows = 0;
        self::$bypasses = [];
        self::$write_exceptions = [];
        self::$entering_site = false;
        self::$acquiring = false;

        if (self::$manual_request !== null) {
            $level = self::$manual_request['transaction_level'];
            self::$manual_request = null;

            if ($level !== null && DB::transactionLevel() >= $level) {
                DB::rollBack($level - 1);
            }
        }

        if (self::$held !== null) {
            $held = self::$held;
            self::$held = null;

            if ($held['transaction_level'] !== null && DB::transactionLevel() >= $held['transaction_level']) {
                DB::rollBack($held['transaction_level'] - 1);
            }

            RsxLocks::release_lock($held['token']);
        }

        self::$owned_transactions = 0;
    }

    // =============================================================================================
    // MODEL CLASSIFICATION
    // =============================================================================================

    /**
     * Does the class declare #[Site_Lock_Exempt('<reason>')], itself or on an ancestor?
     *
     * The declarations are read from the manifest's attribute index, so only an INDEXED class
     * can declare one; an unindexed class (an anonymous subclass, a vendor ancestor) inherits
     * the declaration of the indexed ancestor that carries it.
     */
    public static function _is_exempt(string $class): bool
    {
        if (isset(self::$exempt_cache[$class])) {
            return self::$exempt_cache[$class];
        }

        $declarations = static::__exempt_declarations();
        $exempt = false;

        foreach (array_merge([ltrim($class, '\\')], array_values(class_parents($class))) as $lineage_class) {
            if (!array_key_exists($lineage_class, $declarations)) {
                continue;
            }

            if ($declarations[$lineage_class] === '') {
                throw new RuntimeException(
                    "#[Site_Lock_Exempt] on {$lineage_class} gives no reason. Exemption is for append-only view, "
                    . "audit and telemetry records; say what it records: #[Site_Lock_Exempt('document view history')]."
                );
            }

            $exempt = true;
            break;
        }

        return self::$exempt_cache[$class] = $exempt;
    }

    /**
     * Every class declaring #[Site_Lock_Exempt], from the manifest: FQCN => reason ('' when
     * it gives none).
     */
    private static function __exempt_declarations(): array
    {
        $declarations = [];

        foreach (Manifest::by_attribute(self::EXEMPT_ATTRIBUTE) as $row) {
            if ($row['member'] !== null || $row['class'] === null) {
                continue;
            }

            $record = Manifest::php_class_metadata($row['class']);

            if ($record === null) {
                shouldnt_happen("#[Site_Lock_Exempt] is indexed on {$row['class']}, which has no class record");
            }

            $arguments = $row['instances'][0] ?? [];
            $declarations[$record['fqcn']] = trim((string) ($arguments[0] ?? $arguments['reason'] ?? ''));
        }

        return $declarations;
    }

    /** Does the class use the Site_Scoped trait (directly or through an ancestor)? */
    public static function _uses_site_scope(string $class): bool
    {
        return self::$site_scoped_cache[$class] ??= isset(class_uses_recursive($class)[Site_Scoped::class]);
    }

    // =============================================================================================
    // INTERNALS
    // =============================================================================================

    private static function __run_bypass(?int $site_id, string $reason, callable $work, string $what): mixed
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException(
                "{$what} requires a reason: say why this pass may write without the site's write lock. It is logged."
            );
        }

        Log::info("Site lock bypass: {$what} for " . static::__current_label() . ": {$reason}");

        self::$bypasses[] = ['site_id' => $site_id, 'reason' => $reason];

        try {
            return $work();
        } finally {
            array_pop(self::$bypasses);
        }
    }

    /**
     * Pop the innermost enforcement window and release every write exception acquired inside
     * it that is still open.
     *
     * @return array{label: string, leaked: array} The window's label and the exceptions released
     */
    private static function __close_window(): array
    {
        if (self::$enforcement === []) {
            shouldnt_happen('Site_Lock::_leave_enforcement() with no enforcement window open');
        }

        $window = array_pop(self::$enforcement);

        if ($window['request']) {
            self::$request_windows--;
        }

        $depth = count(self::$enforcement);
        $leaked = [];

        foreach (self::$write_exceptions as $index => $exception) {
            if ($exception['window'] > $depth) {
                $leaked[] = $exception;
                unset(self::$write_exceptions[$index]);
            }
        }

        self::$write_exceptions = array_values(self::$write_exceptions);

        return ['label' => $window['label'], 'leaked' => $leaked];
    }

    /** 'record the view', 'record the download' - the reasons of $exceptions, for a message. */
    private static function __quote_reasons(array $exceptions): string
    {
        return implode(', ', array_map(fn (array $exception) => "'{$exception['reason']}'", $exceptions));
    }

    private static function __leaked_exceptions_message(string $label, array $leaked): string
    {
        return "{$label} ended with " . count($leaked) . ' Site_Lock write exception(s) still open ('
            . static::__quote_reasons($leaked) . '). They have been released. Release every write exception you '
            . 'acquire, in a finally - Site_Lock::write_exception($reason, fn () => ...) does it for you.';
    }

    private static function __require_held(string $token, string $method): array
    {
        if (self::$held === null || self::$held['token'] !== $token) {
            shouldnt_happen("Site_Lock::{$method}(): the token is not the site lock this process holds");
        }

        return self::$held;
    }

    private static function __require_open_transaction(string $token, string $method): array
    {
        $held = static::__require_held($token, $method);

        if ($held['transaction_level'] === null) {
            shouldnt_happen("Site_Lock::{$method}(): the lock on site {$held['site_id']} has no open transaction");
        }

        return $held;
    }

    private static function __test_baseline(): int
    {
        if (self::$test_transaction_baseline === null || !Rsx_Test_Abstract::suite_is_running()) {
            return 0;
        }

        return self::$test_transaction_baseline;
    }

    /** The holder a message names: the held lock's label, else the innermost window's. */
    private static function __current_label(): string
    {
        if (self::$held !== null) {
            return self::$held['label'];
        }

        if (self::$enforcement !== []) {
            return self::$enforcement[array_key_last(self::$enforcement)]['label'];
        }

        return 'code outside any endpoint or task';
    }

    /** The innermost enforcement window's holder: who made the write being refused. */
    private static function __innermost_window_label(): string
    {
        return self::$enforcement === [] ? static::__current_label() : self::$enforcement[array_key_last(self::$enforcement)]['label'];
    }

    /** The innermost REQUEST window's holder, for the site change refusal. */
    private static function __innermost_request_label(): string
    {
        for ($index = count(self::$enforcement) - 1; $index >= 0; $index--) {
            if (self::$enforcement[$index]['request']) {
                return self::$enforcement[$index]['label'];
            }
        }

        return 'a request handler';
    }

    /**
     * Run $body; roll back every database transaction it opened and left open, and fail the
     * request naming the handler. A leak is never committed and never carried into the next
     * call of a batch (whose realtime frames would otherwise wait on it forever).
     */
    private static function __run_guarding_transactions(string $label, callable $body): mixed
    {
        $level = DB::transactionLevel();
        $leaked = 0;

        try {
            $result = $body();
        } finally {
            // A #[Manual_Lock] request transaction begun inside $body (at its first raw site
            // write lock) is the framework's, closed by __run_manual(): never a leak.
            $manual_level = self::$manual_request['transaction_level'] ?? null;
            $floor = $manual_level !== null && $manual_level > $level ? $manual_level : $level;

            while (DB::transactionLevel() > $floor) {
                DB::rollBack();
                $leaked++;
            }
        }

        if ($leaked > 0) {
            throw new RuntimeException(
                "{$label} returned with {$leaked} database transaction(s) still open. They have been rolled back, "
                . 'so whatever the call wrote inside them is gone. Commit or roll back every transaction an endpoint '
                . 'begins - DB::transaction(fn) does both.'
            );
        }

        return $result;
    }

    /**
     * A #[Manual_Lock] handler: no automatic lock; the request transaction its first raw site
     * write lock begins (_on_site_write_lock_granted()) is committed when $guarded returns and
     * rolled back when it throws. Inside another request's boundary (an in-process call from a
     * manual handler, or from any handler already holding the site's lock) the outer request
     * owns everything and $guarded just runs.
     */
    private static function __run_manual(string $label, callable $guarded): mixed
    {
        if (self::$manual_request !== null || RsxLocks::held_site_locks() !== []) {
            return $guarded();
        }

        self::$manual_request = ['label' => $label, 'site_id' => null, 'transaction_level' => null, 'counted' => false, 'wrote' => false];

        try {
            try {
                $result = $guarded();

                // A manual request that took a write lock is a writer request: it has its
                // _transactions row (inside its request transaction when one began).
                if (self::$manual_request['wrote']) {
                    Revision::transaction_id();
                }
            } catch (Throwable $e) {
                static::__close_manual_transaction(false);

                throw $e;
            }

            static::__close_manual_transaction(true);

            return $result;
        } finally {
            if (self::$manual_request !== null && self::$manual_request['transaction_level'] !== null) {
                // A throw from the commit itself: never leave the request transaction open.
                static::__close_manual_transaction(false);
            }

            self::$manual_request = null;
        }
    }

    /**
     * Commit (or roll back) the #[Manual_Lock] request transaction, if one began. A commit
     * with a transaction of the handler's own still open inside it rolls everything back and
     * throws - nothing half-done is committed (the write section's own rule, _commit()).
     */
    private static function __close_manual_transaction(bool $commit): void
    {
        $request = self::$manual_request;

        if ($request === null || $request['transaction_level'] === null) {
            return;
        }

        $level = $request['transaction_level'];
        $current = DB::transactionLevel();

        self::$manual_request['transaction_level'] = null;

        if ($request['counted']) {
            self::$manual_request['counted'] = false;
            self::$owned_transactions--;
        }

        if (!$commit || $current > $level) {
            if ($current >= $level) {
                DB::rollBack($level - 1);
            }

            if ($commit) {
                throw new RuntimeException(
                    "{$request['label']} returned with " . ($current - $level) . ' database transaction(s) of its own '
                    . 'still open inside its request transaction. Everything it wrote was rolled back. Close every '
                    . 'DB::beginTransaction() you open (DB::transaction(fn) does it for you).'
                );
            }

            return;
        }

        if ($current < $level) {
            throw new RuntimeException(
                "{$request['label']}: the request transaction begun at its first site write lock was committed or "
                . 'rolled back by the handler. Never commit or roll back a transaction you did not open; throw to '
                . 'abandon the request.'
            );
        }

        try {
            DB::commit();
        } catch (Throwable $e) {
            // The commit itself failed (the level did not move): nothing committed. Hand the
            // transaction back to __run_manual()'s finally, which rolls it back.
            if (DB::transactionLevel() >= $level) {
                self::$manual_request['transaction_level'] = $level;

                throw $e;
            }

            // It committed; what threw ran after the commit.
            static::__after_commit_failure($e, $request['label']);
        }
    }

    /**
     * A throw met after a write lock's transaction COMMITTED (an after-commit callback the
     * transactions manager did not contain, or a TransactionCommitted listener): the write
     * stands and nothing claims a rollback. Reported, and the request answers what it returned -
     * except an infrastructure outage (Rsx_DatabaseTransactionsManager::_is_contained()), which still
     * ends the request.
     */
    private static function __after_commit_failure(Throwable $e, string $label): void
    {
        if (!Rsx_DatabaseTransactionsManager::_is_contained($e)) {
            throw $e;
        }

        Rsx_DatabaseTransactionsManager::_report_after_commit_failure($e, "{$label}'s commit");
    }

    private static function __section_label(string $section, ?string $description): string
    {
        return $section . ($description !== null ? " \"{$description}\"" : '') . ' in ' . static::__current_label();
    }

    private static function __warn_if_held_too_long(array $held, float $held_seconds): void
    {
        $key = $held['mode'] === self::MODE_WRITE ? 'warn_write_hold_seconds' : 'warn_read_hold_seconds';

        $threshold = (float) config('rsx.locks.' . $key);

        if ($threshold <= 0 || $held_seconds <= $threshold) {
            return;
        }

        Log::warning(sprintf(
            'Site lock held too long: %s lock on site %d held by %s for %.3fs (waited %.3fs for the grant; rsx.locks.%s = %s).',
            strtoupper($held['mode']),
            $held['site_id'],
            $held['label'],
            $held_seconds,
            $held['waited'],
            $key,
            $threshold
        ));
    }

    private static function __throw_no_upgrade(int $site_id): never
    {
        throw new RuntimeException(static::_no_upgrade_message($site_id));
    }

    /**
     * THE refusal of a write lock requested while the same site's read lock is held - one
     * wording for every place that refuses it (Site_Lock::_acquire(), _run_request() for a
     * writer endpoint called from inside a reader, _assert_may_acquire() for the raw
     * RsxLocks::site_write_lock(), RsxLocks::upgrade_lock()).
     *
     * @param int $site_id
     * @return string
     */
    public static function _no_upgrade_message(int $site_id): string
    {
        return "Refused to take the WRITE lock on site {$site_id} while holding its READ lock (" . static::__current_label()
            . '). A read lock cannot turn into a write lock without being released first: two readers that both '
            . 'upgraded would each wait for the other to let go of its read lock, forever - a deadlock. If this code '
            . 'reads and then writes based on what it read, the READ part of the algorithm must be inside the write '
            . 'lock: take the write lock FIRST - declare #[Writer] on the endpoint, or wrap the whole read-then-write '
            . "in Site_Lock::write({$site_id}, fn () => ...) - and do the read inside it. A read inside a write section "
            . 'needs no separate lock. If the write only RECORDS something (a view, an audit entry, telemetry), it '
            . "needs no write lock at all: Site_Lock::write_exception('<what it records>', fn () => ...).";
    }

    private static function __throw_other_site(int $site_id, int $held_site_id): never
    {
        throw new RuntimeException(
            "Site lock rule 5: refused to lock site {$site_id} while holding the lock on site {$held_site_id} ("
            . static::__current_label() . '). A process holds one site\'s lock at a time - finish the section on '
            . "site {$held_site_id} first."
        );
    }

    private static function __throw_unauthorized_write(string $class, int $site_id, string $operation): never
    {
        throw new RuntimeException(
            'Site lock enforcement: ' . class_basename($class) . " {$operation} on site {$site_id} by "
            . static::__innermost_window_label() . " without site {$site_id}'s write lock. A write to a site-scoped model needs "
            . 'the site\'s write lock: declare #[Writer] on the endpoint, wrap the write in '
            . "Site_Lock::write({$site_id}, fn () => ...) (in a task or a command: one section per item, re-reading the record "
            . "inside), for a view, audit or telemetry record Site_Lock::write_exception('<what it records>', fn () => ...), "
            . "or - for a pass that tolerates concurrent writers - Site_Lock::bypass({$site_id}, '<reason>', "
            . 'fn () => ...). See: php artisan rsx:man site_locks'
        );
    }
}
