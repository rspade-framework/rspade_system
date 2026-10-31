<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Session;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Page_Query;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Ip_Address;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Session\User_Agent;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Site_Sign_In_History - the record of a staff sign-in ENTERING A SITE, over `_site_sign_ins`.
 *
 * A staff sign-in is two events. The login IDENTITY signs in (Login_History - before any site
 * is chosen, so it has no site), and then the browser's session is assigned a site: at once
 * for an identity with one membership, after the site picker for one with several, again on
 * every switch to another site, and when an administrator begins an impersonation on one.
 * This class is the record of that second event, written by the framework itself at the one
 * place a session gets its site (Session) - an application never records here.
 *
 * It is the history a SITE'S OWN SCREENS read: every row names its site and the membership
 * (users.id) that entered it, so a reader scoped to a membership never shows what the same
 * person did on another tenant. The identity history stays a system record (the /_sys
 * panel's), because it is not about any one site.
 *
 * A ROW CARRIES:
 *
 *   entry_type   ENTRY_SIGN_IN       the sign-in was bound to this site (directly, or by
 *                                    choosing it right after signing in)
 *                ENTRY_SITE_SWITCH   a signed-in browser moved here from another site
 *                ENTRY_IMPERSONATION an administrator began impersonating the account here;
 *                                    impersonator_login_user_id names them
 *   timezone, timezone_source   the display zone the browser had at that moment
 *                (Rsx_Time::get_user_timezone() / get_user_timezone_source()) - for an
 *                impersonation, the zone the impersonation renders in
 *   ip_address, user_agent, user_session_id (a get_sessions_for_user() entry's id)
 *
 * NOT RECORDED: the rsx:debug harness (a TYPE_PLAYWRIGHT browser), an API key's session,
 * and a command or task (a virtual session has no browser). Rows are pruned on the login
 * history's retention window (rsx.sessions.login_history_retention_days).
 *
 * AN IMPERSONATION IS SOMEBODY ELSE ACTING. A screen showing a person THEIR OWN sign-in
 * history decides whether to list an administrator's visits; the readers make that a stated
 * choice (the `impersonation` argument), never a default.
 */
class Site_Sign_In_History
{
    public const ENTRY_SIGN_IN = 'sign_in';
    public const ENTRY_SITE_SWITCH = 'site_switch';
    public const ENTRY_IMPERSONATION = 'impersonation';

    /** List every row, impersonations included. */
    public const IMPERSONATION_INCLUDE = 'include';

    /** Leave out the rows an impersonation wrote - the person's own sign-ins only. */
    public const IMPERSONATION_EXCLUDE = 'exclude';

    /** Only the rows an impersonation wrote. */
    public const IMPERSONATION_ONLY = 'only';

    private const ENTRY_LABELS = [
        self::ENTRY_SIGN_IN => 'Signed in',
        self::ENTRY_SITE_SWITCH => 'Switched to this site',
        self::ENTRY_IMPERSONATION => 'Impersonated',
    ];

    private const LIST_PAGE_MAX_LIMIT = 200;

    private const LIST_PAGE_DEFAULT_LIMIT = 25;

    /**
     * Record a staff sign-in entering a site, for the browser making this request.
     *
     * FRAMEWORK INTERNAL - Session, the moment it points a browser at a user session on a site.
     *
     * @param int $user_session_id
     * @param int $site_id
     * @param int|null $user_id The membership (users.id)
     * @param int $login_user_id
     * @param int|null $impersonator_login_user_id
     * @param string $entry_type An ENTRY_* constant
     * @return void
     */
    public static function _record(
        int $user_session_id,
        int $site_id,
        ?int $user_id,
        int $login_user_id,
        ?int $impersonator_login_user_id,
        string $entry_type
    ): void {
        if (!isset(self::ENTRY_LABELS[$entry_type])) {
            shouldnt_happen("Site_Sign_In_History: unknown entry type '{$entry_type}'");
        }

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        DB::table('_site_sign_ins')->insert([
            'site_id' => $site_id,
            'user_id' => $user_id,
            'login_user_id' => $login_user_id,
            'impersonator_login_user_id' => $impersonator_login_user_id,
            'user_session_id' => $user_session_id,
            'entry_type' => $entry_type,
            'ip_address_id' => Ip_Address::id(Session::get_client_ip()),
            'user_agent_id' => Audit_Session::user_agent_id(is_string($user_agent) ? $user_agent : null),
            'timezone' => Rsx_Time::get_user_timezone(),
            'timezone_source' => Rsx_Time::get_user_timezone_source(),
            'created_at' => now(),
        ]);
    }

    /**
     * A membership's most recent entries into its site, newest first, each present_record()'s
     * shape.
     *
     * Ungated, like every reader here: whether the caller may see this membership's history
     * is the calling endpoint's question.
     *
     * @param int $user_id The membership (users.id)
     * @param string $impersonation IMPERSONATION_INCLUDE | _EXCLUDE | _ONLY
     * @param int $limit Maximum number of records to return
     * @return array
     */
    public static function get_history_for_user(int $user_id, string $impersonation, int $limit = 10): array
    {
        $query = DB::table('_site_sign_ins')->where('user_id', $user_id);
        self::__apply_impersonation($query, $impersonation, 'Site_Sign_In_History::get_history_for_user');

        return $query
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($record) => self::present_record($record))
            ->toArray();
    }

    /**
     * One PAGE of a membership's entries into its site, for a grid with column sorting, a
     * search box and a numbered pager. The standard display-page reader (Rsx_Page_Query;
     * rsx:man database_schema_architecture, READERS OVER SYSTEM TABLES).
     *
     *     $query = [
     *         'user_id' => 12,                // REQUIRED - whose history (a membership)
     *         'impersonation' => 'exclude',   // REQUIRED - include | exclude | only
     *         'filter' => 'firefox',          // IP address or user agent
     *         'sort'   => 'created_at',       // created_at | ip_address | entry_type
     *         'order'  => 'desc',
     *         'offset' => 0,
     *         'limit'  => 25,                 // 1..200
     *     ];
     *
     * Answers ['rows' => [...], 'total' => int, 'offset' => int, 'limit' => int]; each row
     * is present_record()'s shape. Anything invalid throws InvalidArgumentException.
     *
     * @param array $query
     * @return array{rows: array, total: int, offset: int, limit: int}
     */
    public static function list_page(array $query): array
    {
        $reader = 'Site_Sign_In_History::list_page';

        $parsed = Rsx_Page_Query::parse(
            $query,
            $reader,
            [
                'created_at' => 'h.created_at',
                'ip_address' => 'ip.ip_address',
                'entry_type' => 'h.entry_type',
            ],
            'created_at',
            'desc',
            self::LIST_PAGE_MAX_LIMIT,
            self::LIST_PAGE_DEFAULT_LIMIT,
            ['user_id', 'impersonation']
        );

        $matching = DB::table('_site_sign_ins as h')
            ->select('h.*')
            ->leftJoin('_ip_addresses as ip', 'ip.id', '=', 'h.ip_address_id')
            ->where('h.user_id', Rsx_Page_Query::require_id($query, 'user_id', $reader));

        self::__apply_impersonation($matching, $query['impersonation'] ?? null, $reader, 'h.');

        if ($parsed['filter'] !== '') {
            $like = Rsx_Page_Query::like($parsed['filter']);

            $matching->where(function ($where) use ($like) {
                $where->whereIn('h.ip_address_id', fn ($addresses) => $addresses->select('id')->from('_ip_addresses')->where('ip_address', 'like', $like))
                    ->orWhereIn('h.user_agent_id', fn ($agents) => $agents->select('id')->from('_user_agents')->where('user_agent', 'like', $like));
            });
        }

        $total = (clone $matching)->count();

        // id breaks ties, so two rows that sort equal keep one order from page to page.
        $rows = $matching
            ->orderBy($parsed['sort_column'], $parsed['order'])
            ->orderBy('h.id', $parsed['order'])
            ->offset($parsed['offset'])
            ->limit($parsed['limit'])
            ->get()
            ->map(fn ($record) => self::present_record($record))
            ->all();

        return Rsx_Page_Query::result($rows, $total, $parsed);
    }

    /**
     * One row in the shape the readers answer: id, site_id, user_id, login_user_id,
     * impersonator_login_user_id, is_impersonation, entry_type + entry_type_label,
     * user_session_id, ip_address, raw and parsed user agent, timezone, timezone_source and
     * created_at (ISO).
     *
     * @param object $record A _site_sign_ins row
     * @return array
     */
    public static function present_record(object $record): array
    {
        $int = fn ($value) => $value === null ? null : (int) $value;
        $user_agent = Audit_Session::user_agent_string($int($record->user_agent_id));

        return [
            'id' => (int) $record->id,
            'site_id' => (int) $record->site_id,
            'user_id' => $int($record->user_id),
            'login_user_id' => (int) $record->login_user_id,
            'impersonator_login_user_id' => $int($record->impersonator_login_user_id),
            'is_impersonation' => $record->impersonator_login_user_id !== null,
            'entry_type' => $record->entry_type,
            'entry_type_label' => self::ENTRY_LABELS[$record->entry_type] ?? $record->entry_type,
            'user_session_id' => (int) $record->user_session_id,
            'ip_address' => Ip_Address::address($int($record->ip_address_id)),
            'user_agent' => $user_agent,
            'user_agent_parsed' => User_Agent::parse($user_agent),
            'timezone' => $record->timezone,
            'timezone_source' => $record->timezone_source,
            'created_at' => Rsx_Time::to_iso($record->created_at),
        ];
    }

    /**
     * Narrow a query by the impersonation choice, refusing anything but the three values.
     *
     * A row an impersonation wrote is any row with an impersonator - an impersonation moved
     * to another site included, whose entry_type is ENTRY_SITE_SWITCH.
     */
    private static function __apply_impersonation($query, $impersonation, string $reader, string $prefix = ''): void
    {
        switch ($impersonation) {
            case self::IMPERSONATION_INCLUDE:
                return;
            case self::IMPERSONATION_EXCLUDE:
                $query->whereNull($prefix . 'impersonator_login_user_id');

                return;
            case self::IMPERSONATION_ONLY:
                $query->whereNotNull($prefix . 'impersonator_login_user_id');

                return;
        }

        throw new \InvalidArgumentException(
            "{$reader}: impersonation is required and must be 'include', 'exclude' or 'only' - whether a screen lists an administrator's impersonations is its own decision."
        );
    }
}
