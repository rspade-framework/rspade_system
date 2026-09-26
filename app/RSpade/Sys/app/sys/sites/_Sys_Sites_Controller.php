<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Sites;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Sys\App\Sys\Sites\_Sys_Site_Members_DataGrid;
use App\RSpade\Sys\App\Sys\Sites\_Sys_Sites_DataGrid;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Sites screen's endpoints: the tenant grid, one site's facts and members, Rename
 * and Enable/Disable. There is no delete.
 *
 * SITE 0 IS NOT A TENANT. The Default site is the FK target of sessionless site-scoped
 * writes (Site_Model_Abstract::booted()), so it is absent from the grid and every
 * endpoint here answers not_found for it, exactly as for a missing id. A soft-deleted
 * site answers not_found too: the panel neither lists nor restores deleted tenants.
 *
 * DISABLING IS REAL. sites.is_enabled is framework-enforced (User_Model::is_active()):
 * a member of a disabled site cannot sign in, and a live session on it ends on its next
 * request (Session::enforce_enabled_membership()). A developer cannot disable the site
 * their own session is on - that would sign the panel's user out mid-action.
 */
#[Auth('is_sysadmin')]
class _Sys_Sites_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The tenant grid (_Sys_Sites_DataGrid).
     */
    #[Ajax_Endpoint]
    public static function datagrid_fetch(Request $request, array $params = [])
    {
        return _Sys_Sites_DataGrid::fetch($params);
    }

    /**
     * One site's members (_Sys_Site_Members_DataGrid); site_id is the grid's fixed param.
     *
     * @param array $params site_id, plus the grid's paging/sort/filter params
     */
    #[Ajax_Endpoint]
    public static function members_fetch(Request $request, array $params = [])
    {
        if (static::__find_tenant($params['site_id'] ?? null) === null) {
            return response_not_found('No site with that id.');
        }

        return _Sys_Site_Members_DataGrid::fetch($params);
    }

    /**
     * One site: its facts, authorship, member counts and the column lengths the Rename
     * form enforces.
     *
     * member_count counts live memberships; enabled_member_count those whose own switch
     * is on - the members a Disable signs out and an Enable lets back in.
     *
     * @param array $params id
     * @return array {site: {id, name, slug, is_enabled, timezone, created_at, updated_at,
     *                created_by_author, updated_by_author, member_count, enabled_member_count,
     *                is_current_site, can_disable, field_lengths: {name, slug}}}
     */
    #[Ajax_Endpoint]
    public static function detail(Request $request, array $params = [])
    {
        $site = static::__find_tenant($params['id'] ?? null);

        if ($site === null) {
            return response_not_found('No site with that id.');
        }

        return ['site' => static::__present($site)];
    }

    /**
     * Rename a site: its name and its slug. The Rename dialog's form endpoint.
     *
     * Both are required and capped at the column's length (Site_Model::field_length()).
     * The slug is unique across EVERY site, soft-deleted ones included - the unique index
     * uk_sites_slug covers them.
     *
     * @param array $params site_id, name, slug
     * @return array {site: ...} as detail()
     */
    #[Ajax_Endpoint]
    public static function rename(Request $request, array $params = [])
    {
        $site = static::__find_tenant($params['site_id'] ?? null);

        if ($site === null) {
            return response_not_found('No site with that id.');
        }

        $name = trim((string) ($params['name'] ?? ''));
        $slug = trim((string) ($params['slug'] ?? ''));
        $errors = [];

        foreach (['name' => $name, 'slug' => $slug] as $column => $value) {
            $max = Site_Model::field_length($column);

            if ($value === '') {
                $errors[$column] = 'Required.';
            } elseif (mb_strlen($value) > $max) {
                $errors[$column] = "At most {$max} characters.";
            }
        }

        if (!isset($errors['slug'])
            && Site_Model::withTrashed()->where('slug', $slug)->where('id', '!=', $site->id)->exists()) {
            $errors['slug'] = 'Another site already uses this slug.';
        }

        if ($errors !== []) {
            return response_form_error('The site was not renamed.', $errors);
        }

        $site->name = $name;
        $site->slug = $slug;
        $site->save();

        return ['site' => static::__present($site->fresh())];
    }

    /**
     * Enable or disable a site.
     *
     * Disabling signs out every member of the site on their next request and refuses
     * their sign-in until it is enabled again. Refused for the site the acting session is
     * on. Site 0 never reaches here (not_found), and the model refuses to disable it
     * regardless.
     *
     * @param array $params id, enabled (bool)
     * @return array {site: ...} as detail()
     */
    #[Ajax_Endpoint]
    public static function set_enabled(Request $request, array $params = [])
    {
        $site = static::__find_tenant($params['id'] ?? null);

        if ($site === null) {
            return response_not_found('No site with that id.');
        }

        $enabled = filter_var($params['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            return response_error(Ajax::ERROR_VALIDATION, 'enabled must be true or false.');
        }

        if (!$enabled && (int) $site->id === (int) Session::get_site_id()) {
            return response_error(
                Ajax::ERROR_VALIDATION,
                "Site #{$site->id} is the site your own session is on; disabling it would sign you out. "
                . 'Switch to another site first.'
            );
        }

        if ((bool) $site->is_enabled !== $enabled) {
            $site->is_enabled = $enabled;
            $site->save();
        }

        return ['site' => static::__present($site->fresh())];
    }

    /**
     * A tenant site by id: never site 0, never a soft-deleted one; null otherwise.
     */
    private static function __find_tenant($id): ?Site_Model
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        return Site_Model::find((int) $id);
    }

    /**
     * A site as the detail screen reads it.
     */
    private static function __present(Site_Model $site): array
    {
        $member_count = _Sys_Sites_DataGrid::member_counts([(int) $site->id])[(int) $site->id] ?? 0;
        $enabled_member_count = User_Model::without_site_scope(
            fn () => User_Model::query()->where('site_id', $site->id)->where('is_enabled', true)->count()
        );
        $is_current_site = (int) $site->id === (int) Session::get_site_id();

        return [
            'id' => (int) $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'is_enabled' => (bool) $site->is_enabled,
            'timezone' => $site->timezone,
            'created_at' => Rsx_Time::to_iso($site->created_at),
            'updated_at' => Rsx_Time::to_iso($site->updated_at),
            'created_by_author' => $site->get_created_by_author(),
            'updated_by_author' => $site->get_updated_by_author(),
            'member_count' => $member_count,
            'enabled_member_count' => $enabled_member_count,
            'is_current_site' => $is_current_site,
            'can_disable' => (bool) $site->is_enabled && !$is_current_site,
            'field_lengths' => [
                'name' => Site_Model::field_length('name'),
                'slug' => Site_Model::field_length('slug'),
            ],
        ];
    }
}
