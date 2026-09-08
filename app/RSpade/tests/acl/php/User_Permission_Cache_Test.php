<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Acl\Php;

use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Regression test for the supplementary-permission cache invalidation bug.
 *
 * A User_Model instance caches its supplementary permissions on first access.
 * Previously, calling User_Permission_Model::grant()/deny()/remove() did NOT
 * invalidate that in-memory cache, so an already-loaded instance kept returning
 * the pre-change answer for the rest of the request (e.g. an admin flow that
 * grants a permission and then re-renders the user's effective permissions).
 *
 * The fix adds an in-memory per-process generation counter on
 * User_Permission_Model, bumped by grant/deny/remove; User_Model reloads its
 * cache when the generation advances. These tests exercise the exact scenario:
 * a mutation is made AFTER an instance has cached, on the SAME instance.
 *
 * Runs in the default per-test transaction (rolled back afterward): the grant,
 * the delete and the reload all happen within one method on one connection, so
 * no commit / DB reset is required.
 */
class User_Permission_Cache_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * A [lacking_role, having_role, permission] triple derived from this application's
     * own roles: a permission some role grants by default and another role does not.
     *
     * Permissions and roles are application vocabulary - an application's User_Model may
     * declare an entirely different set - so the triple is read out of $enums at runtime
     * and no PERM_ or ROLE_ constant is named here. An application whose roles all carry
     * the same permissions cannot express the scenario, and the test skips.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function __permission_pair(): array
    {
        $roles = User_Model::role_id__enum();

        foreach ($roles as $having_role => $having) {
            foreach (($having['permissions'] ?? []) as $permission) {
                foreach ($roles as $lacking_role => $lacking) {
                    if (!in_array($permission, $lacking['permissions'] ?? [], true)) {
                        return [(int) $lacking_role, (int) $having_role, (int) $permission];
                    }
                }
            }
        }

        static::__skip('User_Model declares no permission that one role grants and another does not, so role-default permissions cannot be exercised in this application.');
    }

    /**
     * Create a throwaway site user with the given role. User_Model is
     * site-scoped, so the site is impersonated for the current test first.
     */
    private static function __make_user(int $role_id): User_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = null;
        $user->first_name = 'Perm';
        $user->last_name = 'Tester';
        $user->email = 'perm_' . uniqid() . '@example.com';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    /**
     * grant() must be visible on an already-loaded instance in the same request.
     */
    public static function test_grant_visible_on_already_loaded_instance()
    {
        // A role that does NOT grant the chosen permission by default.
        [$lacking_role, , $permission] = self::__permission_pair();
        $user = static::__make_user($lacking_role);

        // Force the instance to cache its (empty) supplementary permissions.
        static::__assert_false(
            $user->has_permission($permission),
            'the role should not carry the permission before the grant'
        );

        // Grant AFTER the instance has already cached.
        User_Permission_Model::grant($user->id, $permission);

        // SAME already-loaded instance must now see the grant.
        static::__assert_true(
            $user->has_permission($permission),
            'Grant must be visible on the same already-loaded User_Model instance'
        );
    }

    /**
     * deny() must be visible on an already-loaded instance in the same request.
     */
    public static function test_deny_visible_on_already_loaded_instance()
    {
        // A role that DOES grant the chosen permission by default.
        [, $having_role, $permission] = self::__permission_pair();
        $user = static::__make_user($having_role);

        // Force the instance to cache; the role default grants the permission.
        static::__assert_true(
            $user->has_permission($permission),
            'the role should carry the permission before the deny'
        );

        // Explicit deny AFTER the instance has already cached.
        User_Permission_Model::deny($user->id, $permission);

        // SAME already-loaded instance must now reflect the deny.
        static::__assert_false(
            $user->has_permission($permission),
            'Deny must flip the same already-loaded User_Model instance to false'
        );
    }

    /**
     * remove() must revert to the role default on an already-loaded instance.
     */
    public static function test_remove_visible_on_already_loaded_instance()
    {
        // A role that lacks the chosen permission by default.
        [$lacking_role, , $permission] = self::__permission_pair();
        $user = static::__make_user($lacking_role);

        // Grant, then confirm the instance sees it (also caches at that generation).
        User_Permission_Model::grant($user->id, $permission);
        static::__assert_true(
            $user->has_permission($permission),
            'the role should carry the permission after the grant'
        );

        // Remove the supplementary grant AFTER the instance cached it.
        User_Permission_Model::remove($user->id, $permission);

        // SAME instance must revert to the role default (no permission).
        static::__assert_false(
            $user->has_permission($permission),
            'Remove must revert the same already-loaded instance to the role default'
        );
    }
}
