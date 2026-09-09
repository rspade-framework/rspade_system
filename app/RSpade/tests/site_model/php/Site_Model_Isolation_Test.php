<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SiteModel\Php;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Database\Models\Site_Scoped;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Profile_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * Structural guarantees of Rsx_Site_Model_Abstract's booted().
 *
 * booted() installs the cross-tenant isolation controls (site read scope +
 * site_id force + site_id validation). booted() is the extension point: a
 * subclass that needs its own hooks overrides booted() and calls parent::booted()
 * first. Forgetting parent::booted() would drop the site scope - that mistake is
 * caught fatally at manifest build by PHP-PARENT-CHAIN-01 (chain-mandatory
 * override enforcement), so booted() is deliberately NOT #[Replaceable].
 *
 * Covers:
 *   - a subclass overriding booted() and chaining parent::booted() gets the
 *     'site' global scope installed, and its own booted() body still runs;
 *   - the site global scope actually isolates reads: a foreign-site row is
 *     invisible to find() from another site (the exact regression the audit hit).
 */
class Site_Model_Isolation_Test extends Rsx_Test_Abstract
{
    public static function setup(): void
    {
        static::__acting_as_site(1);
    }

    private static function __make_site(): int
    {
        // Site_Model is NOT site-scoped, so it is created independent of session.
        $site = new Site_Model();
        $site->slug = 'site-model-test-' . uniqid();
        $site->name = 'Site Model Test';
        $site->is_enabled = true;
        $site->save();

        return (int) $site->id;
    }

    // =====================================================================
    // A subclass overriding booted() + chaining parent installs the scope
    // =====================================================================

    public static function test_booted_override_chains_parent_and_installs_site_scope()
    {
        // A subclass that overrides booted() and calls parent::booted() first
        // (the chain the PHP-PARENT-CHAIN-01 rule enforces).
        // Anonymous so it is not manifest-indexed as a model / needs no table.
        $model = new class extends Rsx_Site_Model_Abstract {
            protected $table = 'users';

            public static bool $extension_hook_ran = false;

            protected static function booted()
            {
                parent::booted();
                static::$extension_hook_ran = true;
            }
        };

        $scopes = $model->getGlobalScopes();
        static::__assert_true(
            isset($scopes['site']),
            'parent::booted() installs the site global scope on a subclass that overrides booted()'
        );

        static::__assert_true(
            $model::$extension_hook_ran,
            'the subclass booted() body runs after chaining to parent'
        );
    }

    // =====================================================================
    // The scope actually isolates cross-tenant reads
    // =====================================================================

    public static function test_cross_tenant_find_returns_null()
    {
        $site_a = self::__make_site();
        $site_b = self::__make_site();

        // Seed a row owned by site B.
        static::__acting_as_site($site_b);
        $foreign = new Portal_User_Model();
        $foreign->email = 'xtenant_' . uniqid() . '@example.com';
        $foreign->set_password('secret-password');
        $foreign->is_verified = true;
        $foreign->status_id = Portal_User_Model::STATUS_ACTIVE;
        $foreign->save();
        $foreign_id = $foreign->id;

        static::__assert_equals($site_b, (int) $foreign->site_id, 'row was forced to the acting site');
        static::__assert_not_null(
            Portal_User_Model::find($foreign_id),
            'row is visible within its own site'
        );

        // Switch to site A: the site-B row must NOT be readable.
        static::__acting_as_site($site_a);
        static::__assert_null(
            Portal_User_Model::find($foreign_id),
            'cross-tenant find() returns null - the site global scope filters foreign-site rows'
        );

        // It was scoped out, not deleted: unscoped access still finds it.
        $unscoped = Portal_User_Model::without_site_scope(function () use ($foreign_id) {
            return Portal_User_Model::find($foreign_id);
        });
        static::__assert_not_null(
            $unscoped,
            'the foreign-site row still exists - isolation is a read filter, not a deletion'
        );
    }

    // =====================================================================
    // Site_Scoped adopted DIRECTLY, on a base that is not site-scoped
    // =====================================================================

    /**
     * The trait installs the boundary on a model that cannot extend
     * Rsx_Site_Model_Abstract.
     *
     * This is the shape an application needs when a framework table is not
     * site-scoped but its own copy of it IS: an override of a split core model must
     * extend the framework's X_Model_Abstract base DIRECTLY, and PHP has no second
     * inheritance slot left to reach the site-scoped abstract with. `use Site_Scoped`
     * is the answer, and it has to install the SAME controls - anything less would be
     * a tenant boundary that only looks like one.
     */
    public static function test_trait_adopted_directly_installs_the_boundary()
    {
        // Extends the NON-site base; the trait is the only source of site scoping.
        $model = new class extends Rsx_Model_Abstract {
            use Site_Scoped;

            protected $table = 'users';
        };

        $scopes = $model->getGlobalScopes();
        static::__assert_true(
            isset($scopes['site']),
            'use Site_Scoped installs the site global scope on a model whose parent is not site-scoped'
        );

        // Installation rides on the TRAIT BOOT, not on booted(), so it cannot be lost
        // by an override that forgets to chain.
        static::__assert_true(
            method_exists($model, 'get_current_site_id'),
            'the adopting model resolves the current tenant through the trait'
        );
    }

    /**
     * The trait's controls are the SAME controls, not a lookalike: the read scope,
     * the create stamp and without_site_scope() all behave as they do on the class.
     */
    public static function test_trait_read_scope_and_create_stamp_match_the_class()
    {
        $site_a = self::__make_site();
        $site_b = self::__make_site();

        // Portal_User_Model reaches the boundary through Rsx_Site_Model_Abstract, which
        // now adopts the trait - so this also proves the extraction kept the class path
        // working. The direct-adopter assertions above cover the other path.
        static::__acting_as_site($site_b);

        $row = new Portal_User_Model();
        $row->email = 'traitscope_' . uniqid() . '@example.com';
        $row->set_password('secret-password');
        $row->is_verified = true;
        $row->status_id = Portal_User_Model::STATUS_ACTIVE;
        $row->save();

        static::__assert_equals(
            $site_b,
            (int) $row->site_id,
            'the creating hook stamped site_id from the acting site - the trait supplies it'
        );

        static::__acting_as_site($site_a);
        static::__assert_null(
            Portal_User_Model::find($row->id),
            'the trait-installed global scope filters the foreign-site row'
        );

        // The flag Rsx_Site_Model_Abstract holds is still ONE flag shared by every model
        // beneath it - a trait's static is copied into the USING class, and that class is
        // the shared ancestor here.
        $unscoped = Portal_User_Model::without_site_scope(function () use ($row) {
            return Portal_User_Model::find($row->id);
        });
        static::__assert_not_null(
            $unscoped,
            'without_site_scope() still suspends the scope for models beneath Rsx_Site_Model_Abstract'
        );
    }

    // =====================================================================
    // user_profiles is site-scoped
    // =====================================================================

    /**
     * A profile is a 1:1 satellite of a site-scoped user, so it carries the same
     * tenant and the boundary is enforced on the satellite too.
     *
     * Before this, User_Profile_Model_Abstract extended Rsx_Model_Abstract: a profile
     * row had no tenant of its own and a query against the table was cross-tenant with
     * nothing filtering it. The only thing keeping reads honest was that callers
     * happened to arrive through the scoped parent - which is not a boundary, it is a
     * habit. A framework model an application is expected to extend should not be the
     * one place the tenant boundary stops.
     */
    public static function test_user_profile_is_site_scoped()
    {
        $model = new User_Profile_Model();

        $scopes = $model->getGlobalScopes();
        static::__assert_true(
            isset($scopes['site']),
            'User_Profile_Model carries the site global scope'
        );

        static::__assert_true(
            $model instanceof \App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract,
            'User_Profile_Model_Abstract extends the site-scoped base, matching users'
        );
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }
}
