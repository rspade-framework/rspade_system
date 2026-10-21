<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Ip_Address;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\PortalUsers\_Sys_Portal_Users_Controller;

/**
 * The Portal Users screen's endpoints, all reads: the portal-user grid across every site
 * (filters, search, site names), one portal user's facts, its live portal sessions, its
 * paged portal sign-in history read through Portal_Login_History::list_page(), and a
 * security answer that carries no secret material.
 *
 * The test runs as user 1, whose session is on site 1; every portal user here lives on a
 * site of its own, so each answer proves the read lifted the site scope. Portal users,
 * sessions, history rows and credentials are written with DB::table() - the site-scoped
 * model's creating hook would force the acting session's site onto a portal user.
 */
class Sys_Portal_Users_Controller_Test extends Rsx_Test_Abstract
{
    private const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';

    public static function setup()
    {
        static::__acting_as_user(1);
    }

    private static function __site(string $name): Site_Model
    {
        $site = new Site_Model();
        $site->slug = 'sys-portal-users-' . uniqid();
        $site->name = $name;
        $site->is_enabled = true;
        $site->save();

        return $site;
    }

    /**
     * A portal user on $site_id; returns its id.
     */
    private static function __portal_user(int $site_id, string $email, array $columns = []): int
    {
        return (int) DB::table('portal_users')->insertGetId(array_merge([
            'site_id' => $site_id,
            'email' => $email,
            'password' => 'not-a-hash',
            'status_id' => Portal_User_Model::STATUS_ACTIVE,
            'is_verified' => 1,
        ], $columns));
    }

    private static function __endpoint(string $method, array $params = [])
    {
        return _Sys_Portal_Users_Controller::$method(Request::create('/'), $params);
    }

    private static function __grid(array $params = []): array
    {
        $response = static::__endpoint('datagrid_fetch', array_merge(['per_page' => 100], $params));

        return array_column($response['records'], null, 'id');
    }

    private static function __assert_error(string $code, $response, string $message): Error_Response
    {
        static::__assert_true($response instanceof Error_Response, "{$message}: expected an error response");
        static::__assert_equals($code, $response->get_error_code(), $message);

        return $response;
    }

    /**
     * RP-PORTAL-USERS-01 - The grid lists live portal users on every site (never a
     * soft-deleted one) with the site name, status word and label, and no password or
     * metadata; the status and site filters and the email search narrow it, an unknown
     * filter value filters nothing; status_options and site_options answer.
     */
    public static function test_grid_lists_every_site_with_filters_and_search()
    {
        $tag = uniqid();
        $site_a = static::__site('Sys Portal Grid A');
        $site_b = static::__site('Sys Portal Grid B');

        $on_a = static::__portal_user($site_a->id, "sys-portal-a-{$tag}@example.com", ['last_login' => '2026-01-02 03:04:05', 'metadata' => json_encode(['secret' => 'SYSPORTALMETA'])]);
        $on_b = static::__portal_user($site_b->id, "sys-portal-b-{$tag}@example.com", ['password_reset_required' => 1]);
        $suspended = static::__portal_user($site_b->id, "sys-portal-suspended-{$tag}@example.com", ['status_id' => Portal_User_Model::STATUS_SUSPENDED, 'is_verified' => 0]);
        $deleted = static::__portal_user($site_a->id, "sys-portal-deleted-{$tag}@example.com", ['deleted_at' => now()]);

        $rows = static::__grid(['filter' => $tag]);

        static::__assert_equals([$suspended, $on_b, $on_a], array_keys($rows), 'the live portal users matching the search, on every site, newest first');
        static::__assert_false(isset($rows[$deleted]), 'a soft-deleted portal user is not listed');
        static::__assert_equals($site_a->id, $rows[$on_a]['site_id'], 'the site id');
        static::__assert_equals('Sys Portal Grid A', $rows[$on_a]['site_name'], 'the site name');
        static::__assert_equals("sys-portal-a-{$tag}@example.com", $rows[$on_a]['printed_name'], 'the printed name is the email');
        static::__assert_equals('suspended', $rows[$suspended]['status'], 'the status word');
        static::__assert_equals('Suspended', $rows[$suspended]['status_label'], 'the status label');
        static::__assert_false($rows[$suspended]['is_verified'], 'verified, as a boolean');
        static::__assert_true($rows[$on_b]['password_reset_required'], 'a required password reset');
        static::__assert_true(str_ends_with($rows[$on_a]['last_login'], 'Z'), 'last_login leaves as ISO UTC');
        static::__assert_null($rows[$on_b]['last_login'], 'never signed in');
        foreach (['password', 'metadata'] as $key) {
            static::__assert_false(array_key_exists($key, $rows[$on_a]), "{$key} never leaves");
        }

        static::__assert_equals([$suspended], array_keys(static::__grid(['filter' => $tag, 'status' => 'suspended'])), 'the status filter');
        static::__assert_equals([$suspended, $on_b], array_keys(static::__grid(['filter' => $tag, 'site' => (string) $site_b->id])), 'the site filter');
        static::__assert_equals(3, count(static::__grid(['filter' => $tag, 'status' => 'bogus', 'site' => 'abc'])), 'unknown filter values filter nothing');
        static::__assert_equals(
            DB::table('portal_users')->whereNull('deleted_at')->count(),
            static::__endpoint('datagrid_fetch')['total'],
            'the total is every live portal user, on every site'
        );

        static::__assert_equals(['active', 'inactive', 'suspended'], array_column(static::__endpoint('status_options'), 'value'), 'the status words in order');
        $site_values = array_column(static::__endpoint('site_options'), 'value');
        static::__assert_true(in_array($site_a->id, $site_values, true) && in_array($site_b->id, $site_values, true), 'every site with a portal user is a site option');
    }

    /**
     * RP-PORTAL-USERS-02 - detail answers the portal user's facts on another site, with
     * can_login (active AND verified) and the required password reset, and no password;
     * a soft-deleted site has no Sites screen; not_found for a soft-deleted, missing, zero
     * and non-numeric id on detail, sessions, signins_fetch and security.
     */
    public static function test_detail_and_not_found()
    {
        $tag = uniqid();
        $site = static::__site('Sys Portal Detail');
        $gone = static::__site('Sys Portal Detail Gone');
        $gone->delete();

        $id = static::__portal_user($site->id, "sys-portal-detail-{$tag}@example.com", ['last_login' => '2026-01-02 03:04:05', 'password_reset_required' => 1]);
        $unverified = static::__portal_user($gone->id, "sys-portal-unverified-{$tag}@example.com", ['is_verified' => 0]);

        $user = static::__endpoint('detail', ['id' => $id])['user'];

        static::__assert_equals($id, $user['id'], 'the portal user is answered');
        static::__assert_equals($site->id, $user['site_id'], 'its site');
        static::__assert_equals('Sys Portal Detail', $user['site_name'], 'the site name');
        static::__assert_true($user['has_site_screen'], 'a live tenant has a Sites screen');
        static::__assert_equals('active', $user['status'], 'its status word');
        static::__assert_true($user['can_login'], 'active and verified can sign in');
        static::__assert_true($user['password_reset_required'], 'the required password reset');
        static::__assert_true(str_ends_with($user['last_login'], 'Z'), 'last_login leaves as ISO UTC');
        foreach (['password', 'metadata'] as $key) {
            static::__assert_false(array_key_exists($key, $user), "{$key} never leaves");
        }

        $other = static::__endpoint('detail', ['id' => $unverified])['user'];
        static::__assert_false($other['can_login'], 'an unverified portal user cannot sign in');
        static::__assert_false($other['has_site_screen'], 'a soft-deleted site has no Sites screen');

        $deleted = static::__portal_user($site->id, "sys-portal-gone-{$tag}@example.com", ['deleted_at' => now()]);
        $missing = (int) DB::table('portal_users')->max('id') + 1000;

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => $deleted]), 'a soft-deleted portal user');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => $missing]), 'a missing id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => 0]), 'id 0');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => 'abc']), 'a non-numeric id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('sessions', ['id' => $deleted]), 'sessions of a soft-deleted portal user');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('signins_fetch', ['portal_user_id' => $missing]), 'sign-ins of a missing portal user');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('security', ['id' => $missing]), 'security of a missing portal user');
    }

    /**
     * A browser signed in to the portal as $portal_user_id on $site_id, at a fixed address and
     * agent; returns its portal user session id.
     */
    private static function __portal_session(int $portal_user_id, int $site_id, array $portal = [], array $options = []): int
    {
        $fixture = static::__insert_session_fixture(array_merge($options, [
            'portal' => array_merge([
                'portal_user_id' => $portal_user_id,
                'portal_site_id' => $site_id,
                'signed_in_at' => '2026-02-03 04:00:00',
                'last_active_at' => '2026-02-03 04:05:06',
            ], $portal),
        ]));

        DB::table('_sessions')->where('id', $fixture['id'])->update([
            'ip_address_id' => Ip_Address::id('203.0.113.8'),
            'user_agent_id' => Audit_Session::user_agent_id(self::FIREFOX),
        ]);

        return $fixture['portal_user_session_id'];
    }

    /**
     * RP-PORTAL-USERS-03 - sessions answers where the portal user is signed in from
     * Portal_Session::get_sessions_for_user(): signed in AND recently signed out, most
     * recently active first, a View as Client row included and marked with the staff
     * member's login identity and email, a signed-out row with its reason, the rsx:debug
     * harness's browser never; datetimes as ISO UTC, and no token.
     */
    public static function test_sessions_shape()
    {
        $tag = uniqid();
        $site = static::__site('Sys Portal Sessions');
        $id = static::__portal_user($site->id, "sys-portal-sessions-{$tag}@example.com");

        $plain = static::__portal_session($id, $site->id);
        $viewed = static::__portal_session($id, $site->id, ['last_active_at' => '2026-02-04 04:05:06', 'impersonator_user_id' => 1]);
        $signed_out = static::__portal_session($id, $site->id, [
            'signed_out_at' => now()->subHour(),
            'sign_out_reason_id' => Session::SIGN_OUT_TIMED_OUT_IDLE,
            'last_active_at' => now()->subHour(),
        ]);
        static::__portal_session($id, $site->id, ['last_active_at' => now()], ['type_id' => Session::TYPE_PLAYWRIGHT]);

        $rows = array_column(static::__endpoint('sessions', ['id' => $id])['sessions'], null, 'id');

        static::__assert_equals([$signed_out, $viewed, $plain], array_keys($rows), 'signed in and signed out, most recently active first; the harness browser never');
        static::__assert_equals('203.0.113.8', $rows[$plain]['ip_address'], 'the IP');
        static::__assert_equals('Firefox on Windows', $rows[$plain]['device_summary'], 'the parsed device');
        static::__assert_true(str_ends_with($rows[$plain]['last_active'], 'Z'), 'last_active leaves as ISO UTC');
        static::__assert_true(str_ends_with($rows[$plain]['signed_in_at'], 'Z'), 'signed_in_at leaves as ISO UTC');
        static::__assert_true($rows[$plain]['is_signed_in'], 'signed in');
        static::__assert_equals(1, $rows[$plain]['sign_in_count'], 'the sign-in count');
        static::__assert_equals('Web', $rows[$plain]['type_label'], 'the browser type');
        static::__assert_false($rows[$plain]['is_current'], 'not the session reading it');
        static::__assert_false($rows[$plain]['is_impersonation'], 'the portal user\'s own browser');
        static::__assert_true($rows[$viewed]['is_impersonation'], 'a staff member viewing as this client');
        static::__assert_equals(1, $rows[$viewed]['impersonator_user_id'], 'the staff membership viewing');

        $viewer = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_equals((int) $viewer->login_user_id, $rows[$viewed]['impersonator_login_user_id'], 'its login identity, the Users screen\'s record');
        static::__assert_equals($viewer->email, $rows[$viewed]['impersonator_email'], 'and its email');

        static::__assert_false($rows[$signed_out]['is_signed_in'], 'a signed-out session is listed');
        static::__assert_equals('Signed out after inactivity', $rows[$signed_out]['sign_out_reason'], 'with its reason');
        static::__assert_true(str_ends_with($rows[$signed_out]['signed_out_at'], 'Z'), 'and when, as ISO UTC');

        foreach (['session_token', 'csrf_token', 'session_id'] as $key) {
            static::__assert_false(array_key_exists($key, $rows[$plain]), "{$key} never leaves");
        }
    }

    /**
     * RP-PORTAL-USERS-06 - session_detail answers one listed session of the portal user (the
     * entry sessions lists) with the portal user's id and email; audit_sessions_fetch pages
     * that session's audit sessions only (most recently seen first, the portal site named),
     * through Audit_Session::list_page_for_user_session(). Another portal user's session, a
     * harness browser, a malformed id and a missing portal user are not_found on both.
     */
    public static function test_session_detail_and_activity()
    {
        $tag = uniqid();
        $site = static::__site('Sys Portal Session Detail');
        $id = static::__portal_user($site->id, "sys-portal-detail-{$tag}@example.com");
        $other = static::__portal_user($site->id, "sys-portal-detail-other-{$tag}@example.com");

        $mine = static::__portal_session($id, $site->id);
        $theirs = static::__portal_session($other, $site->id);
        $harness = static::__portal_session($id, $site->id, [], ['type_id' => Session::TYPE_PLAYWRIGHT]);

        $detail = static::__endpoint('session_detail', ['id' => $id, 'user_session_id' => $mine]);
        static::__assert_equals(['id' => $id, 'email' => "sys-portal-detail-{$tag}@example.com"], $detail['user'], 'the portal user');
        static::__assert_equals($mine, $detail['session']['id'], 'the session');
        static::__assert_equals('Firefox on Windows', $detail['session']['device_summary'], 'as the list shows it');

        $context = fn (int $user_session_id, string $ip) => [
            'site_id' => null, 'login_user_id' => null, 'portal_user_id' => $id, 'portal_site_id' => $site->id,
            'impersonator_login_user_id' => null, 'impersonator_user_id' => null, 'ip_address' => $ip,
            'session_id' => null, 'user_session_id' => $user_session_id,
            'user_agent_id' => Audit_Session::user_agent_id(self::FIREFOX), 'session_type_id' => Session::TYPE_WEB,
        ];
        $older = Audit_Session::_id_for_context($context($mine, '198.51.100.1'));
        $newer = Audit_Session::_id_for_context($context($mine, '198.51.100.2'));
        Audit_Session::_id_for_context($context($theirs, '198.51.100.3'));
        DB::table('_audit_sessions')->where('id', $older)->update(['last_seen_at' => '2026-02-01 00:00:00']);
        DB::table('_audit_sessions')->where('id', $newer)->update(['last_seen_at' => '2026-02-02 00:00:00']);

        $page = static::__endpoint('audit_sessions_fetch', ['portal_user_id' => $id, 'user_session_id' => $mine]);
        static::__assert_equals([$newer, $older], array_column($page['records'], 'id'), 'this session\'s audit sessions only, most recently seen first');
        static::__assert_equals(2, $page['total'], 'the total');
        static::__assert_equals($site->id, $page['records'][0]['site_id'], 'the portal site');
        static::__assert_equals('Sys Portal Session Detail', $page['records'][0]['site_name'], 'named');

        foreach (['session_detail' => 'id', 'audit_sessions_fetch' => 'portal_user_id'] as $endpoint => $key) {
            static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint($endpoint, [$key => $id, 'user_session_id' => $theirs]), "{$endpoint}: another portal user's session");
            static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint($endpoint, [$key => $id, 'user_session_id' => $harness]), "{$endpoint}: a harness browser");
            static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint($endpoint, [$key => $id, 'user_session_id' => 'x']), "{$endpoint}: a malformed id");
            static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint($endpoint, [$key => 0, 'user_session_id' => $mine]), "{$endpoint}: a missing portal user");
        }
    }

    /**
     * RP-PORTAL-USERS-04 - The sign-in grid pages the WHOLE portal history through
     * Portal_Login_History::list_page() (25 + 5 of 30 rows), newest first, each row in
     * present_record()'s shape with ISO created_at and its badge tone; another portal
     * user's rows never appear; search on the IP; a sort key the reader does not publish
     * sorts by created_at; a page past the end is the last page.
     */
    public static function test_signins_are_paged_through_the_reader()
    {
        $tag = uniqid();
        $site = static::__site('Sys Portal Signins');
        $id = static::__portal_user($site->id, "sys-portal-signins-{$tag}@example.com");
        $stranger = static::__portal_user($site->id, "sys-portal-stranger-{$tag}@example.com");

        $row = fn (int $portal_user_id, int $i, string $status, string $ip) => [
            'portal_user_id' => $portal_user_id,
            'site_id' => $site->id,
            'email_attempted' => "sys-portal-signins-{$tag}@example.com",
            'ip_address_id' => Ip_Address::id($ip),
            'user_agent_id' => Audit_Session::user_agent_id(self::FIREFOX),
            'status' => $status,
            'created_at' => sprintf('2026-03-01 00:00:%02d', $i),
        ];

        for ($i = 1; $i <= 30; $i++) {
            DB::table('_portal_login_history')->insert($row(
                $id,
                $i,
                $i === 29 ? Login_History::STATUS_FAILED_PASSWORD_RESET_REQUIRED : Login_History::STATUS_SUCCESS,
                $i === 30 ? '198.51.100.30' : '192.0.2.' . $i
            ));
        }
        DB::table('_portal_login_history')->insert($row($stranger, 31, Login_History::STATUS_SUCCESS, '198.51.100.31'));

        $first = static::__endpoint('signins_fetch', ['portal_user_id' => $id, 'per_page' => 25]);
        $second = static::__endpoint('signins_fetch', ['portal_user_id' => $id, 'per_page' => 25, 'page' => 2]);

        static::__assert_equals(30, $first['total'], 'every row of this portal user is counted, none of the stranger\'s');
        static::__assert_equals(2, $first['total_pages'], 'across two pages');
        static::__assert_equals(25, count($first['records']), 'a full first page');
        static::__assert_equals(5, count($second['records']), 'the rest on the second');
        static::__assert_equals(['created_at', 'desc'], [$first['sort'], $first['order']], 'newest first by default');
        static::__assert_equals('198.51.100.30', $first['records'][0]['ip_address'], 'the newest row leads');

        $top = $first['records'][0];
        foreach (['id', 'email', 'ip_address', 'user_agent', 'user_agent_parsed', 'location', 'status', 'status_label', 'failure_reason', 'created_at', 'tone'] as $key) {
            static::__assert_array_has_key($key, $top, "the presented row carries {$key}");
        }
        static::__assert_equals('Success', $top['status_label'], 'the status label');
        static::__assert_equals('ok', $top['tone'], 'a success is drawn ok');
        static::__assert_equals('fail', $first['records'][1]['tone'], 'a refusal is drawn as a failure');
        static::__assert_null($top['location'], 'the portal table records no location');
        static::__assert_equals('Firefox on Windows', $top['user_agent_parsed']['summary'], 'the parsed user agent');
        static::__assert_true(str_ends_with($top['created_at'], 'Z'), 'created_at leaves as ISO UTC');

        $searched = static::__endpoint('signins_fetch', ['portal_user_id' => $id, 'filter' => '198.51.100']);
        static::__assert_equals(1, $searched['total'], 'search matches the IP, of this portal user only');

        $unknown_sort = static::__endpoint('signins_fetch', ['portal_user_id' => $id, 'sort' => 'portal_user_id', 'order' => 'sideways']);
        static::__assert_equals(['created_at', 'desc'], [$unknown_sort['sort'], $unknown_sort['order']], 'an unpublished sort and a bad order fall back to the default');

        $past_end = static::__endpoint('signins_fetch', ['portal_user_id' => $id, 'per_page' => 25, 'page' => 9]);
        static::__assert_equals(2, $past_end['page'], 'a page past the end is the last page');
        static::__assert_equals(5, count($past_end['records']), 'with the last page\'s rows');
    }

    /**
     * RP-PORTAL-USERS-05 - security answers the portal factor metadata, the 2FA state, the
     * recovery-code COUNT and the connected provider from the portal readers - and no
     * secret: no seed, key, counter, hash or provider user key, by key and by value.
     */
    public static function test_security_exposes_no_secret_material()
    {
        $tag = uniqid();
        $site = static::__site('Sys Portal Security');
        $id = static::__portal_user($site->id, "sys-portal-security-{$tag}@example.com");
        $now = now();

        $credential = fn (array $columns) => DB::table('_portal_two_factor_credentials')->insert(array_merge([
            'portal_user_id' => $id,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $columns));

        $credential(['type_id' => 1, 'label' => 'Phone', 'secret' => "SYSPORTALTOTPSEED{$tag}"]);
        $credential(['type_id' => 2, 'label' => 'Laptop key', 'secret' => "SYSPORTALPASSKEYPUB{$tag}", 'credential_key' => "SYSPORTALCREDKEY{$tag}", 'counter' => 7]);
        $credential(['type_id' => 3, 'secret' => "SYSPORTALRECOVERYHASH{$tag}"]);
        $credential(['type_id' => 1, 'label' => 'Unconfirmed', 'secret' => "SYSPORTALPENDINGSEED{$tag}", 'confirmed_at' => null]);

        DB::table('_portal_sso_identities')->insert([
            'site_id' => $site->id,
            'portal_user_id' => $id,
            'provider_key' => 'google',
            'provider_user_key' => "SYSPORTALPROVIDERKEY{$tag}",
            'email' => "sys-portal-provider-{$tag}@example.com",
            'name' => 'Provider Name',
            'avatar_url' => "https://example.com/SYSPORTALAVATAR{$tag}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $security = static::__endpoint('security', ['id' => $id]);
        $two_factor = $security['two_factor'];

        static::__assert_true($two_factor['is_enabled'], 'a confirmed factor enables 2FA');
        static::__assert_true($two_factor['has_passkey'], 'a confirmed passkey');
        static::__assert_equals(1, $two_factor['recovery_codes_remaining'], 'the recovery codes, as a count');
        static::__assert_equals(['Phone', 'Laptop key'], array_column($two_factor['credentials'], 'label'), 'the confirmed factors only - no recovery code, no unconfirmed enrollment');
        static::__assert_equals(1, count($security['sso']['identities']), 'the connected provider');
        static::__assert_equals("sys-portal-provider-{$tag}@example.com", $security['sso']['identities'][0]['email'], 'its account email');

        $json = json_encode($security);
        foreach (['SYSPORTALTOTPSEED', 'SYSPORTALPASSKEYPUB', 'SYSPORTALCREDKEY', 'SYSPORTALRECOVERYHASH', 'SYSPORTALPENDINGSEED', 'SYSPORTALPROVIDERKEY', 'SYSPORTALAVATAR'] as $needle) {
            static::__assert_false(str_contains($json, $needle), "no {$needle} value anywhere in the answer");
        }
        foreach (['secret', 'credential_key', 'counter', 'portal_user_id'] as $key) {
            static::__assert_false(array_key_exists($key, $two_factor['credentials'][0]), "a credential row carries no {$key}");
        }

        $bare = static::__endpoint('security', ['id' => static::__portal_user($site->id, "sys-portal-bare-{$tag}@example.com")]);
        static::__assert_false($bare['two_factor']['is_enabled'], 'no factor, no 2FA');
        static::__assert_equals([], $bare['two_factor']['credentials'], 'no credentials');
        static::__assert_equals([], $bare['sso']['identities'], 'no providers');
    }
}
