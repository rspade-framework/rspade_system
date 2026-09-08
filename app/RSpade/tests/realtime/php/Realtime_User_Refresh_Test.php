<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Realtime\Php;

use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Models\User_Permission_Model;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * User-record refresh wiring: a change to a staff user's identity-affecting fields
 * (first_name / last_name / role_id / is_enabled), a soft-delete, or an ACL row change
 * (User_Permission_Model grant/deny/remove) pushes a targeted user_refresh. Asserted via
 * the control capture seam. Commits are real (the class opts out of per-test transactions
 * and re-provisions a clean baseline) so the afterCommit flush fires and captures.
 *
 * Self-contained fixtures: setup() creates its OWN site + user (auto ids) rather than
 * relying on a seeded row - the shared baseline's seeded user can be cascade-deleted by an
 * earlier class (users.site_id -> sites.id ON DELETE CASCADE), so pinning to a fixed id is
 * order-fragile.
 */
class Realtime_User_Refresh_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    protected static $requires_db_reset = true;

    private static ?int $site_id = null;

    private static ?int $user_id = null;

    public static function setup()
    {
        // Site_Model is not site-scoped; create it first, then ACT AS that site so the
        // site-scoped User_Model stamps + scopes to it (Rsx_Site_Model_Abstract auto-stamps
        // site_id from the session on save and auto-scopes queries to it).
        $site = new Site_Model();
        $site->slug = 'rt-refresh-' . uniqid();
        $site->name = 'RT Refresh Test Site';
        $site->is_enabled = true;
        $site->save();
        self::$site_id = $site->id;

        static::__acting_as_site(self::$site_id);

        $user = new User_Model();
        $user->email = 'rt-refresh-' . uniqid() . '@example.test';
        $user->first_name = 'RT';
        $user->last_name = 'User';
        $user->role_id = self::__role_ids()[0];
        $user->is_enabled = true;
        $user->save();
        self::$user_id = $user->id;
    }

    public static function teardown()
    {
        static::__reset_session();
    }

    private static function __begin(): void
    {
        config(['rsx.realtime.enabled' => true]);
        Realtime_Emissions::_testing_reset();
        Realtime_Emissions::_testing_set_web_context(null);
        Realtime_Emissions::_testing_start_control_capture();
    }

    /**
     * The role ids this application declares, in enum order. Roles are decorative here -
     * the subject is the refresh push on a role_id CHANGE - so any two distinct ids do.
     *
     * @return array<int, int>
     */
    private static function __role_ids(): array
    {
        return array_values(array_map('intval', User_Model::role_id__enum_ids()));
    }

    /**
     * The permission ids this application's roles grant, deduplicated, in enum order.
     *
     * Permissions are APPLICATION vocabulary - an application's User_Model declares its
     * own set and no PERM_ constant is portable - so the ids are read out of
     * $enums['role_id'][n]['permissions'] at runtime. Which permission a row names is
     * irrelevant here: the subject is the refresh push on an ACL row CHANGE.
     *
     * @return array<int, int>
     */
    private static function __permission_ids(): array
    {
        $ids = [];

        foreach (User_Model::role_id__enum() as $role) {
            foreach (($role['permissions'] ?? []) as $permission) {
                $ids[(int) $permission] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * One permission id, by position, clamped to what this application declares. Every
     * test below establishes its own precondition (granting before it removes, removing
     * before it asserts the absent case), so reusing one id is safe when an application
     * declares only one.
     */
    private static function __permission(int $index): int
    {
        $ids = static::__permission_ids();

        if (empty($ids)) {
            static::__skip('User_Model declares no role permissions, so ACL rows cannot be exercised in this application.');
        }

        return $ids[min($index, count($ids) - 1)];
    }

    private static function __user(): User_Model
    {
        return User_Model::withTrashed()->find(self::$user_id);
    }

    private static function __assert_single_user_refresh(string $context): void
    {
        $captured = Realtime_Emissions::_testing_captured_control();
        static::__assert_count(1, $captured, $context . ': exactly one control frame');
        static::__assert_equals('user_refresh', $captured[0]['kind'], $context);
        static::__assert_equals(self::$site_id, $captured[0]['site_id'], $context);
        static::__assert_equals(self::$user_id, $captured[0]['user_id'], $context);
    }

    // -------------------------------------------------------------------------
    // Watched-field changes push
    // -------------------------------------------------------------------------

    public static function test_first_name_change_pushes()
    {
        $user = static::__user();
        static::__begin();
        $user->first_name = 'Refreshed_' . uniqid();
        $user->save();

        static::__assert_single_user_refresh('first_name change');
    }

    public static function test_last_name_change_pushes()
    {
        $user = static::__user();
        static::__begin();
        $user->last_name = 'Changed_' . uniqid();
        $user->save();

        static::__assert_single_user_refresh('last_name change');
    }

    public static function test_role_id_change_pushes()
    {
        $roles = self::__role_ids();
        if (count($roles) < 2) {
            static::__skip('User_Model declares fewer than two roles, so a role_id change cannot be made.');
        }

        $user = static::__user();
        $user->role_id = $roles[0];
        $user->save();

        static::__begin();
        $user->role_id = $roles[1];
        $user->save();

        static::__assert_single_user_refresh('role_id change');
    }

    public static function test_is_enabled_change_pushes()
    {
        $user = static::__user();
        $user->is_enabled = true;
        $user->save();

        static::__begin();
        $user->is_enabled = false;
        $user->save();

        static::__assert_single_user_refresh('is_enabled change');
    }

    public static function test_soft_delete_pushes()
    {
        $user = static::__user();
        static::__begin();
        $user->delete();

        static::__assert_single_user_refresh('soft delete');

        // Restore so later tests still find the user.
        $user->restore();
    }

    // -------------------------------------------------------------------------
    // Non-watched change / no change is silent
    // -------------------------------------------------------------------------

    public static function test_phone_only_change_is_silent()
    {
        $user = static::__user();
        static::__begin();
        $user->phone = '555-' . rand(1000, 9999);
        $user->save();

        static::__assert_count(0, Realtime_Emissions::_testing_captured_control(), 'a non-watched field change pushes nothing');
    }

    public static function test_no_change_save_is_silent()
    {
        $user = static::__user();
        static::__begin();
        $user->save();

        static::__assert_count(0, Realtime_Emissions::_testing_captured_control(), 'saving an unchanged record pushes nothing');
    }

    // -------------------------------------------------------------------------
    // ACL row changes push
    // -------------------------------------------------------------------------

    public static function test_acl_grant_pushes()
    {
        static::__begin();
        User_Permission_Model::grant(self::$user_id, static::__permission(0));

        static::__assert_single_user_refresh('acl grant');
    }

    public static function test_acl_deny_pushes()
    {
        static::__begin();
        User_Permission_Model::deny(self::$user_id, static::__permission(0));

        static::__assert_single_user_refresh('acl deny');
    }

    public static function test_acl_remove_existing_pushes()
    {
        // Setup a row to remove (before capture).
        User_Permission_Model::grant(self::$user_id, static::__permission(1));

        static::__begin();
        $removed = User_Permission_Model::remove(self::$user_id, static::__permission(1));

        static::__assert_true($removed, 'a real row was removed');
        static::__assert_single_user_refresh('acl remove (existing)');
    }

    public static function test_acl_remove_absent_is_silent()
    {
        // Ensure no row exists (before capture).
        User_Permission_Model::remove(self::$user_id, static::__permission(2));

        static::__begin();
        $removed = User_Permission_Model::remove(self::$user_id, static::__permission(2));

        static::__assert_false($removed, 'nothing was removed');
        static::__assert_count(0, Realtime_Emissions::_testing_captured_control(), 'a no-op remove pushes nothing');
    }
}
