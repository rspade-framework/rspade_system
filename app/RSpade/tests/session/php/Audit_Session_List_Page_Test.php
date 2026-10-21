<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Audit_Session::list_page_for_user_session() - the display-page reader over the audit
 * sessions of ONE user session ("every address and browser this sign-in acted from"), held to
 * the Rsx_Page_Query standard: one page plus the total of every match, the user session as a
 * required scope, published sorts only, a filter over the address and the user agent.
 *
 * The rows are minted through Audit_Session::_id_for_context() with contexts of the test's
 * own (the test process runs a virtual session and records none) and their clocks set
 * directly, so the order is the test's to choose.
 */
class Audit_Session_List_Page_Test extends Rsx_Test_Abstract
{
    private const USER_SESSION = 870001;

    private const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';

    /**
     * Mint an audit session of $user_session_id at $ip with $user_agent, last seen at
     * $last_seen_at (first seen a day before); returns its id.
     */
    private static function __audit(int $user_session_id, string $ip, string $last_seen_at, string $user_agent = self::FIREFOX): int
    {
        $id = Audit_Session::_id_for_context([
            'site_id' => 1,
            'login_user_id' => 501,
            'portal_user_id' => null,
            'portal_site_id' => null,
            'impersonator_login_user_id' => null,
            'impersonator_user_id' => null,
            'ip_address' => $ip,
            'session_id' => 900001,
            'user_session_id' => $user_session_id,
            'user_agent_id' => Audit_Session::user_agent_id($user_agent),
            'session_type_id' => Session::TYPE_WEB,
        ]);

        DB::table('_audit_sessions')->where('id', $id)->update([
            'last_seen_at' => $last_seen_at,
            'first_seen_at' => date('Y-m-d H:i:s', strtotime($last_seen_at) - 86400),
        ]);

        return $id;
    }

    /**
     * sess-auditpage-01 - one page plus the total; rows in describe()'s shape plus
     * device_summary; most recently seen first; the user session bounds the answer.
     */
    public static function test_a_page_of_one_user_sessions_audit_sessions()
    {
        $a = static::__audit(self::USER_SESSION, '198.51.100.1', '2026-03-01 10:00:00');
        $b = static::__audit(self::USER_SESSION, '198.51.100.2', '2026-03-03 10:00:00');
        $c = static::__audit(self::USER_SESSION, '198.51.100.3', '2026-03-02 10:00:00');
        static::__audit(self::USER_SESSION + 1, '198.51.100.4', '2026-03-04 10:00:00');

        $page = Audit_Session::list_page_for_user_session(['user_session_id' => self::USER_SESSION, 'limit' => 2]);

        static::__assert_equals(['rows', 'total', 'offset', 'limit'], array_keys($page), 'the display-page answer');
        static::__assert_equals(3, $page['total'], 'every audit session of the user session, and no other');
        static::__assert_equals([$b, $c], array_column($page['rows'], 'id'), 'most recently seen first, cut at the limit');

        $row = $page['rows'][0];
        $described = Audit_Session::describe($b);
        $described['device_summary'] = 'Firefox on Windows';
        static::__assert_equals($described, $row, 'describe()\'s shape plus device_summary');
        static::__assert_true(str_ends_with($row['last_seen_at'], 'Z'), 'datetimes as ISO UTC');

        $next = Audit_Session::list_page_for_user_session(['user_session_id' => self::USER_SESSION, 'limit' => 2, 'offset' => 2]);
        static::__assert_equals([$a], array_column($next['rows'], 'id'), 'the next page');
    }

    /**
     * sess-auditpage-02 - the published sorts; the filter searches the address and the user
     * agent over the whole set, its wildcards literal.
     */
    public static function test_sorts_and_filter()
    {
        $a = static::__audit(self::USER_SESSION, '198.51.100.9', '2026-03-01 10:00:00');
        $b = static::__audit(self::USER_SESSION, '198.51.100.1', '2026-03-03 10:00:00', 'curl/8.5.0');

        $query = ['user_session_id' => self::USER_SESSION];

        static::__assert_equals([$a, $b], array_column(Audit_Session::list_page_for_user_session($query + ['sort' => 'ip_address'])['rows'], 'id'), 'by address, descending (.9 before .1)');
        static::__assert_equals([$a, $b], array_column(Audit_Session::list_page_for_user_session($query + ['sort' => 'first_seen_at', 'order' => 'asc'])['rows'], 'id'), 'by first seen, ascending');

        static::__assert_equals([$a], array_column(Audit_Session::list_page_for_user_session($query + ['filter' => '100.9'])['rows'], 'id'), 'an address');
        static::__assert_equals([$b], array_column(Audit_Session::list_page_for_user_session($query + ['filter' => 'curl'])['rows'], 'id'), 'a user agent');
        static::__assert_equals(0, Audit_Session::list_page_for_user_session($query + ['filter' => '%'])['total'], 'a wildcard is literal');
    }

    /**
     * sess-auditpage-03 - the user session is required, and an unpublished sort or an
     * unknown key is refused.
     */
    public static function test_the_query_is_validated()
    {
        $bad = [
            'no user session' => [],
            'a zero user session' => ['user_session_id' => 0],
            'an unpublished sort' => ['user_session_id' => self::USER_SESSION, 'sort' => 'tuple_hash'],
            'an unknown key' => ['user_session_id' => self::USER_SESSION, 'login_user_id' => 1],
            'a limit past the maximum' => ['user_session_id' => self::USER_SESSION, 'limit' => 201],
        ];

        foreach ($bad as $label => $query) {
            $thrown = false;

            try {
                Audit_Session::list_page_for_user_session($query);
            } catch (InvalidArgumentException $e) {
                $thrown = true;
            }

            static::__assert_true($thrown, "{$label} is refused");
        }
    }
}
