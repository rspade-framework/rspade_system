<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Country_Model;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The actor model layer: the shared contract behind everything that signs in or stamps
 * authorship (User_Model, Portal_User_Model, Login_User_Model).
 *
 * Covers the three promises the layer makes, all of which are only checkable at runtime:
 *   - the three framework actors ARE actors, and the abstracts are the right ones
 *     (site-scoped vs not) for each
 *   - get_printed_name() never returns empty, works on a TRASHED record, and returns the
 *     SAME string trashed as live (owner ruling: no "(deleted)" marker)
 *   - get_view_profile_url() answers per VIEWER, resolving through the destination's own
 *     auth gates - a real route when the gates pass, null when they do not
 *
 * Plus the soft-delete mandate at the schema level (deleted_at on all three tables), and
 * the display pair Rsx_Model_Abstract::get_created_by_author() hands to <Record_Author>.
 */
class Actor_Model_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const USER_ID = 1;

    /** The two halves of the actor layer, by simple class name (Manifest's vocabulary). */
    private const ACTOR_ABSTRACT = 'Rsx_Actor_Model_Abstract';
    private const SITE_ACTOR_ABSTRACT = 'Rsx_Site_Actor_Model_Abstract';

    /**
     * Whether a class extends either half of the actor layer. There is no shared
     * interface to test against - see Rsx_Actor_Model_Abstract for why.
     */
    private static function __extends_actor_layer(string $simple_class_name): bool
    {
        return Manifest::php_is_subclass_of($simple_class_name, self::ACTOR_ABSTRACT)
            || Manifest::php_is_subclass_of($simple_class_name, self::SITE_ACTOR_ABSTRACT);
    }

    public static function setup(): void
    {
        Rsx_Portal::set_portal_request(false);
        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        Rsx_Portal::set_portal_request(false);
        Portal_Session::logout();
        static::__reset_session();
    }

    /**
     * A throwaway portal account in the test site.
     */
    private static function __make_portal_user(): Portal_User_Model
    {
        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'actor_test_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        return $portal_user;
    }

    /**
     * The least privileged role this application declares - the one granting the fewest
     * permissions (tie: the highest id). Derived from User_Model's own $enums so the
     * class names no role constant, and used for the throwaway staff user whose point is
     * that it fails the gates on the user-management screen.
     */
    private static function __least_privileged_role(): int
    {
        $worst_id = null;
        $worst_count = null;

        foreach (User_Model::role_id__enum() as $role_id => $definition) {
            $role_id = (int) $role_id;
            $count = count($definition['permissions'] ?? []);

            if ($worst_count === null || $count < $worst_count || ($count === $worst_count && $role_id > $worst_id)) {
                $worst_id = $role_id;
                $worst_count = $count;
            }
        }

        return (int) $worst_id;
    }

    /**
     * A throwaway second staff user in the test site, with its own login identity.
     */
    private static function __make_staff_user(): User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'actor_staff_' . uniqid() . '@example.com';
        $login_user->password = Login_User_Model::hash_password('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = (int) $login_user->id;
        $user->first_name = 'Actor';
        $user->last_name = 'Fixture';
        $user->role_id = self::__least_privileged_role();
        $user->is_enabled = true;
        $user->save();

        return $user;
    }

    /**
     * A throwaway non-site-scoped record to hang authorship on. Country_Model is
     * framework-core and NOT site-scoped, so it exercises the plain-model path.
     */
    private static function __make_country(string $name): Country_Model
    {
        $suffix = uniqid();

        $country = new Country_Model();
        $country->alpha2 = strtoupper(substr($suffix, 0, 2));
        $country->alpha3 = strtoupper(substr($suffix, 0, 3));
        $country->numeric = substr($suffix, 0, 3);
        $country->name = $name . ' ' . $suffix;
        $country->enabled = false;
        $country->save();

        return $country;
    }

    // =====================================================================
    // The layer itself
    // =====================================================================

    public static function test_the_three_framework_actors_are_actors()
    {
        foreach (['User_Model', 'Portal_User_Model', 'Login_User_Model'] as $class) {
            static::__assert_true(
                self::__extends_actor_layer($class),
                "{$class} extends the actor layer"
            );
        }
    }

    public static function test_site_scoped_actors_use_the_site_abstract()
    {
        static::__assert_true(
            Manifest::php_is_subclass_of('User_Model', self::SITE_ACTOR_ABSTRACT),
            'User_Model is site-scoped, so it extends the site actor abstract'
        );
        static::__assert_true(
            Manifest::php_is_subclass_of('Portal_User_Model', self::SITE_ACTOR_ABSTRACT),
            'Portal_User_Model is site-scoped, so it extends the site actor abstract'
        );
    }

    public static function test_cross_site_identity_uses_the_plain_actor_abstract()
    {
        static::__assert_true(
            Manifest::php_is_subclass_of('Login_User_Model', self::ACTOR_ABSTRACT),
            'Login_User_Model spans sites, so it must NOT carry the site global scope'
        );
        static::__assert_false(
            Manifest::php_is_subclass_of('Login_User_Model', self::SITE_ACTOR_ABSTRACT),
            'Login_User_Model is deliberately not site-scoped'
        );
    }

    public static function test_every_stamp_target_extends_the_actor_layer()
    {
        // The runtime twin of the ACTOR-01 build check: whatever the stamp can write
        // into a *_by_type column must be resolvable to a name.
        foreach (Rsx_Model_Abstract::AUDIT_ACTOR_MODELS as $cell => $simple_name) {
            static::__assert_true(
                self::__extends_actor_layer($simple_name),
                "the '{$cell}' stamp target {$simple_name} is an actor"
            );
        }
    }

    // =====================================================================
    // The soft-delete mandate
    // =====================================================================

    public static function test_actor_tables_carry_deleted_at()
    {
        foreach (['users', 'login_users', 'portal_users'] as $table) {
            static::__assert_true(
                Schema::hasColumn($table, 'deleted_at'),
                "{$table} has the deleted_at column the actor layer's SoftDeletes writes"
            );
        }
    }

    public static function test_actors_resolve_the_soft_deletes_trait()
    {
        foreach ([User_Model::class, Portal_User_Model::class, Login_User_Model::class] as $class) {
            static::__assert_true(
                in_array(SoftDeletes::class, class_uses_recursive($class), true),
                "{$class} resolves SoftDeletes through the actor layer"
            );
        }
    }

    public static function test_deleting_an_actor_is_a_soft_delete()
    {
        $portal_user = self::__make_portal_user();
        $id = (int) $portal_user->id;

        $portal_user->delete();

        static::__assert_null(
            Portal_User_Model::find($id),
            'a deleted actor is gone from ordinary queries'
        );
        static::__assert_not_null(
            Portal_User_Model::withTrashed()->find($id),
            'but the row survives, so authorship pointing at it can still be resolved'
        );
    }

    // =====================================================================
    // get_printed_name()
    // =====================================================================

    public static function test_printed_name_is_never_empty()
    {
        $user = User_Model::find(self::USER_ID);
        static::__assert_not_empty($user->get_printed_name(), 'a staff user prints a name');

        $login_user = Login_User_Model::find((int) $user->login_user_id);
        static::__assert_not_empty($login_user->get_printed_name(), 'a login identity prints a name');

        $portal_user = self::__make_portal_user();
        static::__assert_not_empty($portal_user->get_printed_name(), 'a portal account prints a name');
    }

    public static function test_printed_name_falls_back_when_the_name_fields_are_empty()
    {
        // An invited-but-never-completed staff user has no first/last name. It must
        // still print something - the email, then the login identity, never blank.
        $user = User_Model::find(self::USER_ID);
        $user->first_name = '';
        $user->last_name = '';
        $user->email = '';

        static::__assert_not_empty(
            $user->get_printed_name(),
            'a nameless staff user still prints something'
        );
    }

    public static function test_a_trashed_actor_prints_the_same_name()
    {
        $portal_user = self::__make_portal_user();
        $live_name = $portal_user->get_printed_name();

        $portal_user->delete();
        $trashed = Portal_User_Model::withTrashed()->find((int) $portal_user->id);

        static::__assert_true($trashed->trashed(), 'the fixture is genuinely trashed');
        static::__assert_equals(
            $live_name,
            $trashed->get_printed_name(),
            'a deleted actor renders IDENTICALLY - the model never adds a "(deleted)" marker'
        );
    }

    // =====================================================================
    // get_view_profile_url()
    // =====================================================================

    public static function test_login_user_has_no_profile_page()
    {
        $user = User_Model::find(self::USER_ID);
        $login_user = Login_User_Model::find((int) $user->login_user_id);

        static::__assert_null(
            $login_user->get_view_profile_url(),
            'RSpade ships no login-identity screen, so null is the permanent answer'
        );
    }

    public static function test_staff_viewing_their_own_record_gets_their_profile_page()
    {
        $user = User_Model::find(self::USER_ID);

        $url = $user->get_view_profile_url();

        static::__assert_not_null($url, 'a signed-in staff user can always reach their own profile');
        static::__assert_contains('profile_display', $url, 'and it is the profile screen, not user admin');
    }

    public static function test_staff_viewing_another_user_gets_the_admin_screen_when_permitted()
    {
        // The acting user (id 1) is a developer, so the can_manage_users gate on the
        // user-management detail screen passes.
        $other = self::__make_staff_user();

        $url = $other->get_view_profile_url();

        static::__assert_not_null($url, 'a user-manager gets a link to another user');
        static::__assert_contains('user_management', $url, 'and it is the user-management detail screen');
        static::__assert_contains((string) $other->id, $url, 'carrying that user\'s id');
    }

    public static function test_a_viewer_without_user_management_gets_no_link()
    {
        // The same record, a different viewer: a staff member who fails the
        // can_manage_users gate on the user-management screen, so the link collapses to
        // null and the widget renders plain text. This is the whole point of resolving
        // through the destination's own gates rather than a hand-rolled check.
        $viewer = self::__make_staff_user();
        $subject = self::__make_staff_user();

        static::__acting_as_user((int) $viewer->id);

        static::__assert_null(
            $subject->get_view_profile_url(),
            'a viewer without user-management rights gets no link to another user'
        );

        // ... while their OWN record still resolves, because the profile screen is
        // gated only on being signed in.
        static::__assert_not_null(
            $viewer->get_view_profile_url(),
            'the same viewer can still reach their own profile'
        );

        static::__acting_as_user(self::USER_ID);
    }

    public static function test_portal_account_has_no_staff_side_profile_page()
    {
        // Staff realm: RSpade ships no per-portal-user detail screen.
        $portal_user = self::__make_portal_user();

        static::__assert_null(
            $portal_user->get_view_profile_url(),
            'a portal account exposes no staff-side profile route'
        );
    }

    public static function test_a_portal_user_gets_their_own_account_screen()
    {
        $portal_user = self::__make_portal_user();

        Rsx_Portal::set_portal_request(true);
        Portal_Session::set_site_id(self::SITE_ID);
        Portal_Session::set_portal_user_id((int) $portal_user->id);

        $own_url = $portal_user->get_view_profile_url();

        static::__assert_not_null($own_url, 'a portal user can reach their own account screen');

        // ... and NOT another account's. Same record class, different viewer.
        $other_portal_user = self::__make_portal_user();
        static::__assert_null(
            $other_portal_user->get_view_profile_url(),
            'portal users do not browse each other - the answer depends on who is asking'
        );

        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
    }

    // =====================================================================
    // The display pair consumed by <Record_Author>
    // =====================================================================

    public static function test_author_display_resolves_a_stamped_record()
    {
        $country = self::__make_country('Actor Test Country');

        $author = $country->get_created_by_author();

        static::__assert_not_null($author, 'a record written by a signed-in actor has an author');
        static::__assert_array_has_key('name', $author, 'the pair carries a name');
        static::__assert_array_has_key('url', $author, 'the pair carries a url slot');
        static::__assert_not_empty($author['name'], 'the name is never empty');
    }

    public static function test_author_display_is_null_for_an_unattributed_record()
    {
        $country = self::__make_country('Unattributed Test Country');

        // Half-set pairs are hand-written data the framework says nothing about.
        $country->created_by_type = null;
        $country->created_by_id = 99999;

        static::__assert_null(
            $country->get_created_by_author(),
            'a half-set authorship pair resolves to null rather than guessing'
        );
    }

    public static function test_author_display_survives_a_trashed_actor()
    {
        $portal_user = self::__make_portal_user();
        $expected_name = $portal_user->get_printed_name();

        $country = self::__make_country('Trashed Author Test Country');

        $country->created_by_type = 'Portal_User_Model';
        $country->created_by_id = (int) $portal_user->id;

        $portal_user->delete();

        $author = $country->get_created_by_author();

        static::__assert_not_null($author, 'authorship still resolves after the actor is deleted');
        static::__assert_equals($expected_name, $author['name'], 'and prints the same name it always did');
    }
}
