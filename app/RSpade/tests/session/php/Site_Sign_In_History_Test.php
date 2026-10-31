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
use App\RSpade\Core\Session\Ip_Address;
use App\RSpade\Core\Session\Session_Cleanup_Service;
use App\RSpade\Core\Session\Site_Sign_In_History;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Site_Sign_In_History's readers: a membership's entries into its site, the impersonation
 * choice every reader makes the caller state, the row shape, and the retention sweep.
 *
 * The rows are WRITTEN by Session when a web sign-in enters a site, which the command line
 * cannot drive (its identity setters only declare an identity); the writer and its three
 * entry types are proven over real HTTP by session/http/sign_in_timezones.sh. Here the rows
 * are inserted as that writer inserts them.
 */
class Site_Sign_In_History_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 870001;

    private static function __insert(array $overrides = []): int
    {
        return (int) DB::table('_site_sign_ins')->insertGetId(array_merge([
            'site_id' => 1,
            'user_id' => self::USER_ID,
            'login_user_id' => 870101,
            'impersonator_login_user_id' => null,
            'user_session_id' => 870201,
            'entry_type' => Site_Sign_In_History::ENTRY_SIGN_IN,
            'ip_address_id' => Ip_Address::id('10.0.0.7'),
            'user_agent_id' => Audit_Session::user_agent_id('site-sign-in-test/1.0'),
            'timezone' => 'Asia/Kolkata',
            'timezone_source' => 'browser',
            'created_at' => now(),
        ], $overrides));
    }

    /**
     * ssi-01: newest first, one membership only, in the published shape.
     */
    public static function test_a_memberships_entries_are_read_back_newest_first()
    {
        $older = static::__insert(['created_at' => now()->subHour()]);
        $newer = static::__insert(['entry_type' => Site_Sign_In_History::ENTRY_SITE_SWITCH]);
        static::__insert(['user_id' => self::USER_ID + 1]);

        $history = Site_Sign_In_History::get_history_for_user(self::USER_ID, Site_Sign_In_History::IMPERSONATION_INCLUDE);

        static::__assert_equals([$newer, $older], array_column($history, 'id'), 'this membership only, newest first');

        $entry = $history[0];
        static::__assert_equals('site_switch', $entry['entry_type']);
        static::__assert_equals('Switched to this site', $entry['entry_type_label']);
        static::__assert_equals(1, $entry['site_id']);
        static::__assert_equals(870101, $entry['login_user_id']);
        static::__assert_false($entry['is_impersonation']);
        static::__assert_equals('10.0.0.7', $entry['ip_address']);
        static::__assert_equals('site-sign-in-test/1.0', $entry['user_agent']);
        static::__assert_array_has_key('summary', $entry['user_agent_parsed']);
        static::__assert_equals('Asia/Kolkata', $entry['timezone']);
        static::__assert_equals('browser', $entry['timezone_source']);
        static::__assert_not_empty($entry['created_at']);
    }

    /**
     * ssi-02: an impersonation is listed, left out or listed alone, as the caller states - a
     * moved impersonation (a site_switch with an impersonator) counts as one.
     */
    public static function test_the_impersonation_choice_is_honoured_and_required()
    {
        $own = static::__insert();
        $visit = static::__insert(['entry_type' => Site_Sign_In_History::ENTRY_IMPERSONATION, 'impersonator_login_user_id' => 870102]);
        $moved = static::__insert(['entry_type' => Site_Sign_In_History::ENTRY_SITE_SWITCH, 'impersonator_login_user_id' => 870102]);

        $ids = fn (string $choice) => array_column(Site_Sign_In_History::get_history_for_user(self::USER_ID, $choice), 'id');

        static::__assert_equals([$moved, $visit, $own], $ids(Site_Sign_In_History::IMPERSONATION_INCLUDE));
        static::__assert_equals([$own], $ids(Site_Sign_In_History::IMPERSONATION_EXCLUDE), 'the person\'s own sign-ins');
        static::__assert_equals([$moved, $visit], $ids(Site_Sign_In_History::IMPERSONATION_ONLY));

        $page = Site_Sign_In_History::list_page(['user_id' => self::USER_ID, 'impersonation' => 'exclude']);
        static::__assert_equals(1, $page['total']);
        static::__assert_equals($own, $page['rows'][0]['id']);

        $impersonated = Site_Sign_In_History::get_history_for_user(self::USER_ID, 'only')[1];
        static::__assert_true($impersonated['is_impersonation']);
        static::__assert_equals(870102, $impersonated['impersonator_login_user_id']);
        static::__assert_equals('Impersonated', $impersonated['entry_type_label']);

        static::__assert_throws(InvalidArgumentException::class, fn () => Site_Sign_In_History::get_history_for_user(self::USER_ID, 'all'), 'impersonation');
        static::__assert_throws(InvalidArgumentException::class, fn () => Site_Sign_In_History::list_page(['user_id' => self::USER_ID]), 'impersonation');
    }

    /**
     * ssi-03: the page reader's sorts, filter and validation.
     */
    public static function test_list_page_sorts_filters_and_validates()
    {
        static::__insert(['ip_address_id' => Ip_Address::id('10.0.0.1')]);
        static::__insert(['ip_address_id' => Ip_Address::id('10.0.0.9'), 'user_agent_id' => Audit_Session::user_agent_id('another-agent/2.0')]);

        $query = ['user_id' => self::USER_ID, 'impersonation' => 'include'];

        $by_ip = Site_Sign_In_History::list_page($query + ['sort' => 'ip_address', 'order' => 'asc']);
        static::__assert_equals(['10.0.0.1', '10.0.0.9'], array_column($by_ip['rows'], 'ip_address'));

        static::__assert_equals(1, Site_Sign_In_History::list_page($query + ['filter' => 'another-agent'])['total'], 'the filter reaches the user agent');
        static::__assert_equals(1, Site_Sign_In_History::list_page($query + ['filter' => '10.0.0.9'])['total'], 'and the address');

        static::__assert_throws(InvalidArgumentException::class, fn () => Site_Sign_In_History::list_page(['impersonation' => 'include']), 'user_id');
        static::__assert_throws(InvalidArgumentException::class, fn () => Site_Sign_In_History::list_page($query + ['sort' => 'timezone']));
    }

    /**
     * ssi-04: the login history's retention sweep prunes these rows on the same window.
     */
    public static function test_the_retention_sweep_prunes_site_sign_ins()
    {
        $retention_days = (int) config('rsx.sessions.login_history_retention_days');

        if ($retention_days <= 0) {
            static::__skip('rsx.sessions.login_history_retention_days keeps history forever on this install');

            return;
        }

        $aged = static::__insert(['created_at' => now()->subDays($retention_days + 1)]);
        $recent = static::__insert();

        static::__run_task_method(Session_Cleanup_Service::class, 'cleanup_login_history');

        static::__assert_false(DB::table('_site_sign_ins')->where('id', $aged)->exists(), 'past the window: pruned');
        static::__assert_true(DB::table('_site_sign_ins')->where('id', $recent)->exists(), 'inside it: kept');
    }
}
