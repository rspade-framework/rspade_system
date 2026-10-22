<?php

namespace App\RSpade\Core\Portal;

use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Portal\Rsx_Portal_Url;
use App\RSpade\Core\Realtime\Realtime;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\Session_Link;

/**
 * Portal_Session - authentication for external portal users.
 *
 * A STATIC FACADE over the PORTAL SIGN-IN of the ONE session RSpade gives a browser.
 * There is no portal session row and no portal cookie: a session identifies a BROWSER
 * (one `rsx` cookie, one `_sessions` row, per cookie jar), and its portal sign-in is a
 * USER SESSION (_user_sessions, realm portal: the portal user, its site, and the staff
 * users.id when it is a "View as Client") that the row's portal pointer names. This class
 * reads and moves that pointer through the framework-internal seams on Session.
 *
 * The staff facade (Session) owns the row itself and the staff pointer. BOTH SIGNED IN AT
 * ONCE IS NORMAL - the same human, in the same browser, signed into the staff app and the
 * client portal. Which a given request consults is decided by the REQUEST's experience
 * (Rsx_Portal::is_portal_request()), never by the row.
 *
 * A portal on its own host (PORTAL_URL) sits behind a separate cookie jar, so ordinarily
 * the browser holds its own row there, with the staff properties null. Staff "View as
 * Client" is the exception: the linked-session handshake points the portal host's cookie
 * at the staff browser's row (see begin_impersonation_from_staff()).
 *
 * Consequences worth stating outright:
 * - Portal logout, ending a portal session and the portal sign-in cap sign out the PORTAL
 *   user session only. They never delete or deactivate the row - it is the browser's
 *   session and may carry a staff sign-in too.
 * - The session id, the session token and the CSRF token are SHARED with the staff
 *   facade. get_session_id() / get_csrf_token() here are the same values Session
 *   reports; they are kept as facade methods so portal code never has to know that.
 * - Site-scoped, with no multi-site switching. THE URL IS THE AUTHORITY: the framework
 *   resolves each portal request's site from its address (Portal_Site_Resolver) and
 *   declares it for that request (_declare_resolved_site()) before Portal_Main::init();
 *   it is never stored. A portal sign-in on this browser for a different site is ended
 *   at that moment (SIGN_OUT_SITE_SWITCHED). get_site_id() throws when nothing has
 *   declared a site - see rsx:man portal.
 * - CLI mints nothing of its own: Rsx_Portal::is_portal_request() is false in CLI, so
 *   nothing can be in portal context. set_site_id() is the declaration there (a command,
 *   a task, a test), and only there: a web request's site is its URL's.
 */
class Portal_Session
{
    private static $_site = null;

    private static $_portal_user = null;

    /**
     * The site declared for this request: in web mode the one its URL resolved to
     * (_declare_resolved_site()), in CLI the one set_site_id() declared.
     *
     * Request-scoped in web mode (the declaration belongs to the request, not to
     * the session row); process-scoped in CLI, where it is also the whole CLI site
     * contract. null = nothing has declared a site yet, which makes get_site_id()
     * throw rather than invent one.
     *
     * @var int|null
     */
    private static $_declared_site_id = null;

    // CLI mode properties (static-only, no database)
    private static $_cli_portal_user_id = null;

    private static $_cli_impersonator_user_id = null;

    /**
     * Check if running in CLI mode
     * @return bool
     */
    private static function __is_cli(): bool
    {
        return php_sapi_name() === 'cli';
    }

    /**
     * Resolve the browser's session from its cookie, without creating one.
     *
     * One cookie, one row, one init: this delegates to the staff facade, which owns
     * the row. Kept as a portal-facing method because portal middleware calls it.
     *
     * @return void
     */
    public static function init(): void
    {
        Session::init();
    }

    /**
     * Return the portal facade to virgin state between tests. THE per-test seam.
     *
     * Clears the process-global CLI state - the declared site, the portal user, the
     * impersonator - plus the resolved caches. All of it is STATIC, so the per-test database
     * transaction does not touch it: a site declared by one test would otherwise be borrowed
     * by every test after it, in this class and in every class that follows. (setup() runs
     * once per CLASS and therefore does NOT survive this, so each test declares its own site.)
     *
     * Called by Rsx_Test_Abstract between test methods, and never by application code. Ending
     * a portal sign-in on a live request is logout().
     *
     * @return void
     */
    public static function _testing_reset(): void
    {
        self::$_declared_site_id = null;
        self::$_cli_portal_user_id = null;
        self::$_cli_impersonator_user_id = null;
        self::$_site = null;
        self::$_portal_user = null;
    }

    /**
     * MISUSE GUARD - Portal_Session is NOT a session-variable bag; there is no get($key).
     *
     * Same trap as Session::get(): a bare Portal_Session::get('site_id') READS like
     * Laravel's session()->get() and is always a mistake here.
     *
     * @throws Rsx_Caller_Exception always
     */
    public static function get(...$args): never
    {
        throw new Rsx_Caller_Exception(
            'Portal_Session::get() does not exist - Portal_Session is the portal experience\'s session facade, not a '
            . 'session-variable bag. Use the named accessor instead: get_site_id(), get_portal_user_id(), '
            . 'get_user(), get_portal_user(), get_session_id(), get_csrf_token().'
        );
    }

    /**
     * Declare which site the portal serves, in a process that has no request: a command,
     * a task, a test.
     *
     * A WEB request never calls this: the framework resolves the site from the portal URL
     * (Portal_Site_Resolver) and declares it itself before Portal_Main::init(), so a call
     * in any web request THROWS - remove it (rsx:man portal). In CLI it is the whole site
     * contract: request-free, it creates no session, may be re-declared (that is how a test
     * moves between tenants), and _testing_reset() clears it.
     *
     * @param int $site_id A real site id (positive)
     * @return void
     * @throws Rsx_Caller_Exception on a non-positive id
     * @throws \RuntimeException in a web request
     */
    public static function set_site_id(int $site_id): void
    {
        if ($site_id <= 0) {
            throw new Rsx_Caller_Exception(
                'Portal_Session::set_site_id() requires a real site id; got ' . $site_id . '. '
                . 'If the site cannot be resolved yet, do not call this - let get_site_id() fail loud instead. '
                . 'See: php artisan rsx:man portal'
            );
        }

        if (!self::__is_cli()) {
            throw new \RuntimeException(
                'Portal_Session::set_site_id(' . $site_id . ') is for commands, tasks and tests only. In a web request '
                . 'the portal\'s site is resolved from its URL (PORTAL_URL, sites.portal_domain) and declared by the '
                . 'framework before Portal_Main::init() - remove the call (an application no longer declares its '
                . 'portal site). See: php artisan rsx:man portal'
            );
        }

        Session::_refuse_if_frozen('Portal_Session::set_site_id()');

        self::$_declared_site_id = $site_id;
        self::$_site = null;
    }

    /**
     * Declare the site this portal request's URL resolved to. Every portal request, before
     * Portal_Main::init() and anything else that may ask for the site.
     *
     * A portal sign-in on this browser that belongs to a different site is signed out
     * (Session::SIGN_OUT_SITE_SWITCHED) and the request continues signed out: a portal user
     * belongs to exactly one site, so the URL and the sign-in can only disagree in path mode
     * (one cookie for every tenant on the host), and the URL wins. In CLI (an in-process
     * dispatch) the virtual portal identity of another site is cleared the same way.
     *
     * FRAMEWORK INTERNAL - Dispatcher, from Rsx_Request_Channel::portal_site_id().
     *
     * @param int $site_id The resolved site (positive)
     * @return void
     */
    public static function _declare_resolved_site(int $site_id): void
    {
        if ($site_id <= 0) {
            shouldnt_happen('Portal_Session::_declare_resolved_site() requires a real site id; got ' . $site_id);
        }

        if (self::__is_cli()) {
            if (self::$_declared_site_id !== $site_id) {
                Session::_refuse_if_frozen('Portal_Session::_declare_resolved_site()');

                if (self::$_declared_site_id !== null && self::$_cli_portal_user_id !== null) {
                    self::$_cli_portal_user_id = null;
                    self::$_cli_impersonator_user_id = null;
                }
            }
        } else {
            $row_site_id = Session::_get_portal_site_id();

            if ($row_site_id !== null && $row_site_id !== $site_id) {
                Session::_sign_out_current('portal', Session::SIGN_OUT_SITE_SWITCHED);
            }
        }

        self::$_declared_site_id = $site_id;
        self::$_site = null;
        self::$_portal_user = null;
    }

    /**
     * The site declared for this process - resolved from a web portal request's URL, or
     * set by a command or test - or null when none was. Never throws, never reads the
     * session row. FRAMEWORK INTERNAL - Rsx_Portal_Url::current_site_id().
     *
     * @return int|null
     */
    public static function _declared_site_id(): ?int
    {
        return self::$_declared_site_id;
    }

    /**
     * Get site ID for the current portal request.
     *
     * Resolution order: the site declared for this request (its URL's, or in CLI
     * set_site_id()'s), then the portal sign-in's own tenant (its user session's site) for a
     * request that declared none. They never disagree: declaring a different site ends that
     * sign-in. When neither exists this THROWS, which is the contract: a portal that does
     * not know its tenant must not quietly pick one.
     *
     * @return int
     * @throws \RuntimeException when no site has been declared
     */
    public static function get_site_id(): int
    {
        // CLI mode: the declaration is the whole story (no cookie, no row).
        if (self::__is_cli()) {
            if (self::$_declared_site_id === null) {
                self::__throw_site_unresolved();
            }

            return self::$_declared_site_id;
        }

        if (self::$_declared_site_id !== null) {
            return self::$_declared_site_id;
        }

        $row_site_id = Session::_get_portal_site_id();

        if ($row_site_id !== null) {
            return $row_site_id;
        }

        self::__throw_site_unresolved();
    }

    /**
     * The one refusal, in one place, so every path words it identically.
     *
     * @return never
     * @throws \RuntimeException always
     */
    private static function __throw_site_unresolved(): never
    {
        if (self::__is_cli()) {
            throw new \RuntimeException(
                'The portal site has not been declared. A command, task or test declares it with '
                . 'Portal_Session::set_site_id(<site id>) before any portal session work. '
                . 'See: php artisan rsx:man portal'
            );
        }

        throw new \RuntimeException(
            'The portal site is not known in this request: it is not a portal request whose URL resolved a site '
            . '(the framework declares it from PORTAL_URL / sites.portal_domain before Portal_Main::init()). '
            . 'See: php artisan rsx:man portal'
        );
    }

    /**
     * Get site model for current session
     * @return Site_Model|null
     */
    public static function get_site()
    {
        $site_id = self::get_site_id();

        if (empty(self::$_site)) {
            self::$_site = Site_Model::find($site_id);
        }

        return self::$_site;
    }

    /**
     * Get portal user ID for current session
     * In CLI mode: returns static CLI property
     * @return int|null
     */
    public static function get_portal_user_id()
    {
        // CLI mode: return static property
        if (self::__is_cli()) {
            return self::$_cli_portal_user_id;
        }

        return Session::_get_portal_user_id();
    }

    /**
     * Check if portal user is logged in
     * @return bool
     */
    public static function is_logged_in(): bool
    {
        return !empty(self::get_portal_user_id());
    }

    /**
     * Get portal user model for current session
     * @return Portal_User_Model|null
     */
    public static function get_user()
    {
        $portal_user_id = self::get_portal_user_id();

        if (empty($portal_user_id)) {
            return null;
        }

        if (empty(self::$_portal_user)) {
            self::$_portal_user = Portal_User_Model::find($portal_user_id);
        }

        return self::$_portal_user;
    }

    /**
     * Alias for get_user() to match naming convention
     * @return Portal_User_Model|null
     */
    public static function get_portal_user()
    {
        return self::get_user();
    }

    /**
     * Get this browser's session ROW (creates if needed).
     *
     * The SAME row the staff facade returns - there is one session per browser. The
     * portal properties on it are this facade's business; the row is Session's.
     *
     * @return Session
     */
    public static function get_session(): Session
    {
        return Session::get_session();
    }

    /**
     * Get current session ID (creates session if needed) - or null where there is no
     * browser (a command, a task). See Session::get_session_id().
     *
     * The SAME id the staff facade reports: one browser, one session. Portal code
     * calls it through this facade so it never has to know that.
     *
     * @return int|null
     */
    public static function get_session_id(): ?int
    {
        return Session::get_session_id();
    }

    /**
     * Get CSRF token for current session.
     *
     * ONE token per browser session, shared with the staff experience - the row is
     * shared, so the token on it is too. Proxied here so portal code keeps talking to
     * its own facade.
     *
     * @return string|null
     */
    public static function get_csrf_token(): ?string
    {
        return Session::get_csrf_token();
    }

    /**
     * Verify CSRF token against this browser's session (the one shared token).
     *
     * @param string $token
     * @return bool
     */
    public static function verify_csrf_token(string $token): bool
    {
        return Session::verify_csrf_token($token);
    }

    // =====================================================================
    // Session values - the browser's one value store, from portal code
    // =====================================================================

    /**
     * Store a value against this browser's session in the PORTAL realm - Session::put_value()
     * with realm 'portal', exposed here so portal code never reaches for the staff facade.
     *
     * A portal value is cleared when the browser's portal sign-in ends (signed out, timed out,
     * ended from elsewhere, or another portal account signed in over it); a staff sign-out
     * never touches it, and a value a signed-out visitor parks before signing in (across a
     * second-factor challenge) is still there after the sign-in. A value that must outlive
     * every sign-out is the browser's own: Session::put_value($key, $value, null, 'guest').
     * See Session::put_value() for the writer semantics and the optional expiry.
     *
     * @param string $key
     * @param mixed $value
     * @param string|null $expires_at ISO datetime, or null to live as long as the realm keeps it
     * @return void
     */
    public static function put_value(string $key, $value, ?string $expires_at = null): void
    {
        Session::put_value($key, $value, $expires_at, 'portal');
    }

    /**
     * Read a value stored against this browser's session in the portal realm. A reader:
     * creates nothing. An expired value reads as absent. See Session::get_value().
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get_value(string $key, $default = null)
    {
        return Session::get_value($key, $default, 'portal');
    }

    /**
     * Remove a value stored against this browser's session in the portal realm. See
     * Session::forget_value().
     *
     * @param string $key
     * @return void
     */
    public static function forget_value(string $key): void
    {
        Session::forget_value($key, 'portal');
    }

    /**
     * Sign the portal account out of this browser, leaving the browser's session (and any
     * staff sign-in on it) intact. Under a View as Client this ends the view instead.
     *
     * @return void
     */
    public static function logout(): void
    {
        self::set_portal_user_id(null);
    }

    /**
     * End the portal sign-in on this browser when it has outlived rsx.sessions.portal, and
     * say why. The portal twin of Session::enforce_timeouts(): the same two clocks on the
     * portal user session (signed_in_at, last_page_view_at), the same Ajax grace, and the
     * same sign-out (SIGN_OUT_TIMED_OUT_*, tokens rotated). A staff sign-in on the same
     * browser is untouched and is judged by its own limits, on staff requests.
     *
     * @param bool $strict True for a page; false gives the Ajax grace
     * @return string|null 'length' or 'idle' when the sign-in was just ended, else null
     */
    public static function enforce_timeouts(bool $strict): ?string
    {
        $reason = Session::_timeout_reason('portal', $strict);

        if ($reason !== null) {
            Session::_sign_out_current('portal', Session::timeout_sign_out_reason($reason));
        }

        return $reason;
    }

    /**
     * Record a PAGE VIEW for the portal sign-in on this browser (its user session), and
     * last_seen on the portal account. See Session::touch_page_view(). Under View as
     * Client the user session is stamped and the account is not.
     *
     * @return void
     */
    public static function touch_page_view(): void
    {
        if (!Session::_page_view_is_due('portal')) {
            return;
        }

        if (self::is_impersonating()) {
            return;
        }

        // A bare statement, as in Session::touch_page_view(): bookkeeping must not bump
        // updated_at or run the model's save surface on every page view.
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE portal_users SET last_seen = ? WHERE id = ?',
            [now()->format('Y-m-d H:i:s.v'), Session::_get_portal_user_id()]
        );
    }

    /**
     * Sign a portal account in on this browser, or (null) sign it out.
     *
     * SIGN-IN: activates the browser's session (minting the row through THE activation path
     * if this is a fresh browser) and finds or creates the portal user session for (this
     * browser, the account, the request's declared site); the
     * browser points at it, its clocks start, the tokens rotate and the account's session
     * cap is applied. The site is the one this request's URL resolved to; a request with
     * none throws. Re-stating the account the browser is already
     * signed in as is not a sign-in and changes nothing.
     *
     * SIGN-OUT: the portal user session is signed out (SIGN_OUT_SIGNED_OUT), the browser's
     * tokens rotate and its PORTAL values are cleared (the staff and guest values stay).
     * The row survives: it is the browser's session, and may carry a staff
     * sign-in. Under a View as Client, signing out of the portal ENDS THE VIEW instead
     * (stop_impersonation()): the staff member never signed into the portal.
     *
     * In CLI mode: sets the static CLI property only, no database.
     *
     * @param int|null $portal_user_id Portal user ID, or null to logout
     * @return void
     */
    public static function set_portal_user_id(?int $portal_user_id): void
    {
        Session::_refuse_if_frozen(empty($portal_user_id) ? 'Portal_Session::logout()' : 'Portal_Session::set_portal_user_id()');

        // Logout if null/0
        if (empty($portal_user_id)) {
            // CLI mode: clear static property only
            if (self::__is_cli()) {
                self::$_cli_portal_user_id = null;
                self::$_cli_impersonator_user_id = null;
                self::$_portal_user = null;
                self::$_site = null;

                return;
            }

            if (!Session::_end_portal_impersonation()) {
                Session::_sign_out_current('portal', Session::SIGN_OUT_SIGNED_OUT);
            }

            self::$_portal_user = null;
            self::$_site = null;

            return;
        }

        // CLI mode: set static property only
        if (self::__is_cli()) {
            self::$_cli_portal_user_id = $portal_user_id;
            self::$_portal_user = null;
            self::$_site = null;

            return;
        }

        // Throws when the request has no declared site (see get_site_id). Read
        // BEFORE activation so a misconfigured portal fails before it mints anything.
        $site_id = self::get_site_id();

        if (!Session::_set_portal_identity($portal_user_id, $site_id)) {
            return;
        }

        self::$_portal_user = null;
        self::$_site = null;

        // Push a refresh to every live PORTAL-stamped connection on this browser. Staff tabs
        // on the same browser are untouched: the realm stamp on a connection records which
        // EXPERIENCE its page was minted on.
        Realtime::push_session_refresh('portal', Session::get_session_id());

        // Sign-in is the moment this user's session count grows, so it is where the
        // concurrent-session cap is applied.
        Session::_enforce_session_cap(Session::REALM_PORTAL, (int) $portal_user_id);

        // Update portal user's last login timestamp
        $portal_user = self::get_user();
        if ($portal_user) {
            $portal_user->last_login = now();
            $portal_user->save();
        }
    }

    /**
     * Check if a session exists.
     *
     * In web mode: whether this browser has a session at all (the shared row) -
     * creates nothing. In CLI: whether a site or portal user has been declared.
     *
     * @return bool
     */
    public static function has_session(): bool
    {
        // CLI mode: check if a site or portal user has been declared
        if (self::__is_cli()) {
            return self::$_declared_site_id !== null || self::$_cli_portal_user_id !== null;
        }

        return Session::has_session();
    }

    /**
     * Find a session by token, narrowed to browsers SIGNED INTO THE PORTAL. A browser
     * session with no portal sign-in resolves to null here - it is not a portal session,
     * whatever else it may be.
     *
     * @param string $token
     * @return Session|null
     */
    public static function find_by_token(string $token)
    {
        return Session::_portal_query()
            ->where('session_token', $token)
            ->where('active', true)
            ->first();
    }

    // =========================================================================
    // CLI MODE SETTERS
    // =========================================================================

    // NOTE: there is no cli_set_site_id(). set_site_id() is the CLI site setter (a web
    // request's site is its URL's). _testing_reset() clears it.

    /**
     * Set portal user ID in CLI mode
     * @param int $portal_user_id
     * @return void
     */
    public static function cli_set_portal_user_id(int $portal_user_id): void
    {
        Session::_refuse_if_frozen('Portal_Session::cli_set_portal_user_id()');

        self::$_cli_portal_user_id = $portal_user_id;
        self::$_portal_user = null;
    }

    // =========================================================================
    // SESSION MANAGEMENT METHODS
    // =========================================================================

    /**
     * Where a portal account is signed in: its PORTAL user sessions, most recently active
     * first, formatted for display - signed in, or signed out within
     * rsx.sessions.unused_session_days. The same entries as Session::get_sessions_for_user()
     * (site_id is the portal site, impersonator_id a staff users.id).
     *
     * Returns the WHOLE set, for the same reason as the staff twin: a user cannot end a
     * session they were not shown.
     *
     * @param int|null $portal_user_id If null, uses current logged-in user
     * @param bool $include_impersonations Also answer the View as Client sessions of this
     *                          account (each entry carries `is_impersonation`). False for the
     *                          user's own screen.
     * @return array
     */
    public static function get_sessions_for_user(?int $portal_user_id = null, bool $include_impersonations = false): array
    {
        if ($portal_user_id === null) {
            $portal_user_id = self::get_portal_user_id();
        }

        if (empty($portal_user_id)) {
            return [];
        }

        return Session::_list_user_sessions(Session::REALM_PORTAL, (int) $portal_user_id, null, $include_impersonations);
    }

    /**
     * Sign one of the CURRENT portal account's own portal sessions out, on another browser.
     * Anything on the browser this request is on is refused (that is logout()'s job). The
     * other browser's staff sign-in, if it has one, is untouched.
     *
     * Fail-closed: the owning-account predicate is part of the lookup, so a user session
     * belonging to anyone else matches nothing and returns false.
     *
     * @param int $user_session_id A portal user session id (get_sessions_for_user()'s `id`)
     * @return bool True if one of the caller's own portal sessions was ended
     */
    public static function terminate_session(int $user_session_id): bool
    {
        $portal_user_id = self::get_portal_user_id();
        if (empty($portal_user_id)) {
            return false;
        }

        $ids = Session::_signed_in_user_session_ids(Session::REALM_PORTAL, (int) $portal_user_id, $user_session_id, true);

        return !empty(Session::_sign_out_user_sessions($ids, Session::SIGN_OUT_ENDED_BY_USER));
    }

    /**
     * Sign the current portal account out of every OTHER browser, keeping this one.
     *
     * @return int Number of portal sessions ended
     */
    public static function terminate_all_other_sessions(): int
    {
        $portal_user_id = self::get_portal_user_id();
        if (empty($portal_user_id)) {
            return 0;
        }

        // "Sign out everywhere else" also ends every other browser's two-factor trust.
        \App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor::forget_trusted_devices((int) $portal_user_id, true);

        $ids = Session::_signed_in_user_session_ids(Session::REALM_PORTAL, (int) $portal_user_id, null, true);

        return count(Session::_sign_out_user_sessions($ids, Session::SIGN_OUT_ENDED_BY_USER));
    }

    /**
     * Sign a specific portal account out of every browser (optionally sparing one portal user
     * session). For admin actions; a password change ends the other sign-ins by itself
     * (Portal_User_Model_Abstract::booted()).
     *
     * @param int $portal_user_id
     * @param int|null $except_user_session_id A portal user session to spare
     * @return int Number of portal sessions ended
     */
    public static function terminate_all_sessions_for_user(int $portal_user_id, ?int $except_user_session_id = null): int
    {
        $ids = Session::_signed_in_user_session_ids(Session::REALM_PORTAL, $portal_user_id, null, false);

        if ($except_user_session_id !== null) {
            $ids = array_values(array_diff($ids, [$except_user_session_id]));
        }

        return count(Session::_sign_out_user_sessions($ids, Session::SIGN_OUT_ENDED_BY_ADMIN));
    }

    // =========================================================================
    // IMPERSONATION (staff "View as Client")
    //
    // A staff member views the portal AS a portal user. The impersonation is a portal USER
    // SESSION on the staff member's own browser - the portal account, its tenant and the
    // staff users.id doing the viewing - that the browser's portal pointer names while it
    // lasts (begin_impersonation_from_staff()):
    //
    //   - portal under a prefix on the application host: one cookie, one row, so the
    //     pointer moves on the caller's own row and the portal reads it on the next page;
    //   - portal on its own host: the browser holds a different cookie there, so the
    //     linked-session handshake (Session_Link) proves both hosts are the same browser
    //     and points the portal host's cookie at the caller's row. Both hosts then share
    //     one row.
    //
    // READ-ONLY BEHAVIOR: the framework refuses every portal Ajax endpoint not marked
    // #[Portal_Impersonation_Readable] while is_impersonating(); the application renders
    // the read-only experience. See: php artisan rsx:man portal.
    //
    // impersonator_user_id is a STAFF users.id, a different id space from the staff
    // experience's own impersonator_login_user_id (login_users.id) - which is exactly
    // why a user session keeps both columns instead of collapsing them.
    // =========================================================================

    /**
     * Start viewing the portal as a portal user, from a staff request, and return the
     * URL the staff page opens (normally in a new tab).
     *
     * The portal is the one of $portal_site_id (Rsx_Portal_Url::for_site()), and whether it
     * shares the caller's host is decided for that site:
     *
     * Same host (the portal under a prefix on APP_URL's host): the impersonation becomes
     * the caller's own browser's portal sign-in, and the portal's landing URL is returned.
     * Separate host (a PORTAL_URL host, a tenant subdomain, a custom domain): a
     * linked-session handshake is started for the caller's row and its first leg's URL is
     * returned (absolute, on that portal's origin); the impersonation is applied on the
     * handshake's staff leg, after the browser has proved itself.
     *
     * FALSE when the site has no portal (the feature off, the site unserved or its portal
     * switched off, under 'only' no custom domain) - nothing is applied, and the staff
     * screen tells its user there is no portal to view.
     *
     * Refuses (AjaxUnauthorizedException) unless the staff realm's `can_impersonate`
     * check passes for the caller, and requires $impersonator_user_id to be the caller's
     * own staff user. The target user's last_login is never touched - the contact's
     * real login history stays clean.
     *
     * @param int $portal_user_id       Target portal user to view as
     * @param int $impersonator_user_id Staff users.id of the caller
     * @param int $portal_site_id       Tenant of the target portal user
     * @return string|false URL to open, or false when the site has no portal
     * @throws AjaxUnauthorizedException When can_impersonate denies the caller
     */
    public static function begin_impersonation_from_staff(int $portal_user_id, int $impersonator_user_id, int $portal_site_id): string|false
    {
        Session::_refuse_if_frozen('Portal_Session::begin_impersonation_from_staff()');

        if ($portal_user_id <= 0 || $impersonator_user_id <= 0 || $portal_site_id <= 0) {
            shouldnt_happen('begin_impersonation_from_staff() requires portal_user_id, impersonator_user_id and portal_site_id');
        }

        $staff_user = Session::get_user();

        if ($staff_user === null || (int) $staff_user->id !== $impersonator_user_id) {
            shouldnt_happen('begin_impersonation_from_staff(): $impersonator_user_id must be the signed-in staff user');
        }

        if (!Auth_Gates::evaluate('can_impersonate', Auth_Gates::REALM_STAFF)) {
            throw new AjaxUnauthorizedException('Not authorized to view the client portal as this user.');
        }

        $session_id = Session::get_session_id();

        if ($session_id === null) {
            shouldnt_happen('begin_impersonation_from_staff(): View as Client moves a browser\'s portal sign-in, and this process has no browser session');
        }

        $address = Rsx_Portal_Url::for_site($portal_site_id);

        if ($address === false) {
            return false;
        }

        if ($address['separate_host']) {
            return Session_Link::begin_impersonation($session_id, $portal_user_id, $portal_site_id, $impersonator_user_id);
        }

        Session::_apply_portal_impersonation($session_id, $portal_user_id, $portal_site_id, $impersonator_user_id);

        if (self::__is_cli()) {
            // CLI reads the portal identity from these overrides, not from a row (see
            // get_portal_user_id()); keep them in step with what was just written.
            self::$_declared_site_id = $portal_site_id;
            self::$_cli_portal_user_id = $portal_user_id;
            self::$_cli_impersonator_user_id = $impersonator_user_id;
        }

        self::$_portal_user = null;
        self::$_site = null;

        return Rsx_Portal::portal_path('/', $portal_site_id);
    }

    /**
     * Whether the current portal identity is a staff impersonation. The APPLICATION
     * is responsible for enforcing read-only behavior based on this flag (gate writes,
     * render a read-only UI) - see rsx:man portal.
     *
     * @return bool
     */
    public static function is_impersonating(): bool
    {
        if (self::__is_cli()) {
            return !empty(self::$_cli_impersonator_user_id);
        }

        return !empty(Session::_get_portal_impersonator_user_id());
    }

    /**
     * The staff User_Model id impersonating in the current session, or null.
     *
     * @return int|null
     */
    public static function get_impersonator_user_id(): ?int
    {
        if (self::__is_cli()) {
            return self::$_cli_impersonator_user_id;
        }

        return Session::_get_portal_impersonator_user_id();
    }

    /**
     * End the current View as Client: its portal user session is signed out and the
     * browser's portal pointer returns to the browser's own portal sign-in, if it has one.
     * The staff member's own staff sign-in is untouched, and nothing rotates (the same row may
     * be serving the staff host through another cookie). No-op when not impersonating.
     *
     * @return void
     */
    public static function stop_impersonation(): void
    {
        Session::_refuse_if_frozen('Portal_Session::stop_impersonation()');

        if (self::__is_cli()) {
            self::$_cli_impersonator_user_id = null;
            self::$_cli_portal_user_id = null;
            self::$_portal_user = null;
            self::$_site = null;

            return;
        }

        if (!Session::_end_portal_impersonation()) {
            return;
        }

        self::$_portal_user = null;
        self::$_site = null;
    }

    /**
     * End a portal identity declared in CLI mode: the portal user, the impersonator and the
     * declared site. FRAMEWORK INTERNAL - Session::_end_virtual() calls it, which is how every
     * task run ends.
     *
     * @return void
     */
    public static function _clear_cli_identity(): void
    {
        self::$_declared_site_id = null;
        self::$_cli_portal_user_id = null;
        self::$_cli_impersonator_user_id = null;
        self::$_site = null;
        self::$_portal_user = null;
    }

    /**
     * Declare the portal identity of a virtual session - the portal user, its site and a View as
     * Client impersonator - in one step, past the frozen guard the public setters carry.
     *
     * FRAMEWORK INTERNAL - Session::_begin_virtual(), which declares a run's whole context
     * before freezing it.
     *
     * @param int|null $portal_user_id
     * @param int|null $site_id
     * @param int|null $impersonator_user_id Staff users.id
     * @return void
     */
    public static function _declare_virtual_identity(?int $portal_user_id, ?int $site_id, ?int $impersonator_user_id): void
    {
        self::$_cli_portal_user_id = $portal_user_id;
        self::$_declared_site_id = $site_id;
        self::$_cli_impersonator_user_id = $impersonator_user_id;
        self::$_site = null;
        self::$_portal_user = null;
    }

    /**
     * The portal facade's declarations - the declared site, the virtual portal user and its
     * impersonator - as a value for _restore_context(). FRAMEWORK INTERNAL - the portal half of
     * Session::_snapshot_context().
     *
     * @return array
     */
    public static function _snapshot_context(): array
    {
        return [
            'declared_site_id' => self::$_declared_site_id,
            'cli_portal_user_id' => self::$_cli_portal_user_id,
            'cli_impersonator_user_id' => self::$_cli_impersonator_user_id,
        ];
    }

    /**
     * Put back what _snapshot_context() took, dropping the resolved site and portal user.
     * FRAMEWORK INTERNAL - the portal half of Session::_restore_context().
     *
     * @param array $snapshot
     * @return void
     */
    public static function _restore_context(array $snapshot): void
    {
        self::$_declared_site_id = $snapshot['declared_site_id'];
        self::$_cli_portal_user_id = $snapshot['cli_portal_user_id'];
        self::$_cli_impersonator_user_id = $snapshot['cli_impersonator_user_id'];
        self::$_site = null;
        self::$_portal_user = null;
    }

    /**
     * Set impersonator user id in CLI mode (test seeding; mirrors
     * cli_set_portal_user_id).
     *
     * @param int|null $impersonator_user_id
     * @return void
     */
    public static function cli_set_impersonator_user_id(?int $impersonator_user_id): void
    {
        Session::_refuse_if_frozen('Portal_Session::cli_set_impersonator_user_id()');

        self::$_cli_impersonator_user_id = $impersonator_user_id;
    }
}
