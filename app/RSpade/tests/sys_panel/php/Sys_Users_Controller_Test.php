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
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Users\_Sys_Users_Controller;

/**
 * The Users screen's endpoints: the login-identity grid (filters, search, membership
 * counts), one identity's detail with its memberships across every site, its sessions,
 * its paged sign-in history, a security answer that carries no secret material, and the
 * administration actions - status, a membership's switch, ending sessions, removing
 * second factors, disconnecting providers - with their refusals on the acting
 * developer's own identity, membership and session.
 *
 * The test runs as user 1, whose session is on site 1, and builds its identities inside
 * the per-test transaction. Identities, memberships, sessions and credentials are written
 * with DB::table() - the site-scoped model's creating hook would force the acting
 * session's site onto a membership.
 */
class Sys_Users_Controller_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__acting_as_user(1);
    }

    private static function __site(string $name, bool $enabled = true): Site_Model
    {
        $site = new Site_Model();
        $site->slug = 'sys-users-' . uniqid();
        $site->name = $name;
        $site->is_enabled = $enabled;
        $site->save();

        return $site;
    }

    /**
     * A login identity; returns its id.
     */
    private static function __identity(string $email, array $columns = []): int
    {
        return (int) DB::table('login_users')->insertGetId(array_merge([
            'email' => $email,
            'password' => 'not-a-hash',
            'status_id' => Login_User_Model::STATUS_ACTIVE,
            'is_verified' => 1,
            'is_activated' => 1,
        ], $columns));
    }

    /**
     * A membership of $login_user_id on $site_id; returns the users id.
     */
    private static function __membership(int $login_user_id, int $site_id, array $columns = []): int
    {
        return (int) DB::table('users')->insertGetId(array_merge([
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'first_name' => 'Sys',
            'last_name' => 'Users',
            'role_id' => static::most_privileged_role_id(),
            'is_enabled' => 1,
        ], $columns));
    }

    private static function __endpoint(string $method, array $params = [])
    {
        return _Sys_Users_Controller::$method(Request::create('/'), $params);
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
     * RP-USERS-01 - The grid lists live identities (never a soft-deleted one) with their
     * status word and label, developer flag and membership_count (live memberships on
     * any site, enabled or not; a soft-deleted one excluded). The status and developer
     * filters and the email search narrow it; an unknown filter value filters nothing.
     */
    public static function test_grid_filters_search_and_membership_counts()
    {
        $tag = uniqid();
        $site_a = static::__site('Sys Users Grid A');
        $site_b = static::__site('Sys Users Grid B');
        $site_c = static::__site('Sys Users Grid C');

        $member = static::__identity("sys-users-member-{$tag}@example.com");
        static::__membership($member, $site_a->id);
        static::__membership($member, $site_b->id, ['is_enabled' => 0]);
        static::__membership($member, $site_c->id, ['deleted_at' => now()]);

        $developer = static::__identity("sys-users-dev-{$tag}@example.com", ['is_developer' => 1]);
        $suspended = static::__identity("sys-users-suspended-{$tag}@example.com", ['status_id' => Login_User_Model::STATUS_SUSPENDED]);
        $deleted = static::__identity("sys-users-deleted-{$tag}@example.com", ['deleted_at' => now()]);

        $rows = static::__grid(['filter' => $tag]);

        static::__assert_equals([$suspended, $developer, $member], array_keys($rows), 'the live identities matching the search, newest first');
        static::__assert_false(isset($rows[$deleted]), 'a soft-deleted identity is not listed');
        static::__assert_equals(2, $rows[$member]['membership_count'], 'memberships on every site, enabled or not; a deleted one is not counted');
        static::__assert_equals(0, $rows[$developer]['membership_count'], 'an identity with no membership counts 0');
        static::__assert_true($rows[$developer]['is_developer'], 'the developer flag');
        static::__assert_false($rows[$member]['is_developer'], 'a boolean, not an absent key');
        static::__assert_equals('suspended', $rows[$suspended]['status'], 'the status word');
        static::__assert_equals('Suspended', $rows[$suspended]['status_label'], 'the status label');
        static::__assert_false(array_key_exists('password', $rows[$member]), 'the password hash never leaves');
        static::__assert_equals(
            DB::table('login_users')->whereNull('deleted_at')->count(),
            static::__endpoint('datagrid_fetch')['total'],
            'the total is every live identity'
        );

        static::__assert_equals([$suspended], array_keys(static::__grid(['filter' => $tag, 'status' => 'suspended'])), 'status=suspended');
        static::__assert_equals([$developer, $member], array_keys(static::__grid(['filter' => $tag, 'status' => 'active'])), 'status=active');
        static::__assert_equals([$developer], array_keys(static::__grid(['filter' => $tag, 'developer' => 'yes'])), 'developer=yes');
        static::__assert_equals([$suspended, $member], array_keys(static::__grid(['filter' => $tag, 'developer' => 'no'])), 'developer=no');
        static::__assert_equals(3, count(static::__grid(['filter' => $tag, 'status' => 'sideways', 'developer' => 'maybe'])), 'unknown filter values filter nothing');
        static::__assert_equals([$member], array_keys(static::__grid(['filter' => "sys-users-member-{$tag}"])), 'search matches the email');

        $options = static::__endpoint('status_options');
        static::__assert_equals(['active', 'inactive', 'suspended'], array_column($options, 'value'), 'the status options are the enum words, in order');
    }

    /**
     * RP-USERS-02 - detail answers an identity's facts and every live membership across
     * every site (the acting session is on site 1; these are not), with the site name,
     * the role label, User_Model's is_active (a disabled site makes an enabled membership
     * inactive), the invitation state and whether the Sites screen shows the site;
     * not_found for a missing, soft-deleted, zero or non-numeric id.
     */
    public static function test_detail_memberships_across_sites_and_not_found()
    {
        $tag = uniqid();
        $site_a = static::__site('Sys Users Detail A');
        $site_off = static::__site('Sys Users Detail Off', false);
        $site_gone = static::__site('Sys Users Detail Gone');
        $site_gone->delete();
        $site_invite = static::__site('Sys Users Detail Invite');
        $site_left = static::__site('Sys Users Detail Left');

        $id = static::__identity("sys-users-detail-{$tag}@example.com", ['last_login' => '2026-01-02 03:04:05', 'timezone' => 'America/Chicago']);
        $on_a = static::__membership($id, $site_a->id, ['is_2fa_required' => 1, 'is_api_access_enabled' => 0]);
        $on_off = static::__membership($id, $site_off->id);
        $on_gone = static::__membership($id, $site_gone->id);
        $invited = static::__membership($id, $site_invite->id, ['invite_code' => 'sys-users-' . $tag, 'invite_accepted_at' => null, 'invite_expires_at' => '2020-01-01 00:00:00']);
        static::__membership($id, $site_left->id, ['deleted_at' => now()]);

        $detail = static::__endpoint('detail', ['id' => $id]);
        $user = $detail['user'];

        static::__assert_equals($id, $user['id'], 'the identity is answered');
        static::__assert_equals('active', $user['status'], 'its status word');
        static::__assert_equals('America/Chicago', $user['timezone'], 'its timezone');
        static::__assert_true(str_ends_with($user['last_login'], 'Z'), 'last_login leaves as ISO UTC');
        static::__assert_false($user['is_self'], 'not the acting identity');
        static::__assert_equals(4, $user['membership_count'], 'the live memberships');
        static::__assert_false(array_key_exists('password', $user), 'the password hash never leaves');

        $rows = array_column($detail['memberships'], null, 'id');
        static::__assert_equals([$on_a, $on_off, $on_gone, $invited], array_keys($rows), 'every live membership, by site then id - none of them on the session\'s site');

        static::__assert_equals('Sys Users Detail A', $rows[$on_a]['site_name'], 'the site name');
        static::__assert_equals(User_Model::role_id__enum()[static::most_privileged_role_id()]['label'], $rows[$on_a]['role_label'], 'the role label');
        static::__assert_true($rows[$on_a]['is_active'], 'enabled membership on an enabled site is active');
        static::__assert_true($rows[$on_a]['is_2fa_required'], '2FA required');
        static::__assert_false($rows[$on_a]['is_api_access_enabled'], 'API access');
        static::__assert_null($rows[$on_a]['invite'], 'never an invitation');
        static::__assert_true($rows[$on_a]['has_site_screen'], 'a live tenant has a Sites screen');

        static::__assert_true($rows[$on_off]['is_enabled'], 'the membership\'s own switch is on');
        static::__assert_false($rows[$on_off]['is_active'], 'but its site is disabled, so it is not active');
        static::__assert_false($rows[$on_off]['site_is_enabled'], 'the site state rides the row');

        static::__assert_true($rows[$on_gone]['site_is_deleted'], 'a soft-deleted site is marked');
        static::__assert_false($rows[$on_gone]['has_site_screen'], 'and has no Sites screen');
        static::__assert_false($rows[$on_gone]['is_active'], 'nor an active membership');

        static::__assert_equals('expired', $rows[$invited]['invite'], 'an unaccepted invitation past its expiry');
        static::__assert_false(array_key_exists('invite_code', $rows[$invited]), 'the invitation code never leaves');

        static::__assert_true(static::__endpoint('detail', ['id' => 1])['user']['is_self'], 'the acting identity is marked');

        $deleted = static::__identity("sys-users-gone-{$tag}@example.com", ['deleted_at' => now()]);
        $missing = (int) DB::table('login_users')->max('id') + 1000;

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => $deleted]), 'a soft-deleted identity');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => $missing]), 'a missing id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => 0]), 'id 0');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => 'abc']), 'a non-numeric id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('sessions', ['id' => $deleted]), 'sessions of a soft-deleted identity');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('signins_fetch', ['login_user_id' => $missing]), 'sign-ins of a missing identity');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('security', ['id' => $missing]), 'security of a missing identity');
    }

    /**
     * RP-USERS-03 - sessions answers the identity's ACTIVE sessions (an inactive one is
     * gone) from Session::get_sessions_for_user(), each with its site, type label and
     * impersonation columns, datetimes as ISO UTC, and no token.
     */
    public static function test_sessions_shape()
    {
        $tag = uniqid();
        $site = static::__site('Sys Users Sessions Site');
        $id = static::__identity("sys-users-sessions-{$tag}@example.com");
        $impersonator = static::__identity("sys-users-imp-{$tag}@example.com");

        $session = function (array $columns) use ($id) {
            return (int) DB::table('_sessions')->insertGetId(array_merge([
                'active' => 1,
                'login_user_id' => $id,
                'session_token' => 'sys-users-' . uniqid('', true),
                'csrf_token' => 'sys-users-csrf-' . uniqid('', true),
                'ip_address' => '203.0.113.7',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
                'last_active' => '2026-02-03 04:05:06',
            ], $columns));
        };

        $plain = $session(['site_id' => $site->id, 'last_active' => '2026-02-03 04:05:06']);
        $impersonated = $session([
            'site_id' => $site->id,
            'last_active' => '2026-02-04 04:05:06',
            'impersonator_login_user_id' => $impersonator,
            'impersonation_started_at' => '2026-02-04 04:00:00',
        ]);
        $session(['active' => 0]);

        $rows = array_column(static::__endpoint('sessions', ['id' => $id])['sessions'], null, 'id');

        static::__assert_equals([$impersonated, $plain], array_keys($rows), 'the active sessions, most recently active first');
        static::__assert_equals('Sys Users Sessions Site', $rows[$plain]['site_name'], 'the session\'s site');
        static::__assert_equals($site->id, $rows[$plain]['site_id'], 'its id');
        static::__assert_equals('Web', $rows[$plain]['type_label'], 'the session type');
        static::__assert_equals('203.0.113.7', $rows[$plain]['ip_address'], 'the IP');
        static::__assert_equals('Firefox on Windows', $rows[$plain]['device_summary'], 'the parsed device');
        static::__assert_true(str_ends_with($rows[$plain]['last_active'], 'Z'), 'last_active leaves as ISO UTC');
        static::__assert_false($rows[$plain]['is_current'], 'not the session reading it');
        static::__assert_null($rows[$plain]['impersonator_login_user_id'], 'not impersonated');
        static::__assert_equals($impersonator, $rows[$impersonated]['impersonator_login_user_id'], 'the impersonating identity');
        static::__assert_equals("sys-users-imp-{$tag}@example.com", $rows[$impersonated]['impersonator_email'], 'and its email');
        static::__assert_true(str_ends_with($rows[$impersonated]['impersonation_started_at'], 'Z'), 'impersonation_started_at as ISO UTC');

        foreach (['session_token', 'csrf_token', 'handoff_token'] as $key) {
            static::__assert_false(array_key_exists($key, $rows[$plain]), "{$key} never leaves");
        }
    }

    /**
     * RP-USERS-04 - The sign-in grid pages the WHOLE history (more rows than
     * Login_History::get_history_for_user()'s default 10), newest first, each row in
     * Login_History::present_record()'s shape, and searches the IP.
     */
    public static function test_signins_are_paged_not_truncated()
    {
        $tag = uniqid();
        $id = static::__identity("sys-users-signins-{$tag}@example.com");

        for ($i = 1; $i <= 30; $i++) {
            DB::table('_login_history')->insert([
                'login_user_id' => $id,
                'email_attempted' => "sys-users-signins-{$tag}@example.com",
                'ip_address' => $i === 30 ? '198.51.100.30' : '192.0.2.' . $i,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0',
                'status' => 'success',
                'created_at' => sprintf('2026-03-01 00:00:%02d', $i),
            ]);
        }

        $first = static::__endpoint('signins_fetch', ['login_user_id' => $id, 'per_page' => 25]);
        $second = static::__endpoint('signins_fetch', ['login_user_id' => $id, 'per_page' => 25, 'page' => 2]);

        static::__assert_equals(30, $first['total'], 'every row is counted');
        static::__assert_equals(2, $first['total_pages'], 'across two pages');
        static::__assert_equals(25, count($first['records']), 'a full first page');
        static::__assert_equals(5, count($second['records']), 'the rest on the second');
        static::__assert_equals('198.51.100.30', $first['records'][0]['ip_address'], 'newest first');

        $row = $first['records'][0];
        foreach (['id', 'email', 'ip_address', 'user_agent', 'user_agent_parsed', 'location', 'status', 'status_label', 'failure_reason', 'created_at'] as $key) {
            static::__assert_array_has_key($key, $row, "the presented row carries {$key}");
        }
        static::__assert_equals('Success', $row['status_label'], 'the status label');
        static::__assert_equals('Firefox on Windows', $row['user_agent_parsed']['summary'], 'the parsed user agent');
        static::__assert_true(str_ends_with($row['created_at'], 'Z'), 'created_at leaves as ISO UTC');

        $searched = static::__endpoint('signins_fetch', ['login_user_id' => $id, 'filter' => '198.51.100']);
        static::__assert_equals(1, $searched['total'], 'search matches the IP');
    }

    /**
     * RP-USERS-05 - security answers the factor metadata, the 2FA state, the recovery-code
     * COUNT and the connected providers - and no secret: no TOTP seed, no passkey key or
     * counter, no recovery-code hash, no provider user key, by key and by value.
     */
    public static function test_security_exposes_no_secret_material()
    {
        $tag = uniqid();
        $id = static::__identity("sys-users-security-{$tag}@example.com");
        $now = now();

        $credential = fn (array $columns) => DB::table('_two_factor_credentials')->insert(array_merge([
            'login_user_id' => $id,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $columns));

        $credential(['type_id' => 1, 'label' => 'Phone', 'secret' => "SYSUSERSTOTPSEED{$tag}"]);
        $credential(['type_id' => 2, 'label' => 'Laptop key', 'secret' => "SYSUSERSPASSKEYPUB{$tag}", 'credential_key' => "SYSUSERSCREDKEY{$tag}", 'counter' => 7]);
        $credential(['type_id' => 3, 'secret' => "SYSUSERSRECOVERYHASH{$tag}"]);
        $credential(['type_id' => 3, 'secret' => "SYSUSERSRECOVERYHASH2{$tag}"]);
        $credential(['type_id' => 1, 'label' => 'Unconfirmed', 'secret' => "SYSUSERSPENDINGSEED{$tag}", 'confirmed_at' => null]);

        DB::table('_sso_identities')->insert([
            'login_user_id' => $id,
            'provider_key' => 'google',
            'provider_user_key' => "SYSUSERSPROVIDERKEY{$tag}",
            'email' => "sys-users-provider-{$tag}@example.com",
            'name' => 'Provider Name',
            'avatar_url' => "https://example.com/SYSUSERSAVATAR{$tag}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $security = static::__endpoint('security', ['id' => $id]);
        $two_factor = $security['two_factor'];

        static::__assert_true($two_factor['is_enabled'], 'a confirmed factor enables 2FA');
        static::__assert_true($two_factor['has_passkey'], 'a confirmed passkey');
        static::__assert_equals(2, $two_factor['recovery_codes_remaining'], 'the recovery codes, as a count');
        static::__assert_equals(['Phone', 'Laptop key'], array_column($two_factor['credentials'], 'label'), 'the confirmed factors only - no recovery code, no unconfirmed enrollment');
        static::__assert_equals(1, count($security['sso']['identities']), 'the connected provider');
        static::__assert_equals("sys-users-provider-{$tag}@example.com", $security['sso']['identities'][0]['email'], 'its account email');

        foreach ($two_factor['credentials'] as $row) {
            foreach (['secret', 'credential_key', 'counter', 'login_user_id'] as $key) {
                static::__assert_false(array_key_exists($key, $row), "a credential row carries no {$key}");
            }
        }
        foreach (['provider_user_key', 'avatar_url'] as $key) {
            static::__assert_false(array_key_exists($key, $security['sso']['identities'][0]), "an identity row carries no {$key}");
        }

        $json = json_encode($security);
        foreach (['SYSUSERSTOTPSEED', 'SYSUSERSPASSKEYPUB', 'SYSUSERSCREDKEY', 'SYSUSERSRECOVERYHASH', 'SYSUSERSPENDINGSEED', 'SYSUSERSPROVIDERKEY', 'SYSUSERSAVATAR'] as $needle) {
            static::__assert_false(str_contains($json, $needle), "no {$needle} value anywhere in the answer");
        }

        $bare = static::__endpoint('security', ['id' => static::__identity("sys-users-bare-{$tag}@example.com")]);
        static::__assert_false($bare['two_factor']['is_enabled'], 'no factor, no 2FA');
        static::__assert_equals([], $bare['two_factor']['credentials'], 'no credentials');
        static::__assert_equals([], $bare['sso']['identities'], 'no providers');
    }

    /**
     * An active _sessions row of $login_user_id; returns its id.
     */
    private static function __session_row(int $login_user_id): int
    {
        return (int) DB::table('_sessions')->insertGetId([
            'active' => 1,
            'login_user_id' => $login_user_id,
            'site_id' => 1,
            'session_token' => 'sys-users-' . uniqid('', true),
            'csrf_token' => 'sys-users-csrf-' . uniqid('', true),
            'ip_address' => '203.0.113.9',
            'user_agent' => 'sys-users-actions',
            'last_active' => now(),
        ]);
    }

    private static function __session_active(int $session_id): bool
    {
        return (bool) DB::table('_sessions')->where('id', $session_id)->value('active');
    }

    /**
     * RP-USERS-06 - set_status writes the status a word names (updated_by = the acting
     * developer), refuses an unknown word, refuses the acting identity (unchanged), and
     * answers not_found for a missing identity; detail publishes the refusal on the acting
     * identity only, and the status options.
     */
    public static function test_set_status_and_its_refusals()
    {
        $tag = uniqid();
        $id = static::__identity("sys-users-status-{$tag}@example.com");

        $result = static::__endpoint('set_status', ['id' => $id, 'status' => 'suspended']);

        static::__assert_equals('suspended', $result['status'], 'the new status word');
        static::__assert_equals(Login_User_Model::STATUS_SUSPENDED, (int) DB::table('login_users')->where('id', $id)->value('status_id'), 'stored');
        static::__assert_equals(1, (int) DB::table('login_users')->where('id', $id)->value('updated_by_id'), 'the acting developer is the last writer');

        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('set_status', ['id' => $id, 'status' => 'sideways']), 'an unknown word');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('set_status', ['id' => 0, 'status' => 'active']), 'a missing identity');

        $refused = static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('set_status', ['id' => 1, 'status' => 'inactive']), 'the acting identity');
        static::__assert_true(str_contains($refused->get_metadata()['_message'], 'your own identity'), 'the refusal says why');
        static::__assert_equals(Login_User_Model::STATUS_ACTIVE, (int) DB::table('login_users')->where('id', 1)->value('status_id'), 'the acting identity is unchanged');

        static::__assert_null(static::__endpoint('detail', ['id' => $id])['user']['status_refusal'], 'no refusal on another identity');
        static::__assert_true(is_string(static::__endpoint('detail', ['id' => 1])['user']['status_refusal']), 'the refusal is published on the acting identity');
        static::__assert_equals(['active', 'inactive', 'suspended'], array_column(static::__endpoint('detail', ['id' => $id])['user']['status_options'], 'value'), 'the status options');
    }

    /**
     * RP-USERS-07 - set_membership_enabled switches a membership on ANOTHER site (the
     * acting session is on site 1) off and on, answering is_active; refuses disabling the
     * membership the acting session is on (enabling it is allowed), refuses a malformed
     * enabled, and answers not_found for a missing or soft-deleted membership. detail marks
     * the acting membership is_current with its disable_refusal.
     */
    public static function test_set_membership_enabled_and_its_refusals()
    {
        $tag = uniqid();
        $site = static::__site('Sys Users Membership Switch');
        $id = static::__identity("sys-users-switch-{$tag}@example.com");
        $membership = static::__membership($id, $site->id);
        $gone = static::__membership($id, static::__site('Sys Users Membership Gone')->id, ['deleted_at' => now()]);

        $off = static::__endpoint('set_membership_enabled', ['id' => $membership, 'enabled' => false]);
        static::__assert_false($off['is_enabled'], 'disabled');
        static::__assert_false($off['is_active'], 'and so not active');
        static::__assert_equals(0, (int) DB::table('users')->where('id', $membership)->value('is_enabled'), 'stored');
        static::__assert_equals($site->id, (int) DB::table('users')->where('id', $membership)->value('site_id'), 'its site is untouched');

        $on = static::__endpoint('set_membership_enabled', ['id' => $membership, 'enabled' => 'true']);
        static::__assert_true($on['is_enabled'] && $on['is_active'], 'enabled again, and active');

        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('set_membership_enabled', ['id' => $membership, 'enabled' => 'maybe']), 'a malformed enabled');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('set_membership_enabled', ['id' => $gone, 'enabled' => false]), 'a soft-deleted membership');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('set_membership_enabled', ['id' => 'abc', 'enabled' => false]), 'a non-numeric id');

        $refused = static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('set_membership_enabled', ['id' => 1, 'enabled' => false]), 'the acting session\'s membership');
        static::__assert_true(str_contains($refused->get_metadata()['_message'], 'sign you out'), 'the refusal says why');
        static::__assert_equals(1, (int) DB::table('users')->where('id', 1)->value('is_enabled'), 'the acting membership is unchanged');
        static::__assert_true(static::__endpoint('set_membership_enabled', ['id' => 1, 'enabled' => true])['is_enabled'], 'enabling it is never refused');

        $own = array_column(static::__endpoint('detail', ['id' => 1])['memberships'], null, 'id')[1];
        static::__assert_true($own['is_current'], 'the acting membership is marked');
        static::__assert_true(is_string($own['disable_refusal']), 'with its refusal');
        $other = array_column(static::__endpoint('detail', ['id' => $id])['memberships'], null, 'id')[$membership];
        static::__assert_false($other['is_current'], 'another membership is not');
        static::__assert_null($other['disable_refusal'], 'and carries no refusal');
    }

    /**
     * RP-USERS-08 - terminate_session ends one session (through
     * Session::developer_terminate_sessions(), so session.terminated fires with the
     * developer as actor), answers not_found for an ended, unknown or other identity's
     * session, and refuses the session the panel is used from; terminate_all_sessions ends
     * every session of an identity, and on the acting identity spares the current one.
     * sessions publishes terminate_refusal on the current session only.
     */
    public static function test_terminate_sessions_and_the_current_session_refusal()
    {
        $tag = uniqid();
        $id = static::__identity("sys-users-terminate-{$tag}@example.com");
        $other = static::__identity("sys-users-terminate-other-{$tag}@example.com");
        $one = static::__session_row($id);
        $two = static::__session_row($id);
        $three = static::__session_row($id);
        $others_row = static::__session_row($other);
        $current = Session::get_session_id();

        $captured = [];

        Event_Registry::_set_test_handlers('session.terminated', [
            function ($data) use (&$captured) {
                $captured[] = $data;
            },
        ]);

        try {
            $result = static::__endpoint('terminate_session', ['id' => $id, 'session_id' => $one]);
        } finally {
            Event_Registry::_clear_test_handlers();
        }

        static::__assert_equals(1, $result['count'], 'one session ended');
        static::__assert_false(static::__session_active($one), 'deactivated');
        static::__assert_count(1, $captured, 'the termination event fired once');
        static::__assert_equals(1, $captured[0]['actor_login_user_id'], 'actor = the acting developer');
        static::__assert_equals($id, $captured[0]['target_login_user_id'], 'target = the identity');
        static::__assert_equals('admin', $captured[0]['scope'], 'scope admin');

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('terminate_session', ['id' => $id, 'session_id' => $one]), 'an already-ended session');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('terminate_session', ['id' => $id, 'session_id' => $others_row]), 'another identity\'s session');
        static::__assert_true(static::__session_active($others_row), 'which is untouched');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('terminate_session', ['id' => $id, 'session_id' => 'x']), 'a malformed session id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('terminate_all_sessions', ['id' => 0]), 'a missing identity');

        $refused = static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('terminate_session', ['id' => 1, 'session_id' => $current]), 'the current session');
        static::__assert_true(str_contains($refused->get_metadata()['_message'], 'Sign out instead'), 'the refusal says why');
        static::__assert_true(static::__session_active($current), 'the current session is untouched');

        static::__assert_equals(2, static::__endpoint('terminate_all_sessions', ['id' => $id])['count'], 'the identity\'s remaining sessions');
        static::__assert_false(static::__session_active($two) || static::__session_active($three), 'all ended');
        static::__assert_equals(0, static::__endpoint('terminate_all_sessions', ['id' => $id])['count'], 'nothing left is 0, not an error');

        $own = static::__session_row(1);
        static::__assert_equals(1, static::__endpoint('terminate_all_sessions', ['id' => 1])['count'], 'the acting identity\'s other session');
        static::__assert_false(static::__session_active($own), 'is ended');
        static::__assert_true(static::__session_active($current), 'and the current session is spared');

        $rows = array_column(static::__endpoint('sessions', ['id' => 1])['sessions'], null, 'id');
        static::__assert_true(is_string($rows[$current]['terminate_refusal']), 'the current session publishes its refusal');
        static::__assert_null(array_column(static::__endpoint('sessions', ['id' => $other])['sessions'], null, 'id')[$others_row]['terminate_refusal'], 'another session does not');
    }

    /**
     * RP-USERS-09 - remove_two_factor removes one factor (a foreign or unknown credential
     * id is not_found, never a silent no-op), keeps the recovery codes while a factor
     * remains and drops them with the last one; with no credential_id removes every
     * credential; refused on the acting identity, where security publishes the refusal.
     */
    public static function test_remove_two_factor_and_its_refusal()
    {
        $tag = uniqid();
        $id = static::__identity("sys-users-2fa-{$tag}@example.com");
        $stranger = static::__identity("sys-users-2fa-stranger-{$tag}@example.com");
        $now = now();

        $credential = fn (int $owner, array $columns) => (int) DB::table('_two_factor_credentials')->insertGetId(array_merge([
            'login_user_id' => $owner,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $columns));

        $totp = $credential($id, ['type_id' => 1, 'label' => 'Phone', 'secret' => 'x']);
        $passkey = $credential($id, ['type_id' => 2, 'label' => 'Key', 'secret' => 'y', 'credential_key' => "sys-users-{$tag}"]);
        $credential($id, ['type_id' => 3, 'secret' => 'r1']);
        $credential($id, ['type_id' => 3, 'secret' => 'r2']);
        $foreign = $credential($stranger, ['type_id' => 1, 'label' => 'Theirs', 'secret' => 'z']);

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('remove_two_factor', ['id' => $id, 'credential_id' => $foreign]), 'another identity\'s credential');
        static::__assert_true(DB::table('_two_factor_credentials')->where('id', $foreign)->exists(), 'which is untouched');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('remove_two_factor', ['id' => $id, 'credential_id' => 999000111]), 'an unknown credential');

        static::__assert_true(static::__endpoint('remove_two_factor', ['id' => $id, 'credential_id' => $totp])['is_enabled'], 'one factor remains');
        static::__assert_false(DB::table('_two_factor_credentials')->where('id', $totp)->exists(), 'the TOTP is gone');
        static::__assert_equals(2, DB::table('_two_factor_credentials')->where('login_user_id', $id)->where('type_id', 3)->count(), 'the recovery codes stay while a factor remains');

        static::__assert_false(static::__endpoint('remove_two_factor', ['id' => $id, 'credential_id' => $passkey])['is_enabled'], 'the last factor is gone');
        static::__assert_equals(0, DB::table('_two_factor_credentials')->where('login_user_id', $id)->count(), 'and the recovery codes with it');

        $credential($id, ['type_id' => 1, 'label' => 'Again', 'secret' => 'x2']);
        $credential($id, ['type_id' => 3, 'secret' => 'r3']);
        static::__assert_false(static::__endpoint('remove_two_factor', ['id' => $id])['is_enabled'], 'remove all');
        static::__assert_equals(0, DB::table('_two_factor_credentials')->where('login_user_id', $id)->count(), 'nothing is left');

        $own = $credential(1, ['type_id' => 1, 'label' => 'Mine', 'secret' => 'm']);
        $refused = static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('remove_two_factor', ['id' => 1]), 'the acting identity');
        static::__assert_true(str_contains($refused->get_metadata()['_message'], 'your own identity'), 'the refusal says why');
        static::__assert_true(DB::table('_two_factor_credentials')->where('id', $own)->exists(), 'the acting identity keeps its factor');
        static::__assert_true(is_string(static::__endpoint('security', ['id' => 1])['refusal']), 'security publishes the refusal on the acting identity');
        static::__assert_null(static::__endpoint('security', ['id' => $id])['refusal'], 'and not on another');
    }

    /**
     * RP-USERS-10 - unlink_sso disconnects one provider (a foreign or unknown link id is
     * not_found), then every provider; refused on the acting identity.
     */
    public static function test_unlink_sso_and_its_refusal()
    {
        $tag = uniqid();
        $id = static::__identity("sys-users-sso-{$tag}@example.com");
        $stranger = static::__identity("sys-users-sso-stranger-{$tag}@example.com");
        $now = now();

        $link = fn (int $owner, string $provider) => (int) DB::table('_sso_identities')->insertGetId([
            'login_user_id' => $owner,
            'provider_key' => $provider,
            'provider_user_key' => "sys-users-{$provider}-{$owner}-{$tag}",
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $google = $link($id, 'google');
        $link($id, 'microsoft');
        $foreign = $link($stranger, 'google');

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('unlink_sso', ['id' => $id, 'link_id' => $foreign]), 'another identity\'s link');
        static::__assert_true(DB::table('_sso_identities')->where('id', $foreign)->exists(), 'which is untouched');

        static::__assert_equals(1, static::__endpoint('unlink_sso', ['id' => $id, 'link_id' => $google])['count'], 'one provider left');
        static::__assert_false(DB::table('_sso_identities')->where('id', $google)->exists(), 'the link is gone');
        static::__assert_equals(0, static::__endpoint('unlink_sso', ['id' => $id])['count'], 'disconnect all');

        $own = $link(1, 'google');
        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('unlink_sso', ['id' => 1, 'link_id' => $own]), 'the acting identity');
        static::__assert_true(DB::table('_sso_identities')->where('id', $own)->exists(), 'keeps its link');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('unlink_sso', ['id' => 0]), 'a missing identity');
    }

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();
    }
}
