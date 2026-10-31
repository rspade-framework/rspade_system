<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Database\Rsx_Page_Query;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Ip_Address;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\User_Agent;

/**
 * Login_History_Abstract - recording sign-in outcomes and reading them back, written once for
 * both sign-in realms.
 *
 * Login_History binds it to the staff identity (login_users, `_login_history`);
 * Portal_Login_History binds it to the client portal identity (portal_users,
 * `_portal_login_history`). The two realms record the same outcomes under the same status
 * vocabulary, through the same API, into separate tables - the same split the second-factor
 * and federated sign-in subsystems make (Rsx_Two_Factor / Rsx_Portal_Two_Factor). An
 * application calls the realm class, never this one.
 *
 * TWO STORES, ON PURPOSE:
 *
 * - ROWS are written only for outcomes a stranger cannot cause: a successful sign-in
 *   (record_success()), a sign-in refused AFTER its credentials were proven
 *   (record_refusal() - a password that must be reset), and the passkey enrollment
 *   ceremony of a signed-in identity (record_passkey_enrollment()). Each needs a credential
 *   to produce, so the table grows with real use, never with attack volume, and is pruned
 *   on a retention window by Session_Cleanup_Service (rsx.sessions.login_history_retention_days,
 *   one window for both realms).
 *
 *   PASSKEY ENROLLMENT CEREMONIES (begun, enrolled, failed, abandoned) are the record a
 *   support engineer reads when "my passkey would not save" - a ceremony that was begun and
 *   never confirmed is the browser or the phone declining, not the server refusing.
 *
 * - FAILURES are EPHEMERAL. record_failure() writes no row at all: it increments two
 *   Rsx_Counter counters (per email, per IP) that expire FAILURE_WINDOW_SECONDS after the
 *   first failure, feeds Login_Throttle, and emits one Log::warning() line. That log line is the forensic record; the counters exist only to
 *   answer "how many failures in the window".
 *
 *   WHY (2026-08-12 CR): a sign-in form is anonymous-reachable, so a persisted failure row
 *   was an UNAUTHENTICATED INSERT - any bot could grow the table without limit, and the only
 *   consumers of those rows were the two short-window counts below. Storing them bought
 *   unbounded growth in exchange for a number a TTL'd counter answers exactly.
 *
 *   Consequence, stated plainly: a long-horizon failure history (a 30-day failed-attempt count,
 *   "who attacked this account, from where") is not answerable from this API. The window is
 *   the horizon. Anything longer must come from the log.
 *
 * ENFORCEMENT LIVES IN Login_Throttle, not here: record_failure() hands every failure to it
 * (per client address and per account, one limit shared by both realms), and the credential
 * verifiers refuse a throttled caller before any lookup. The counters below are a readable
 * per-email/per-IP statistic, kept per realm, and nothing enforces them.
 *
 * Under maintenance mode redis is stopped, the increments are dropped and the counts read 0.
 */
abstract class Login_History_Abstract
{
    /**
     * How long a failure counter lives from its first failure: the horizon of
     * get_failed_attempts_count*().
     */
    public const FAILURE_WINDOW_SECONDS = 15 * 60;

    /**
     * Status vocabulary - one spelling for both realms.
     *
     * Framework producers (the staff class names; each portal twin writes the same status
     * into the portal table): RsxAuth::verify_credentials() / Portal_Auth::verify_credentials()
     * write SUCCESS, FAILED_PASSWORD and FAILED_NOT_FOUND, and FAILED_PASSWORD_RESET_REQUIRED
     * when a correct password belongs to an identity flagged password_reset_required;
     * Rsx_Two_Factor::verify_challenge() writes FAILED_2FA on a wrong second-factor answer (and
     * the SUCCESS row when the challenge passes); Rsx_Sso::handle_callback() writes FAILED_SSO
     * when a federated sign-in does not verify - a state that does not match, a token exchange
     * the provider refused, a provider account connected to no local identity;
     * Rsx_Two_Factor::verify_passkey_login() writes FAILED_PASSKEY when a passwordless passkey
     * sign-in does not verify (and the SUCCESS row when it does).
     *
     * FAILED_DISABLED is written by the framework wherever a sign-in is refused because the
     * realm will not admit the identity: for staff, no ACTIVE site membership
     * (users.is_enabled + sites.is_enabled, User_Model::is_active()) - RsxAuth::verify_credentials(),
     * Rsx_Two_Factor::verify_challenge() and verify_passkey_login(), and Rsx_Sso when
     * RsxAuth::login() refuses; for the portal, Portal_User_Model::can_login() or the declared
     * site refusing a second-factor, passkey or federated sign-in.
     *
     * FAILED_LOCKED has NO framework producer - account state an application defines is
     * APPLICATION vocabulary, so the app records that outcome itself through record_failure().
     * It is kept here so every app spells the same outcome the same way.
     *
     * FAILED_PASSWORD_RESET_REQUIRED is a ROW (record_refusal()), not a counter: it is reached
     * only after the password matched, so whoever caused it already holds the password, and it
     * never feeds the throttle.
     */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED_PASSWORD = 'failed_password';
    public const STATUS_FAILED_2FA = 'failed_2fa';
    public const STATUS_FAILED_LOCKED = 'failed_locked';
    public const STATUS_FAILED_DISABLED = 'failed_disabled';
    public const STATUS_FAILED_NOT_FOUND = 'failed_not_found';
    public const STATUS_FAILED_SSO = 'failed_sso';
    public const STATUS_FAILED_PASSKEY = 'failed_passkey';
    public const STATUS_FAILED_PASSWORD_RESET_REQUIRED = 'failed_password_reset_required';

    /**
     * Passkey enrollment ceremony outcomes - rows, written by the second-factor facades through
     * record_passkey_enrollment() (see that method). Not sign-ins and not failures in the
     * throttle's sense: nothing here feeds Login_Throttle.
     *
     * BEGUN      begin_passkey_registration() issued creation options.
     * ENROLLED   confirm_passkey_registration() stored the credential.
     * FAILED     the confirmation reached the server and did not verify (failure_reason
     *            carries why).
     * ABANDONED  begun and never confirmed: superseded by a new attempt, found expired by
     *            Session_Values_Cleanup_Service, or confirmed after the window closed.
     */
    public const STATUS_PASSKEY_ENROLL_BEGUN = 'passkey_enroll_begun';
    public const STATUS_PASSKEY_ENROLLED = 'passkey_enrolled';
    public const STATUS_PASSKEY_ENROLL_FAILED = 'passkey_enroll_failed';
    public const STATUS_PASSKEY_ENROLL_ABANDONED = 'passkey_enroll_abandoned';

    private const LIST_PAGE_MAX_LIMIT = 200;

    private const LIST_PAGE_DEFAULT_LIMIT = 25;

    // =========================================================================
    // THE REALM BINDING
    // =========================================================================

    /**
     * The realm's history table.
     */
    abstract protected static function __table(): string;

    /**
     * A query on the realm's history table. Each binding names its table literally here, so
     * every read and write of a system table is spelled out where the table is chosen.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    abstract protected static function __query();

    /**
     * The column naming the identity on a row - also the scope key list_page() requires.
     */
    abstract protected static function __identity_column(): string;

    /**
     * The prefix of this realm's failure-counter keys, so one address typed at both realms
     * keeps two statistics.
     */
    abstract protected static function __counter_prefix(): string;

    /**
     * The message of the warning line record_failure() writes.
     */
    abstract protected static function __failure_log_message(): string;

    /**
     * Columns a row carries beyond the shared ones, for this identity.
     *
     * @param int $identity_id
     * @return array
     */
    abstract protected static function __row_columns(int $identity_id): array;

    /**
     * The location a row records, or null.
     *
     * @param object $record
     * @return string|null
     */
    abstract protected static function __format_location(object $record): ?string;

    // =========================================================================
    // RECORDING
    // =========================================================================

    /**
     * Record a successful sign-in - a row.
     *
     * @param int $identity_id The identity that signed in
     * @param string $email The email used to sign in
     * @return void
     */
    public static function record_success(int $identity_id, string $email): void
    {
        self::__insert_row($identity_id, $email, self::STATUS_SUCCESS, null, self::client_context());
    }

    /**
     * Record a sign-in refused AFTER its credentials were proven - a row.
     *
     * The outcome a stranger cannot cause: the identity is known and the password matched, so
     * the refusal is written to the identity's history like a success, and it is not a failure
     * in the throttle's sense (nothing is counted). The framework's producer is the credential
     * verifier refusing an identity flagged password_reset_required
     * (STATUS_FAILED_PASSWORD_RESET_REQUIRED); an application may record its own post-password
     * refusals here (STATUS_FAILED_LOCKED for an account state it defines) when it wants them
     * in the history rather than in the failure counters.
     *
     * @param int $identity_id The identity refused
     * @param string $email The email used to sign in
     * @param string $status A STATUS_FAILED_* constant
     * @param string|null $reason Detail, null when none
     * @return void
     */
    public static function record_refusal(int $identity_id, string $email, string $status, ?string $reason = null): void
    {
        self::__insert_row($identity_id, $email, $status, $reason, self::client_context());
    }

    /**
     * Record one passkey enrollment ceremony outcome - a row.
     *
     * Called by the realm's second-factor facade and nothing else.
     *
     * $client_context is the {ip_address, user_agent} pair the row carries, as captured by
     * client_context() - passed when the outcome is recorded somewhere other than the request
     * that began the ceremony (an abandoned enrollment found by the hourly sweep is recorded
     * against the browser that began it, not against the CLI). Null = the current request.
     *
     * @param int $identity_id The identity enrolling
     * @param string $email Its email address
     * @param string $status One of the STATUS_PASSKEY_ENROLL* constants
     * @param string|null $reason Detail (why it failed or was abandoned), null when none
     * @param array|null $client_context {ip_address, user_agent}, null for the current request
     * @return void
     */
    public static function record_passkey_enrollment(
        int $identity_id,
        string $email,
        string $status,
        ?string $reason = null,
        ?array $client_context = null
    ): void {
        self::__insert_row($identity_id, $email, $status, $reason, $client_context ?? self::client_context());
    }

    /**
     * The client a history row is attributed to: {ip_address, user_agent}, read the way every
     * row written here reads them (ip_address null outside a request).
     *
     * @return array
     */
    public static function client_context(): array
    {
        return [
            'ip_address' => Session::get_client_ip(),
            'user_agent' => self::_get_user_agent(),
        ];
    }

    /**
     * Record a failed sign-in attempt
     *
     * Writes NO database row (see the class docblock - 2026-08-12 CR). It increments the
     * per-email and per-IP counters, both expiring FAILURE_WINDOW_SECONDS after the first
     * failure, feeds Login_Throttle, and logs one warning line carrying the whole event for
     * forensics.
     *
     * Safe to call with anything a stranger typed: nothing here is trusted, and no secret ever
     * reaches this function (the password is never a parameter).
     *
     * @param string $email The email attempted
     * @param string $status One of the STATUS_FAILED_* constants
     * @param string|null $reason Optional failure reason details
     * @param int|null $identity_id The identity, when known
     * @return void
     */
    public static function record_failure(
        string $email,
        string $status = self::STATUS_FAILED_PASSWORD,
        ?string $reason = null,
        ?int $identity_id = null
    ): void {
        // The per-IP counter of a caller with no client (CLI) is keyed 'CLI'.
        $ip_address = Session::get_client_ip() ?? 'CLI';

        Rsx_Counter::increment(self::_email_counter_key($email), self::FAILURE_WINDOW_SECONDS);
        Rsx_Counter::increment(self::_ip_counter_key($ip_address), self::FAILURE_WINDOW_SECONDS);

        // Every recorded failure feeds the framework throttle - password misses, unknown
        // addresses, and whatever outcomes the application records here itself (a second
        // factor, an account status) - against the client address and this account, one
        // limit for both realms. A CLI caller (no remote party) is not throttled.
        Login_Throttle::record_failure($email);

        $context = [
            'email' => $email,
            'ip_address' => $ip_address,
            'status' => $status,
            'reason' => $reason,
        ];

        if ($identity_id !== null) {
            $context[static::__identity_column()] = $identity_id;
        }

        Log::warning(static::__failure_log_message(), $context);
    }

    /**
     * Get count of failed attempts for an email within the failure window, in this realm.
     *
     * THE ANSWER IS BOUNDED BY FAILURE_WINDOW_SECONDS (15 minutes). Failures live in a
     * counter that expires with that window, so a request for a LARGER window returns the
     * window's count, not a longer history. $minutes is retained so existing call sites keep
     * working; shrink such a stat to the window rather than trusting a number that cannot mean
     * what it says.
     *
     * Returns 0 when the cache is unavailable (maintenance mode) - throttling fails open.
     *
     * @param string $email
     * @param int $minutes Requested time window in minutes (see above - the fixed window wins)
     * @return int Number of failed attempts inside the fixed window
     */
    public static function get_failed_attempts_count(string $email, int $minutes = 15): int
    {
        return Rsx_Counter::get(self::_email_counter_key($email));
    }

    /**
     * Get count of failed attempts from an IP within the failure window, in this realm.
     *
     * Same window semantics as get_failed_attempts_count().
     *
     * @param string $ip_address
     * @param int $minutes Requested time window in minutes (see above - the fixed window wins)
     * @return int Number of failed attempts inside the fixed window
     */
    public static function get_failed_attempts_count_by_ip(string $ip_address, int $minutes = 15): int
    {
        return Rsx_Counter::get(self::_ip_counter_key($ip_address));
    }

    // =========================================================================
    // READING
    // =========================================================================

    /**
     * The newest $limit rows of one identity's history.
     *
     * Successes, post-password refusals and passkey enrollment outcomes - failures are not
     * stored (see the class docblock). The status/label and failure_reason keys tell them
     * apart.
     *
     * @param int $identity_id
     * @param int $limit Maximum number of records to return
     * @return array Array of history records, each present_record()'s shape
     */
    public static function get_history_for_user(int $identity_id, int $limit = 10): array
    {
        return static::__query()
            ->where(static::__identity_column(), $identity_id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($record) => static::present_record($record))
            ->toArray();
    }

    /**
     * One PAGE of an identity's history, for a grid with column sorting, a search box and a
     * numbered pager. The standard display-page reader (Rsx_Page_Query;
     * rsx:man database_schema_architecture, READERS OVER SYSTEM TABLES).
     *
     *     $query = [
     *         'login_user_id' => 12,          // REQUIRED - whose history (portal: 'portal_user_id')
     *         'filter' => 'firefox',          // attempted email, IP address or user agent
     *         'sort'   => 'created_at',       // created_at | email | ip_address | status
     *         'order'  => 'desc',
     *         'offset' => 0,
     *         'limit'  => 25,                 // 1..200
     *     ];
     *
     * Answers ['rows' => [...], 'total' => int, 'offset' => int, 'limit' => int]; each row
     * is present_record()'s shape, and total counts every match before the page is cut.
     * The filter is applied in the query, so it searches the whole history and not the
     * page. Anything invalid throws InvalidArgumentException.
     *
     * Ungated, like every reader here: whether the caller may see this identity's history
     * is the calling endpoint's question.
     *
     * @param array $query
     * @return array{rows: array, total: int, offset: int, limit: int}
     */
    public static function list_page(array $query): array
    {
        $table = static::__table();
        $identity_column = static::__identity_column();
        $reader = class_basename(static::class) . '::list_page';

        // The names are what present_record() calls the values; the columns are the
        // framework's business.
        $sortable = [
            'created_at' => $table . '.created_at',
            'email' => $table . '.email_attempted',
            'ip_address' => 'ip.ip_address',
            'status' => $table . '.status',
        ];

        $parsed = Rsx_Page_Query::parse(
            $query,
            $reader,
            $sortable,
            'created_at',
            'desc',
            self::LIST_PAGE_MAX_LIMIT,
            self::LIST_PAGE_DEFAULT_LIMIT,
            [$identity_column]
        );

        // The address is joined for ordering; the filter reaches it through a subquery.
        $matching = static::__query()
            ->select($table . '.*')
            ->leftJoin('_ip_addresses as ip', 'ip.id', '=', $table . '.ip_address_id')
            ->where($table . '.' . $identity_column, Rsx_Page_Query::require_id($query, $identity_column, $reader));

        if ($parsed['filter'] !== '') {
            $like = Rsx_Page_Query::like($parsed['filter']);

            $matching->where(function ($where) use ($like, $table) {
                $where->where($table . '.email_attempted', 'like', $like)
                    ->orWhereIn($table . '.ip_address_id', fn ($addresses) => $addresses->select('id')->from('_ip_addresses')->where('ip_address', 'like', $like))
                    ->orWhereIn($table . '.user_agent_id', fn ($agents) => $agents->select('id')->from('_user_agents')->where('user_agent', 'like', $like));
            });
        }

        $total = (clone $matching)->count();

        // id breaks ties, so two rows that sort equal keep one order from page to page.
        $rows = $matching
            ->orderBy($parsed['sort_column'], $parsed['order'])
            ->orderBy($table . '.id', $parsed['order'])
            ->offset($parsed['offset'])
            ->limit($parsed['limit'])
            ->get()
            ->map(fn ($record) => static::present_record($record))
            ->all();

        return Rsx_Page_Query::result($rows, $total, $parsed);
    }

    /**
     * One history row in the shape get_history_for_user() answers: the attempted email, IP,
     * raw and parsed user agent, location, status + its label, failure reason and created_at.
     *
     * The one presenter for the realm's table, public so a reader that pages the rows itself
     * (the /_sys panel's per-identity sign-in grid) presents them identically.
     *
     * @param object $record A history row of this realm (a query-builder row object)
     * @return array
     */
    public static function present_record(object $record): array
    {
        $user_agent = Audit_Session::user_agent_string($record->user_agent_id === null ? null : (int) $record->user_agent_id);

        return [
            'id' => $record->id,
            'email' => $record->email_attempted,
            'ip_address' => Ip_Address::address($record->ip_address_id === null ? null : (int) $record->ip_address_id),
            'user_agent' => $user_agent,
            'user_agent_parsed' => User_Agent::parse($user_agent),
            'location' => static::__format_location($record),
            'status' => $record->status,
            'status_label' => self::_get_status_label($record->status),
            'failure_reason' => $record->failure_reason,
            'created_at' => $record->created_at,
        ];
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Write one row of this realm's history.
     */
    private static function __insert_row(
        int $identity_id,
        string $email,
        string $status,
        ?string $reason,
        array $client_context
    ): void {
        static::__query()->insert([
            static::__identity_column() => $identity_id,
            'email_attempted' => $email,
            'ip_address_id' => Ip_Address::id($client_context['ip_address'] ?? null),
            'user_agent_id' => Audit_Session::user_agent_id($client_context['user_agent'] ?? null),
            'status' => $status,
            'failure_reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            'created_at' => now(),
        ] + static::__row_columns($identity_id));
    }

    /**
     * Counter key for an email address.
     *
     * Hashed: the raw address would otherwise sit in redis for the window, readable by anything
     * that can list the keyspace. Normalized first so casing and stray whitespace cannot be used
     * to open a fresh window per attempt.
     *
     * @param string $email
     * @return string
     */
    private static function _email_counter_key(string $email): string
    {
        return static::__counter_prefix() . ':email:' . sha1(strtolower(trim($email)));
    }

    /**
     * Counter key for a client IP.
     *
     * @param string $ip_address
     * @return string
     */
    private static function _ip_counter_key(string $ip_address): string
    {
        return static::__counter_prefix() . ':ip:' . $ip_address;
    }

    /**
     * The client's user agent, or null when there is none (a command-line process has no client).
     *
     * @return string|null
     */
    private static function _get_user_agent(): ?string
    {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $user_agent ?: null;
    }

    /**
     * Get human-readable status label
     *
     * @param string $status
     * @return string
     */
    private static function _get_status_label(string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS => 'Success',
            self::STATUS_FAILED_PASSWORD => 'Failed - Invalid Password',
            self::STATUS_FAILED_2FA => 'Failed - 2FA Verification',
            self::STATUS_FAILED_LOCKED => 'Failed - Account Locked',
            self::STATUS_FAILED_DISABLED => 'Failed - Account Disabled',
            self::STATUS_FAILED_NOT_FOUND => 'Failed - User Not Found',
            self::STATUS_FAILED_SSO => 'Failed - SSO Sign-In',
            self::STATUS_FAILED_PASSKEY => 'Failed - Passkey Sign-In',
            self::STATUS_FAILED_PASSWORD_RESET_REQUIRED => 'Refused - Password Reset Required',
            self::STATUS_PASSKEY_ENROLL_BEGUN => 'Passkey Enrollment Started',
            self::STATUS_PASSKEY_ENROLLED => 'Passkey Enrolled',
            self::STATUS_PASSKEY_ENROLL_FAILED => 'Passkey Enrollment Failed',
            self::STATUS_PASSKEY_ENROLL_ABANDONED => 'Passkey Enrollment Abandoned',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
