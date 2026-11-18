<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SiteModel\Php;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Portal\Portal_User_Model;

/**
 * A SITE-SCOPED RECORD NEVER CHANGES SITE (Site_Scoped, the tenant boundary).
 *
 * Moving a row to another tenant by assigning site_id and saving is fatal, by every route:
 *   - from the record's own site: save() refuses a record whose site_id is not the acting
 *     site's ("This indicates a serious security issue");
 *   - after switching the acting site to the target: the saving hook compares the new
 *     site_id with the stored one ("Changing site_id is not allowed");
 *   - through update(['site_id' => ...]): refused before anything is written.
 * In every case the stored row keeps its site.
 *
 * Fixture: Portal_User_Model (framework, site-scoped) in two fixture sites. Every write
 * rolls back with the per-test transaction.
 */
class Site_Id_Immutability_Test extends Rsx_Test_Abstract
{
    public static function teardown(): void
    {
        static::__reset_session();
    }

    private static function __make_site(): int
    {
        $site = new Site_Model();
        $site->slug = 'site-id-immutability-' . uniqid();
        $site->name = 'Site Id Immutability Test';
        $site->is_enabled = true;
        $site->save();

        return (int) $site->id;
    }

    /** A portal user saved in $site_id, acting as that site. */
    private static function __make_record(int $site_id): Portal_User_Model
    {
        static::__acting_as_site($site_id);

        $record = new Portal_User_Model();
        $record->email = 'site_move_' . uniqid() . '@example.com';
        $record->set_password('secret-password');
        $record->is_verified = true;
        $record->status_id = Portal_User_Model::STATUS_ACTIVE;
        $record->save();

        return $record;
    }

    private static function __stored_site_id(int $id): int
    {
        return (int) DB::table((new Portal_User_Model())->getTable())->where('id', $id)->value('site_id');
    }

    public static function test_a_new_site_id_is_refused_from_the_records_own_site()
    {
        $site_a = static::__make_site();
        $site_b = static::__make_site();
        $record = static::__make_record($site_a);

        $record->site_id = $site_b;

        $e = static::__assert_throws(RuntimeException::class, fn () => $record->save());
        static::__assert_contains('site_id', $e->getMessage());
        static::__assert_equals($site_a, static::__stored_site_id($record->id), 'the row stayed in its site');
    }

    public static function test_a_new_site_id_is_refused_after_switching_to_the_target_site()
    {
        $site_a = static::__make_site();
        $site_b = static::__make_site();
        $record = static::__make_record($site_a);

        // Acting as the TARGET site passes save()'s cross-site check; the saving hook still
        // compares the new value with the stored one.
        static::__acting_as_site($site_b);
        $record->site_id = $site_b;

        static::__assert_throws(RuntimeException::class, fn () => $record->save(), 'Changing site_id is not allowed');
        static::__assert_equals($site_a, static::__stored_site_id($record->id), 'the row stayed in its site');
    }

    public static function test_update_with_a_new_site_id_is_refused()
    {
        $site_a = static::__make_site();
        $site_b = static::__make_site();
        $record = static::__make_record($site_a);

        static::__assert_throws(RuntimeException::class, fn () => $record->update(['site_id' => $site_b]), 'Changing site_id is never allowed');
        static::__assert_equals($site_a, static::__stored_site_id($record->id));
    }

    public static function test_an_unchanged_site_id_saves()
    {
        $site_a = static::__make_site();
        $record = static::__make_record($site_a);

        $record->site_id = $site_a;
        $record->is_verified = false;
        $record->save();

        static::__assert_equals($site_a, static::__stored_site_id($record->id), 'reassigning the same site is not a move');
    }
}
