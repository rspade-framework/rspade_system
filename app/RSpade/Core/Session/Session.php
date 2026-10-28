<?php

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Ip_Address;
use App\RSpade\Core\Session\Rsx_Session_Cookie;
use App\RSpade\Core\Session\User_Agent;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Session model - handles both authentication sessions and static session management
 *
 * This class serves dual purposes:
 * 1. As a Laravel Eloquent model for the sessions table
 * 2. As a static interface for session management (similar to RS3 design)
 *
 * ONE SESSION PER BROWSER. A _sessions row identifies a BROWSER - one session cookie, one
 * row, per cookie jar - and NOT an authentication realm. It holds the cookie and CSRF
 * tokens, the session values, its type and last use, and two POINTERS: the staff user
 * session and the portal user session currently signed in on it (null = signed out, or a
 * guest). Which pointer a request consults is decided by the REQUEST's experience
 * (Rsx_Portal::is_portal_request()); both set at once is normal - the same human, in the
 * same browser, signed into the staff app and the client portal. This class owns the row,
 * the staff realm and the engine both realms share; Portal_Session is a facade over the
 * portal pointer, reaching it through the _*_portal_* seams at the bottom of this class.
 * There is exactly ONE activation path (__activate), so a portal login on a fresh browser
 * mints the row the same way a staff page view would.
 *
 * A USER SESSION (_user_sessions) is "where you're signed in": browser x realm x account x
 * site x impersonator. Its identity never changes. The same account signing in again on the
 * same browser and site REUSES its row (sign_in_count); a different account, site or
 * impersonator is a different row. Every getter for WHO is signed in (get_login_user_id(),
 * get_site_id(), is_impersonating(), the portal getters, the session lifetimes) reads
 * through the current user session. A sign-in finds-or-creates the user session and points
 * the browser at it; a sign-out stamps it (signed_out_at + a SIGN_OUT_* reason), nulls the
 * pointer and clears that realm's session values (the guest values and the other realm's
 * stay - see put_value()). Nothing about a past sign-in is deleted.
 *
 * TOKENS ROTATE IN PLACE. Every sign-in and every sign-out on the browser that is making
 * the request gives its row a new session token and CSRF token, and the response carries the
 * new cookie (__rotate_tokens()). The row remembers the hash of the token it replaced
 * (previous_token_hash): an Ajax call or a sub-resource still carrying that token was in flight
 * when the rotation happened, and it is REFUSED - no row minted, no cookie sent - so it can
 * never overwrite the cookie the browser was just given (_arrived_on_rotated_token()). A PAGE
 * NAVIGATION carrying it is an ordinary guest, so a browser that missed the new cookie
 * recovers on its next page load. Any older token matches nothing and is an ordinary guest.
 * Ending a sign-in on ANOTHER browser (an administrator, the
 * session cap, "sign out everywhere else") only stamps the user session and moves that
 * browser's pointer - its token stays the key to the row, which now grants nothing it did
 * not grant a guest, and its next sign-in rotates it.
 *
 * A portal on its OWN host (PORTAL_URL) is reached through a separate cookie jar, so that
 * browser holds a session cookie per host. Ordinarily each names its own row. The one place
 * the two are joined is staff "View as Client": the linked-session handshake (Session_Link)
 * ends with the portal host's cookie naming the SAME row as the staff host's, through
 * _clone_session_to_this_host().
 *
 * ---------------------------------------------------------------------------
 * WHO MINTS A ROW, AND WHO DELIBERATELY DOES NOT
 * ---------------------------------------------------------------------------
 *
 * The class is LAZY BY DESIGN: a row is written only when something genuinely demands one.
 * Read this before adding an init()/__activate() call anywhere - turning a reader into a
 * writer is the easy mistake here, and it is invisible until a table fills with rows nobody
 * asked for.
 *
 * __activate() is THE only creation path, in both modes. Its callers, and only these, can
 * mint:
 *
 *   get_session_id()          "the id, making one if I have to" - the contract (web only)
 *   get_session()             same, returning the model
 *   put_value()               a writer
 *   set_login_user_id($id)    WEB only - signing in demands a session
 *   _set_portal_identity()    the moment a portal session becomes necessary
 *
 * Everything else creates NOTHING, on purpose:
 *
 *   has_session()             the question get_session_id() cannot answer
 *   get_site_id/get_user_id/get_login_user_id/get_csrf_token/is_logged_in
 *   has_api_access()          a question about the identity, not a demand for one
 *   is_impersonating() and the impersonator accessors
 *   set_site_id()             with nobody signed in, a REQUEST-SCOPED override (web) or the
 *                             virtual session's site. Declaring a tenant is not asking for a
 *                             session.
 *   set_temporary_site_id()   never, in any mode - see that method
 *   __row_if_any()            the portal seams' non-creating read
 *
 * THE LOOKUP. init() loads the row named by the cookie only while it is ACTIVE, and a row
 * unused for rsx.sessions.unused_session_days is made inactive there and treated as absent
 * (kept for history, never resumed). last_active is written at most once a minute.
 *
 * A PROCESS WITH NO BROWSER RUNS A VIRTUAL SESSION. A command, a task worker and the test
 * runner (every CLI process) have no cookie and no browser, so they never have a _sessions
 * row, a user session or an audit session: get_session_id() answers null, has_session()
 * answers whether an identity or a site has been declared, and the identity setters
 * (set_login_user_id(), set_site_id(), impersonate(), begin_impersonation()) write to
 * in-memory statics only. Session values belong to a browser, so put_value() refuses there.
 * _begin_virtual() / _end_virtual() start a fresh one and blank the process again; the task
 * runner does both around every run a worker executes. A #[Run_As_Dispatcher] task runs in a
 * FROZEN virtual session BOUND to the sign-in that dispatched it: get_session_id() and
 * get_user_session_id() answer that browser and user session, the identity is that user
 * session's, and every identity setter and the session values throw (_refuse_if_frozen()).
 *
 * TWO MACHINE KINDS OF ROW. An API key has ONE row of its own (TYPE_API, api_key_id), minted
 * on the key's first call and reused after, with a user session (REALM_API) for the key's
 * owner - see _set_api_identity(). The rsx:debug harness signs in through the signed dev-auth
 * headers onto a TYPE_PLAYWRIGHT row, one per account and site (portal: per portal user),
 * reused while its sign-in is within the ordinary limits - see _use_harness_session(). Neither
 * kind is listed with a person's sessions, and Playwright traffic records no audit session.
 *
 * The tiers the getters consult, highest first: $_api_identity (Bearer, immutable, on the
 * key's own row), $_temporary_site_id (a declared tenant for this script),
 * $_request_site_id_override (subdomain enforcement, and the tenant of a guest), the virtual
 * session's statics, then the current user session.
 *
 * A sign-in lasts as long as rsx.sessions.staff / rsx.sessions.portal allow - a maximum
 * length from the sign-in and a maximum idle time from the last page view, both measured on
 * the user session - enforced when a request arrives (enforce_timeouts()). There is no
 * "remember me" option: the cookie is always long-lived.
 *
 * @FILE-SUBCLASS-01-EXCEPTION Class intentionally named Session instead of Session_Model
 * to maintain compatibility with static method calls like Session::init().  The developer will
 * almost never be interacting with a session orm record directly, so the term Session_Model
 * doesn't have much meaning.
 *
 *
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _sessions
 *
 * @property int $active
 * @property int $api_key_id
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $csrf_token
 * @property string $first_signed_in_at
 * @property int $id
 * @property int $ip_address_id
 * @property string $last_active
 * @property int $portal_user_session_id
 * @property string $previous_token_hash
 * @property string $session_token
 * @property int $staff_user_session_id
 * @property int $type_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property int $user_agent_id
 * @property int $version
 *
 * @property-read string $type_id__label
 * @property-read string $type_id__constant
 *
 * @method static array type_id__enum() Get all enum definitions with full metadata
 * @method static array type_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array type_id__enum_labels() Get simple id => label map
 * @method static array type_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Session extends Rsx_System_Model_Abstract
                     {
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const TYPE_WEB = 1;
    const TYPE_PLAYWRIGHT = 2;
    const TYPE_API = 3;
    const TYPE_CLI = 4;
    /**
     * Session type - stamped by the writer at creation, NEVER inferred from a header or the
     * user-agent afterwards: TYPE_WEB by __activate(), TYPE_PLAYWRIGHT only by the signed
     * dev-auth path (_use_harness_session()), TYPE_API only by an API key's first call
     * (_set_api_identity()). TYPE_CLI names no row any more - a process with no browser runs a
     * virtual session - and stays in the enum because the audit sessions recorded before
     * that carry it.
     */
    public static $enums = [
        'type_id' => [
            1 => ['constant' => 'TYPE_WEB', 'label' => 'Web', 'order' => 1],
            2 => ['constant' => 'TYPE_PLAYWRIGHT', 'label' => 'Playwright', 'order' => 2],
            3 => ['constant' => 'TYPE_API', 'label' => 'API', 'order' => 3],
            4 => ['constant' => 'TYPE_CLI', 'label' => 'CLI', 'order' => 4],
        ],
    ];

    // =========================================================================
    // USER SESSION VOCABULARY (_user_sessions.realm_id / sign_out_reason_id)
    // =========================================================================

    /** A staff sign-in: login identity + site membership + optional staff impersonator. */
    const REALM_STAFF = 1;

    /** A client-portal sign-in: portal user + its site + optional staff "View as Client". */
    const REALM_PORTAL = 2;

    /** An API key's owner, signed in through the key's own browser-less session row. */
    const REALM_API = 3;

    /**
     * A session value's realm only (_session_values.realm_id), never a user session's: a
     * value that belongs to the BROWSER rather than to whoever is signed in on it, kept
     * through every sign-out. See put_value().
     */
    const REALM_GUEST = 4;

    /** The realm words put_value() / get_value() / forget_value() take, by realm id. */
    private const VALUE_REALMS = [
        'staff' => self::REALM_STAFF,
        'portal' => self::REALM_PORTAL,
        'guest' => self::REALM_GUEST,
    ];

    /** The account signed out on this browser (logout()). */
    const SIGN_OUT_SIGNED_OUT = 1;

    /** rsx.sessions.<realm>.max_length_minutes passed (enforce_timeouts()). */
    const SIGN_OUT_TIMED_OUT_LENGTH = 2;

    /** rsx.sessions.<realm>.max_idle_minutes passed (enforce_timeouts()). */
    const SIGN_OUT_TIMED_OUT_IDLE = 3;

    /** The account itself ended it from another browser (terminate_session() and friends). */
    const SIGN_OUT_ENDED_BY_USER = 4;

    /** An administrator or a developer ended it. */
    const SIGN_OUT_ENDED_BY_ADMIN = 5;

    /** The framework ended it with no operator involved (a password set from the command line). */
    const SIGN_OUT_ENDED_BY_SYSTEM = 6;

    /** rsx.sessions.max_web_sessions_per_user: a newer sign-in of the account took its place. */
    const SIGN_OUT_SESSION_CAP = 7;

    /** Another account signed in on this browser in the same realm. */
    const SIGN_OUT_REPLACED = 8;

    /** The browser's staff sign-in moved to another site (set_site_id()). */
    const SIGN_OUT_SITE_SWITCHED = 9;

    /** The membership or its site was disabled or deleted (enforce_enabled_membership()). */
    const SIGN_OUT_MEMBERSHIP_DISABLED = 10;

    /** An impersonation was stopped, or ran past rsx.sessions.impersonation_max_days. */
    const SIGN_OUT_IMPERSONATION_ENDED = 11;

    /** The browser's session was reset (reset()). */
    const SIGN_OUT_SESSION_RESET = 12;

    /** The API key the user session stood for was revoked (_end_api_key_session()). */
    const SIGN_OUT_API_KEY_REVOKED = 13;

    /**
     * The API key the user session stood for passed its expires_at - signed out when the key
     * is next presented, or by the hourly Session_Cleanup_Service::cleanup_sessions sweep.
     */
    const SIGN_OUT_API_KEY_EXPIRED = 14;

    /**
     * The browser went unused for rsx.sessions.unused_session_days and was made inactive - at
     * its next lookup, or by the daily Session_Cleanup_Service::deactivate_unused_sessions
     * sweep (_deactivate_browsers()). Listed as "Browser unused for N days".
     */
    const SIGN_OUT_BROWSER_UNUSED = 15;

    /**
     * The account's password changed (_end_sessions_for_password_change(), from the identity
     * model's own save): every other signed-in web sign-in of the identity ends.
     */
    const SIGN_OUT_PASSWORD_CHANGED = 16;

    /** Labels for the SIGN_OUT_* reasons, for screens that list user sessions. */
    const SIGN_OUT_REASON_LABELS = [
        self::SIGN_OUT_SIGNED_OUT => 'Signed out',
        self::SIGN_OUT_TIMED_OUT_LENGTH => 'Sign-in expired',
        self::SIGN_OUT_TIMED_OUT_IDLE => 'Signed out after inactivity',
        self::SIGN_OUT_ENDED_BY_USER => 'Ended from another device',
        self::SIGN_OUT_ENDED_BY_ADMIN => 'Ended by an administrator',
        self::SIGN_OUT_ENDED_BY_SYSTEM => 'Ended by the system',
        self::SIGN_OUT_SESSION_CAP => 'Too many sessions',
        self::SIGN_OUT_REPLACED => 'Another account signed in',
        self::SIGN_OUT_SITE_SWITCHED => 'Switched site',
        self::SIGN_OUT_MEMBERSHIP_DISABLED => 'Membership disabled',
        self::SIGN_OUT_IMPERSONATION_ENDED => 'Impersonation ended',
        self::SIGN_OUT_SESSION_RESET => 'Session reset',
        self::SIGN_OUT_API_KEY_REVOKED => 'API key revoked',
        self::SIGN_OUT_API_KEY_EXPIRED => 'API key expired',
        self::SIGN_OUT_BROWSER_UNUSED => 'Browser unused',
        self::SIGN_OUT_PASSWORD_CHANGED => 'Password changed',
    ];

    /**
     * What a screen prints for a SIGN_OUT_* reason - SIGN_OUT_REASON_LABELS, with the unused
     * period spelled out for SIGN_OUT_BROWSER_UNUSED. Null for an unknown reason.
     *
     * @param int $reason_id
     * @return string|null
     */
    public static function sign_out_reason_label(int $reason_id): ?string
    {
        if ($reason_id === self::SIGN_OUT_BROWSER_UNUSED) {
            $days = (int) config('rsx.sessions.unused_session_days');

            return $days > 0 ? "Browser unused for {$days} days" : self::SIGN_OUT_REASON_LABELS[$reason_id];
        }

        return self::SIGN_OUT_REASON_LABELS[$reason_id] ?? null;
    }

    /** The _sessions pointer column each realm's current user session lives in. */
    private const POINTER_COLUMNS = [
        self::REALM_STAFF => 'staff_user_session_id',
        self::REALM_PORTAL => 'portal_user_session_id',
    ];

    /**
     * last_active (the browser) and last_active_at (its current user sessions) are written at
     * most this often: they answer "unused for 30 days" and "most recent first", which a
     * minute's precision serves. A write rate, not a timeout - nothing expires on it.
     */
    private const LAST_ACTIVE_STAMP_INTERVAL_SECONDS = 60;

    /**
     * A page view is stamped at most this often per session: the stamp exists to
     * measure idleness against windows of hours or more, so one write a minute is all
     * the precision it can use. A write rate, not a timeout - nothing expires on it.
     */
    private const PAGE_VIEW_STAMP_INTERVAL_SECONDS = 60;

    // Static session management properties
    private static $_session = null;

    private static $_site = null;

    private static $_login_user = null; // Authentication identity (Login_User_Model)

    private static $_user = null; // Site-specific user (User_Model)
    private static $_user_key = null; // "<login_user_id>:<site_id>" $_user was resolved for

    private static $_session_token = null;

    private static $_has_init = false;

    private static $_has_activate = false;

    private static $_has_set_cookie = false;

    // True once THIS request has minted the browser's row or rotated its tokens. The token
    // it now carries has never left the server before this response, so a sign-in or a
    // sign-out later in the same request has nothing to rotate.
    private static bool $_minted_this_request = false;

    // The CSRF token a rotation in THIS request gave the browser, or null when nothing
    // rotated. The page that made the request still holds the old one; an Ajax response
    // hands it this one (_rotated_csrf_token()).
    private static ?string $_rotated_csrf_token = null;

    // True when this request arrived carrying the token a rotation just REPLACED (it matches
    // a live row's previous_token_hash). The request is refused (the dispatcher and the Ajax
    // entry point ask _arrived_on_rotated_token()), nothing mints a row for it and no session
    // cookie is emitted, so it can never overwrite the cookie the rotation sent.
    private static bool $_arrived_on_rotated_token = false;

    // The user sessions the loaded row's two pointers name, read once per row:
    // ['session_id' => the row's id, REALM_STAFF => ?object, REALM_PORTAL => ?object].
    // null = not read yet. Dropped whenever a pointer moves (__point()).
    private static ?array $_user_sessions = null;

    // Request-scoped overrides
    private static $_request_site_id_override = null;

    // DECLARED tenant for this script, set by set_temporary_site_id(). Outranks every
    // other tier except an API identity, is never persisted, and creates nothing - see
    // that method for why it is a separate property rather than a reuse of the
    // request/CLI overrides above.
    private static ?int $_temporary_site_id = null;

    // API identity (cookie-less). When set: [login_user_id, site_id, user_id, session_id,
    // user_session_id] - the last two the key's own row and its REALM_API user session, or
    // null for an identity installed with no key (Api_Tester_Key's visibility question).
    // Consulted by the accessors ahead of every other tier; see _set_api_identity().
    private static ?array $_api_identity = null;

    // THE VIRTUAL SESSION: the declared context of a process with no browser (who a command,
    // a task or a test is acting as). Statics only - a virtual session never has a row. The
    // getters read them directly, so a command that declares an identity gets coherent
    // answers and mints nothing.
    private static $_cli_site_id = null;

    private static $_cli_login_user_id = null; // Authentication identity ID

    private static $_cli_user_id = null; // Site-specific user ID

    // Impersonation (main-session, in-place identity swap) in a virtual session
    private static $_cli_impersonator_login_user_id = null; // Real principal behind an impersonation

    private static $_cli_impersonation_started_at = null; // ISO string, or null

    // A virtual session BOUND to a browser's sign-in: a #[Run_As_Dispatcher] task runs bound
    // to the user session of the request that dispatched it (Task_Dispatcher_Context).
    // get_session_id() answers that browser's row, get_user_session_id() the user session.
    private static ?int $_cli_session_id = null;

    private static ?int $_cli_user_session_id = null;

    // FROZEN: the identity, site, realm and impersonation of the virtual session cannot change
    // and session values are refused - the context is the dispatching request's, and the run
    // is bound to it. Only _end_virtual() / _begin_virtual() lift it.
    private static bool $_cli_frozen = false;

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per browser, plus one per API key and per harness account - the table this
     * whole discipline came from (62,617 rows for one login user).
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * The table associated with the model
     * @var string
     */
    protected $table = '_sessions';

    /**
     * Session rows are written on essentially every request (last-active bumps, version
     * bumps on identity change). That is per-request infrastructure churn, not user-facing
     * data anyone subscribes to, so it must never kick the realtime emitter engine.
     * @var bool
     */
    public static $realtime_silent = true;

    /**
     * The attributes that should be cast
     * @var array
     */
    protected $casts = [
        'active' => 'boolean',
        'type_id' => 'integer',
        'api_key_id' => 'integer',
        'staff_user_session_id' => 'integer',
        'portal_user_session_id' => 'integer',
        'version' => 'integer',
        'last_active' => 'datetime',
    ];

    /**
     * Columns that should never be exported to JavaScript
     * @var array
     */
    public static $neverExport = [
        'session_token',
        'previous_token_hash',
        'csrf_token',
        'ip_address_id',
    ];

    /**
     * Whether the current request is an API request (authenticated via API key).
     * Set only by Session::_set_api_identity().
     */
    public static bool $_is_api_request = false;

    /**
     * Check if the current request is an API request
     */
    public static function is_api_request(): bool
    {
        return static::$_is_api_request;
    }

    /**
     * Establish the cookie-less API identity for the current request.
     *
     * Called exactly once per request by Rsx_Api_Bearer after a Bearer key authenticates.
     * The identity is served by the accessors ahead of every other tier, and the browser
     * cookie machinery is disabled for the rest of the request: no cookie is read and no
     * Set-Cookie is emitted. Throws on a second call.
     *
     * ONE SESSION ROW PER API KEY. With $api_key_id, the key's own _sessions row (TYPE_API,
     * api_key_id unique, deleted with the key) is found - or minted on the key's first call -
     * together with a user session (REALM_API) for the key's owner bound to it, so the key's
     * use is listed where the owner is signed in (get_sessions_for_user()) and the request's
     * acts are audited under it. get_session_id() and get_user_session_id() answer those two
     * rows for the rest of the request. Both are stamped used at most once a minute, the row
     * with the caller's address and user agent. Ending the user session from a screen does not
     * stop the key - revoking the key does, and its row and user session go with it.
     *
     * Without $api_key_id (Api_Tester_Key asking which endpoints a user's gates admit) the
     * identity is installed alone and nothing is written: get_session_id() answers null.
     *
     * @param int $login_user_id Authentication identity (login_users.id)
     * @param int $site_id       Tenant scope (users.site_id)
     * @param int $user_id       Site-specific user (users.id)
     * @param int|null $api_key_id The key that authenticated the request
     */
    public static function _set_api_identity(int $login_user_id, int $site_id, int $user_id, ?int $api_key_id = null): void
    {
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::_set_api_identity() called twice in one request');
        }

        self::$_api_identity = [
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'user_id' => $user_id,
            'session_id' => null,
            'user_session_id' => null,
        ];
        self::$_is_api_request = true;

        if ($api_key_id !== null) {
            $rows = self::__api_key_session($api_key_id, $login_user_id, $site_id);

            self::$_api_identity['session_id'] = $rows['session_id'];
            self::$_api_identity['user_session_id'] = $rows['user_session_id'];
        }

        // Kill the cookie/session machinery for this request.
        self::$_has_init = true;
        self::$_has_set_cookie = true;
        self::$_session = null;

        // Drop resolved caches so they re-derive from the API identity.
        self::$_login_user = null;
        self::$_user = null;
        self::$_site = null;
    }

    /**
     * Sign out the user session an API key's row carries, when the key stops working: a
     * revoked or expired key stays in the database, and so does its row, but nothing is
     * signed in through it any more (SIGN_OUT_API_KEY_REVOKED / SIGN_OUT_API_KEY_EXPIRED). A
     * key that was never used, or whose user session is already signed out, has nothing to
     * end. Deleting a key needs nothing - its row and user session are deleted with it.
     *
     * FRAMEWORK INTERNAL - Api_Key_Model_Abstract::revoke() and find_by_key().
     *
     * @param int $api_key_id
     * @param int $reason SIGN_OUT_API_KEY_REVOKED or SIGN_OUT_API_KEY_EXPIRED
     * @return void
     */
    public static function _end_api_key_session(int $api_key_id, int $reason): void
    {
        $ids = DB::table('_user_sessions as u')
            ->join('_sessions as s', 's.id', '=', 'u.session_id')
            ->where('s.api_key_id', $api_key_id)
            ->where('u.realm_id', self::REALM_API)
            ->whereNull('u.signed_out_at')
            ->pluck('u.id')
            ->all();

        self::_sign_out_user_sessions($ids, $reason, false);
    }

    /**
     * Sign out every user session still signed in through an API key that has passed its
     * expires_at (SIGN_OUT_API_KEY_EXPIRED) - the sweep behind the refusal at authentication,
     * for keys nobody presents again. Reads the API rows (one per key that was ever used), so
     * the set is bounded by the keys, not by traffic. Answers how many were signed out.
     *
     * FRAMEWORK INTERNAL - Session_Cleanup_Service::cleanup_sessions().
     *
     * @return int
     */
    public static function _end_expired_api_key_sessions(): int
    {
        $ids = DB::table('_sessions as s')
            ->join('_api_keys as k', 'k.id', '=', 's.api_key_id')
            ->join('_user_sessions as u', 'u.session_id', '=', 's.id')
            ->where('s.type_id', self::TYPE_API)
            ->whereNotNull('k.expires_at')
            ->where('k.expires_at', '<', now()->format('Y-m-d H:i:s.v'))
            ->where('u.realm_id', self::REALM_API)
            ->whereNull('u.signed_out_at')
            ->pluck('u.id')
            ->all();

        return count(self::_sign_out_user_sessions($ids, self::SIGN_OUT_API_KEY_EXPIRED, false));
    }

    /**
     * Find or mint an API key's session row and its owner's REALM_API user session, and stamp
     * both used (at most once a minute).
     *
     * Each step is one statement on a unique key (api_key_id; the user session's tuple), so
     * two first calls of one key arriving at once land on the same rows. A user session found
     * signed out (somebody ended it from a sessions screen) is signed in again.
     *
     * @param int $api_key_id
     * @param int $login_user_id
     * @param int $site_id
     * @return array{session_id: int, user_session_id: int}
     */
    private static function __api_key_session(int $api_key_id, int $login_user_id, int $site_id): array
    {
        $now = now();
        $stamp = $now->format('Y-m-d H:i:s.v');
        $ip_address_id = Ip_Address::id(self::get_client_ip());
        $user_agent_id = Audit_Session::user_agent_id($_SERVER['HTTP_USER_AGENT'] ?? null);

        $row = DB::table('_sessions')->where('api_key_id', $api_key_id)->first();

        if ($row === null) {
            // The token satisfies the column's uniqueness and is never transmitted: the key
            // is the credential, and no cookie exists.
            DB::statement(
                'INSERT INTO _sessions
                    (active, type_id, api_key_id, session_token, csrf_token, ip_address_id, user_agent_id, last_active, version)
                 VALUES (1, ?, ?, ?, NULL, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                [self::TYPE_API, $api_key_id, bin2hex(random_bytes(32)), $ip_address_id, $user_agent_id, $stamp]
            );

            $row = DB::table('_sessions')->where('id', (int) DB::getPdo()->lastInsertId())->first();
        } elseif ($row->last_active === null || \Carbon\Carbon::parse($row->last_active)->lt($now->copy()->subSeconds(self::LAST_ACTIVE_STAMP_INTERVAL_SECONDS))) {
            DB::table('_sessions')->where('id', $row->id)->update([
                'last_active' => $stamp,
                'ip_address_id' => $ip_address_id,
                'user_agent_id' => $user_agent_id,
            ]);
        }

        $session_id = (int) $row->id;
        $identity = ['login_user_id' => $login_user_id, 'site_id' => $site_id, 'impersonator_login_user_id' => null];

        $user_session = DB::table('_user_sessions')
            ->where('tuple_hash', self::_user_session_tuple_hash($session_id, self::REALM_API, $identity))
            ->first(['id', 'signed_out_at', 'last_active_at']);

        if ($user_session === null || $user_session->signed_out_at !== null) {
            $user_session_id = self::__activate_user_session($session_id, self::REALM_API, $identity);
        } else {
            $user_session_id = (int) $user_session->id;

            if (\Carbon\Carbon::parse($user_session->last_active_at)->lt($now->copy()->subSeconds(self::LAST_ACTIVE_STAMP_INTERVAL_SECONDS))) {
                DB::table('_user_sessions')->where('id', $user_session_id)->update(['last_active_at' => $stamp]);
            }
        }

        return ['session_id' => $session_id, 'user_session_id' => $user_session_id];
    }

    /**
     * Clear the API identity tier and the flags it set. Called when an API dispatch ends
     * (Api_Dispatcher::end_request(), after the response is rendered), by a new in-process
     * dispatch, by Api_Tester_Key around its one question, and by the test seam.
     */
    public static function _reset_api_identity(): void
    {
        self::$_api_identity = null;
        self::$_is_api_request = false;
        self::$_has_init = false;
        self::$_has_set_cookie = false;
        self::$_minted_this_request = false;
        self::$_rotated_csrf_token = null;
        self::$_arrived_on_rotated_token = false;
        self::$_session = null;
        self::$_user_sessions = null;
        self::$_login_user = null;
        self::$_user = null;
        self::$_site = null;
    }

    /**
     * Return Session to virgin, pre-init state. THE per-test seam.
     *
     * A test runner is one process running many requests, which is the assumption Session is
     * built to violate: an identity is established once per request and never torn down,
     * because a web process dies at the end of the request. The runner has to perform that
     * boundary itself, and this is the one call that does it.
     *
     * Everything cleared here is REQUEST-SCOPED PROCESS STATE, which a database transaction
     * cannot roll back - the rollback undoes rows, not statics. Left standing, one test's
     * identity is silently inherited by every test after it, in this class and every class
     * that follows.
     *
     * Called by Rsx_Test_Abstract between test methods. Application code must never call it:
     * outside a runner there is no such thing as "the request is over, start another", and a
     * live request that tore its own identity down would simply be broken.
     *
     * @return void
     */
    public static function _testing_reset(): void
    {
        // The API identity tier, the loader flags and every resolved cache.
        self::_reset_api_identity();
        Audit_Session::_reset();
        Ip_Address::_reset();

        // Per-request overrides a test may have set on its way through. Narrow on purpose.
        self::$_request_site_id_override = null;
        self::$_browser_session_id_for_tests = null;

        // THE DECLARED TENANT, and it has to be cleared HERE rather than only in
        // reset_impersonation(). set_temporary_site_id() declares a tenant "for the rest
        // of the script" and OUTRANKS every other tier, the CLI declaration included -
        // correct for its real callers, a web request or a one-shot command whose script
        // ends moments later. Rsx_Initial_User::create() is one of them and deliberately
        // leaves the declaration standing.
        //
        // A TEST RUN IS NOT THAT SCRIPT. The runner creates the baseline user in-process,
        // and again whenever a $requires_db_reset class re-provisions, so each of those
        // left a declaration behind in a process that then ran every remaining class.
        // Because it outranks the CLI tier, __acting_as_site() still WROTE but
        // get_site_id() kept answering the baseline site: a fixture seeded "in another
        // site" landed in the baseline one, and every cross-tenant assertion after it
        // passed vacuously instead of failing. It surfaced as tests that pass alone and
        // fail in the suite, moving between classes as container scheduling changed.
        //
        // reset_impersonation() already states the rule - a declared tenant is
        // script-scoped, and for a test the SCRIPT is the test - but that runs only when a
        // test calls __reset_session() itself. This is the boundary that always runs.
        self::$_temporary_site_id = null;

        // A virtual session's BINDING and freeze belong to one run (a #[Run_As_Dispatcher]
        // task), never to a suite: a test that bound one and failed before ending it must not
        // leave every later test frozen.
        self::$_cli_frozen = false;
        self::$_cli_session_id = null;
        self::$_cli_user_session_id = null;

        // DELIBERATELY NOT CLEARED: $_cli_site_id / $_cli_login_user_id / $_cli_user_id.
        //
        // Those are the virtual session's DECLARATION - "act as this user on this site" - and the
        // established contract is that they survive between tests in a class, because setup()
        // runs once per CLASS and is where a suite declares them. Clearing them here reads
        // like good hygiene and is not: every fixture written after the first test method
        // silently lands on site_id 0, and the failure surfaces as a NOT NULL violation three
        // layers away from the cause.
        //
        // The portal facade makes the opposite choice for its own declaration, and says so.
        // The difference is deliberate: a portal site is declared per REQUEST by the
        // application, a CLI identity is declared per SUITE by the test author.
    }

    /**
     * Whether this process runs a VIRTUAL session: it has no browser. Every CLI process
     * does - a command, a task worker, the test runner - from boot to exit.
     *
     * @return bool
     */
    private static function __is_virtual(): bool
    {
        return php_sapi_name() === 'cli';
    }

    /**
     * Whether this process runs a virtual session (no browser, no row; see the class
     * docblock). FRAMEWORK INTERNAL.
     *
     * @return bool
     */
    public static function _is_virtual(): bool
    {
        return self::__is_virtual();
    }

    /**
     * Start a fresh virtual session, optionally already acting as somebody.
     *
     * Ends whatever virtual session the process held (_end_virtual()), then declares the
     * context given - every key optional:
     *
     *   realm                        'staff', 'portal' or 'api' - declares the request realm
     *                                (portal for 'portal', staff otherwise); absent leaves it
     *   site_id                      the staff tenant
     *   login_user_id, user_id       the staff identity (login_users.id, users.id)
     *   impersonator_login_user_id   a staff impersonator behind it
     *   portal_user_id, portal_site_id, impersonator_user_id
     *                                the portal identity, its site and a View as Client
     *                                impersonator (staff users.id)
     *   session_id, user_session_id  the browser and the sign-in the context is BOUND to -
     *                                what get_session_id() and get_user_session_id() answer
     *   audit_session_id             the acting context get_audit_session_id() answers
     *   frozen                       true: the context cannot change for as long as it lasts -
     *                                every identity, site, realm and impersonation setter of
     *                                both facades throws, and so do the session values
     *
     * Nothing is written anywhere: a virtual session has no _sessions row, no user session and
     * no audit session of its own. Refused in a web request, which is a browser's session.
     *
     * A FROZEN, BOUND session is how a #[Run_As_Dispatcher] task runs (Task_Dispatcher_Context):
     * it acts exactly as the request that dispatched it, on that request's browser, and
     * nothing it does can make it act as anybody else.
     *
     * FRAMEWORK INTERNAL - the task runner and the test harness.
     *
     * @param array $context
     * @return void
     */
    public static function _begin_virtual(array $context = []): void
    {
        self::_end_virtual();

        $int_or_null = fn (string $key) => isset($context[$key]) ? (int) $context[$key] : null;

        self::$_cli_site_id = $int_or_null('site_id');
        self::$_cli_login_user_id = $int_or_null('login_user_id');
        self::$_cli_user_id = $int_or_null('user_id');
        self::$_cli_session_id = $int_or_null('session_id');
        self::$_cli_user_session_id = $int_or_null('user_session_id');

        if (!empty($context['impersonator_login_user_id'])) {
            self::$_cli_impersonator_login_user_id = (int) $context['impersonator_login_user_id'];
            self::$_cli_impersonation_started_at = Rsx_Time::now_iso();
        }

        if (isset($context['realm'])) {
            if (!in_array($context['realm'], ['staff', 'portal', 'api'], true)) {
                shouldnt_happen("Session::_begin_virtual(): unknown realm '{$context['realm']}'");
            }

            \App\RSpade\Core\Portal\Rsx_Portal::set_portal_request($context['realm'] === 'portal');
        }

        if (isset($context['portal_user_id']) || isset($context['portal_site_id'])) {
            \App\RSpade\Core\Portal\Portal_Session::_declare_virtual_identity(
                $int_or_null('portal_user_id'),
                $int_or_null('portal_site_id'),
                $int_or_null('impersonator_user_id')
            );
        }

        Audit_Session::_declare($int_or_null('audit_session_id'));

        // The timezone follows the identity just declared, not the one resolved before it.
        Rsx_Time::_clear_user_timezone_cache();

        self::$_cli_frozen = !empty($context['frozen']);
    }

    /**
     * End the virtual session: the process acts as nobody, on no site, with no impersonation,
     * no declared tenant (set_temporary_site_id()), no portal identity, no bound browser or
     * sign-in and no declared audit session; the request realm is forgotten (staff again), and
     * every resolution keyed on the identity is dropped - the resolved user, login identity and
     * site, Rsx_Time's user timezone, Rsx_Dark_Mode's mode and the second factors passed
     * (Rsx_Two_Factor). A frozen session is lifted.
     * Refused in a web request.
     *
     * THE one blanking entry point: the task runner calls it after EVERY run a worker or
     * rsx:task:process --once executes, so nothing one run was - who, where, in which realm,
     * in which timezone - is inherited by the next run on that process.
     *
     * FRAMEWORK INTERNAL - the task runner and the test harness.
     *
     * @return void
     */
    public static function _end_virtual(): void
    {
        if (!self::__is_virtual()) {
            shouldnt_happen('Session::_end_virtual() / _begin_virtual() are for a process with no browser; a web request is a browser\'s session');
        }

        self::$_cli_frozen = false;

        self::$_cli_site_id = null;
        self::$_cli_login_user_id = null;
        self::$_cli_user_id = null;
        self::$_cli_impersonator_login_user_id = null;
        self::$_cli_impersonation_started_at = null;
        self::$_cli_session_id = null;
        self::$_cli_user_session_id = null;
        self::$_temporary_site_id = null;
        self::$_request_site_id_override = null;

        self::$_site = null;
        self::$_user = null;
        self::$_login_user = null;

        \App\RSpade\Core\Portal\Portal_Session::_clear_cli_identity();
        Audit_Session::_declare(null);
        \App\RSpade\Core\Dispatch\Rsx_Request_Channel::reset();
        Rsx_Time::_clear_user_timezone_cache();
        \App\RSpade\Core\Theme\Rsx_Dark_Mode::_clear_cache();
        \App\RSpade\Core\TwoFactor\Rsx_Two_Factor::_reset_request_state();
    }

    /**
     * Whether this process runs a FROZEN virtual session - a #[Run_As_Dispatcher] task bound to
     * the request that dispatched it. FRAMEWORK INTERNAL.
     *
     * @return bool
     */
    public static function _is_frozen(): bool
    {
        return self::$_cli_frozen;
    }

    /**
     * Throw when the virtual session is frozen: $call would change who the run acts as, where,
     * or reach the browser's session values, and a run bound to the request that dispatched it
     * may do neither. FRAMEWORK INTERNAL - Session and Portal_Session guard their setters with it.
     *
     * @param string $call The refused call, as the message names it
     * @return void
     * @throws Rsx_Caller_Exception
     */
    public static function _refuse_if_frozen(string $call): void
    {
        if (!self::$_cli_frozen) {
            return;
        }

        throw new Rsx_Caller_Exception(
            "{$call}: this task runs as the user who dispatched it (#[Run_As_Dispatcher]) and is bound to the request that dispatched it - "
            . 'its identity, site, realm and impersonation cannot change, and the browser\'s session values cannot be read or written. '
            . 'Pass what the task needs in its params; dispatch a task without #[Run_As_Dispatcher] to act as somebody else.'
        );
    }

    /**
     * Everything that says who and where this process is acting, as a value: the virtual
     * session's declarations, the declared tenant and request override, the portal facade's
     * declarations, the declared audit session and the request realm. _restore_context() puts
     * it back exactly. Browser rows and user sessions are database state and are not part of
     * it.
     *
     * FRAMEWORK INTERNAL - Task::internal() keeps its caller's context around an inline run.
     *
     * @return array
     */
    public static function _snapshot_context(): array
    {
        return [
            'session' => [
                'cli_site_id' => self::$_cli_site_id,
                'cli_login_user_id' => self::$_cli_login_user_id,
                'cli_user_id' => self::$_cli_user_id,
                'cli_impersonator_login_user_id' => self::$_cli_impersonator_login_user_id,
                'cli_impersonation_started_at' => self::$_cli_impersonation_started_at,
                'cli_session_id' => self::$_cli_session_id,
                'cli_user_session_id' => self::$_cli_user_session_id,
                'cli_frozen' => self::$_cli_frozen,
                'temporary_site_id' => self::$_temporary_site_id,
                'request_site_id_override' => self::$_request_site_id_override,
            ],
            'portal' => \App\RSpade\Core\Portal\Portal_Session::_snapshot_context(),
            'audit_session_id' => Audit_Session::_declared_id(),
            'channel' => \App\RSpade\Core\Dispatch\Rsx_Request_Channel::_snapshot(),
        ];
    }

    /**
     * Put back a context _snapshot_context() took, and drop every resolution keyed on the
     * identity (resolved user, login identity and site, the user timezone, the dark mode) so
     * the restored context is the one they answer for.
     *
     * FRAMEWORK INTERNAL - Task::internal().
     *
     * @param array $snapshot
     * @return void
     */
    public static function _restore_context(array $snapshot): void
    {
        $session = $snapshot['session'];

        self::$_cli_site_id = $session['cli_site_id'];
        self::$_cli_login_user_id = $session['cli_login_user_id'];
        self::$_cli_user_id = $session['cli_user_id'];
        self::$_cli_impersonator_login_user_id = $session['cli_impersonator_login_user_id'];
        self::$_cli_impersonation_started_at = $session['cli_impersonation_started_at'];
        self::$_cli_session_id = $session['cli_session_id'];
        self::$_cli_user_session_id = $session['cli_user_session_id'];
        self::$_cli_frozen = $session['cli_frozen'];
        self::$_temporary_site_id = $session['temporary_site_id'];
        self::$_request_site_id_override = $session['request_site_id_override'];

        self::$_site = null;
        self::$_user = null;
        self::$_login_user = null;

        \App\RSpade\Core\Portal\Portal_Session::_restore_context($snapshot['portal']);
        Audit_Session::_declare($snapshot['audit_session_id']);
        \App\RSpade\Core\Dispatch\Rsx_Request_Channel::_restore($snapshot['channel']);
        Rsx_Time::_clear_user_timezone_cache();
        \App\RSpade\Core\Theme\Rsx_Dark_Mode::_clear_cache();
    }

    /**
     * Initialize session from cookie or request
     * Loads existing session but does not create new one
     *
     * In a virtual session: nothing to load. A process with no browser has no cookie to
     * resume from.
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$_has_init) {
            return;
        }
        self::$_has_init = true;

        // Virtual session: no cookie, nothing to resume
        if (self::__is_virtual()) {
            return;
        }

        Manifest::init();

        $session_token = $_COOKIE[Rsx_Session_Cookie::name()] ?? null;

        if (empty($session_token)) {
            self::$_session = null;

            return;
        }

        // One cookie, one row: the token identifies the BROWSER, whatever it is signed into.
        // Only an ACTIVE row resumes. A token that matches nothing - never issued, or rotated
        // away two rotations ago - is simply a guest: nothing is created for it and no cookie
        // is sent until something asks for a session.
        $session = static::where('session_token', $session_token)->where('active', true)->first();

        if (!$session) {
            self::$_session = null;

            // The token the browser's LATEST rotation replaced: this request was in flight
            // when that sign-in or sign-out happened. An Ajax call or a sub-resource of an
            // open page is refused, and its response must not carry a cookie that would
            // overwrite the one the rotation sent. A PAGE NAVIGATION is not refused: it is
            // the browser starting over (it never stored the new cookie, or the user went
            // back to a page from before), so it is an ordinary guest, lazily, like any
            // token that matches nothing - which is how such a browser recovers.
            self::$_arrived_on_rotated_token = !self::__is_page_navigation()
                && static::where('previous_token_hash', hash('sha256', $session_token))->where('active', true)->exists();

            return;
        }

        // A browser unused for rsx.sessions.unused_session_days is retired here: whatever is
        // still signed in on it is signed out, and it is made inactive (kept, for the history
        // its user sessions record) and treated as absent.
        $unused_days = (int) config('rsx.sessions.unused_session_days');

        if ($unused_days > 0 && $session->last_active !== null && $session->last_active->lt(now()->subDays($unused_days))) {
            self::_deactivate_browsers([(int) $session->id]);
            self::$_session = null;

            return;
        }

        self::$_session = $session;
        self::__stamp_last_active();

        // Re-emit the SAME token cookie on every response (sliding 365-day expiry). Re-sending
        // the identical value merely refreshes the browser-side expiry; a rotation replaces it
        // (__replace_cookie()).
        self::$_session_token = $session_token;
        self::_set_cookie();
    }

    /**
     * Record that the loaded browser was used: its last_active and the last_active_at of the
     * user sessions it points at, at most once a minute. The UPDATE carries the interval
     * test, so concurrent requests cannot both write.
     *
     * @return void
     */
    private static function __stamp_last_active(): void
    {
        $session = self::$_session;
        $now = now();

        if ($session->last_active !== null && $session->last_active->gt($now->copy()->subSeconds(self::LAST_ACTIVE_STAMP_INTERVAL_SECONDS))) {
            return;
        }

        $stamped = static::where('id', $session->id)
            ->where('last_active', '<', $now->copy()->subSeconds(self::LAST_ACTIVE_STAMP_INTERVAL_SECONDS))
            ->raw_bulk()
            ->update(['last_active' => $now]);

        if ($stamped === 0) {
            return;
        }

        $session->last_active = $now;
        $session->syncOriginalAttribute('last_active');

        $user_session_ids = array_values(array_filter([$session->staff_user_session_id, $session->portal_user_session_id]));

        if (!empty($user_session_ids)) {
            DB::table('_user_sessions')
                ->whereIn('id', $user_session_ids)
                ->update(['last_active_at' => $now->format('Y-m-d H:i:s.v')]);
        }
    }

    /**
     * Whether the current request is a top-level PAGE NAVIGATION: the page channel, with
     * Sec-Fetch-Dest 'document' (or no Sec-Fetch-Dest at all - a client that does not send
     * it is taken as a navigation, as the dispatcher's session lifetimes take it). An Ajax
     * call, an image, a download, an iframe's document are not.
     *
     * @return bool
     */
    private static function __is_page_navigation(): bool
    {
        if (\App\RSpade\Core\Dispatch\Rsx_Request_Channel::current() !== \App\RSpade\Core\Dispatch\Rsx_Request_Channel::PAGE) {
            return false;
        }

        $fetch_dest = (string) request()->headers->get('Sec-Fetch-Dest', 'document');

        return $fetch_dest === 'document' || $fetch_dest === '';
    }

    /**
     * Whether this request arrived carrying the token the browser's latest rotation replaced
     * - one a sign-in or a sign-out on that browser has just swapped for a new one - and is
     * NOT a page navigation.
     *
     * Such a request is refused before any application code runs: a sub-resource on the page
     * channel answers 401, an Ajax call the session_expired envelope (cause 'rotated'). It was
     * in flight when the rotation happened; answering it as a guest would mint a row and send
     * a cookie over the one the browser was just given. A page navigation carrying that token
     * is never refused: it is answered as a guest (see init()).
     *
     * FRAMEWORK INTERNAL - the dispatcher and the Ajax entry point.
     *
     * @return bool
     */
    public static function _arrived_on_rotated_token(): bool
    {
        self::init();

        return self::$_arrived_on_rotated_token;
    }

    /**
     * Activate session - creates new one if needed.
     *
     * THE ONE ACTIVATION PATH for a browser. A portal login on a fresh browser arrives here
     * too (through Session::_set_portal_identity()) and mints exactly the row a staff page
     * view would: same cookie, same token machinery, portal properties written afterwards.
     * There is no site argument - a row belongs to a browser, not to a tenant. (An API key's
     * row and a harness row are minted by their own paths, which know what they are making.)
     *
     * Never reached in a virtual session, which has no browser to mint a row for, nor in a
     * request on a rotated token, which is refused before anything can ask.
     *
     * @return void
     */
    private static function __activate(): void
    {
        if (self::$_has_activate) {
            return;
        }

        if (self::__is_virtual()) {
            shouldnt_happen('Session::__activate() in a virtual session: a process with no browser has no session row to create');
        }

        self::$_has_activate = true;

        self::init();

        if (self::$_arrived_on_rotated_token) {
            shouldnt_happen('A request on a rotated session token must be refused before anything creates a session (Session::_arrived_on_rotated_token())');
        }

        if (empty(self::$_session)) {
            self::__mint_browser_row(self::TYPE_WEB);
        }
    }

    /**
     * Create a browser row of $type with fresh tokens, make it this request's session and send
     * its cookie (replacing any session cookie the request already queued).
     *
     * @param int $type TYPE_WEB or TYPE_PLAYWRIGHT
     * @return void
     */
    private static function __mint_browser_row(int $type): void
    {
        self::$_session_token = bin2hex(random_bytes(32));

        $session = new static();
        $session->session_token = self::$_session_token;
        $session->csrf_token = bin2hex(random_bytes(32));
        $session->ip_address = self::get_client_ip();
        $session->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $session->last_active = now();
        $session->active = true;
        $session->type_id = $type;
        $session->version = 1;

        $session->save();

        self::$_session = $session;
        self::$_user_sessions = null;
        self::$_site = null;
        self::$_login_user = null;
        self::$_user = null;
        self::$_minted_this_request = true;
        self::$_has_init = true;
        self::$_has_activate = true;
        self::__replace_cookie();
    }

    /**
     * Give the browser's row a new session token and CSRF token, in place, and send the new
     * cookie with this response.
     *
     * Called on the browser that is making the request at every sign-in and every sign-out.
     * The token the browser presented may be held by somebody else too - a copied cookie, a
     * token planted before the visitor signed in - and after a rotation it matches nothing:
     * the row (its values, its trust, its other realm's sign-in) carries on under a token
     * nobody has seen. The row's address and user agent are refreshed with it.
     *
     * The hash of the token being replaced is kept (previous_token_hash), so a request that
     * was already in flight with it is refused rather than answered as a guest - see
     * _arrived_on_rotated_token().
     *
     * Skipped when this request minted the row or has already rotated it: that token has not
     * left the server yet. Never in a virtual session or on an API request (no cookie).
     *
     * @return void
     */
    private static function __rotate_tokens(): void
    {
        if (self::$_api_identity !== null || self::__is_virtual() || empty(self::$_session) || self::$_minted_this_request) {
            return;
        }

        $session = self::$_session;
        $previous_token_hash = hash('sha256', (string) $session->session_token);
        $session_token = bin2hex(random_bytes(32));
        $csrf_token = bin2hex(random_bytes(32));
        $ip_address_id = Ip_Address::id(self::get_client_ip());
        $user_agent_id = Audit_Session::user_agent_id($_SERVER['HTTP_USER_AGENT'] ?? null);

        static::where('id', $session->id)->raw_bulk()->update([
            'session_token' => $session_token,
            'previous_token_hash' => $previous_token_hash,
            'csrf_token' => $csrf_token,
            'ip_address_id' => $ip_address_id,
            'user_agent_id' => $user_agent_id,
            'version' => DB::raw('version + 1'),
        ]);

        $session->setRawAttributes(array_merge($session->getAttributes(), [
            'session_token' => $session_token,
            'previous_token_hash' => $previous_token_hash,
            'csrf_token' => $csrf_token,
            'ip_address_id' => $ip_address_id,
            'user_agent_id' => $user_agent_id,
            'version' => (int) $session->version + 1,
        ]), true);

        self::$_session_token = $session_token;
        self::$_rotated_csrf_token = $csrf_token;
        self::$_minted_this_request = true;

        self::__replace_cookie();
    }

    /**
     * The CSRF token the page must use from now on, when a sign-in in this request moved
     * the browser to a new session row - else null.
     *
     * A sign-in made by an Ajax call leaves its page holding the superseded row's token,
     * and the next call from that page would fail the CSRF check. The Ajax envelope
     * carries this value so the client replaces window.rsxapp.csrf at once.
     *
     * FRAMEWORK INTERNAL - the Ajax envelope.
     *
     * @return string|null
     */
    public static function _rotated_csrf_token(): ?string
    {
        return self::$_rotated_csrf_token;
    }

    /**
     * Send the current session token as THE session cookie of this response, withdrawing
     * a session Set-Cookie the request already queued (init() re-emits the token it was
     * presented with). Every other header is kept exactly as it was.
     *
     * @return void
     */
    private static function __replace_cookie(): void
    {
        if (self::$_has_set_cookie) {
            $session_cookie_prefix = '/^Set-Cookie:\s*' . preg_quote(Rsx_Session_Cookie::name(), '/') . '=/i';
            $kept = [];
            foreach (headers_list() as $header) {
                if (stripos($header, 'Set-Cookie:') === 0 && !preg_match($session_cookie_prefix, $header)) {
                    $kept[] = $header;
                }
            }

            header_remove('Set-Cookie');

            foreach ($kept as $header) {
                header($header, false);
            }

            self::$_has_set_cookie = false;
        }

        self::_set_cookie();
    }

    /**
     * Set the session cookie with security flags
     *
     * Emits nothing in a virtual session (no response to attach a cookie to) and nothing for
     * a request on a rotated token, which is being refused: whatever it touches on the way out
     * must not replace the cookie that rotation sent.
     *
     * @return void
     */
    private static function _set_cookie(): void
    {
        if (self::$_has_set_cookie) {
            return;
        }
        self::$_has_set_cookie = true;

        if (self::__is_virtual() || self::$_arrived_on_rotated_token) {
            return;
        }

        // ONE cookie for the whole site - staff pages and portal pages alike.
        setcookie(Rsx_Session_Cookie::name(), self::$_session_token, Rsx_Session_Cookie::options(time() + (365 * 86400)));
    }

    /**
     * Make this request's browser the rsx:debug harness's row for a staff account - see
     * __use_harness_session(). The dispatcher calls it for a request whose signed dev-auth
     * headers verified, then signs the account in as usual (a re-statement, and so a no-op,
     * when the row is already signed in as it).
     *
     * THE SITE is the request's declared one when the application declares a site per request
     * (Main::init(), a request override); otherwise the account's first active membership,
     * lowest site id first - the site a real sign-in of a single-site account binds, so a
     * harness run renders what that account sees rather than a site-less session.
     *
     * FRAMEWORK INTERNAL - Dispatcher::__handle_dev_auth().
     *
     * @param int $login_user_id
     * @return void
     */
    public static function _use_harness_session(int $login_user_id): void
    {
        $site_id = self::$_request_site_id_override;

        if ($site_id === null) {
            $site_id = (int) (User_Model::without_site_scope(
                fn () => User_Model::where('login_user_id', $login_user_id)
                    ->active()
                    ->orderBy('site_id')
                    ->value('site_id')
            ) ?? 0);
        }

        self::__use_harness_session(self::REALM_STAFF, [
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'impersonator_login_user_id' => null,
        ]);

        // The dispatcher signs the account in next (RsxAuth::login()), and a sign-in takes its
        // site from the request's declaration, else the session's - which a freshly minted
        // harness browser does not have. Declare the site worked out above for this request,
        // so a new harness row is signed in on it (and is found again by the next run).
        if (self::$_request_site_id_override === null && $site_id > 0) {
            self::$_request_site_id_override = $site_id;
        }
    }

    /**
     * The portal twin of _use_harness_session(): the harness row for a portal user on its site.
     *
     * FRAMEWORK INTERNAL - Dispatcher::__handle_portal_dev_auth().
     *
     * @param int $portal_user_id
     * @param int $portal_site_id
     * @return void
     */
    public static function _use_portal_harness_session(int $portal_user_id, int $portal_site_id): void
    {
        self::__use_harness_session(self::REALM_PORTAL, [
            'portal_user_id' => $portal_user_id,
            'portal_site_id' => $portal_site_id,
            'impersonator_user_id' => null,
        ]);
    }

    /**
     * ONE HARNESS ROW PER ACCOUNT. Every rsx:debug run starts a fresh browser, so a run that
     * minted a row per run would leave one behind every time. Instead the harness signs in
     * on a TYPE_PLAYWRIGHT row per staff account and site (per portal user), shared by every
     * run:
     *
     *   - the browser's cookie already names a harness row signed in as that identity (the
     *     run's own later requests): kept as it is;
     *   - otherwise an active harness row signed in as that identity is REUSED - the cookie is
     *     pointed at it - provided its sign-in is within the realm's ordinary limits
     *     (rsx.sessions.<realm>, from the sign-in and from the last page view) and the row
     *     within rsx.sessions.unused_session_days. A candidate past a limit is signed out
     *     with that reason (or made inactive) and passed over;
     *   - otherwise a NEW harness row is minted, and the caller's sign-in lands on it.
     *
     * A harness row is never a browser somebody uses: it is never listed with an account's
     * sessions and its traffic records no audit session. Only this path - reached through the
     * signed dev-auth headers - creates one.
     *
     * @param int $realm_id REALM_STAFF or REALM_PORTAL
     * @param array $identity The realm's identity, as __activate_user_session() takes it
     * @return void
     */
    private static function __use_harness_session(int $realm_id, array $identity): void
    {
        if (self::__is_virtual() || self::$_api_identity !== null) {
            shouldnt_happen('Session::_use_harness_session() is for a browser request carrying signed dev-auth headers');
        }

        self::init();

        $realm = self::__realm_word($realm_id);
        $identity_column = self::__identity_column($realm_id);
        $impersonator_column = self::__impersonator_column($realm_id);
        $site_column = $realm_id === self::REALM_PORTAL ? 'portal_site_id' : 'site_id';
        $matches = fn (object $user_session) => (int) $user_session->$identity_column === (int) $identity[$identity_column]
            && $user_session->$impersonator_column === null
            && (int) $user_session->$site_column === (int) $identity[$site_column];

        if (!empty(self::$_session) && (int) self::$_session->type_id === self::TYPE_PLAYWRIGHT) {
            $current = self::__user_session($realm_id);

            if ($current !== null && $matches($current)) {
                return;
            }
        }

        $unused_days = (int) config('rsx.sessions.unused_session_days');
        $unused_before = $unused_days > 0 ? now()->subDays($unused_days) : null;

        $candidates = DB::table('_sessions as s')
            ->join('_user_sessions as u', 'u.id', '=', 's.' . self::POINTER_COLUMNS[$realm_id])
            ->where('s.type_id', self::TYPE_PLAYWRIGHT)
            ->where('s.active', true)
            ->whereNull('u.signed_out_at')
            ->where('u.' . $identity_column, $identity[$identity_column])
            ->where('u.' . $site_column, $identity[$site_column])
            ->whereNull('u.' . $impersonator_column)
            ->orderBy('s.last_active', 'desc')
            ->orderBy('s.id', 'desc')
            ->select(['s.id as harness_session_id', 's.last_active as harness_last_active', 'u.*'])
            ->get();

        foreach ($candidates as $candidate) {
            if ($unused_before !== null && \Carbon\Carbon::parse($candidate->harness_last_active)->lt($unused_before)) {
                static::where('id', $candidate->harness_session_id)->raw_bulk()->update(['active' => false]);

                continue;
            }

            $expiry = self::__expiry_of($candidate, $realm);

            if ($expiry !== null && now()->gte($expiry['at'])) {
                self::_sign_out_user_sessions([(int) $candidate->id], self::timeout_sign_out_reason($expiry['reason']), false);

                continue;
            }

            $row = static::where('id', $candidate->harness_session_id)->first();

            self::$_session = $row;
            self::$_session_token = $row->session_token;
            self::$_user_sessions = null;
            self::$_site = null;
            self::$_login_user = null;
            self::$_user = null;
            self::$_has_init = true;
            self::$_has_activate = true;

            self::__replace_cookie();
            self::__stamp_last_active();

            return;
        }

        self::__mint_browser_row(self::TYPE_PLAYWRIGHT);
    }

    /**
     * Make an EXISTING session row this browser's session on THIS host: load it as the
     * current session for the rest of the request and emit its token in the session cookie.
     *
     * The last leg of the linked-session handshake (Session_Link), on a portal served from
     * its own host: once both hosts have proved they are the same browser, the portal
     * host's cookie is pointed at the staff browser's row, so one row serves both hosts.
     * Never called with a row the handshake has not just verified.
     *
     * The request may already have resolved a DIFFERENT row from this host's cookie, and
     * init() then re-emitted that token. That earlier Set-Cookie is withdrawn before the
     * new one is sent, so the response carries exactly one session cookie. The replaced row
     * is DEACTIVATED: its token lived only in this browser's cookie on this host, which no
     * longer holds it, so nothing could ever resume it - leaving it active would only list a
     * device nobody is on. (A row that is the target itself is simply kept.)
     *
     * No token is minted here: this re-emits one that already exists (see init()).
     *
     * FRAMEWORK INTERNAL - for Session_Link only. Refused in a virtual session, where there
     * is no browser and no response to carry a cookie.
     *
     * @param int $session_id An active _sessions row
     * @return bool False when the row is gone or no longer active (nothing is changed)
     */
    public static function _clone_session_to_this_host(int $session_id): bool
    {
        if (self::__is_virtual()) {
            shouldnt_happen('Session::_clone_session_to_this_host() has no browser to give a cookie to in a virtual session');
        }

        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::_clone_session_to_this_host() called on a headless API request');
        }

        $row = static::where('id', $session_id)->where('active', true)->first();

        if (!$row) {
            return false;
        }

        self::init();

        if (!empty(self::$_session) && (int) self::$_session->id !== $session_id) {
            static::where('id', self::$_session->id)->raw_bulk()->update(['active' => false]);
        }

        self::$_session = $row;
        self::$_session_token = $row->session_token;
        self::$_user_sessions = null;
        self::$_site = null;
        self::$_login_user = null;
        self::$_user = null;
        self::$_has_init = true;
        self::$_has_activate = true;

        self::__replace_cookie();

        return true;
    }

    /**
     * The requesting client's IP address as the framework sees it: $request->ip(), resolved
     * by the TrustProxies middleware - X-Forwarded-For honoured only through a trusted peer
     * (loopback plus config('rsx.http.trusted_proxies')), walked right to left, so a header
     * the client wrote cannot choose the answer. Reads no session state and creates nothing,
     * so it is safe on any code path (including ones that must not mint a session).
     *
     * Returns null when there is no request context at all (CLI, tasks, programmatic work).
     * An API request answered in-process (an API identity in a CLI process) has one: the
     * request it was dispatched with. A caller that needs a non-null string supplies its own
     * placeholder.
     *
     * @return string|null Client IP, or null outside a request
     */
    public static function get_client_ip(): ?string
    {
        if (self::__is_virtual() && self::$_api_identity === null) {
            return null;
        }

        return request()->ip() ?? '0.0.0.0';
    }

    /**
     * Reset the current browser's session: THE invalidation primitive.
     *
     * Every sign-in on the browser - staff and portal, impersonations included - is signed
     * out (SIGN_OUT_SESSION_RESET), its values go with them, the row is made INACTIVE and
     * the cookie is cleared, so the browser starts over as a stranger. logout() is the
     * everyday exit; it ends one realm's sign-in and keeps the browser.
     *
     * In a virtual session there is no row and no cookie: the hard exit ends the declared
     * impersonation and forgets what was resolved.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::init();

        if (self::__is_virtual()) {
            self::_refuse_if_frozen('Session::reset()');

            // Reset is the hard exit - impersonation cannot span it
            self::$_cli_impersonator_login_user_id = null;
            self::$_cli_impersonation_started_at = null;

            self::$_site = null;
            self::$_user = null;

            return;
        }

        if (!empty(self::$_session)) {
            $session_id = (int) self::$_session->id;

            self::_sign_out_user_sessions(
                DB::table('_user_sessions')->where('session_id', $session_id)->whereNull('signed_out_at')->pluck('id')->all(),
                self::SIGN_OUT_SESSION_RESET
            );

            DB::table('_session_values')->where('session_id', $session_id)->delete();
            static::where('id', $session_id)->raw_bulk()->update(['active' => false]);
        }

        // Clear impersonation CLI overrides (hard teardown, both modes)
        self::$_cli_impersonator_login_user_id = null;
        self::$_cli_impersonation_started_at = null;

        self::$_session = null;
        self::$_user_sessions = null;
        self::$_site = null;
        self::$_user = null;
        self::$_login_user = null;
        self::$_has_init = false;
        self::$_has_activate = false;
        self::$_has_set_cookie = false;
        self::$_minted_this_request = false;
        self::$_rotated_csrf_token = null;

        // Clear cookie
        setcookie(Rsx_Session_Cookie::name(), '', Rsx_Session_Cookie::options(time() - 3600));
    }

    /**
     * Get site ID for current session
     * Respects request-scoped override first (for subdomain enforcement)
     * In CLI mode: returns static CLI property
     * @return int
     */
    public static function get_site_id(): int
    {
        // API request identity (headless) takes precedence over every other tier
        if (self::$_api_identity !== null) {
            return self::$_api_identity['site_id'];
        }

        // A tenant DECLARED for this script outranks the stored session, both
        // overrides below, and the CLI branch - the caller is asserting the context
        // the rest of this execution runs in. See set_temporary_site_id().
        if (self::$_temporary_site_id !== null) {
            return self::$_temporary_site_id;
        }

        // Request override takes precedence (subdomain enforcement)
        if (self::$_request_site_id_override !== null) {
            return self::$_request_site_id_override;
        }

        // CLI mode: return static property
        if (self::__is_virtual()) {
            return self::$_cli_site_id ?? 0;
        }

        return (int) (self::__user_session(self::REALM_STAFF)?->site_id ?? 0);
    }

    /**
     * Whether a staff site has been DECLARED for this execution - as opposed to
     * get_site_id() answering 0 only because nothing said otherwise.
     *
     * Declared: an API key's identity, set_temporary_site_id(), a request override
     * (set_site_id() for a guest, the subdomain override), a virtual session's site
     * (set_site_id() / impersonate() in a command, task or test - site 0 included, when it
     * was named), or a signed-in staff user session bound to a site. Not declared: a plain
     * task or command that named no site, a guest request that declared none, and a
     * sign-in not yet bound to a site.
     *
     * A site-scoped read with no site declared THROWS (Site_Scoped): reading "site 0"
     * by default answered every query with nothing, silently.
     *
     * @return bool
     */
    public static function has_declared_site(): bool
    {
        if (self::$_api_identity !== null || self::$_temporary_site_id !== null || self::$_request_site_id_override !== null) {
            return true;
        }

        if (self::__is_virtual()) {
            return self::$_cli_site_id !== null;
        }

        return (int) (self::__user_session(self::REALM_STAFF)?->site_id ?? 0) > 0;
    }

    /** Site ids this process has confirmed exist (a site row is soft-deleted, never removed). */
    private static array $_existing_site_ids = [];

    /**
     * Refuse a site id with no sites row behind it. The setters only RECORD a site - setting
     * one is a no-op until something uses it, and a boot-time declaration may precede the
     * sites table itself (migrate on a fresh database) - so the check is made where the site
     * MATTERS: the first site-scoped read or create (Site_Scoped). A typo or a stale id would
     * otherwise be the tenant every scoped query uses, silently. Confirmed once per process.
     * A soft-deleted site still EXISTS here - its data does, and administrative work on it
     * must be able to declare it; whether a deleted site may be SIGNED IN to is the
     * membership rules' question, not this one.
     *
     * @throws \RuntimeException
     */
    public static function _assert_site_exists(int $site_id, string $call): void
    {
        if (isset(self::$_existing_site_ids[$site_id])) {
            return;
        }

        if (!Site_Model::withTrashed()->where('id', $site_id)->exists()) {
            throw new \RuntimeException("{$call}: there is no site {$site_id} (no sites row with that id).");
        }

        self::$_existing_site_ids[$site_id] = true;
    }

    /**
     * MISUSE GUARD - Session is NOT a session-variable bag; there is no get($key).
     *
     * Session extends the Eloquent model for the _sessions table, so a bare
     * Session::get('site_id') - which READS like Laravel's session()->get() - would
     * otherwise fall through static magic to Eloquent's Model::get(): a FULL-TABLE
     * SELECT hydrating every _sessions row (observed OOM at ~54k rows), returning a
     * Collection, never the value. This shadow intercepts exactly that misuse shape
     * and fails loud at the call site. Legitimate query-builder reads are unaffected:
     * Session::where(...)->get() / Session::query()->get() go through the Builder
     * instance, not this static.
     *
     * @throws Rsx_Caller_Exception always
     */
    public static function get(...$args): never
    {
        throw new Rsx_Caller_Exception(
            'Session::get() does not exist - Session is the _sessions Eloquent model, not a session-variable bag. '
            . 'Use the named accessor instead: get_site_id(), get_user_id(), get_login_user_id(), get_session_id(), '
            . 'get_user(), get_site(), get_csrf_token(). For a query, use Session::where(...)->get().'
        );
    }

    /**
     * Get site model for current session
     * @return Site_Model|null
     */
    public static function get_site()
    {
        $site_id = self::get_site_id();

        if ($site_id === 0) {
            return null;
        }

        if (empty(self::$_site)) {
            self::$_site = Site_Model::find($site_id);
        }

        return self::$_site;
    }

    /**
     * Get login user ID (authentication identity) for current session
     * In CLI mode: returns static CLI property
     * @return int|null
     */
    public static function get_login_user_id()
    {
        // API request identity (headless) takes precedence
        if (self::$_api_identity !== null) {
            return self::$_api_identity['login_user_id'];
        }

        // CLI mode: return static property
        if (self::__is_virtual()) {
            return self::$_cli_login_user_id;
        }

        $user_session = self::__user_session(self::REALM_STAFF);

        return $user_session === null ? null : (int) $user_session->login_user_id;
    }

    /**
     * Get site-specific user ID for current session
     * This is the users.id (site-specific), not login_users.id
     * In CLI mode: the user impersonate() named, otherwise the one derived from the declared
     * login identity + site exactly as a web request derives it (get_user())
     * @return int|null
     */
    public static function get_user_id()
    {
        // API request identity (headless) takes precedence
        if (self::$_api_identity !== null) {
            return self::$_api_identity['user_id'];
        }

        // CLI mode: the user impersonate() named; otherwise derived like a web request's
        if (self::__is_virtual() && self::$_cli_user_id !== null) {
            return self::$_cli_user_id;
        }

        $user = self::get_user();

        return $user ? $user->id : null;
    }

    /**
     * Check if user is logged in
     * @return bool
     */
    public static function is_logged_in(): bool
    {
        return !empty(self::get_login_user_id());
    }

    /**
     * Get login user model (authentication identity) for current session
     * @return Login_User_Model|null
     */
    public static function get_login_user()
    {
        $login_user_id = self::get_login_user_id();

        if (empty($login_user_id)) {
            return null;
        }

        if (empty(self::$_login_user)) {
            self::$_login_user = Login_User_Model::find($login_user_id);
        }

        return self::$_login_user;
    }

    /**
     * Get site-specific user model for current session
     * @return User_Model|null
     */
    public static function get_user()
    {
        $login_user_id = self::get_login_user_id();
        $site_id = self::get_site_id();

        if (empty($login_user_id) || empty($site_id)) {
            return null;
        }

        // Keyed on the pair: a later set_site_id() or temporary site must not answer the
        // previous site's user.
        $key = $login_user_id . ':' . $site_id;
        if (empty(self::$_user) || self::$_user_key !== $key) {
            self::$_user = User_Model::where('login_user_id', $login_user_id)
                ->where('site_id', $site_id)
                ->first();
            self::$_user_key = $key;
        }

        return self::$_user;
    }

    /**
     * Is the signed-in identity still an ACTIVE member of the site this request serves?
     *
     * THE REQUEST-TIME HALF OF THE FRAMEWORK'S is_enabled CONTRACT. RsxAuth::verify_credentials() and
     * RsxAuth::login() refuse an identity with no active membership at sign-in; this is what
     * keeps a session that was legitimate five minutes ago from outliving the membership behind
     * it. When the users row for (login_user_id, site_id) is MISSING or is not active
     * (User_Model::is_active(): users.is_enabled off, or its SITE disabled or deleted), the
     * session is LOGGED OUT and false is returned, and the transport that asked answers
     * response_unauthorized() through its ordinary channel - a login redirect for a page, the
     * auth_required envelope for an Ajax call. Turning the switch off therefore ends the sessions
     * that were already open - for one membership or, through sites.is_enabled, for a whole
     * site - which is the only reading of "disabled" worth having.
     *
     * THE EFFECTIVE IDENTITY IS WHAT IS CHECKED. Under impersonation the session carries the
     * TARGET, so it is the target's membership that is asked about: disabling an account ends an
     * impersonation of it, the same as it ends that account's own sessions.
     *
     * CREATES NOTHING and costs at most TWO queries - the membership and its site. It reads the
     * accessors, which answer from the session row the request already loaded, and goes through
     * get_user(), so a request that has already resolved its user pays only for the site. A
     * caller with no identity, no tenant, or a headless API identity (which the Bearer dispatcher
     * has already checked) is permitted here: "there is nobody to disable" is not a denial.
     *
     * Invoked ONCE per request, by the dispatcher immediately before the #[Auth] gates and by the
     * Ajax browser entry point before its gates. Nothing else calls it; CLI processes and the
     * suite have no session row to enforce against.
     *
     * @return bool True when the request may proceed; false when the session was just ended
     */
    public static function enforce_enabled_membership(): bool
    {
        // The headless API identity is not backed by a session row, and Rsx_Api_Bearer has
        // already refused a disabled membership before minting it.
        if (self::$_api_identity !== null) {
            return true;
        }

        if (empty(self::get_login_user_id()) || empty(self::get_site_id())) {
            return true;
        }

        $user = self::get_user();

        if ($user !== null && $user->is_active()) {
            return true;
        }

        self::__logout_staff(self::SIGN_OUT_MEMBERSHIP_DISABLED);

        return false;
    }

    // =========================================================================
    // AUDIT ATTRIBUTION (_audit_sessions, _user_agents, _ip_addresses)
    // =========================================================================

    /**
     * The id of the AUDIT SESSION for whoever is acting right now - the value to store
     * on a record of something somebody did (an activity log row, an export record).
     *
     * NEVER STORE get_session_id() FOR THAT. A session row is the BROWSER: whoever is
     * signed in on it changes, and a guest's is collected - so a stored session id does not
     * say who did something. An audit session is a permanent row in _audit_sessions: one per
     * user session x client address x user agent (a guest's act: per browser), describing the
     * acting context (identity, tenant, impersonators, address, user agent), minted on first
     * use and never collected. See Audit_Session.
     *
     * A browser request and a Bearer-key API call (the key's own session) have one. A
     * command, a task and the rsx:debug harness have NONE, and answer null - unless the task
     * runner declared the dispatching request's (a #[Run_As_Dispatcher] task). The same
     * context answers the same id; a sign-in, a site switch, an impersonation or a new address
     * answers a new one. Creates no session.
     *
     * The framework stamps it on every recorded unit of work itself
     * (_transactions.audit_session_id, nullable for exactly that reason).
     *
     * @return int|null
     */
    public static function get_audit_session_id(): ?int
    {
        return Audit_Session::current_id();
    }

    /**
     * What an audit session recorded: site_id, login_user_id, portal_user_id,
     * portal_site_id, both impersonators, ip_address, user_agent (the string),
     * session_id (the browser), user_session_id, session_type_id, first_seen_at and
     * last_seen_at. Null
     * when there is no such audit session.
     *
     * The way to read an id stored from get_audit_session_id() back - _audit_sessions
     * itself is the framework's table.
     *
     * @param int $audit_session_id
     * @return array|null
     */
    public static function get_audit_session(int $audit_session_id): ?array
    {
        return Audit_Session::describe($audit_session_id);
    }

    /**
     * The browser's user-agent string for this row - `$session->user_agent`. The row
     * stores user_agent_id; the string lives once in _user_agents.
     *
     * @return string|null
     */
    public function getUserAgentAttribute(): ?string
    {
        return Audit_Session::user_agent_string($this->attributes['user_agent_id'] ?? null);
    }

    /**
     * Assigning `$session->user_agent = $string` records the string in _user_agents and
     * stores its id.
     *
     * @param string|null $user_agent
     * @return void
     */
    public function setUserAgentAttribute(?string $user_agent): void
    {
        $this->attributes['user_agent_id'] = Audit_Session::user_agent_id($user_agent);
    }

    /**
     * The client address that created this row - `$session->ip_address`, null for a
     * command's row. The row stores ip_address_id; the address lives once in
     * _ip_addresses (see Ip_Address).
     *
     * @return string|null
     */
    public function getIpAddressAttribute(): ?string
    {
        return Ip_Address::address($this->attributes['ip_address_id'] ?? null);
    }

    /**
     * Assigning `$session->ip_address = $string` records the address in _ip_addresses and
     * stores its id.
     *
     * @param string|null $ip_address
     * @return void
     */
    public function setIpAddressAttribute(?string $ip_address): void
    {
        $this->attributes['ip_address_id'] = Ip_Address::id($ip_address);
    }

    /**
     * The sessions an audit session for the act happening now is keyed on, or null when this
     * context records NO audit session: a virtual session (a command, a task - unless the
     * task runner declared one), the rsx:debug harness (a TYPE_PLAYWRIGHT row), and an API
     * identity installed with no key. Creates nothing.
     *
     *   session_id       the browser row, or the API key's row; null for a guest who has none
     *   user_session_id  the user session acting - the request realm's on a browser, the
     *                    key owner's REALM_API one on an API call; null for a guest
     *   session_type_id  the row's type (TYPE_WEB for a guest with no row)
     *
     * FRAMEWORK INTERNAL - Audit_Session.
     *
     * @return array{session_id: int|null, user_session_id: int|null, session_type_id: int}|null
     */
    public static function _audit_context(): ?array
    {
        if (self::$_api_identity !== null) {
            if (self::$_api_identity['session_id'] === null) {
                return null;
            }

            return [
                'session_id' => self::$_api_identity['session_id'],
                'user_session_id' => self::$_api_identity['user_session_id'],
                'session_type_id' => self::TYPE_API,
            ];
        }

        if (self::__is_virtual()) {
            return null;
        }

        $row = self::__row_if_any();

        if ($row !== null && (int) $row->type_id === self::TYPE_PLAYWRIGHT) {
            return null;
        }

        return [
            'session_id' => $row === null ? null : (int) $row->id,
            'user_session_id' => self::get_user_session_id(),
            'session_type_id' => $row === null ? self::TYPE_WEB : (int) $row->type_id,
        ];
    }

    // =========================================================================
    // SESSION LIFETIME (rsx.sessions.staff / rsx.sessions.portal)
    // =========================================================================

    /**
     * When the sign-in one experience holds on this browser stops being valid, and why - or
     * null when it holds none, or no limit applies.
     *
     * Two clocks per experience, both on the CURRENT user session of that realm:
     *
     *   max_length_minutes   from signed_in_at - the SIGN-IN. The hard ceiling: it is what
     *                        bounds a session however busy it is. A site switch and a staff
     *                        impersonation carry it over, so neither restarts it.
     *   max_idle_minutes     from last_page_view_at - a rendered page or an SPA navigation.
     *                        An Ajax call is not a page view, so an open tab that polls does
     *                        not keep a session alive.
     *
     * A limit of 0 means "no limit". The earlier of the two is the answer.
     *
     * FRAMEWORK INTERNAL - Session and Portal_Session enforce through it.
     *
     * @param string $realm 'staff' or 'portal'
     * @return array{at: \Carbon\Carbon, reason: string}|null reason is 'length' or 'idle'
     */
    public static function _timeout_expiry(string $realm): ?array
    {
        $user_session = self::__user_session(self::__realm_id($realm));

        return $user_session === null ? null : self::__expiry_of($user_session, $realm);
    }

    /**
     * When one user session's sign-in stops being valid under a realm's limits, and why, or
     * null when no limit applies - _timeout_expiry() for a user session given by row.
     *
     * @param object $user_session A _user_sessions row
     * @param string $realm 'staff' or 'portal'
     * @return array{at: \Carbon\Carbon, reason: string}|null
     */
    private static function __expiry_of(object $user_session, string $realm): ?array
    {
        $limits = [
            'length' => [$user_session->signed_in_at, 'max_length_minutes'],
            'idle' => [$user_session->last_page_view_at, 'max_idle_minutes'],
        ];

        $expiry = null;

        foreach ($limits as $reason => [$since, $config_key]) {
            $minutes = (int) config('rsx.sessions.' . $realm . '.' . $config_key);

            if ($minutes <= 0 || empty($since)) {
                continue;
            }

            $at = \Carbon\Carbon::parse($since)->addMinutes($minutes);

            if ($expiry === null || $at->lt($expiry['at'])) {
                $expiry = ['at' => $at, 'reason' => $reason];
            }
        }

        return $expiry;
    }

    /**
     * Whether an experience's identity is past its lifetime for THIS kind of request.
     *
     * A PAGE ($strict) is judged at the limit itself. Everything else - an Ajax call, a
     * thumbnail, a download - is allowed rsx.sessions.ajax_grace_minutes beyond it, so a
     * page that was already open when the limit passed can still finish what it was
     * doing (save the form on screen) even though it can no longer navigate.
     *
     * Answers the reason ('length' or 'idle') or null. Ends nothing - see
     * enforce_timeouts().
     *
     * FRAMEWORK INTERNAL.
     *
     * @param string $realm 'staff' or 'portal'
     * @param bool $strict
     * @return string|null
     */
    public static function _timeout_reason(string $realm, bool $strict): ?string
    {
        if (self::$_api_identity !== null || self::__is_virtual()) {
            return null;
        }

        $expiry = self::_timeout_expiry($realm);

        if ($expiry === null) {
            return null;
        }

        $deadline = $expiry['at'];

        if (!$strict) {
            $deadline = $deadline->copy()->addMinutes((int) config('rsx.sessions.ajax_grace_minutes'));
        }

        return now()->gte($deadline) ? $expiry['reason'] : null;
    }

    /**
     * End the staff sign-in on this browser when it has outlived rsx.sessions.staff, and say
     * why.
     *
     * Invoked ONCE per request by the dispatcher (a page: $strict) and by the Ajax entry
     * point (not strict - the grace applies), ahead of the #[Auth] gates, so the request
     * continues as a signed-out one and its ordinary channel answers: the login redirect for
     * a page, the session_expired envelope for an Ajax call.
     *
     * The user session is signed out with SIGN_OUT_TIMED_OUT_LENGTH / _IDLE (an impersonation
     * takes the impersonator's own sign-in with it - it was running on the impersonator's
     * clocks) and the browser's tokens rotate, exactly as at a logout. Nothing is written
     * while no limit has been passed.
     *
     * @param bool $strict True for a page; false gives the Ajax grace
     * @return string|null 'length' or 'idle' when the sign-in was just ended, else null
     */
    public static function enforce_timeouts(bool $strict): ?string
    {
        $reason = self::_timeout_reason('staff', $strict);

        if ($reason !== null) {
            self::_sign_out_current('staff', self::timeout_sign_out_reason($reason));
        }

        return $reason;
    }

    /**
     * The SIGN_OUT_* reason a session limit is recorded under.
     *
     * @param string $reason 'length' or 'idle'
     * @return int
     */
    public static function timeout_sign_out_reason(string $reason): int
    {
        return $reason === 'idle' ? self::SIGN_OUT_TIMED_OUT_IDLE : self::SIGN_OUT_TIMED_OUT_LENGTH;
    }

    /**
     * The sentence shown to somebody whose sign-in was ended by a session limit.
     *
     * @param string $reason 'length' or 'idle'
     * @return string
     */
    public static function timeout_message(string $reason): string
    {
        return $reason === 'idle'
            ? 'You were signed out after a period of inactivity. Please sign in again.'
            : 'Your session has expired. Please sign in again.';
    }

    /**
     * Record a PAGE VIEW for the staff sign-in on this browser: the stamp max_idle_minutes is
     * measured from (the user session's last_page_view_at), and last_seen on the account.
     *
     * Called when a page renders its bundle and when the browser reports an SPA navigation
     * (Rsx_Session_Controller). Never for an Ajax or API call - those are not somebody looking
     * at a page.
     *
     * Written at most once a minute per user session; the account's last_seen rides the same
     * write. While impersonating, the user session is stamped (it is in use) and the account
     * is not (its owner was not here). CREATES NOTHING: no sign-in, no write.
     *
     * @return void
     */
    public static function touch_page_view(): void
    {
        if (!self::_page_view_is_due('staff')) {
            return;
        }

        if (self::is_impersonating()) {
            return;
        }

        // Written with a bare statement on purpose: last_seen is bookkeeping, and a model
        // save() here would bump updated_at, record a revision and push a realtime
        // refresh to the account's own pages on every page view.
        $now = now()->format('Y-m-d H:i:s.v');
        $user_session = self::__user_session(self::REALM_STAFF);

        DB::statement('UPDATE login_users SET last_seen = ? WHERE id = ?', [$now, $user_session->login_user_id]);

        $user_id = self::get_user_id();

        if (!empty($user_id)) {
            DB::statement('UPDATE users SET last_seen = ? WHERE id = ?', [$now, $user_id]);
        }
    }

    /**
     * Stamp one experience's page view on its current user session if a minute has passed
     * since the last stamp, and say whether it was stamped. The UPDATE carries the interval
     * test, so concurrent requests cannot both win.
     *
     * FRAMEWORK INTERNAL - touch_page_view() and Portal_Session::touch_page_view().
     *
     * @param string $realm 'staff' or 'portal'
     * @return bool
     */
    public static function _page_view_is_due(string $realm): bool
    {
        if (self::$_api_identity !== null || self::__is_virtual()) {
            return false;
        }

        $user_session = self::__user_session(self::__realm_id($realm));

        if ($user_session === null) {
            return false;
        }

        $now = now()->format('Y-m-d H:i:s.v');
        $stale_before = now()->subSeconds(self::PAGE_VIEW_STAMP_INTERVAL_SECONDS)->format('Y-m-d H:i:s.v');

        $stamped = DB::table('_user_sessions')
            ->where('id', $user_session->id)
            ->whereNull('signed_out_at')
            ->where(function ($query) use ($stale_before) {
                $query->whereNull('last_page_view_at')->orWhere('last_page_view_at', '<', $stale_before);
            })
            ->update(['last_page_view_at' => $now, 'last_active_at' => $now]);

        if ($stamped === 0) {
            return false;
        }

        $user_session->last_page_view_at = $now;
        $user_session->last_active_at = $now;

        return true;
    }

    /**
     * Whether the current identity is permitted to use the external API.
     *
     * The one predicate every API seam asks: the Bearer dispatcher before it
     * establishes an identity, the docs console before it renders, and the JS twin
     * Permission.has_api_access() before it draws a link. Backed by the
     * users.is_api_access_enabled column, which the user-management UI writes.
     *
     * CREATES NOTHING, EVER - this is a question, and asking a question must never
     * mint a session row. The ordering below is what makes that true:
     *
     *   1. The headless Bearer tier is checked FIRST, because it is backed by no row
     *      at all: has_session() answers false for it by contract, so an early-out on
     *      has_session() would refuse every API request before its user was consulted.
     *   2. Only then does the absence of a session end the question - a caller with no
     *      session has no identity to have access, and get_user() must not be reached
     *      through init() paths for a caller that plainly has nobody signed in.
     *   3. Finally the site-scoped user is resolved; no user, no access.
     *
     * @return bool
     */
    public static function has_api_access(): bool
    {
        if (self::$_api_identity === null && !self::has_session()) {
            return false;
        }

        $user = self::get_user();

        if (!$user) {
            return false;
        }

        return (bool) $user->is_api_access_enabled;
    }

    /**
     * Whether the signed-in identity is a developer of this installation.
     *
     * A developer IS the login_users.is_developer flag, and nothing else. It is never a
     * role - a role says what somebody may do inside one site's records, while this says
     * who builds the software - and it is never a hostname: a box is not trusted because
     * of what it is called.
     *
     * Only a person with a database client sets it, by hand
     * (UPDATE login_users SET is_developer = 1 WHERE email = '...'). See rsx:man session.
     *
     * CREATES NOTHING - like has_api_access(), this is a question. The headless API tier
     * is never a developer tier: a bearer key is a machine credential, and developer
     * surfaces are for a person at a browser.
     *
     * @return bool
     */
    public static function is_developer(): bool
    {
        if (self::$_api_identity !== null) {
            return false;
        }

        if (!self::has_session()) {
            return false;
        }

        return (bool) self::get_login_user()?->is_developer;
    }

    /**
     * Get current session model (creates if needed). A browser request only: a virtual
     * session and an API request have no browser row to return, and throw.
     *
     * @return Session
     */
    public static function get_session(): Session
    {
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::get_session() is unavailable in an API request');
        }

        if (self::__is_virtual()) {
            if (self::$_browser_session_id_for_tests === null) {
                self::_refuse_if_frozen('Session::get_session()');

                shouldnt_happen('Session::get_session(): a virtual session (a command, a task) has no browser session row');
            }

            return static::findOrFail(self::$_browser_session_id_for_tests);
        }

        self::__activate();

        return self::$_session;
    }

    /**
     * The id of this browser's session, creating the session if the browser has none yet -
     * or NULL where there is no browser.
     *
     *   a browser request    the browser's row, minted on first demand (with its cookie)
     *   an API request       the key's own row (TYPE_API); null for an identity installed
     *                        with no key
     *   a virtual session    null - a command or a task has no browser. A task run as its
     *                        dispatcher (#[Run_As_Dispatcher]) answers the dispatching
     *                        browser's row; a test acting as a browser
     *                        (Rsx_Test_Abstract::__as_browser()) answers that row.
     *
     * Call it only when you want a session - asking creates one for a guest. has_session() is
     * the question that creates nothing. A column that stores this id is nullable for the
     * contexts that have none; a record of what somebody DID stores get_audit_session_id().
     *
     * @return int|null
     */
    public static function get_session_id(): ?int
    {
        if (self::$_api_identity !== null) {
            return self::$_api_identity['session_id'];
        }

        if (self::__is_virtual()) {
            return self::$_browser_session_id_for_tests ?? self::$_cli_session_id;
        }

        self::__activate();

        return (int) self::$_session->id;
    }

    /**
     * Get CSRF token for current session
     * @return string|null
     */
    public static function get_csrf_token(): ?string
    {
        return self::__csrf_row()?->csrf_token;
    }

    /**
     * Verify CSRF token
     * @param string $token
     * @return bool
     */
    public static function verify_csrf_token(string $token): bool
    {
        $row = self::__csrf_row();

        if ($row === null || empty($row->csrf_token)) {
            return false;
        }

        // Use constant-time comparison
        return hash_equals($row->csrf_token, $token);
    }

    /**
     * The browser row whose CSRF token this request answers to, or null: none on an API
     * request (no browser), none in a virtual session unless a test is acting as a browser,
     * and none for a guest who has minted nothing. Creates nothing.
     *
     * @return static|null
     */
    private static function __csrf_row(): ?self
    {
        if (self::$_api_identity !== null) {
            return null;
        }

        if (self::__is_virtual()) {
            return self::$_browser_session_id_for_tests === null ? null : static::find(self::$_browser_session_id_for_tests);
        }

        return self::__row_if_any();
    }

    /**
     * Sign the staff account out of this browser. Same as set_login_user_id(null).
     *
     * @return void
     */
    public static function logout(): void
    {
        self::set_login_user_id(null);
    }

    /**
     * Sign a staff account in on this browser, or (null) sign the current one out.
     *
     * SIGN-IN (web): the user session for (this browser, the account, the site, no
     * impersonator) is found or created and the browser points at it; its clocks start
     * (signed_in_at, last_page_view_at) and its sign_in_count grows. The site is the
     * request's declared tenant when there is one (set_site_id() before anybody signed in),
     * else the site the browser's staff sign-in was on, else 0 until set_site_id() binds it.
     * Whatever staff sign-in the browser held before is signed out (SIGN_OUT_REPLACED) unless
     * it IS that user session. The browser's tokens rotate (__rotate_tokens()), and the
     * account's session cap is applied. Re-stating the account the browser is already signed
     * in as, with $touch_last_login false (the rsx:debug harness on every navigation), is
     * not a sign-in and changes nothing.
     *
     * SIGN-OUT (web): the current staff user session is stamped SIGN_OUT_SIGNED_OUT - an
     * impersonation together with the impersonator's own sign-in behind it, since logging out
     * is the hard exit - the pointer is cleared, the browser's STAFF values are cleared (the
     * portal's and the guest values stay), and the tokens rotate. An already signed-out browser is left
     * exactly as it was (nothing is minted).
     *
     * In CLI mode this only declares or clears the identity statics: no row, no user session,
     * and never a last_login stamp.
     *
     * @param int|null $login_user_id Login user ID, or null to logout
     * @param bool $touch_last_login Whether this is somebody signing in: stamps
     *        login_users.last_login. False for the rsx:debug dev-auth harness.
     * @return void
     */
    public static function set_login_user_id(?int $login_user_id, bool $touch_last_login = true): void
    {
        // API request identity is immutable - endpoints must not log in/out
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::set_login_user_id() cannot mutate identity in an API request');
        }

        self::_refuse_if_frozen(empty($login_user_id) ? 'Session::logout()' : 'Session::set_login_user_id()');

        if (empty($login_user_id)) {
            self::__logout_staff(self::SIGN_OUT_SIGNED_OUT);

            return;
        }

        // CLI mode: set the identity override. Deliberately mints nothing - a command that
        // declares who it is acting as has not asked for a session.
        if (self::__is_virtual()) {
            self::$_cli_login_user_id = $login_user_id;
            self::$_cli_user_id = null;
            self::$_login_user = null;
            self::$_user = null;
            self::$_site = null;

            return;
        }

        self::__activate();

        $current = self::__user_session(self::REALM_STAFF);

        if (!$touch_last_login && $current !== null && (int) $current->login_user_id === $login_user_id
            && $current->impersonator_login_user_id === null) {
            return;
        }

        $site_id = self::$_request_site_id_override ?? (int) ($current?->site_id ?? 0);

        self::__sign_in(self::REALM_STAFF, [
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'impersonator_login_user_id' => null,
        ], self::SIGN_OUT_REPLACED);

        self::__rotate_tokens();

        Realtime::push_session_refresh('staff', (int) self::$_session->id);

        // Sign-in is the moment this account's session count grows, so it is where the
        // concurrent-session cap is applied.
        self::_enforce_session_cap(self::REALM_STAFF, $login_user_id);

        if ($touch_last_login) {
            $login_user_record = self::get_login_user();
            if ($login_user_record) {
                $login_user_record->last_login = now();
                $login_user_record->save();
            }
        }
    }

    /**
     * End the staff sign-in on this browser, recording why. The CLI branch clears the
     * declared identity instead.
     *
     * @param int $reason SIGN_OUT_*
     * @return void
     */
    private static function __logout_staff(int $reason): void
    {
        if (self::__is_virtual()) {
            self::_refuse_if_frozen('Session::logout()');

            self::$_cli_login_user_id = null;
            self::$_cli_user_id = null;
            // Logout is a hard exit - impersonation cannot span it
            self::$_cli_impersonator_login_user_id = null;
            self::$_cli_impersonation_started_at = null;
            self::$_login_user = null;
            self::$_user = null;
            self::$_site = null;

            return;
        }

        self::_sign_out_current('staff', $reason);
    }

    /**
     * Sign out the sign-in one realm holds on THIS browser: its current user session (with,
     * for a staff impersonation, the impersonator's own sign-in behind it - the hard exit),
     * then rotate the browser's tokens. No-op when that realm is signed out.
     *
     * FRAMEWORK INTERNAL - logout(), the session lifetimes, Portal_Session.
     *
     * @param string $realm 'staff' or 'portal'
     * @param int $reason SIGN_OUT_*
     * @return void
     */
    public static function _sign_out_current(string $realm, int $reason): void
    {
        $realm_id = self::__realm_id($realm);
        $current = self::__user_session($realm_id);

        if ($current === null) {
            return;
        }

        $ids = [(int) $current->id];

        if ($realm_id === self::REALM_STAFF && $current->impersonator_login_user_id !== null) {
            $own = self::__suspended_own_user_session((int) self::$_session->id, $current);

            if ($own !== null) {
                $ids[] = (int) $own->id;
            }
        }

        self::_sign_out_user_sessions($ids, $reason);
        self::__rotate_tokens();
    }

    /**
     * Sign this account out of its oldest sessions in a realm beyond
     * rsx.sessions.max_web_sessions_per_user.
     *
     * Called from the sign-in paths, because a sign-in is the only moment an account's
     * session count grows. The N most recently active SIGNED-IN user sessions of the account
     * (impersonations of it not counted) survive; the rest are signed out
     * (SIGN_OUT_SESSION_CAP) and their browsers' pointers cleared. Only browsers of
     * TYPE_WEB count and are evicted: a test run must never sign a developer out of their own
     * browser.
     *
     * Disabled entirely when the setting is 0 or null. Capacity management, not termination:
     * no authorization, no refresh push and no 'session.terminated' event - the evicted
     * browser learns at its next request.
     *
     * FRAMEWORK INTERNAL - the staff and portal sign-ins.
     *
     * @param int $realm_id REALM_STAFF or REALM_PORTAL
     * @param int $identity_id login_users.id or portal_users.id
     * @return void
     */
    public static function _enforce_session_cap(int $realm_id, int $identity_id): void
    {
        $max = config('rsx.sessions.max_web_sessions_per_user');

        if (empty($max)) {
            return;
        }

        $signed_in = fn () => self::__signed_in_query($realm_id, $identity_id)
            ->whereNull('u.' . self::__impersonator_column($realm_id))
            ->where('s.type_id', self::TYPE_WEB);

        // The ids to KEEP. This LIMIT is the cap itself - "the N most recent" is precisely
        // what the caller asked for - and N is small and config-bounded.
        $keep = $signed_in()
            ->orderBy('u.last_active_at', 'desc')
            ->orderBy('u.id', 'desc')
            ->limit((int) $max)
            ->pluck('u.id')
            ->all();

        $evict = $signed_in()->whereNotIn('u.id', $keep)->pluck('u.id')->all();

        self::_sign_out_user_sessions($evict, self::SIGN_OUT_SESSION_CAP, false);
    }

    /**
     * Set the staff tenant.
     *
     * SIGNED IN (web): the browser's staff sign-in moves to that site. The user session for
     * (this browser, the account, the new site, the same impersonator) is found or created
     * and made current, carrying the sign-in's clocks (switching site does not restart the
     * session lifetime), and the one it leaves is signed out SIGN_OUT_SITE_SWITCHED. A
     * sign-in that has no site yet (site 0) is BOUND to the new one in place. Nobody signed
     * in afresh, so the tokens do not rotate. Live tabs on the browser get a refresh push.
     *
     * NOBODY SIGNED IN (web): a REQUEST-SCOPED override, no write and no session - a guest's
     * tenant is declared per request (Main::init()). CLI: the identity static.
     *
     * @param int $site_id
     * @return void
     */
    public static function set_site_id(int $site_id): void
    {
        // API request identity is immutable - endpoints must not switch tenant
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::set_site_id() cannot mutate identity in an API request');
        }

        self::_refuse_if_frozen('Session::set_site_id()');

        // CLI mode: set the tenant override. Mints nothing - same laziness as the web branch
        // below, which stores a request-scoped override rather than creating a row.
        if (self::__is_virtual()) {
            self::$_cli_site_id = $site_id;
            self::$_site = null;

            return;
        }

        $current = self::__user_session(self::REALM_STAFF);

        if ($current === null) {
            self::$_request_site_id_override = $site_id;
            self::$_site = null;

            return;
        }

        // Already there (this guard IS the confirmed-different gate for the push below)
        if ((int) $current->site_id === $site_id) {
            return;
        }

        $identity = [
            'login_user_id' => (int) $current->login_user_id,
            'site_id' => $site_id,
            'impersonator_login_user_id' => $current->impersonator_login_user_id === null ? null : (int) $current->impersonator_login_user_id,
        ];

        $session_id = (int) self::$_session->id;
        $target_hash = self::_user_session_tuple_hash($session_id, self::REALM_STAFF, $identity);

        if ((int) $current->site_id === 0 && !DB::table('_user_sessions')->where('tuple_hash', $target_hash)->exists()) {
            // A sign-in that never had a site: bind it, rather than leave a site-0 row behind.
            DB::table('_user_sessions')->where('id', $current->id)->update([
                'site_id' => $site_id,
                'user_id' => self::__membership_id($identity['login_user_id'], $site_id),
                'tuple_hash' => $target_hash,
            ]);

            self::__forget_user_sessions();
        } else {
            self::__sign_in(self::REALM_STAFF, $identity, self::SIGN_OUT_SITE_SWITCHED, [
                'signed_in_at' => $current->signed_in_at,
                'last_page_view_at' => $current->last_page_view_at,
            ]);
        }

        self::$_site = null;
        self::$_user = null;

        // Tenant switched -> push a refresh to every live connection on this browser.
        Realtime::push_session_refresh('staff', $session_id);
    }

    /**
     * DECLARE the tenant for the rest of this script, writing nothing anywhere.
     *
     * set_site_id() is the wrong tool when there is no session and none should be
     * created: in web mode, once a row exists it WRITES site_id to that row and, on
     * the sign-in path, a row plus a Set-Cookie can come into being. This does
     * neither, in either mode:
     *
     *   - it never creates a _sessions row and never emits a cookie;
     *   - it never writes to an existing row - a browser mid-session keeps whatever
     *     tenant it had, and gets it back the moment this is cleared;
     *   - it works identically in CLI and web, because it is pure static state.
     *
     * From here until clear_temporary_site_id() or the end of the script:
     * get_site_id() returns this value and has_session() answers true. The point of
     * has_session() answering true is that site-scoped code guards on it before
     * trusting get_site_id(), so a declared tenant that did not satisfy that guard
     * would be a tenant half the framework refused to see.
     *
     * It OUTRANKS the stored session and both overrides - the caller is asserting
     * the context this execution runs in, so a row that happens to say otherwise
     * does not win. An API request is the one thing it will not override: that
     * identity is immutable for the request, exactly as set_site_id() has it.
     *
     * The motivating case is creating the initial user, where site 1 must be the
     * ambient tenant for the account write and for the user.initial.created
     * handlers - in a first-run WEB request whose visitor has no session, and in a
     * migrate/test CLI process, with the same one call. See Rsx_Initial_User.
     *
     * @param int $site_id
     * @return void
     */
    public static function set_temporary_site_id(int $site_id): void
    {
        // API request identity is immutable - endpoints must not switch tenant
        if (self::$_api_identity !== null) {
            shouldnt_happen('Session::set_temporary_site_id() cannot mutate identity in an API request');
        }

        self::_refuse_if_frozen('Session::set_temporary_site_id()');

        self::$_temporary_site_id = $site_id;

        // Drop the resolved site so the next get_site() re-derives from the declaration
        self::$_site = null;
    }

    /**
     * Drop the declared tenant and return to normal resolution - the stored session,
     * the request override, or the CLI static, whichever applied before.
     *
     * Nothing was written, so nothing has to be undone: this is a single assignment,
     * and it is safe to call when no declaration is in force.
     *
     * @return void
     */
    public static function clear_temporary_site_id(): void
    {
        self::_refuse_if_frozen('Session::clear_temporary_site_id()');

        self::$_temporary_site_id = null;

        self::$_site = null;
    }

    /**
     * Whether a tenant has been DECLARED for this script by set_temporary_site_id().
     *
     * @return bool
     */
    public static function has_temporary_site_id(): bool
    {
        return self::$_temporary_site_id !== null;
    }

    /**
     * The browser-facing hash of the session this request HOLDS, or null when it holds
     * none - what rsxapp.session_hash and Spa_Session_Controller::get_state() both publish,
     * and Rsx.validate_session() compares (a difference reloads the page). Read from the
     * session row, so a session created or rotated DURING this request is the one hashed -
     * never from the incoming cookie, which names the session the request arrived with.
     * Hashed so the token itself never reaches the page. Creates nothing.
     *
     * @return string|null
     */
    public static function _client_session_hash(): ?string
    {
        self::init();

        if (empty(self::$_session)) {
            return null;
        }

        return hash_hmac('sha256', self::$_session->session_token, config('app.key'));
    }

    /**
     * Check if a session exists - the question get_session_id() does NOT answer,
     * because asking it creates one. Creates nothing itself, in any mode.
     *
     * Web: whether this browser has a session row. Virtual session: whether a site or a user
     * has been declared (a command acting as somebody has a session context, though it never
     * has a row), or the process is acting as a test browser. An API request: false - the
     * key's row is not a browser, and nothing that guards on a browser session applies to it.
     *
     * @return bool
     */
    public static function has_session(): bool
    {
        // API request: the key's row is not a browser session
        if (self::$_api_identity !== null) {
            return false;
        }

        // A tenant DECLARED for this script IS a session context, in either mode -
        // site-scoped code guards on has_session() before trusting get_site_id(),
        // so answering false here would hide the tenant from half the framework.
        if (self::$_temporary_site_id !== null) {
            return true;
        }

        // Virtual session: a declared identity context, or a test acting as a browser
        if (self::__is_virtual()) {
            return self::$_browser_session_id_for_tests !== null
                || self::$_cli_session_id !== null
                || self::$_cli_site_id !== null
                || self::$_cli_user_id !== null;
        }

        // Web mode: init and check if session exists
        self::init();

        return !empty(self::$_session);
    }

    /**
     * Get session by token (for API/external access).
     *
     * The token identifies the BROWSER's session row, whatever identities it carries.
     * Portal_Session::find_by_token() is the same lookup narrowed to rows that
     * actually hold a portal identity.
     *
     * @param string $token
     * @return Session|null
     */
    public static function find_by_token(string $token)
    {
        return static::where('session_token', $token)
            ->where('active', true)
            ->first();
    }

    /**
     * Override save to increment version on updates
     * @param array $options
     * @return bool
     */
    public function save(array $options = []): bool
    {
        // Increment version on updates (but don't check for conflicts since sessions
        // are single-user and shouldn't have real concurrent modifications)
        if ($this->exists && $this->isDirty()) {
            $this->version = ($this->version ?? 1) + 1;
        }

        return parent::save($options);
    }

    /**
     * Impersonate a full session context for CLI/testing. Sets the site, login
     * identity and site-specific user as static overrides, so the Session getters
     * return these values and site-scoped models / audit attribution see the
     * impersonated context.
     *
     * Creates no session row and no user session: declaring a context is not demanding a
     * session, and a CLI process's identity lives in these statics alone.
     *
     * Only available in CLI mode (artisan / test runner) - there is no notion
     * of a static override in a web request.
     *
     * @param int $site_id
     * @param int|null $login_user_id Authentication identity (login_users.id)
     * @param int|null $user_id Site-specific user (users.id)
     * @return void
     */
    public static function impersonate(int $site_id, ?int $login_user_id, ?int $user_id): void
    {
        if (!self::__is_virtual()) {
            shouldnt_happen('Session::impersonate() is only available in CLI/test context');
        }

        self::_refuse_if_frozen('Session::impersonate()');

        self::$_cli_site_id = $site_id;
        self::$_cli_login_user_id = $login_user_id;
        self::$_cli_user_id = $user_id;

        // Clear cached resolutions so the new context is reloaded on next access
        self::$_site = null;
        self::$_user = null;
        self::$_login_user = null;
    }

    /**
     * Clear the virtual session's declared staff context (site, login identity, user,
     * impersonation, declared tenant). Nothing was written, so nothing is undone. The portal
     * facade's declarations are its own and are left alone (_end_virtual() clears both).
     *
     * Only available in a virtual session (CLI / test runner).
     * @return void
     */
    public static function reset_impersonation(): void
    {
        if (!self::__is_virtual()) {
            shouldnt_happen('Session::reset_impersonation() is only available in CLI/test context');
        }

        self::_refuse_if_frozen('Session::reset_impersonation()');

        self::$_cli_site_id = null;
        self::$_cli_login_user_id = null;
        self::$_cli_user_id = null;

        // The CLI half of web impersonation (begin_impersonation() in CLI) is context too:
        // a test that began one must not hand it to the next test.
        self::$_cli_impersonator_login_user_id = null;
        self::$_cli_impersonation_started_at = null;

        // A declared tenant is script-scoped, and for a test the SCRIPT is the test:
        // leaving it set would leak the declaration into every later test in the run.
        self::$_temporary_site_id = null;

        self::$_site = null;
        self::$_user = null;
        self::$_login_user = null;
    }

    // =========================================================================
    // WEB IMPERSONATION ("Log in as user") - same cookie, a user session of its own
    //
    // Distinct from the CLI-only impersonate()/reset_impersonation() above (which set
    // static overrides for test setup). An impersonation is a USER SESSION whose identity
    // is the target and whose impersonator_login_user_id is the real principal; the
    // browser points at it while it lasts, and the principal's own sign-in stays signed in
    // behind it, SUSPENDED, until stop_impersonation() points the browser back. Staff
    // impersonation is full read/write; whether to restrict anything is entirely the app's
    // call (core exposes only the is_impersonating() flag).
    // =========================================================================

    /**
     * Begin impersonating the given login user from the CURRENT staff sign-in.
     *
     * The user session (this browser, the target, the current site, impersonator = the
     * current account) is found or created and made current; it runs on the impersonator's
     * clocks (signed_in_at, last_page_view_at), because an impersonation is not a sign-in -
     * nobody re-authenticated, so nothing rotates and the target's last_login is untouched.
     * Its started_at is now. The impersonator's own user session stays signed in, suspended.
     *
     * Non-recursive: throws if already impersonating. Throws if there is no signed-in staff
     * account to impersonate from, or if target === the current login_user_id.
     *
     * @param int $target_login_user_id
     * @return void
     */
    public static function begin_impersonation(int $target_login_user_id): void
    {
        self::_refuse_if_frozen('Session::begin_impersonation()');

        // Must have an active principal to impersonate FROM
        if (!self::is_logged_in()) {
            shouldnt_happen('begin_impersonation requires an active logged-in session');
        }

        // Non-recursive - one level only
        if (self::is_impersonating()) {
            shouldnt_happen('begin_impersonation: already impersonating (nesting is not allowed)');
        }

        $impersonator_login_user_id = self::get_login_user_id();

        // Self-impersonation is a no-op that would only confuse restore semantics
        if ($target_login_user_id === $impersonator_login_user_id) {
            shouldnt_happen('begin_impersonation: cannot impersonate the current login user');
        }

        // CLI mode: record on static overrides, then swap identity
        if (self::__is_virtual()) {
            self::$_cli_impersonator_login_user_id = $impersonator_login_user_id;
            self::$_cli_impersonation_started_at = Rsx_Time::now_iso();
            self::set_login_user_id($target_login_user_id, false);

            return;
        }

        $own = self::__user_session(self::REALM_STAFF);

        self::__sign_in(self::REALM_STAFF, [
            'login_user_id' => $target_login_user_id,
            'site_id' => (int) $own->site_id,
            'impersonator_login_user_id' => $impersonator_login_user_id,
        ], null, [
            'signed_in_at' => $own->signed_in_at,
            'last_page_view_at' => $own->last_page_view_at,
        ]);

        Realtime::push_session_refresh('staff', (int) self::$_session->id);
    }

    /**
     * End the current impersonation: its user session is signed out
     * (SIGN_OUT_IMPERSONATION_ENDED) and the browser points back at the impersonator's own
     * sign-in, which takes over the impersonation's last page view. Nothing rotates - the
     * impersonator never stopped being signed in - and last_login is untouched. Returns false
     * (no-op) when the browser is not impersonating.
     *
     * @return bool True if an impersonation was ended, false if none was active
     */
    public static function stop_impersonation(): bool
    {
        self::_refuse_if_frozen('Session::stop_impersonation()');

        if (!self::is_impersonating()) {
            return false;
        }

        $impersonator_login_user_id = self::get_impersonator_login_user_id();

        // CLI mode: clear static overrides, then swap identity back
        if (self::__is_virtual()) {
            self::$_cli_impersonator_login_user_id = null;
            self::$_cli_impersonation_started_at = null;
            self::set_login_user_id($impersonator_login_user_id, false);

            return true;
        }

        $impersonation = self::__user_session(self::REALM_STAFF);
        $own = self::__suspended_own_user_session((int) self::$_session->id, $impersonation);

        if ($own !== null && $impersonation->last_page_view_at !== null
            && ($own->last_page_view_at === null || $own->last_page_view_at < $impersonation->last_page_view_at)) {
            DB::table('_user_sessions')->where('id', $own->id)->update(['last_page_view_at' => $impersonation->last_page_view_at]);
        }

        // Signing the impersonation out moves the pointer back to the suspended own sign-in.
        self::_sign_out_user_sessions([(int) $impersonation->id], self::SIGN_OUT_IMPERSONATION_ENDED);

        return true;
    }

    /**
     * Is the current staff sign-in an impersonation?
     * In CLI mode: reads the static override.
     *
     * @return bool
     */
    public static function is_impersonating(): bool
    {
        if (self::__is_virtual()) {
            return !empty(self::$_cli_impersonator_login_user_id);
        }

        return self::__user_session(self::REALM_STAFF)?->impersonator_login_user_id !== null;
    }

    /**
     * The real principal's login_user_id behind the impersonation, or null.
     * In CLI mode: reads the static override.
     *
     * @return int|null
     */
    public static function get_impersonator_login_user_id(): ?int
    {
        if (self::__is_virtual()) {
            return self::$_cli_impersonator_login_user_id;
        }

        $impersonator = self::__user_session(self::REALM_STAFF)?->impersonator_login_user_id;

        return $impersonator === null ? null : (int) $impersonator;
    }

    /**
     * The real principal as a Login_User_Model, or null when not impersonating.
     *
     * @return Login_User_Model|null
     */
    public static function get_impersonator_login_user(): ?Login_User_Model
    {
        $impersonator_login_user_id = self::get_impersonator_login_user_id();

        if (empty($impersonator_login_user_id)) {
            return null;
        }

        return Login_User_Model::find($impersonator_login_user_id);
    }

    /**
     * When the current impersonation started (ISO 8601 string), or null.
     * In CLI mode: reads the static override.
     *
     * @return string|null
     */
    public static function get_impersonation_started_at(): ?string
    {
        if (self::__is_virtual()) {
            return self::$_cli_impersonation_started_at;
        }

        $user_session = self::__user_session(self::REALM_STAFF);

        if ($user_session === null || $user_session->impersonator_login_user_id === null) {
            return null;
        }

        return Rsx_Time::to_iso($user_session->started_at);
    }

    /**
     * Set the impersonator login_user_id in CLI mode (test seeding; mirrors
     * cli_set_* on Portal_Session). Raw setter, no side effects.
     *
     * @param int|null $login_user_id
     * @return void
     */
    public static function cli_set_impersonator_login_user_id(?int $login_user_id): void
    {
        self::_refuse_if_frozen('Session::cli_set_impersonator_login_user_id()');

        self::$_cli_impersonator_login_user_id = $login_user_id;
    }

    /**
     * Set site_id override for current request (subdomain enforcement)
     * This overrides the database session site_id for THIS REQUEST ONLY
     * Does NOT modify the session record
     * Use case: User visits subdomain assigned to a tenant, enforce that tenant
     * @param int $site_id
     * @return void
     */
    public static function set_request_site_id_override(int $site_id): void
    {
        self::_refuse_if_frozen('Session::set_request_site_id_override()');

        self::$_request_site_id_override = $site_id;
        self::$_site = null; // Clear cached site
    }

    /**
     * Clear site_id override (return to normal session-based site_id)
     * @return void
     */
    public static function clear_request_site_id_override(): void
    {
        self::$_request_site_id_override = null;
        self::$_site = null; // Clear cached site
    }

    /**
     * Check if request has site_id override active
     * @return bool
     */
    public static function has_request_site_id_override(): bool
    {
        return self::$_request_site_id_override !== null;
    }

    // =========================================================================
    // SESSION MANAGEMENT METHODS
    // =========================================================================

    /**
     * Where a login identity is signed in: its STAFF user sessions, most recently active
     * first, formatted for display. One entry per browser x site (x impersonator), however
     * many times that account has signed in there.
     *
     * Answers every user session that is SIGNED IN, plus those signed out within
     * rsx.sessions.unused_session_days (when that is 0, every signed-out one) - so a screen
     * can show "signed out 2 days ago" beside the live ones. Browsers driven by the
     * rsx:debug harness (TYPE_PLAYWRIGHT) are never listed. Each API key of the account that
     * has been used is listed too, as one entry (`is_api`); the terminate functions do not
     * end it - revoking the key does. The set is bounded by the identity's browsers x sites
     * and keys; it is not truncated, because a user cannot end a session they were not shown.
     *
     * AN IMPERSONATION IS NOT ONE OF THE USER'S SESSIONS. It is somebody else acting as them
     * from somebody else's browser, and is left out unless $include_impersonations asks for
     * it - an administrator's view, where each entry's `is_impersonation` lets the screen
     * label it.
     *
     * Each entry: id (the user session - what the terminate functions take), session_id (the
     * browser), site_id, user_id, ip_address, user_agent, user_agent_parsed, device_summary,
     * location (the browser's - its address and agent are refreshed at every sign-in on it),
     * last_active, created_at, signed_in_at, first_seen (first_signed_in_at), signed_out_at,
     * sign_out_reason (a label, or null), is_signed_in, is_current, is_impersonation,
     * impersonator_login_user_id, impersonation_started_at, sign_in_count, type_id (the
     * browser's), is_api (an API key's use).
     *
     * @param int|null $login_user_id If null, uses current logged-in user
     * @param int|null $site_id Only the user sessions on this site - a tenant's view of an
     *                          identity that may belong to several. Null for every site.
     * @param bool $include_impersonations Also answer the impersonations of this identity.
     * @return array
     */
    public static function get_sessions_for_user(?int $login_user_id = null, ?int $site_id = null, bool $include_impersonations = false): array
    {
        if ($login_user_id === null) {
            $login_user_id = self::get_login_user_id();
        }

        if (empty($login_user_id)) {
            return [];
        }

        return self::_list_user_sessions(self::REALM_STAFF, (int) $login_user_id, $site_id, $include_impersonations);
    }

    /**
     * One account's user sessions in a realm, formatted for display: the listing behind both
     * get_sessions_for_user() functions (see Session's for the rules and the fields).
     *
     * FRAMEWORK INTERNAL - Session and Portal_Session.
     *
     * @param int $realm_id
     * @param int $identity_id login_users.id or portal_users.id
     * @param int|null $site_id Staff only: narrow to one site
     * @param bool $include_impersonations
     * @return array
     */
    public static function _list_user_sessions(int $realm_id, int $identity_id, ?int $site_id, bool $include_impersonations): array
    {
        $query = self::__listing_query($realm_id, $identity_id)
            ->when($site_id !== null, fn ($query) => $query->where('u.site_id', $site_id))
            ->when(!$include_impersonations, fn ($query) => $query->whereNull('u.' . self::__impersonator_column($realm_id)));

        return self::__present_user_sessions($query->get()->all(), $realm_id);
    }

    /**
     * The id of the user session the current request is signed in through - the staff one,
     * or on a portal request the portal one; on an API request the key owner's REALM_API one.
     * Null when that realm is signed out, in a virtual session (except a task run as its
     * dispatcher, which answers the user session it is bound to), and for an API identity
     * installed with no key. Creates nothing.
     *
     * What a piece of work started from this request binds itself to (a task run as its
     * dispatcher); a screen that marks "this session" compares against it.
     *
     * @return int|null
     */
    public static function get_user_session_id(): ?int
    {
        if (self::$_api_identity !== null) {
            return self::$_api_identity['user_session_id'];
        }

        if (self::__is_virtual()) {
            return self::$_cli_user_session_id;
        }

        $realm_id = \App\RSpade\Core\Portal\Rsx_Portal::is_portal_request() ? self::REALM_PORTAL : self::REALM_STAFF;
        $user_session = self::__user_session($realm_id);

        return $user_session === null ? null : (int) $user_session->id;
    }

    /**
     * The listing query over one identity's user sessions in a realm: signed in, or signed out
     * within rsx.sessions.unused_session_days; never a harness browser; most recently active
     * first. The staff listing includes the account's API keys in use (REALM_API) - where the
     * account is signed in includes a key acting as it. Joined to the browser as `s`.
     *
     * @param int $realm_id
     * @param int $identity_id
     * @return \Illuminate\Database\Query\Builder
     */
    private static function __listing_query(int $realm_id, int $identity_id)
    {
        $unused_days = (int) config('rsx.sessions.unused_session_days');
        $since = $unused_days > 0 ? now()->subDays($unused_days)->format('Y-m-d H:i:s.v') : null;

        return DB::table('_user_sessions as u')
            ->join('_sessions as s', 's.id', '=', 'u.session_id')
            ->whereIn('u.realm_id', $realm_id === self::REALM_STAFF ? [self::REALM_STAFF, self::REALM_API] : [$realm_id])
            ->where('u.' . self::__identity_column($realm_id), $identity_id)
            ->where('s.type_id', '<>', self::TYPE_PLAYWRIGHT)
            ->when($since !== null, fn ($query) => $query->where(function ($query) use ($since) {
                $query->whereNull('u.signed_out_at')->orWhere('u.last_active_at', '>=', $since);
            }))
            ->orderBy('u.last_active_at', 'desc')
            ->orderBy('u.id', 'desc')
            ->select(['u.*', 's.ip_address_id', 's.user_agent_id', 's.type_id']);
    }

    /**
     * Format user session rows (from __listing_query()) for display.
     *
     * @param array $rows
     * @param int $realm_id
     * @return array
     */
    private static function __present_user_sessions(array $rows, int $realm_id): array
    {
        $current = self::__is_virtual() || self::$_api_identity !== null ? null : self::__user_session($realm_id);
        $current_id = $current === null ? null : (int) $current->id;
        $impersonator_column = self::__impersonator_column($realm_id);
        $out = [];

        foreach ($rows as $row) {
            $user_agent = Audit_Session::user_agent_string($row->user_agent_id === null ? null : (int) $row->user_agent_id);
            $parsed_ua = User_Agent::parse($user_agent);
            $impersonator = $row->$impersonator_column;

            $out[] = [
                'id' => (int) $row->id,
                'session_id' => (int) $row->session_id,
                'site_id' => $realm_id === self::REALM_PORTAL
                    ? ($row->portal_site_id === null ? null : (int) $row->portal_site_id)
                    : ($row->site_id === null ? null : (int) $row->site_id),
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'ip_address' => Ip_Address::address($row->ip_address_id === null ? null : (int) $row->ip_address_id),
                'user_agent' => $user_agent,
                'user_agent_parsed' => $parsed_ua,
                'device_summary' => $parsed_ua['summary'],
                'location' => null,
                'last_active' => Rsx_Time::to_iso($row->last_active_at),
                'created_at' => Rsx_Time::to_iso($row->created_at),
                'signed_in_at' => Rsx_Time::to_iso($row->signed_in_at),
                'first_seen' => Rsx_Time::to_iso($row->first_signed_in_at),
                'signed_out_at' => $row->signed_out_at === null ? null : Rsx_Time::to_iso($row->signed_out_at),
                'sign_out_reason' => $row->sign_out_reason_id === null ? null : self::sign_out_reason_label((int) $row->sign_out_reason_id),
                'is_signed_in' => $row->signed_out_at === null,
                'is_current' => (int) $row->id === $current_id,
                'is_impersonation' => $impersonator !== null,
                'impersonator_id' => $impersonator === null ? null : (int) $impersonator,
                'impersonation_started_at' => $impersonator === null ? null : Rsx_Time::to_iso($row->started_at),
                'sign_in_count' => (int) $row->sign_in_count,
                'type_id' => (int) $row->type_id,
                'is_api' => (int) $row->realm_id === self::REALM_API,
            ];
        }

        return $out;
    }

    /**
     * Authorize the CURRENT actor to terminate sessions belonging to $target_login_user_id.
     * Returns void when permitted; THROWS AjaxUnauthorizedException (403 on the Ajax
     * channel) when not. Called first by every PUBLIC termination function that can name
     * a user other than the caller.
     *
     * THE RULE - two ways to be authorized, and only two:
     *   1. The target IS the actor. Self-management of one's own device list is always
     *      allowed and needs no role at all.
     *   2. The actor's role may ADMINISTER the target's role - Permission::can_admin_role()
     *      on the target's per-site users.role_id. That list is "my role and the roles
     *      BELOW it" (User_Model::$enums role_id.can_admin_roles, equal-or-lower
     *      administration), so a SUPERIOR refuses for free and by construction, and a PEER
     *      is permitted exactly because the role names itself - two Site Admins may sign
     *      each other out, as they may edit each other.
     * A target with no users row on the acting site is likewise refused - the actor's site
     * cannot see that identity, so there is nothing here to authorize against.
     *
     * REFUSAL THROWS; ABSENCE RETURNS FALSE. The two outcomes must never be conflated. A
     * caller that lacks authority gets an exception it cannot mistake for "nothing happened";
     * a caller with authority naming a session id that does not exist (or is already inactive)
     * gets a plain false. This is what surfaces a wiring bug the moment it is written rather
     * than in an audit months later - the shape the 2026-08-12 CR asked for, after
     * terminate_session()'s single overloaded false had hidden an admin-terminate feature
     * that never worked at all.
     *
     * SINGLE-TENANT ASSUMPTION - READ THIS BEFORE REUSING THE RULE.
     * A session belongs to a login_users row, which is CROSS-SITE; a role lives on a
     * users row, which is PER-SITE. This guard resolves the target's role in the ACTOR'S
     * OWN site and terminates sessions that are not scoped to any site at all. On a
     * deployment with ONE site those are the same population and the rule is exactly right.
     * On a genuinely multi-tenant deployment they are not: the target may hold a lower role
     * on the actor's site and a higher one elsewhere, and terminating the shared login
     * session reaches that other tenant too. The multi-tenant model (membership-scoped
     * termination, per-site session affinity) is BACKLOG B-92 and is deliberately NOT
     * approximated here. Do not "improve" this into a half-tenant-aware rule.
     *
     * VOCABULARY: framework only. can_admin_role() is a framework primitive on the app's
     * Permission class (Permission_Abstract::can_admin_role, resolved by simple name through
     * the autoloader). An #[Auth_Check]
     * name is APPLICATION vocabulary and must never appear in core; a check name that exists
     * in one app and not another would make this function undefined behavior downstream.
     *
     * PORTAL: these end STAFF user sessions only. A portal sign-in on the same browser is a
     * user session of its own and carries on; the portal twins (Portal_Session::terminate_*)
     * are a separate realm and are out of scope here.
     *
     * @param int $target_login_user_id login_users.id whose sessions are to be terminated
     * @throws AjaxUnauthorizedException when the actor may not terminate this user's sessions
     */
    private static function __authorize_session_termination(int $target_login_user_id): void
    {
        self::init();

        $actor_login_user_id = self::get_login_user_id();

        if (empty($actor_login_user_id)) {
            throw new AjaxUnauthorizedException('Session termination requires a logged-in actor.');
        }

        // Rule 1 - self-management.
        if ((int) $actor_login_user_id === $target_login_user_id) {
            return;
        }

        // Rule 2 - the target's role, as it exists on the acting site.
        $target_user = User_Model::where('login_user_id', $target_login_user_id)
            ->where('site_id', self::get_site_id())
            ->first();

        if (empty($target_user)) {
            throw new AjaxUnauthorizedException(
                'Not authorized to terminate sessions for this user: no such user on the acting site.'
            );
        }

        if (!Permission::can_admin_role((int) $target_user->role_id)) {
            throw new AjaxUnauthorizedException('Not authorized to terminate sessions for this user.');
        }
    }

    /**
     * Fire the audit event for each staff user session a termination signed out. (The
     * refresh push to each browser is _sign_out_user_sessions()'s.) Called by every
     * termination path, which is why it exists as a function rather than four copies.
     *
     * @param int|null $actor_login_user_id Who performed it; null for the unchecked internal path
     * @param array $ended What _sign_out_user_sessions() answered
     * @param string $scope 'self' | 'admin' | 'internal'
     */
    private static function __announce_sessions_terminated(?int $actor_login_user_id, array $ended, string $scope): void
    {
        foreach ($ended as $user_session) {
            if ((int) $user_session->realm_id !== self::REALM_STAFF) {
                continue;
            }

            Rsx::trigger_action('session.terminated', [
                'actor_login_user_id'  => $actor_login_user_id,
                'target_login_user_id' => (int) $user_session->login_user_id,
                'user_session_id'      => (int) $user_session->id,
                'session_id'           => (int) $user_session->session_id,
                'scope'                => $scope,
            ]);
        }
    }

    /**
     * The SIGN_OUT_* reason a termination is recorded under.
     *
     * @param string $scope 'self' | 'admin' | 'internal'
     * @return int
     */
    private static function __termination_reason(string $scope): int
    {
        return match ($scope) {
            'self' => self::SIGN_OUT_ENDED_BY_USER,
            'admin' => self::SIGN_OUT_ENDED_BY_ADMIN,
            default => self::SIGN_OUT_ENDED_BY_SYSTEM,
        };
    }

    /**
     * End one of the CURRENT staff account's own signed-in user sessions, on another browser.
     * The current one is refused (that is logout()'s job), and so is anything else on this
     * browser.
     *
     * Fail-closed: the owning-account predicate is part of the lookup, so a user session
     * belonging to anyone else matches nothing and returns false. Cross-user (admin)
     * termination lives in terminate_session_for_user().
     *
     * @param int $user_session_id A user session id (get_sessions_for_user()'s `id`)
     * @return bool True if one of the caller's own sign-ins was ended, false otherwise
     */
    public static function terminate_session(int $user_session_id): bool
    {
        $login_user_id = self::get_login_user_id();

        if (empty($login_user_id)) {
            return false;
        }

        $ids = self::__terminable_ids((int) $login_user_id, $user_session_id, true);

        if (empty($ids)) {
            return false;
        }

        $ended = self::_sign_out_user_sessions($ids, self::SIGN_OUT_ENDED_BY_USER);
        self::__announce_sessions_terminated((int) $login_user_id, $ended, 'self');

        return !empty($ended);
    }

    /**
     * End ONE signed-in user session belonging to ANOTHER (or the same) login identity - the
     * admin primitive. Authorization first: see __authorize_session_termination() for the
     * rule, the single-tenant assumption, and the throw-vs-false contract.
     *
     * A refusal THROWS. A false return means one thing only: no such signed-in user session
     * of that identity (wrong id, wrong owner, already signed out) - or it is a sign-in on the
     * actor's own browser, which an admin screen must never end.
     *
     * @param int $login_user_id login_users.id that owns the user session
     * @param int $user_session_id The user session to end
     * @return bool True if it was ended
     * @throws AjaxUnauthorizedException when the actor may not terminate this user's sessions
     */
    public static function terminate_session_for_user(int $login_user_id, int $user_session_id): bool
    {
        self::__authorize_session_termination($login_user_id);

        $ids = self::__terminable_ids($login_user_id, $user_session_id, true);

        if (empty($ids)) {
            return false;
        }

        $actor_login_user_id = (int) self::get_login_user_id();
        $scope = $actor_login_user_id === $login_user_id ? 'self' : 'admin';

        $ended = self::_sign_out_user_sessions($ids, self::__termination_reason($scope));
        self::__announce_sessions_terminated($actor_login_user_id, $ended, $scope);

        return !empty($ended);
    }

    /**
     * End every OTHER signed-in staff user session of the current account - "sign out my
     * other devices" - keeping the one this browser is on. Impersonations of the account
     * end too. Every other browser's two-factor trust for the account is forgotten with them.
     *
     * @return int Number of user sessions ended
     */
    public static function terminate_all_other_sessions(): int
    {
        $login_user_id = self::get_login_user_id();

        if (empty($login_user_id)) {
            return 0;
        }

        // "Sign out everywhere else" also ends every other browser's two-factor trust.
        \App\RSpade\Core\TwoFactor\Rsx_Two_Factor::forget_trusted_devices((int) $login_user_id, true);

        $ended = self::_sign_out_user_sessions(
            self::__terminable_ids((int) $login_user_id, null, true),
            self::SIGN_OUT_ENDED_BY_USER
        );

        self::__announce_sessions_terminated((int) $login_user_id, $ended, 'self');

        return count($ended);
    }

    /**
     * End EVERY signed-in staff user session of a login identity (optionally sparing one) -
     * the bulk admin primitive, e.g. "sign this user out everywhere".
     *
     * Authorization first: see __authorize_session_termination(). A refusal THROWS; a return
     * of 0 means only that the identity had nothing signed in to end.
     *
     * Framework code with NO acting user (CLI maintenance, an app-internal flow that carries
     * its own authorization) calls _deactivate_sessions_for_user() instead.
     *
     * @param int $login_user_id
     * @param int|null $except_user_session_id A user session to spare
     * @return int Number of user sessions ended
     * @throws AjaxUnauthorizedException when the actor may not terminate this user's sessions
     */
    public static function terminate_all_sessions_for_user(int $login_user_id, ?int $except_user_session_id = null): int
    {
        self::__authorize_session_termination($login_user_id);

        $actor_login_user_id = (int) self::get_login_user_id();

        return self::_deactivate_sessions_for_user(
            $login_user_id,
            $except_user_session_id,
            $actor_login_user_id === $login_user_id ? 'self' : 'admin',
            $actor_login_user_id
        );
    }

    /**
     * End one signed-in user session of a login identity, or all of them - the DEVELOPER
     * primitive, for the framework's /_sys panel and any other developer-only operator
     * surface.
     *
     * AUTHORIZATION IS Session::is_developer() AND NOTHING ELSE. The admin primitives above
     * resolve the target's role on the ACTING site (the single-tenant rule), which cannot
     * answer for an operator who administers the whole install across every site. A caller
     * that is not a developer is REFUSED by a throw, never answered with 0.
     *
     * REFUSAL THROWS; ABSENCE RETURNS 0:
     *   - not a developer                                       -> AjaxUnauthorizedException
     *   - $user_session_id not a signed-in one of $login_user_id -> 0
     *   - $user_session_id on the caller's OWN browser            -> 0 (signing yourself out
     *     is logout()'s job, never an operator screen's)
     * With $user_session_id null every signed-in user session of the identity ends EXCEPT
     * those on the caller's own browser.
     *
     * @param int $login_user_id login_users.id whose sessions end
     * @param int|null $user_session_id One user session to end; null for every one
     * @return int Number of user sessions ended
     * @throws AjaxUnauthorizedException when the caller is not a developer
     */
    public static function developer_terminate_sessions(int $login_user_id, ?int $user_session_id = null): int
    {
        if (!self::is_developer()) {
            throw new AjaxUnauthorizedException('Only a developer may terminate sessions from here.');
        }

        $actor_login_user_id = (int) self::get_login_user_id();
        $scope = $actor_login_user_id === $login_user_id ? 'self' : 'admin';

        $ended = self::_sign_out_user_sessions(
            self::__terminable_ids($login_user_id, $user_session_id, true),
            self::__termination_reason($scope)
        );

        self::__announce_sessions_terminated($actor_login_user_id, $ended, $scope);

        return count($ended);
    }

    /**
     * FRAMEWORK-INTERNAL, NO AUTHORIZATION - sign a login identity out of every browser
     * (optionally sparing one user session, or narrowed to one).
     *
     * For callers that have NO acting user to authorize against: CLI maintenance, and
     * app-internal flows that have already applied their own authorization and are simply
     * executing the consequence. Fires the same refresh push and 'session.terminated' event
     * per user session as the guarded paths; the default scope 'internal' with a null actor
     * reads as "the framework did this, no operator was involved".
     *
     * @param int $login_user_id
     * @param int|null $except_user_session_id A user session to spare
     * @param string $scope Event scope: 'internal' (default), or the guarded wrapper's 'self'/'admin'
     * @param int|null $actor_login_user_id Event actor; null for a genuinely unattributed call
     * @param int|null $only_user_session_id Narrow to this one user session
     * @return int Number of user sessions ended
     */
    public static function _deactivate_sessions_for_user(
        int $login_user_id,
        ?int $except_user_session_id = null,
        string $scope = 'internal',
        ?int $actor_login_user_id = null,
        ?int $only_user_session_id = null
    ): int {
        $ids = self::__terminable_ids($login_user_id, $only_user_session_id, false);

        if ($except_user_session_id !== null) {
            $ids = array_values(array_diff($ids, [$except_user_session_id]));
        }

        $ended = self::_sign_out_user_sessions($ids, self::__termination_reason($scope));
        self::__announce_sessions_terminated($actor_login_user_id, $ended, $scope);

        return count($ended);
    }

    /**
     * FRAMEWORK INTERNAL, NO AUTHORIZATION - the session consequence of a password change,
     * called from the identity model's own `updated` hook (Login_User_Model_Abstract and
     * Portal_User_Model_Abstract booted()), so every path that saves a new password through
     * the model gets it: a change-password screen, a reset link, a command, an admin screen.
     *
     * Every signed-in user session of the identity in $realm_id ends with
     * SIGN_OUT_PASSWORD_CHANGED - impersonations of it included, since they act as that
     * identity - EXCEPT those on the browser making the request: the browser that changed the
     * password stays signed in. A command, a task or an API call has no browser
     * (_current_browser_session_id() is null), so there every web sign-in of the identity
     * ends. An API key's own user session (REALM_API) is a different realm and is never
     * touched: the key is its credential, not the password.
     *
     * Staff sign-ins fire 'session.terminated' with scope 'internal' and a null actor, like
     * every framework-initiated termination; each browser gets the refresh push.
     *
     * @param int $realm_id REALM_STAFF (login_users.id) or REALM_PORTAL (portal_users.id)
     * @param int $identity_id
     * @return int Number of user sessions ended
     */
    public static function _end_sessions_for_password_change(int $realm_id, int $identity_id): int
    {
        if ($realm_id !== self::REALM_STAFF && $realm_id !== self::REALM_PORTAL) {
            shouldnt_happen("_end_sessions_for_password_change(): realm {$realm_id} has no password");
        }

        $ended = self::_sign_out_user_sessions(
            self::_signed_in_user_session_ids($realm_id, $identity_id, null, true),
            self::SIGN_OUT_PASSWORD_CHANGED
        );

        self::__announce_sessions_terminated(null, $ended, 'internal');

        return count($ended);
    }

    /**
     * The signed-in staff user sessions a termination of $login_user_id may end.
     *
     * @param int $login_user_id
     * @param int|null $only A single user session id
     * @param bool $spare_this_browser
     * @return array<int, int>
     */
    private static function __terminable_ids(int $login_user_id, ?int $only, bool $spare_this_browser): array
    {
        return self::_signed_in_user_session_ids(self::REALM_STAFF, $login_user_id, $only, $spare_this_browser);
    }

    /**
     * The ids of an account's SIGNED-IN user sessions in a realm - impersonations of it
     * included - narrowed to $only when given. With $spare_this_browser, nothing on the
     * browser making the request.
     *
     * FRAMEWORK INTERNAL - the termination functions of both facades.
     *
     * @param int $realm_id
     * @param int $identity_id login_users.id or portal_users.id
     * @param int|null $only A single user session id
     * @param bool $spare_this_browser
     * @return array<int, int>
     */
    public static function _signed_in_user_session_ids(int $realm_id, int $identity_id, ?int $only, bool $spare_this_browser): array
    {
        $current_session_id = self::_current_browser_session_id();

        return DB::table('_user_sessions')
            ->where('realm_id', $realm_id)
            ->where(self::__identity_column($realm_id), $identity_id)
            ->whereNull('signed_out_at')
            ->when($only !== null, fn ($query) => $query->where('id', $only))
            ->when($spare_this_browser && $current_session_id !== null, fn ($query) => $query->where('session_id', '<>', $current_session_id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The browser and the staff sign-in this request is on, formatted like a
     * get_sessions_for_user() entry, or null when the browser has no session or no staff
     * sign-in.
     *
     * @return array|null
     */
    public static function get_current_session_info(): ?array
    {
        $current = self::__user_session(self::REALM_STAFF);

        if ($current === null) {
            return null;
        }

        $row = (object) array_merge((array) $current, [
            'ip_address_id' => self::$_session->ip_address_id,
            'user_agent_id' => self::$_session->user_agent_id,
            'type_id' => self::$_session->type_id,
        ]);

        return self::__present_user_sessions([$row], self::REALM_STAFF)[0];
    }

    // =========================================================================
    // PORTAL SEAMS - FRAMEWORK INTERNAL
    //
    // NOT application API. These exist for exactly one caller,
    // App\RSpade\Core\Portal\Portal_Session, the facade over the browser's PORTAL
    // pointer, plus Session_Link for "View as Client" on a portal of its own host. There is
    // no portal row and no portal cookie: the browser has ONE session, and its portal
    // sign-in is a user session (REALM_PORTAL) the row points at.
    //
    // Application code wanting portal session state calls Portal_Session's public
    // accessors (get_portal_user_id(), get_sessions_for_user(), ...).
    // =========================================================================

    /**
     * The current session row if one already exists, WITHOUT creating it.
     *
     * @return static|null
     */
    private static function __row_if_any(): ?self
    {
        self::init();

        return self::$_session;
    }

    /**
     * A query builder over every browser row SIGNED INTO THE PORTAL (its portal pointer is
     * set). Portal_Session::find_by_token() narrows a token lookup with it.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function _portal_query()
    {
        return static::query()->whereNotNull('portal_user_session_id');
    }

    /**
     * The portal account signed in on this browser, or null. Creates nothing.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return int|null
     */
    public static function _get_portal_user_id(): ?int
    {
        $user_session = self::__user_session(self::REALM_PORTAL);

        return $user_session === null ? null : (int) $user_session->portal_user_id;
    }

    /**
     * The tenant of the portal sign-in on this browser, or null. Distinct from the staff
     * site: one browser can legitimately be looking at a different site in each experience.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return int|null
     */
    public static function _get_portal_site_id(): ?int
    {
        $site_id = self::__user_session(self::REALM_PORTAL)?->portal_site_id;

        return $site_id === null ? null : (int) $site_id;
    }

    /**
     * The staff users.id viewing the portal as a client on this browser, or null. A
     * different id space from impersonator_login_user_id (login_users.id).
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return int|null
     */
    public static function _get_portal_impersonator_user_id(): ?int
    {
        $impersonator = self::__user_session(self::REALM_PORTAL)?->impersonator_user_id;

        return $impersonator === null ? null : (int) $impersonator;
    }

    /**
     * When the portal's current sign-in on this browser started being the current one - for
     * a View as Client, when it began. Null when the portal is signed out.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return string|null ISO 8601
     */
    public static function _get_portal_started_at(): ?string
    {
        $user_session = self::__user_session(self::REALM_PORTAL);

        return $user_session === null ? null : Rsx_Time::to_iso($user_session->started_at);
    }

    /**
     * Test seam: the browser row a test process is ACTING AS. A virtual session has no
     * browser, so a test of something that belongs to one (session values, two-factor
     * trust, a challenge parked between two calls) names a fixture row here
     * (Rsx_Test_Abstract::__as_browser()): get_session_id(), has_session(), the session
     * values and get_session() then answer that row. Identity stays the virtual session's -
     * the row's pointers are not read. Cleared by _testing_reset().
     */
    public static ?int $_browser_session_id_for_tests = null;

    /**
     * The id of THIS BROWSER's session row, or null when it has none (a guest who has
     * minted nothing, a command, a headless API request). Creates nothing.
     *
     * FRAMEWORK INTERNAL - two-factor browser trust keys on it
     * (Rsx_Two_Factor_Abstract::trust_this_device()).
     *
     * @return int|null
     */
    public static function _current_browser_session_id(): ?int
    {
        if (self::$_browser_session_id_for_tests !== null) {
            return self::$_browser_session_id_for_tests;
        }

        if (self::__is_virtual() || self::$_api_identity !== null) {
            return null;
        }

        $row = self::__row_if_any();

        return $row === null ? null : (int) $row->id;
    }

    /**
     * Sign a portal account in on this browser, activating the row if needed (a portal login
     * is exactly the moment a session becomes necessary): the portal user session for
     * (this browser, the account, the site) is found or created and made current, whatever
     * the portal pointer named before is signed out (SIGN_OUT_REPLACED) - unless it is the
     * browser's own portal sign-in behind a View as Client, which is replaced as well - and
     * the tokens rotate. Re-stating the account the browser is already signed into the portal
     * as is not a sign-in and changes nothing.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @param int $portal_user_id
     * @param int $portal_site_id
     * @return bool Whether this was a sign-in (false: a re-statement)
     */
    public static function _set_portal_identity(int $portal_user_id, int $portal_site_id): bool
    {
        self::__activate();

        $current = self::__user_session(self::REALM_PORTAL);

        if ($current !== null && (int) $current->portal_user_id === $portal_user_id
            && $current->impersonator_user_id === null && (int) $current->portal_site_id === $portal_site_id) {
            return false;
        }

        self::__sign_in(self::REALM_PORTAL, [
            'portal_user_id' => $portal_user_id,
            'portal_site_id' => $portal_site_id,
            'impersonator_user_id' => null,
        ], self::SIGN_OUT_REPLACED);

        self::__rotate_tokens();

        return true;
    }

    /**
     * Start a staff "View as Client" on ONE browser row: the portal user session (that
     * browser, the portal account, its site, impersonator = the staff users.id) is found or
     * created and made current, its started_at now. A View as Client already running there is
     * replaced; the browser's own portal sign-in, if it has one, stays signed in behind it.
     * Nobody re-authenticated, so nothing rotates.
     *
     * Row-addressed rather than "this browser's session", because its two callers name the
     * row explicitly: Portal_Session::begin_impersonation_from_staff() on the same host (the
     * caller's own row), and the staff leg of the linked-session handshake (Session_Link),
     * which has just proved the browser's staff cookie names that row.
     *
     * FRAMEWORK INTERNAL - for Portal_Session and Session_Link only.
     *
     * @param int $session_id
     * @param int $portal_user_id
     * @param int $portal_site_id
     * @param int $impersonator_user_id Staff users.id
     * @return bool False when the row is gone or no longer active
     */
    public static function _apply_portal_impersonation(int $session_id, int $portal_user_id, int $portal_site_id, int $impersonator_user_id): bool
    {
        $row = static::where('id', $session_id)->where('active', true)->first();

        if (!$row) {
            return false;
        }

        $previous = $row->portal_user_session_id === null
            ? null
            : DB::table('_user_sessions')->where('id', $row->portal_user_session_id)->first();

        $user_session_id = self::__activate_user_session($session_id, self::REALM_PORTAL, [
            'portal_user_id' => $portal_user_id,
            'portal_site_id' => $portal_site_id,
            'impersonator_user_id' => $impersonator_user_id,
        ]);

        if ($previous !== null && (int) $previous->id !== $user_session_id && $previous->impersonator_user_id !== null) {
            self::_sign_out_user_sessions([(int) $previous->id], self::SIGN_OUT_REPLACED, false);
        }

        self::__point($session_id, self::REALM_PORTAL, $user_session_id);

        return true;
    }

    /**
     * End a "View as Client" on this browser: its portal user session is signed out
     * (SIGN_OUT_IMPERSONATION_ENDED) and the portal pointer falls back to the browser's own
     * portal sign-in, if it has one. The staff sign-in is untouched and nothing rotates - the
     * same row may be serving the staff host through another cookie. No-op when the portal
     * pointer is not a View as Client.
     *
     * FRAMEWORK INTERNAL - for Portal_Session only.
     *
     * @return bool Whether one was ended
     */
    public static function _end_portal_impersonation(): bool
    {
        $current = self::__user_session(self::REALM_PORTAL);

        if ($current === null || $current->impersonator_user_id === null) {
            return false;
        }

        self::_sign_out_user_sessions([(int) $current->id], self::SIGN_OUT_IMPERSONATION_ENDED);

        return true;
    }

    // =========================================================================
    // USER SESSIONS (_user_sessions) - "where you're signed in"
    //
    // The engine both realms run on. Everything here is addressed by browser row id or user
    // session id rather than by "this request", so a sign-out can reach another browser and
    // the tests can drive it from the command line.
    // =========================================================================

    /**
     * The realm id for a realm word.
     *
     * @param string $realm 'staff' or 'portal'
     * @return int
     */
    private static function __realm_id(string $realm): int
    {
        return match ($realm) {
            'staff' => self::REALM_STAFF,
            'portal' => self::REALM_PORTAL,
        };
    }

    /**
     * The realm word Realtime::push_session_refresh() takes for a realm id.
     *
     * @param int $realm_id
     * @return string
     */
    private static function __realm_word(int $realm_id): string
    {
        return $realm_id === self::REALM_PORTAL ? 'portal' : 'staff';
    }

    /**
     * The column holding a realm's account id.
     *
     * @param int $realm_id
     * @return string
     */
    private static function __identity_column(int $realm_id): string
    {
        return $realm_id === self::REALM_PORTAL ? 'portal_user_id' : 'login_user_id';
    }

    /**
     * The column holding a realm's impersonator.
     *
     * @param int $realm_id
     * @return string
     */
    private static function __impersonator_column(int $realm_id): string
    {
        return $realm_id === self::REALM_PORTAL ? 'impersonator_user_id' : 'impersonator_login_user_id';
    }

    /**
     * The user session a realm's pointer names on the loaded browser row, or null (signed
     * out, a guest, no row). Both pointers are read in one query, once per row.
     *
     * @param int $realm_id REALM_STAFF or REALM_PORTAL
     * @return object|null A _user_sessions row
     */
    private static function __user_session(int $realm_id): ?object
    {
        if (self::$_api_identity !== null || self::__is_virtual()) {
            return null;
        }

        $row = self::__row_if_any();

        if ($row === null) {
            return null;
        }

        if (self::$_user_sessions === null || self::$_user_sessions['session_id'] !== (int) $row->id) {
            $ids = array_values(array_filter([$row->staff_user_session_id, $row->portal_user_session_id]));
            $loaded = empty($ids) ? [] : DB::table('_user_sessions')->whereIn('id', $ids)->get()->keyBy('id')->all();

            self::$_user_sessions = [
                'session_id' => (int) $row->id,
                self::REALM_STAFF => $row->staff_user_session_id === null ? null : ($loaded[$row->staff_user_session_id] ?? null),
                self::REALM_PORTAL => $row->portal_user_session_id === null ? null : ($loaded[$row->portal_user_session_id] ?? null),
            ];
        }

        return self::$_user_sessions[$realm_id];
    }

    /**
     * Drop what this request has read through the pointers, so the next getter re-reads.
     *
     * @return void
     */
    private static function __forget_user_sessions(): void
    {
        self::$_user_sessions = null;
        self::$_login_user = null;
        self::$_user = null;
        self::$_site = null;
    }

    /**
     * The tuple hash that makes a user session unique: realm, browser, the realm's account
     * and site, and the impersonator. The migration that created _user_sessions computes the
     * same string in SQL.
     *
     * @param int $session_id
     * @param int $realm_id
     * @param array $identity login_user_id/site_id/impersonator_login_user_id (staff) or
     *                        portal_user_id/portal_site_id/impersonator_user_id (portal)
     * @return string
     */
    public static function _user_session_tuple_hash(int $session_id, int $realm_id, array $identity): string
    {
        $parts = [
            $realm_id,
            $session_id,
            $identity['login_user_id'] ?? null,
            $identity['site_id'] ?? null,
            $identity['portal_user_id'] ?? null,
            $identity['portal_site_id'] ?? null,
            $identity['impersonator_login_user_id'] ?? null,
            $identity['impersonator_user_id'] ?? null,
        ];

        return hash('sha256', implode('|', array_map(fn ($part) => $part === null ? '' : (string) (int) $part, $parts)));
    }

    /**
     * The membership (users.id) of a login identity on a site, or null (site 0, or none).
     *
     * @param int $login_user_id
     * @param int $site_id
     * @return int|null
     */
    private static function __membership_id(int $login_user_id, int $site_id): ?int
    {
        if ($site_id === 0) {
            return null;
        }

        $id = User_Model::without_site_scope(
            fn () => User_Model::where('login_user_id', $login_user_id)->where('site_id', $site_id)->value('id')
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Find or create the user session for ($session_id, $realm_id, $identity) and make it
     * SIGNED IN: signed_in_at and last_page_view_at from $carry or now, started_at and
     * last_active_at now, any sign-out cleared, sign_in_count + 1. One statement on the unique
     * tuple, so two requests signing in at once land on the same row. Moves no pointer. The
     * browser's first_signed_in_at is stamped the first time any of its user sessions is.
     *
     * @param int $session_id
     * @param int $realm_id
     * @param array $identity
     * @param array|null $carry signed_in_at / last_page_view_at to keep (a site switch, an
     *                          impersonation running on the impersonator's clocks)
     * @return int The user session id
     */
    private static function __activate_user_session(int $session_id, int $realm_id, array $identity, ?array $carry = null): int
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $as_stamp = fn ($value) => $value === null ? $now : Rsx_Time::to_database($value);

        $login_user_id = $identity['login_user_id'] ?? null;
        $site_id = $realm_id === self::REALM_PORTAL ? null : (int) ($identity['site_id'] ?? 0);

        DB::statement(
            'INSERT INTO _user_sessions
                (session_id, realm_id, tuple_hash, login_user_id, user_id, site_id, portal_user_id, portal_site_id,
                 impersonator_login_user_id, impersonator_user_id, first_signed_in_at, signed_in_at, started_at,
                 last_page_view_at, last_active_at, sign_in_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                user_id = VALUES(user_id),
                signed_in_at = VALUES(signed_in_at),
                started_at = VALUES(started_at),
                last_page_view_at = VALUES(last_page_view_at),
                last_active_at = VALUES(last_active_at),
                signed_out_at = NULL,
                sign_out_reason_id = NULL,
                sign_in_count = sign_in_count + 1',
            [
                $session_id,
                $realm_id,
                self::_user_session_tuple_hash($session_id, $realm_id, $identity),
                $login_user_id,
                $login_user_id === null ? null : self::__membership_id((int) $login_user_id, (int) $site_id),
                $site_id,
                $identity['portal_user_id'] ?? null,
                $identity['portal_site_id'] ?? null,
                $identity['impersonator_login_user_id'] ?? null,
                $identity['impersonator_user_id'] ?? null,
                $now,
                $as_stamp($carry['signed_in_at'] ?? null),
                $now,
                $as_stamp($carry['last_page_view_at'] ?? null),
                $now,
            ]
        );

        $user_session_id = (int) DB::getPdo()->lastInsertId();

        // The browser's FIRST sign-in, in any realm: from here on it is no longer a guest the
        // cleanup may delete (Session_Cleanup_Service::cleanup_sessions()).
        $stamped = DB::table('_sessions')->where('id', $session_id)->whereNull('first_signed_in_at')->update(['first_signed_in_at' => $now]);

        if ($stamped > 0 && !empty(self::$_session) && (int) self::$_session->id === $session_id) {
            self::$_session->setAttribute('first_signed_in_at', $now);
            self::$_session->syncOriginalAttribute('first_signed_in_at');
        }

        return $user_session_id;
    }

    /**
     * Point a browser's realm pointer at a user session (or null). When the browser is the
     * one this request loaded, the loaded row follows and every identity read is redone.
     *
     * @param int $session_id
     * @param int $realm_id
     * @param int|null $user_session_id
     * @return void
     */
    private static function __point(int $session_id, int $realm_id, ?int $user_session_id): void
    {
        $column = self::POINTER_COLUMNS[$realm_id];

        static::where('id', $session_id)->raw_bulk()->update([$column => $user_session_id]);

        if (!empty(self::$_session) && (int) self::$_session->id === $session_id) {
            self::$_session->setAttribute($column, $user_session_id);
            self::$_session->syncOriginalAttribute($column);
            self::__forget_user_sessions();
        }
    }

    /**
     * Sign THIS browser into a realm as $identity: activate that user session (__activate_
     * user_session()), point the browser at it, and dispose of what the pointer named before.
     *
     * $previous_reason says what happens to the previous one: a SIGN_OUT_* signs it out
     * (with, for a staff impersonation replaced by anything but the same impersonation on
     * another site, the suspended sign-in behind it - the browser now belongs to somebody
     * else); null SUSPENDS it (it stays signed in, not current - the impersonator's own
     * sign-in behind an impersonation). Rotation is the caller's.
     *
     * @param int $realm_id
     * @param array $identity
     * @param int|null $previous_reason
     * @param array|null $carry
     * @return void
     */
    private static function __sign_in(int $realm_id, array $identity, ?int $previous_reason, ?array $carry = null): void
    {
        $session_id = (int) self::$_session->id;
        $previous = self::__user_session($realm_id);

        $user_session_id = self::__activate_user_session($session_id, $realm_id, $identity, $carry);

        self::__point($session_id, $realm_id, $user_session_id);

        if ($previous === null || (int) $previous->id === $user_session_id || $previous_reason === null) {
            return;
        }

        $ended = [(int) $previous->id];
        $impersonator_column = self::__impersonator_column($realm_id);
        $same_impersonator = $previous->$impersonator_column !== null
            && (int) $previous->$impersonator_column === (int) ($identity[$impersonator_column] ?? 0);

        // Another account taking the browser ends the impersonator's suspended sign-in with
        // the impersonation; an impersonation moving to another site keeps it.
        if ($realm_id === self::REALM_STAFF && $previous->$impersonator_column !== null && !$same_impersonator) {
            $own = self::__suspended_own_user_session($session_id, $previous);

            if ($own !== null && (int) $own->id !== $user_session_id) {
                $ended[] = (int) $own->id;
            }
        }

        self::_sign_out_user_sessions($ended, $previous_reason, false);
    }

    /**
     * The signed-in, non-impersonation user session behind an impersonation on a browser: for
     * a staff impersonation the impersonator's own sign-in, for a View as Client the
     * browser's own portal sign-in. Null when there is none.
     *
     * @param int $session_id
     * @param object $impersonation A _user_sessions row
     * @return object|null
     */
    private static function __suspended_own_user_session(int $session_id, object $impersonation): ?object
    {
        $realm_id = (int) $impersonation->realm_id;

        $query = DB::table('_user_sessions')
            ->where('session_id', $session_id)
            ->where('realm_id', $realm_id)
            ->whereNull('signed_out_at')
            ->whereNull(self::__impersonator_column($realm_id))
            ->where('id', '<>', $impersonation->id);

        if ($realm_id === self::REALM_STAFF) {
            $query->where('login_user_id', $impersonation->impersonator_login_user_id);
        }

        return $query->orderBy('last_active_at', 'desc')->first();
    }

    /**
     * A query over one account's SIGNED-IN user sessions in a realm, joined to the browser
     * as `s`.
     *
     * @param int $realm_id
     * @param int $identity_id
     * @return \Illuminate\Database\Query\Builder
     */
    private static function __signed_in_query(int $realm_id, int $identity_id)
    {
        return DB::table('_user_sessions as u')
            ->join('_sessions as s', 's.id', '=', 'u.session_id')
            ->where('u.realm_id', $realm_id)
            ->where('u.' . self::__identity_column($realm_id), $identity_id)
            ->whereNull('u.signed_out_at');
    }

    /**
     * SIGN OUT user sessions, wherever their browsers are. THE one sign-out every path runs
     * through - logout, the lifetimes, terminations, the cap, a site switch, an impersonation
     * ending - so the rules hold everywhere:
     *
     *   - each user session still signed in is stamped signed_out_at + $reason; a staff
     *     sign-in that ends takes the impersonations riding it on the same browser with it
     *     (an impersonation is the impersonator's authority);
     *   - every browser pointer naming one moves: to the browser's suspended own sign-in when
     *     an impersonation ended and that sign-in is still on, otherwise to null;
     *   - a browser whose realm is left signed out - or taken over by another account
     *     (SIGN_OUT_REPLACED) - loses that REALM's session values (put_value()); the other
     *     realm's and the guest values stay;
     *   - each browser touched gets a realtime refresh push for the realm, so its live tabs
     *     reload (skipped with $push false: the cap, and a pointer the caller is replacing).
     *
     * TOKENS ARE NOT ROTATED HERE. On the browser making the request the caller rotates
     * (_sign_out_current()); another browser keeps its token, which now names a row that grants
     * nothing it did not grant a guest - its next sign-in rotates it.
     *
     * FRAMEWORK INTERNAL - Session, Portal_Session, Session_Cleanup_Service.
     *
     * @param array $user_session_ids
     * @param int $reason SIGN_OUT_*
     * @param bool $push
     * @return array<int, object> The _user_sessions rows this call signed out
     */
    public static function _sign_out_user_sessions(array $user_session_ids, int $reason, bool $push = true): array
    {
        $ids = array_values(array_unique(array_map('intval', $user_session_ids)));

        if (empty($ids)) {
            return [];
        }

        $ended = DB::table('_user_sessions')->whereIn('id', $ids)->whereNull('signed_out_at')->get()->keyBy('id')->all();

        foreach ($ended as $user_session) {
            if ((int) $user_session->realm_id !== self::REALM_STAFF || $user_session->impersonator_login_user_id !== null) {
                continue;
            }

            $riding = DB::table('_user_sessions')
                ->where('session_id', $user_session->session_id)
                ->where('realm_id', self::REALM_STAFF)
                ->where('impersonator_login_user_id', $user_session->login_user_id)
                ->whereNull('signed_out_at')
                ->get();

            foreach ($riding as $impersonation) {
                $ended[$impersonation->id] = $impersonation;
            }
        }

        if (empty($ended)) {
            return [];
        }

        $ended_ids = array_map('intval', array_keys($ended));

        DB::table('_user_sessions')
            ->whereIn('id', $ended_ids)
            ->whereNull('signed_out_at')
            ->update([
                'signed_out_at' => now()->format('Y-m-d H:i:s.v'),
                'sign_out_reason_id' => $reason,
            ]);

        $browsers = [];

        foreach (self::POINTER_COLUMNS as $realm_id => $column) {
            $pointing = DB::table('_sessions')->whereIn($column, $ended_ids)->pluck($column, 'id')->all();

            foreach ($pointing as $session_id => $user_session_id) {
                $return_to = null;
                $user_session = $ended[$user_session_id];

                if ($user_session->{self::__impersonator_column($realm_id)} !== null) {
                    $own = self::__suspended_own_user_session((int) $session_id, $user_session);
                    $return_to = $own === null || in_array((int) $own->id, $ended_ids, true) ? null : (int) $own->id;
                }

                self::__point((int) $session_id, $realm_id, $return_to);
                $browsers[(int) $session_id][$realm_id] = true;
            }
        }

        foreach ($ended as $user_session) {
            $browsers[(int) $user_session->session_id][(int) $user_session->realm_id] = true;
        }

        // A realm whose sign-in ENDED on a browser takes that realm's values with it: its
        // pointer was left empty there, or another account took the browser over
        // (SIGN_OUT_REPLACED). A pointer that moved to the same person - a site switch, an
        // impersonation starting or ending - keeps them. Guest values and the other realm's
        // are never touched. An API key's row holds no values.
        $pointers = DB::table('_sessions')
            ->whereIn('id', array_keys($browsers))
            ->get(['id', 'staff_user_session_id', 'portal_user_session_id'])
            ->keyBy('id')
            ->all();

        $clear = [];

        foreach ($browsers as $session_id => $realms) {
            foreach (array_keys($realms) as $realm_id) {
                if (!isset(self::POINTER_COLUMNS[$realm_id]) || !isset($pointers[$session_id])) {
                    continue;
                }

                if ($reason === self::SIGN_OUT_REPLACED || $pointers[$session_id]->{self::POINTER_COLUMNS[$realm_id]} === null) {
                    $clear[$realm_id][] = (int) $session_id;
                }
            }
        }

        foreach ($clear as $realm_id => $session_ids) {
            DB::table('_session_values')->whereIn('session_id', $session_ids)->where('realm_id', $realm_id)->delete();
        }

        if ($push) {
            foreach ($browsers as $session_id => $realms) {
                foreach (array_keys($realms) as $realm_id) {
                    Realtime::push_session_refresh(self::__realm_word($realm_id), $session_id);
                }
            }
        }

        return array_values($ended);
    }

    /**
     * Retire browsers that went unused for rsx.sessions.unused_session_days: every user session
     * still signed in on them is signed out (SIGN_OUT_BROWSER_UNUSED - so it leaves the "signed
     * in" lists and stops counting toward the session cap; their realm values go with it), then
     * the rows are made inactive. Nothing is deleted. A row already inactive is left alone.
     * Answers how many browsers were made inactive.
     *
     * No realtime push: a browser unused for that long has no live page to tell.
     *
     * FRAMEWORK INTERNAL - init() (a lookup of such a browser) and
     * Session_Cleanup_Service::deactivate_unused_sessions().
     *
     * @param int[] $session_ids
     * @return int
     */
    public static function _deactivate_browsers(array $session_ids): int
    {
        $session_ids = array_values(array_unique(array_map('intval', $session_ids)));

        if (empty($session_ids)) {
            return 0;
        }

        self::_sign_out_user_sessions(
            DB::table('_user_sessions')->whereIn('session_id', $session_ids)->whereNull('signed_out_at')->pluck('id')->all(),
            self::SIGN_OUT_BROWSER_UNUSED,
            false
        );

        return static::whereIn('id', $session_ids)->where('active', true)->raw_bulk()->update(['active' => false]);
    }

    /**
     * Sign out, wherever their browsers are, up to $limit user sessions of a realm that are
     * still signed in and CURRENT on their browser past one of the realm's sign-in clocks -
     * the sweep behind enforce_timeouts(), for browsers nobody brings back, so "where you're
     * signed in" stops listing a sign-in that is already over. Answers how many it read (the
     * caller pages until a batch comes back short).
     *
     * $clock is 'length' (signed_in_at + rsx.sessions.<realm>.max_length_minutes) or 'idle'
     * (last_page_view_at + max_idle_minutes); a limit of 0 reads nothing. The cutoff carries
     * rsx.sessions.ajax_grace_minutes on top of the limit: a page left open past its limit
     * may still save through Ajax for that long, and the sweep must not end it sooner than a
     * request would. Each row is recorded under the reason enforce_timeouts() would have
     * given (the EARLIER of its two expiries), and a staff impersonation takes the
     * impersonator's own sign-in behind it with it, exactly as at a request.
     *
     * Only the user session a browser POINTS AT is read: a sign-in suspended behind an
     * impersonation is not running its own clocks (the impersonation is), and is judged when
     * the browser returns to it. An API key's user session has no limits. Read through
     * idx_user_sessions_length_sweep / idx_user_sessions_idle_sweep, each row's browser by
     * primary key. Tokens are not rotated (no browser is making this request); every browser
     * touched gets the realm's refresh push and loses the realm's session values.
     *
     * FRAMEWORK INTERNAL - Session_Cleanup_Service::cleanup_sessions().
     *
     * @param string $realm 'staff' or 'portal'
     * @param string $clock 'length' or 'idle'
     * @param int $limit Rows per batch
     * @return int Rows read (signed out)
     */
    public static function _end_timed_out_user_sessions(string $realm, string $clock, int $limit): int
    {
        $realm_id = self::__realm_id($realm);
        [$column, $config_key] = match ($clock) {
            'length' => ['signed_in_at', 'max_length_minutes'],
            'idle' => ['last_page_view_at', 'max_idle_minutes'],
        };

        $minutes = (int) config('rsx.sessions.' . $realm . '.' . $config_key);

        if ($minutes <= 0) {
            return 0;
        }

        $cutoff = now()
            ->subMinutes($minutes + (int) config('rsx.sessions.ajax_grace_minutes'))
            ->format('Y-m-d H:i:s.v');

        $rows = DB::table('_user_sessions as u')
            ->join('_sessions as s', function ($join) use ($realm_id) {
                $join->on('s.id', '=', 'u.session_id')->on('s.' . self::POINTER_COLUMNS[$realm_id], '=', 'u.id');
            })
            ->where('u.realm_id', $realm_id)
            ->whereNull('u.signed_out_at')
            ->where('u.' . $column, '<', $cutoff)
            ->limit($limit)
            ->select('u.*')
            ->get();

        $by_reason = [];

        foreach ($rows as $user_session) {
            $expiry = self::__expiry_of($user_session, $realm);
            $reason = self::timeout_sign_out_reason($expiry === null ? $clock : $expiry['reason']);

            $by_reason[$reason][] = (int) $user_session->id;

            if ($realm_id === self::REALM_STAFF && $user_session->impersonator_login_user_id !== null) {
                $own = self::__suspended_own_user_session((int) $user_session->session_id, $user_session);

                if ($own !== null) {
                    $by_reason[$reason][] = (int) $own->id;
                }
            }
        }

        foreach ($by_reason as $reason => $ids) {
            self::_sign_out_user_sessions($ids, $reason);
        }

        return $rows->count();
    }

    /**
     * End the impersonations that started before $cutoff: a staff impersonation together
     * with the impersonator's own sign-in behind it (the browser signs in again as itself), a
     * View as Client alone (the staff member's own sign-in carries on).
     *
     * FRAMEWORK INTERNAL - Session_Cleanup_Service::end_stale_impersonations().
     *
     * @param string $cutoff Any Rsx_Time-parseable moment
     * @return array{staff: int, portal: int} How many impersonations were ended
     */
    public static function _end_stale_impersonations(string $cutoff): array
    {
        $before = Rsx_Time::to_database($cutoff);
        $counts = [];

        foreach ([self::REALM_STAFF => 'staff', self::REALM_PORTAL => 'portal'] as $realm_id => $word) {
            $stale = DB::table('_user_sessions')
                ->where('realm_id', $realm_id)
                ->whereNotNull(self::__impersonator_column($realm_id))
                ->whereNull('signed_out_at')
                ->where('started_at', '<', $before)
                ->get();

            $ids = [];

            foreach ($stale as $impersonation) {
                $ids[] = (int) $impersonation->id;

                if ($realm_id === self::REALM_STAFF) {
                    $own = self::__suspended_own_user_session((int) $impersonation->session_id, $impersonation);

                    if ($own !== null) {
                        $ids[] = (int) $own->id;
                    }
                }
            }

            self::_sign_out_user_sessions($ids, self::SIGN_OUT_IMPERSONATION_ENDED);
            $counts[$word] = $stale->count();
        }

        return $counts;
    }


    // =========================================================================
    // SESSION-SCOPED KEY/VALUE STORAGE (_session_values)
    // =========================================================================

    /**
     * Store a value against THIS browser session, in a REALM.
     *
     * For data that belongs to a browser session rather than to a user or a record: a parked
     * second-factor challenge, a wizard's half-finished input, a chosen filter. Its lifetime
     * is bounded by the browser row's - the row's values are removed by an ON DELETE CASCADE
     * when the row is deleted - and, for a signed-in realm, by that realm's sign-in.
     *
     * EVERY VALUE HAS A REALM, which decides when it is cleared:
     *
     *   'staff'   cleared when the browser's staff sign-in ends - signed out, timed out,
     *             ended from elsewhere, or another account signed in over it. A site switch
     *             and an impersonation starting or ending keep it.
     *   'portal'  the same, for the browser's portal sign-in.
     *   'guest'   the BROWSER's own: kept through every sign-out, in either realm. For a
     *             preference that is not anybody's (a dismissed banner, a chosen language).
     *
     * $realm null is the realm of the request's EXPERIENCE: 'portal' on a portal request
     * (Rsx_Portal::is_portal_request()), 'staff' otherwise - whether or not anybody is signed
     * in yet, so a value a signed-out visitor parks before signing in (across a second-factor
     * challenge) is still there after the sign-in, and goes when that sign-in ends. Read and
     * forget in the realm you wrote; each realm is its own namespace, so one key may hold a
     * value in each. Portal_Session's three methods are these, in the 'portal' realm.
     *
     * This is NOT a cache (RsxCache), NOT configuration (Rsx_Settings, which is declared
     * and site-scoped), and NOT for anything a user should still have on another device.
     *
     * A WRITER: it establishes a session if there is not one yet, like every other Session
     * writer. Values are JSON-encoded, so scalars and arrays round-trip unchanged.
     *
     * VALUES BELONG TO A BROWSER. A virtual session (a command, a task) and an API request
     * have none, so this THROWS there - a value written where no browser will ever read it
     * back is a bug in the caller. The readers below answer "absent" in the same places.
     *
     * $expires_at is OPTIONAL and defaults to null, meaning "as long as the realm keeps it".
     * Pass one only when a value must die SOONER than that; the framework never invents an
     * expiry for you.
     *
     * created_at/updated_at are maintained by the column defaults, so they are not written
     * here - an ISO string with a 'Z' is not a MySQL datetime, and to_database() is only
     * appropriate for the value we actually mean to control.
     *
     * @param string $key
     * @param mixed $value
     * @param string|null $expires_at ISO datetime, or null to live as long as the realm keeps it
     * @param string|null $realm 'staff', 'portal' or 'guest'; null = the request's experience
     * @return void
     */
    public static function put_value(string $key, $value, ?string $expires_at = null, ?string $realm = null): void
    {
        self::_refuse_if_frozen("Session::put_value('{$key}')");

        if (self::$_api_identity !== null || (self::__is_virtual() && self::$_browser_session_id_for_tests === null)) {
            throw new Rsx_Caller_Exception(
                "Session::put_value('{$key}'): session values belong to a browser, and this "
                . (self::$_api_identity !== null ? 'API request' : 'process (a command or a task)')
                . ' has none. Store it in a table of your own, keyed on what it belongs to.'
            );
        }

        $realm_id = self::__value_realm_id($realm);
        $session_id = self::get_session_id();

        DB::table('_session_values')->updateOrInsert(
            ['session_id' => $session_id, 'realm_id' => $realm_id, 'value_key' => $key],
            [
                'value' => json_encode($value),
                'expires_at' => $expires_at === null ? null : Rsx_Time::to_database($expires_at),
            ]
        );
    }

    /**
     * Read a value stored against this browser session, in a realm (see put_value() - null
     * is the request's experience).
     *
     * A READER: it never creates a session. With no session there is by definition no value,
     * so the default is returned - asking a question must not have the side effect of
     * minting a session row for every anonymous visitor.
     *
     * An EXPIRED value reads as absent immediately, because the query filters on expires_at
     * rather than trusting the prune task to have run. Correctness comes from the read; the
     * task only reclaims space.
     *
     * @param string $key
     * @param mixed $default Returned when the key is unset, expired, or there is no session
     * @param string|null $realm 'staff', 'portal' or 'guest'; null = the request's experience
     * @return mixed
     */
    public static function get_value(string $key, $default = null, ?string $realm = null)
    {
        self::_refuse_if_frozen("Session::get_value('{$key}')");

        $realm_id = self::__value_realm_id($realm);
        $session_id = self::__values_session_id();

        if ($session_id === null) {
            return $default;
        }

        $row = DB::table('_session_values')
            ->where('session_id', $session_id)
            ->where('realm_id', $realm_id)
            ->where('value_key', $key)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Rsx_Time::to_database(Rsx_Time::now_iso()));
            })
            ->first();

        if (!$row) {
            return $default;
        }

        $decoded = json_decode($row->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    /**
     * Remove a value stored against this browser session, in a realm (see put_value() - null
     * is the request's experience).
     *
     * A READER in the session sense - it never creates a session, because there is nothing
     * to remove from one that does not exist. Removing an absent key is not an error.
     *
     * @param string $key
     * @param string|null $realm 'staff', 'portal' or 'guest'; null = the request's experience
     * @return void
     */
    public static function forget_value(string $key, ?string $realm = null): void
    {
        self::_refuse_if_frozen("Session::forget_value('{$key}')");

        $realm_id = self::__value_realm_id($realm);
        $session_id = self::__values_session_id();

        if ($session_id === null) {
            return;
        }

        DB::table('_session_values')
            ->where('session_id', $session_id)
            ->where('realm_id', $realm_id)
            ->where('value_key', $key)
            ->delete();
    }

    /**
     * The realm id (_session_values.realm_id) for a realm word, or for null the request's
     * experience. Throws on any other word.
     *
     * @param string|null $realm
     * @return int REALM_STAFF, REALM_PORTAL or REALM_GUEST
     */
    private static function __value_realm_id(?string $realm): int
    {
        if ($realm === null) {
            return \App\RSpade\Core\Portal\Rsx_Portal::is_portal_request() ? self::REALM_PORTAL : self::REALM_STAFF;
        }

        if (!isset(self::VALUE_REALMS[$realm])) {
            throw new Rsx_Caller_Exception(
                "Session value realm '{$realm}' does not exist. A session value's realm is 'staff', 'portal' or 'guest' (or null for the request's experience)."
            );
        }

        return self::VALUE_REALMS[$realm];
    }

    /**
     * The realm id of a realm word ('staff', 'portal' or 'guest') as _session_values stores
     * it - for framework code that reads the table directly (Rsx_Two_Factor_Abstract's
     * enrollment marker). FRAMEWORK INTERNAL.
     *
     * @param string $realm
     * @return int
     */
    public static function _value_realm_id(string $realm): int
    {
        return self::__value_realm_id($realm);
    }

    /**
     * The browser row whose values the readers consult, or null when there is none: no row
     * yet (a guest who minted nothing), a virtual session not acting as a test browser, an
     * API request. Creates nothing.
     *
     * @return int|null
     */
    private static function __values_session_id(): ?int
    {
        if (self::$_api_identity !== null) {
            return null;
        }

        if (self::__is_virtual()) {
            return self::$_browser_session_id_for_tests;
        }

        $row = self::__row_if_any();

        return $row === null ? null : (int) $row->id;
    }

}
