<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;

/**
 * Portal_Sso_Identity_Model_Abstract - one connection between a PORTAL user and one account at
 * one identity provider.
 *
 * The portal realm's twin of Sso_Identity_Model: "portal_users id 12 is also Google subject
 * 1029384756", with no token and a snapshot of what the provider asserted at link time. What
 * differs is the owner - a portal_users row - and the uniqueness: portal users are
 * site-scoped, so (site_id, provider_key, provider_user_key) is the unique key, and one
 * provider account may be a portal user of two different sites. site_id is always the
 * owner's own site_id.
 *
 * NOTHING HERE IS PUBLIC. There is no fetch(). Read and write it through Rsx_Portal_Sso,
 * which is the only class application code touches.
 *
 * See: php artisan rsx:man sso
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's Portal_Sso_Identity_Model lives
 * here; `Portal_Sso_Identity_Model.php` beside it is a shell an application replaces by
 * declaring `class Portal_Sso_Identity_Model extends Portal_Sso_Identity_Model_Abstract`
 * under rsx/models/.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _portal_sso_identities
 *
 * @property string $avatar_url
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $email
 * @property int $id
 * @property string $last_login_at
 * @property string $name
 * @property int $portal_user_id
 * @property string $provider_key
 * @property string $provider_user_key
 * @property int $site_id
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @mixin \Eloquent
 */
abstract class Portal_Sso_Identity_Model_Abstract extends Rsx_System_Model_Abstract
{
    /**
     * UNBOUNDED: one row per portal user per connected provider.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * Declared and EMPTY: provider_key is a registry string, exactly as on the staff table.
     *
     * @var array
     */
    public static $enums = [];

    protected $table = '_portal_sso_identities';
}
