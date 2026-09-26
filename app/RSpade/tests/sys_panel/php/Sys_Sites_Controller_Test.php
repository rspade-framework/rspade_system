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
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Sites\_Sys_Sites_Controller;

/**
 * The Sites screen's endpoints: the tenant grid (site 0 and deleted sites excluded,
 * member counts, filters), one site's detail and members, Rename's server validation
 * and Enable/Disable with its refusals.
 *
 * The test runs as user 1, whose session is on site 1, and builds its sites inside the
 * per-test transaction. Memberships and identities are written with DB::table() - the
 * site-scoped model's creating hook would force the acting session's site onto them.
 */
class Sys_Sites_Controller_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        static::__acting_as_user(1);
    }

    private static function __site(string $name, bool $enabled = true): Site_Model
    {
        $site = new Site_Model();
        $site->slug = 'sys-sites-' . uniqid();
        $site->name = $name;
        $site->is_enabled = $enabled;
        $site->save();

        return $site;
    }

    /**
     * A membership on $site_id for a new login identity (email $email); returns the users id.
     */
    private static function __member(int $site_id, string $email, array $columns = []): int
    {
        $login_user_id = (int) DB::table('login_users')->insertGetId([
            'email' => $email,
            'password' => 'not-a-hash',
            'last_login' => '2026-01-02 03:04:05',
        ]);

        return (int) DB::table('users')->insertGetId(array_merge([
            'login_user_id' => $login_user_id,
            'site_id' => $site_id,
            'first_name' => 'Sys',
            'last_name' => 'Member',
            'role_id' => static::most_privileged_role_id(),
            'is_enabled' => 1,
        ], $columns));
    }

    private static function __endpoint(string $method, array $params = [])
    {
        return _Sys_Sites_Controller::$method(Request::create('/'), $params);
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
     * RP-SITES-01 - The grid lists tenants only: never site 0, never a soft-deleted site.
     * member_count counts live memberships (a soft-deleted one is not a member); the
     * enabled filter and the name/slug search narrow it.
     */
    public static function test_grid_lists_tenants_with_member_counts()
    {
        $site = static::__site('Sys Sites Grid Probe');
        $disabled = static::__site('Sys Sites Disabled Probe', false);
        $deleted = static::__site('Sys Sites Deleted Probe');
        $deleted->delete();

        static::__member($site->id, 'sys-sites-a-' . uniqid() . '@example.com');
        static::__member($site->id, 'sys-sites-b-' . uniqid() . '@example.com', ['is_enabled' => 0]);
        static::__member($site->id, 'sys-sites-c-' . uniqid() . '@example.com', ['deleted_at' => now()]);

        $rows = static::__grid();

        static::__assert_false(isset($rows[0]), 'site 0 (the Default site) is never listed');
        static::__assert_false(isset($rows[$deleted->id]), 'a soft-deleted site is not listed');
        static::__assert_true(isset($rows[$site->id]) && isset($rows[$disabled->id]), 'enabled and disabled tenants are listed');
        static::__assert_equals(2, $rows[$site->id]['member_count'], 'live memberships, enabled or not, are counted; a deleted one is not');
        static::__assert_equals(0, $rows[$disabled->id]['member_count'], 'a site with no members counts 0');
        static::__assert_true(str_ends_with($rows[$site->id]['created_at'], 'Z'), 'created_at leaves as ISO UTC');
        static::__assert_equals(
            DB::table('sites')->whereNull('deleted_at')->where('id', '!=', 0)->count(),
            static::__endpoint('datagrid_fetch')['total'],
            'the total is every live tenant'
        );

        $only_disabled = static::__grid(['enabled' => 'disabled']);
        static::__assert_true(isset($only_disabled[$disabled->id]) && !isset($only_disabled[$site->id]), 'enabled=disabled lists disabled sites only');

        $only_enabled = static::__grid(['enabled' => 'enabled']);
        static::__assert_true(isset($only_enabled[$site->id]) && !isset($only_enabled[$disabled->id]), 'enabled=enabled lists enabled sites only');

        static::__assert_equals(count(static::__grid()), count(static::__grid(['enabled' => 'sideways'])), 'an unknown filter value filters nothing');

        $by_slug = static::__grid(['filter' => $site->slug]);
        static::__assert_equals([$site->id], array_keys($by_slug), 'search matches the slug');

        $by_name = static::__grid(['filter' => 'Sites Disabled Probe']);
        static::__assert_equals([$disabled->id], array_keys($by_name), 'search matches the name');
    }

    /**
     * RP-SITES-02 - detail answers a tenant with its counts, and not_found for site 0, a
     * soft-deleted site and a missing id; members_fetch lists the site's live memberships
     * with the identity's email, the role label and User_Model's is_active.
     */
    public static function test_detail_and_members()
    {
        $site = static::__site('Sys Sites Detail Probe');
        $enabled_email = 'sys-sites-on-' . uniqid() . '@example.com';
        $role_id = static::most_privileged_role_id();
        $role_label = User_Model::role_id__enum()[$role_id]['label'];
        $enabled_id = static::__member($site->id, $enabled_email);
        $disabled_id = static::__member($site->id, 'sys-sites-off-' . uniqid() . '@example.com', ['is_enabled' => 0]);
        static::__member($site->id, 'sys-sites-gone-' . uniqid() . '@example.com', ['deleted_at' => now()]);
        $invite_id = (int) DB::table('users')->insertGetId([
            'login_user_id' => null,
            'site_id' => $site->id,
            'email' => 'sys-sites-invite-' . uniqid() . '@example.com',
            'role_id' => $role_id,
            'is_enabled' => 1,
        ]);

        $detail = static::__endpoint('detail', ['id' => $site->id])['site'];

        static::__assert_equals($site->id, $detail['id'], 'the site is answered');
        static::__assert_equals(3, $detail['member_count'], 'live memberships, the invitation included');
        static::__assert_equals(2, $detail['enabled_member_count'], 'memberships whose own switch is on');
        static::__assert_false($detail['is_current_site'], 'not the acting session\'s site');
        static::__assert_true($detail['can_disable'], 'an enabled site that is not the session\'s own can be disabled');
        static::__assert_equals(Site_Model::field_length('name'), $detail['field_lengths']['name'], 'the name length is the column\'s');
        static::__assert_equals(Site_Model::field_length('slug'), $detail['field_lengths']['slug'], 'the slug length is the column\'s');

        $deleted = static::__site('Sys Sites Deleted Detail');
        $deleted->delete();
        $missing = (int) DB::table('sites')->max('id') + 1000;

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => 0]), 'site 0 is not a tenant');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => $deleted->id]), 'a soft-deleted site');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('detail', ['id' => $missing]), 'a missing id');
        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('members_fetch', ['site_id' => 0]), 'site 0 has no members screen');

        $members = static::__endpoint('members_fetch', ['site_id' => $site->id, 'per_page' => 100]);
        $rows = array_column($members['records'], null, 'id');

        static::__assert_equals([$enabled_id, $disabled_id, $invite_id], array_keys($rows), 'the live memberships, by role then id');
        static::__assert_equals(3, $members['total'], 'the deleted membership is not a member');
        static::__assert_equals($enabled_email, $rows[$enabled_id]['email'], 'the identity\'s email');
        static::__assert_equals($role_label, $rows[$enabled_id]['role_label'], 'the role label');
        static::__assert_true($rows[$enabled_id]['is_active'], 'an enabled membership on an enabled site is active');
        static::__assert_false($rows[$disabled_id]['is_active'], 'a disabled membership is not');
        static::__assert_null($rows[$invite_id]['login_user_id'], 'an invitation has no identity');
        static::__assert_true(str_ends_with((string) $rows[$enabled_id]['last_login'], 'Z'), 'last_login leaves as ISO UTC');
        static::__assert_equals(
            [$enabled_id],
            array_column(static::__endpoint('members_fetch', ['site_id' => $site->id, 'filter' => $enabled_email])['records'], 'id'),
            'search matches the email'
        );
        static::__assert_equals(
            [$disabled_id],
            array_column(static::__endpoint('members_fetch', ['site_id' => $site->id, 'enabled' => 'disabled'])['records'], 'id'),
            'the enabled filter reads the membership\'s own switch'
        );
    }

    /**
     * RP-SITES-03 - Rename validates on the server: both fields required, each within its
     * column's length, the slug unique across every site (a soft-deleted one's included);
     * a valid rename is saved; site 0 is not_found.
     */
    public static function test_rename_validation()
    {
        $site = static::__site('Sys Sites Rename Probe');
        $other = static::__site('Sys Sites Rename Other');
        $deleted = static::__site('Sys Sites Rename Deleted');
        $deleted->delete();

        $blank = static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('rename', [
            'site_id' => $site->id, 'name' => '  ', 'slug' => '',
        ]), 'blank fields');
        static::__assert_array_has_key('name', $blank->get_metadata(), 'a blank name is a field error');
        static::__assert_array_has_key('slug', $blank->get_metadata(), 'a blank slug is a field error');

        $long = static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('rename', [
            'site_id' => $site->id,
            'name' => str_repeat('n', Site_Model::field_length('name') + 1),
            'slug' => str_repeat('s', Site_Model::field_length('slug') + 1),
        ]), 'too long');
        static::__assert_array_has_key('name', $long->get_metadata(), 'an over-length name is a field error');
        static::__assert_array_has_key('slug', $long->get_metadata(), 'an over-length slug is a field error');

        $duplicate = static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('rename', [
            'site_id' => $site->id, 'name' => 'Fine', 'slug' => $other->slug,
        ]), 'another site\'s slug');
        static::__assert_equals(['_message', 'slug'], array_keys($duplicate->get_metadata()), 'only the slug is in error');

        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('rename', [
            'site_id' => $site->id, 'name' => 'Fine', 'slug' => $deleted->slug,
        ]), 'a soft-deleted site\'s slug is still taken');

        static::__assert_equals('Sys Sites Rename Probe', Site_Model::find($site->id)->name, 'a refused rename writes nothing');

        $new_slug = 'sys-sites-renamed-' . uniqid();
        $result = static::__endpoint('rename', [
            'site_id' => $site->id,
            'name' => str_repeat('n', Site_Model::field_length('name')),
            'slug' => $new_slug,
        ]);
        static::__assert_equals($new_slug, $result['site']['slug'], 'the rename answers the saved site');
        static::__assert_equals($new_slug, Site_Model::find($site->id)->slug, 'and is saved');

        $same = static::__endpoint('rename', ['site_id' => $site->id, 'name' => 'Kept', 'slug' => $new_slug]);
        static::__assert_equals('Kept', $same['site']['name'], 'keeping its own slug is not a duplicate');

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('rename', [
            'site_id' => 0, 'name' => 'Nope', 'slug' => 'nope',
        ]), 'site 0 cannot be renamed here');
    }

    /**
     * RP-SITES-04 - Disable makes the site's memberships inactive (User_Model::is_active());
     * Enable restores them. Refused: site 0 (not_found), the acting session's own site, a
     * value that is not a boolean.
     */
    public static function test_enable_disable()
    {
        $site = static::__site('Sys Sites Toggle Probe');
        $member_id = static::__member($site->id, 'sys-sites-toggle-' . uniqid() . '@example.com');
        $is_active = fn () => User_Model::without_site_scope(fn () => User_Model::find($member_id)->is_active());

        static::__assert_true($is_active(), 'the membership starts active');

        $result = static::__endpoint('set_enabled', ['id' => $site->id, 'enabled' => false]);
        static::__assert_false($result['site']['is_enabled'], 'the answer is the disabled site');
        static::__assert_false((bool) Site_Model::find($site->id)->is_enabled, 'the site is disabled');
        static::__assert_false($is_active(), 'its membership is no longer active');
        static::__assert_false($result['site']['can_disable'], 'a disabled site offers no Disable');

        $result = static::__endpoint('set_enabled', ['id' => $site->id, 'enabled' => 'true']);
        static::__assert_true($result['site']['is_enabled'], 'enabled again');
        static::__assert_true($is_active(), 'the membership is active again');

        static::__assert_error(Ajax::ERROR_NOT_FOUND, static::__endpoint('set_enabled', ['id' => 0, 'enabled' => false]), 'site 0 is refused');
        static::__assert_true((bool) Site_Model::find(0)->is_enabled, 'site 0 stays enabled');

        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('set_enabled', ['id' => 1, 'enabled' => false]), 'the session\'s own site is refused');
        static::__assert_true((bool) Site_Model::find(1)->is_enabled, 'and stays enabled');
        static::__assert_false(static::__endpoint('detail', ['id' => 1])['site']['can_disable'], 'and offers no Disable');

        static::__assert_error(Ajax::ERROR_VALIDATION, static::__endpoint('set_enabled', ['id' => $site->id, 'enabled' => 'maybe']), 'a non-boolean is refused');
    }
}
