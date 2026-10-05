<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Acl\Php;

use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The API an ACL administration screen is written against: the permission catalogue
 * (User_Model::permission_definitions() / permission_exists() / permission_label()),
 * the per-user breakdown (get_permission_breakdown()), the list-screen reads
 * (User_Permission_Model::for_users(), the withEffectivePermission scope), the reset
 * (remove_all()) and the refusal of an unknown permission id on every write.
 *
 * THE SUBJECT IS AGREEMENT. A screen that shows "effective" must show what
 * has_permission() will answer, and "who holds X" must list exactly the users
 * has_permission(X) is true for - so each read is checked against has_permission() for
 * every permission in the catalogue rather than against a hand-written expectation.
 *
 * Roles and permissions are application vocabulary, so the fixture is derived from
 * User_Model's own $enums and catalogue at runtime and no PERM_ or ROLE_ constant is
 * named here. Runs in the default per-test transaction.
 */
class Acl_Administration_Api_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /**
     * [role, a permission the role grants, a catalogue permission it does not grant].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function __role_fixture(): array
    {
        $catalogue = array_keys(User_Model::permission_definitions());

        foreach (User_Model::role_id__enum() as $role_id => $role) {
            $granted = $role['permissions'] ?? [];
            $missing = array_values(array_diff($catalogue, $granted));

            if (!empty($granted) && !empty($missing)) {
                return [(int) $role_id, (int) $granted[0], (int) $missing[0]];
            }
        }

        static::__skip('User_Model declares no role that grants some catalogue permissions and lacks others.');
    }

    private static function __make_user(int $role_id): User_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = null;
        $user->first_name = 'Acl';
        $user->last_name = 'Admin';
        $user->email = 'acl_admin_' . uniqid() . '@example.com';
        $user->role_id = $role_id;
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    /** An id the catalogue certainly does not define. */
    private static function __unknown_permission(): int
    {
        return max(array_keys(User_Model::permission_definitions())) + 1000;
    }

    /**
     * ACL-ADMIN-01: the catalogue lists every permission with its label and description,
     * and every permission a role grants by default is in it.
     */
    public static function test_catalogue_covers_every_role_permission()
    {
        $definitions = User_Model::permission_definitions();

        static::__assert_not_empty($definitions);

        foreach ($definitions as $permission_id => $definition) {
            static::__assert_equals($permission_id, $definition['id']);
            static::__assert_not_empty($definition['constant']);
            static::__assert_not_empty($definition['label']);
            static::__assert_not_empty($definition['description']);
            static::__assert_true(User_Model::permission_exists($permission_id));
            static::__assert_equals($definition['label'], User_Model::permission_label($permission_id));
            static::__assert_equals(
                $permission_id,
                constant(User_Model::class . '::' . $definition['constant']),
                "{$definition['constant']} names the id it is filed under"
            );
        }

        foreach (User_Model::role_id__enum() as $role_id => $role) {
            foreach ($role['permissions'] ?? [] as $permission_id) {
                static::__assert_true(
                    User_Model::permission_exists((int) $permission_id),
                    "role {$role_id} grants permission {$permission_id}, which the catalogue must define"
                );
            }
        }

        static::__assert_false(User_Model::permission_exists(static::__unknown_permission()));
        static::__assert_throws(Rsx_Caller_Exception::class, fn() => User_Model::permission_label(static::__unknown_permission()));
    }

    /**
     * ACL-ADMIN-02: grant() and deny() refuse an id the catalogue does not define, and
     * write nothing.
     */
    public static function test_writes_refuse_an_unknown_permission()
    {
        [$role_id] = static::__role_fixture();
        $user = static::__make_user($role_id);
        $unknown = static::__unknown_permission();

        static::__assert_throws(Rsx_Caller_Exception::class, fn() => User_Permission_Model::grant($user->id, $unknown));
        static::__assert_throws(Rsx_Caller_Exception::class, fn() => User_Permission_Model::deny($user->id, $unknown));
        static::__assert_equals(0, User_Permission_Model::where('user_id', $user->id)->count());
    }

    /**
     * ACL-ADMIN-03: the breakdown explains each permission - role default, override,
     * effective - and its "effective" column is exactly has_permission(). A preloaded
     * for_users() entry gives the same breakdown.
     */
    public static function test_breakdown_agrees_with_has_permission()
    {
        [$role_id, $role_permission, $outside_permission] = static::__role_fixture();
        $user = static::__make_user($role_id);

        User_Permission_Model::deny($user->id, $role_permission);
        User_Permission_Model::grant($user->id, $outside_permission);

        $breakdown = $user->get_permission_breakdown();

        static::__assert_equals(array_keys(User_Model::permission_definitions()), array_keys($breakdown));

        static::__assert_true($breakdown[$role_permission]['from_role']);
        static::__assert_equals('deny', $breakdown[$role_permission]['override']);
        static::__assert_false($breakdown[$role_permission]['effective'], 'DENY wins over the role');

        static::__assert_false($breakdown[$outside_permission]['from_role']);
        static::__assert_equals('grant', $breakdown[$outside_permission]['override']);
        static::__assert_true($breakdown[$outside_permission]['effective']);

        foreach ($breakdown as $permission_id => $row) {
            static::__assert_equals($user->has_permission($permission_id), $row['effective'], "permission {$permission_id}");
        }

        $preloaded = User_Permission_Model::for_users([$user->id])[$user->id];

        static::__assert_equals($breakdown, $user->get_permission_breakdown($preloaded));
    }

    /**
     * ACL-ADMIN-04: for_users() answers a page of users in one call - every requested id
     * present, empty lists for a user with no rows.
     */
    public static function test_for_users_returns_every_requested_id()
    {
        [$role_id, $role_permission, $outside_permission] = static::__role_fixture();
        $with_rows = static::__make_user($role_id);
        $without_rows = static::__make_user($role_id);

        User_Permission_Model::deny($with_rows->id, $role_permission);
        User_Permission_Model::grant($with_rows->id, $outside_permission);

        $result = User_Permission_Model::for_users([$with_rows->id, $without_rows->id]);

        static::__assert_equals([$with_rows->id, $without_rows->id], array_keys($result));
        static::__assert_equals([$outside_permission], $result[$with_rows->id]['grants']);
        static::__assert_equals([$role_permission], $result[$with_rows->id]['denies']);
        static::__assert_equals(['grants' => [], 'denies' => []], $result[$without_rows->id]);
        static::__assert_equals(User_Permission_Model::for_user($with_rows->id), $result[$with_rows->id]);
        static::__assert_equals([], User_Permission_Model::for_users([]));
    }

    /**
     * ACL-ADMIN-05: remove_all() reverts a user to the role defaults, and an instance
     * that already loaded its permissions sees the change.
     */
    public static function test_remove_all_reverts_to_role_defaults()
    {
        [$role_id, $role_permission, $outside_permission] = static::__role_fixture();
        $user = static::__make_user($role_id);

        User_Permission_Model::deny($user->id, $role_permission);
        User_Permission_Model::grant($user->id, $outside_permission);

        static::__assert_false($user->has_permission($role_permission));

        static::__assert_equals(2, User_Permission_Model::remove_all($user->id));
        static::__assert_true($user->has_permission($role_permission), 'the role default is back');
        static::__assert_false($user->has_permission($outside_permission), 'the grant is gone');
        static::__assert_equals(0, User_Permission_Model::remove_all($user->id), 'nothing left to remove');
    }

    /**
     * ACL-ADMIN-06: withEffectivePermission() lists exactly the users has_permission()
     * is true for - by role, by GRANT, and never with a DENY.
     */
    public static function test_effective_permission_scope_matches_has_permission()
    {
        [$role_id, $role_permission, $outside_permission] = static::__role_fixture();

        $by_role = static::__make_user($role_id);
        $denied = static::__make_user($role_id);
        $granted = static::__make_user($role_id);

        User_Permission_Model::deny($denied->id, $role_permission);
        User_Permission_Model::grant($granted->id, $outside_permission);

        $fixture_ids = [$by_role->id, $denied->id, $granted->id];

        foreach (array_keys(User_Model::permission_definitions()) as $permission_id) {
            $holders = User_Model::whereIn('users.id', $fixture_ids)
                ->withEffectivePermission($permission_id)
                ->pluck('users.id')
                ->map(fn($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            $expected = [];

            foreach ([$by_role, $denied, $granted] as $user) {
                if (User_Model::find($user->id)->has_permission($permission_id)) {
                    $expected[] = (int) $user->id;
                }
            }

            sort($expected);

            static::__assert_equals($expected, $holders, "holders of permission {$permission_id}");
        }

        static::__assert_throws(Rsx_Caller_Exception::class, fn() => User_Model::query()->withEffectivePermission(static::__unknown_permission()));
    }
}
