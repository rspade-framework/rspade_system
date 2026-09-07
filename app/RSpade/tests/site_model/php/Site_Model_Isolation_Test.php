<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SiteModel\Php;

use App\RSpade\Core\Database\Models\Rsx_Site_Model_Abstract;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
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

    public static function teardown(): void
    {
        static::__reset_session();
    }
}
