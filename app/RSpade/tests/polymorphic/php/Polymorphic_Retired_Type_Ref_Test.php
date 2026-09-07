<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Polymorphic\Php;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\TypeRefs\Retired_Type_Ref;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A RETIRED type ref - a _type_refs row whose model class no longer exists in the codebase -
 * must fail loud, at the point of use, naming the id and the class.
 *
 * Retirement is simulated the only way it can be without deleting a real model: a
 * _type_refs row is inserted for a class name that no file declares, inside the per-test
 * transaction. The registry's cached views (memory + Redis) are dropped around every test
 * through the _reset_cached_state() seam, so the rolled-back row never outlives the test,
 * and the morph map is snapshotted and restored (Relation::morphMap is global process state
 * and the poison alias would otherwise leak into the rest of the suite).
 *
 * Fixtures reuse Portal_Notification_Model (subject_type/subject_id) over Site_Model, the
 * same pair Polymorphic_Morph_Relations_Test uses.
 */
class Polymorphic_Retired_Type_Ref_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** A class name no file in the codebase declares. */
    private const RETIRED_CLASS = 'Retired_Poly_Test_Model';

    /** The morph map as it stood before this class ran. */
    private static ?array $morph_map_snapshot = null;

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
        self::$morph_map_snapshot = Relation::morphMap();
    }

    public static function teardown(): void
    {
        Relation::morphMap(self::$morph_map_snapshot ?? [], false);
        Type_Ref_Registry::_reset_cached_state();
    }

    /**
     * Insert a _type_refs row for a class that does not exist and re-register the morph map,
     * exactly as boot would. Returns the retired type-ref id.
     */
    private static function __retire_a_type_ref(): int
    {
        // The live alias the tests assert on must EXIST before the morph map is rebuilt.
        // Type refs are created lazily on first use, and a restored baseline database
        // holds no Site_Model row - so on a worker that restored the baseline just before
        // this class, nothing had created it yet and the alias was missing. Creating it
        // here (inside the per-test transaction) makes the class order-independent.
        Type_Ref_Registry::class_to_id('Site_Model');

        DB::insert(
            'INSERT INTO _type_refs (class_name, table_name, created_at, updated_at) VALUES (?, NULL, NOW(3), NOW(3))',
            [self::RETIRED_CLASS]
        );
        $id = (int) DB::select('SELECT id FROM _type_refs WHERE class_name = ?', [self::RETIRED_CLASS])[0]->id;

        Type_Ref_Registry::_reset_cached_state();
        Type_Ref_Registry::register_morph_map();

        return $id;
    }

    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'retired_poly_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /**
     * A notification row whose subject_type holds a RAW type-ref id. The value is written
     * with SQL, not the ORM: the cast now refuses to write a retired class, which is the
     * point of test_class_to_id_refuses_a_retired_class below.
     */
    private static function __make_notification_pointing_at(int $type_ref_id): Portal_Notification_Model
    {
        $user = static::__make_portal_user();

        $row = new Portal_Notification_Model();
        $row->site_id = self::SITE_ID;
        $row->portal_user_id = $user->id;
        $row->type = 'retired_poly_test';
        $row->save();

        DB::update(
            'UPDATE portal_notifications SET subject_type = ?, subject_id = ? WHERE id = ?',
            [$type_ref_id, self::SITE_ID, $row->id]
        );

        return $row;
    }

    // =====================================================================
    // Point of use - the poison alias
    // =====================================================================

    public static function test_morph_to_over_a_retired_type_ref_throws_naming_the_id_and_class()
    {
        $retired_id = static::__retire_a_type_ref();
        $row = static::__make_notification_pointing_at($retired_id);

        $fresh = Portal_Notification_Model::find($row->id);

        $exception = static::__assert_throws(
            \RuntimeException::class,
            static fn () => $fresh->subject,
            'no longer exists'
        );

        static::__assert_contains((string) $retired_id, $exception->getMessage(), 'the message names the type ref id');
        static::__assert_contains(self::RETIRED_CLASS, $exception->getMessage(), 'the message names the class');
    }

    public static function test_cast_read_throws_instead_of_returning_a_phantom_class_name()
    {
        $retired_id = static::__retire_a_type_ref();
        $row = static::__make_notification_pointing_at($retired_id);

        $fresh = Portal_Notification_Model::find($row->id);

        $exception = static::__assert_throws(
            \RuntimeException::class,
            static fn () => $fresh->subject_type,
            'no longer exists'
        );

        static::__assert_contains(self::RETIRED_CLASS, $exception->getMessage(), 'the cast names the retired class');
    }

    public static function test_class_to_id_refuses_a_retired_class()
    {
        static::__retire_a_type_ref();

        // The row IS in the registry - this is the cache-hit path, which used to return the id.
        static::__assert_not_null(
            Type_Ref_Registry::find_id_by_class_name(self::RETIRED_CLASS),
            'the retired class is present in _type_refs'
        );

        static::__assert_throws(
            \RuntimeException::class,
            static fn () => Type_Ref_Registry::class_to_id(self::RETIRED_CLASS),
            'no longer exists'
        );
    }

    public static function test_where_has_morph_wildcard_produces_the_named_error()
    {
        $retired_id = static::__retire_a_type_ref();
        static::__make_notification_pointing_at($retired_id);

        $exception = static::__assert_throws(
            \RuntimeException::class,
            static fn () => Portal_Notification_Model::whereHasMorph('subject', '*')->get(),
            'no longer exists'
        );

        static::__assert_contains(self::RETIRED_CLASS, $exception->getMessage(), 'the wildcard error names the class');
    }

    // =====================================================================
    // A retired ref that nothing references is INERT
    // =====================================================================

    public static function test_an_unreferenced_retired_type_ref_leaves_everything_else_working()
    {
        $retired_id = static::__retire_a_type_ref();

        // No row points at the retired id. Registration happened (above) without throwing,
        // and an ordinary morph over a LIVE type ref is unaffected.
        static::__assert_equals(
            Site_Model::class,
            Relation::getMorphedModel('Site_Model'),
            'a live class-name alias still resolves'
        );
        static::__assert_equals(
            Site_Model::class,
            Relation::getMorphedModel(Type_Ref_Registry::class_to_id('Site_Model')),
            'a live integer alias still resolves'
        );

        $user = static::__make_portal_user();
        $row = new Portal_Notification_Model();
        $row->site_id = self::SITE_ID;
        $row->portal_user_id = $user->id;
        $row->type = 'retired_poly_test_unaffected';
        $row->subject_type = 'Site_Model';
        $row->subject_id = self::SITE_ID;
        $row->save();

        $fresh = Portal_Notification_Model::find($row->id);
        static::__assert_instance_of(Site_Model::class, $fresh->subject, 'an unaffected morph still resolves');

        // The retired alias IS in the map (never silently dropped) - it just poisons on use.
        static::__assert_not_null(
            Relation::getMorphedModel((string) $retired_id),
            'the retired id is registered, not silently absent'
        );
    }

    // =====================================================================
    // The audit surface
    // =====================================================================

    public static function test_find_id_by_class_name_reads_the_registry_without_validating()
    {
        $retired_id = static::__retire_a_type_ref();

        static::__assert_equals(
            $retired_id,
            Type_Ref_Registry::find_id_by_class_name(self::RETIRED_CLASS),
            'the cleanup-migration lookup resolves a RETIRED class'
        );
        static::__assert_null(
            Type_Ref_Registry::find_id_by_class_name('Never_Registered_Poly_Test_Model'),
            'an unknown class is null, not an auto-created row'
        );
    }

    // =====================================================================
    // A retired row is SILENT - no warning, no health row
    // =====================================================================

    /**
     * Ruling: a _type_refs row whose class is gone is HARMLESS and must produce no warning,
     * health row or error anywhere - not at boot, not in rsx:debug, not in rsx:health.
     * Deleting such a row is what causes harm, so the framework offers no way to delete one.
     *
     * The boot path is register_morph_map(), which used to Log::warning() the retired pairs
     * on EVERY boot - which is exactly what surfaced in rsx:debug, whose harness echoes new
     * laravel.log entries. Pinned structurally: the registry does not log at all.
     */
    public static function test_the_registry_never_logs_about_a_retired_type_ref()
    {
        static::__retire_a_type_ref();

        // Registration is silent by construction: no logger reaches this class.
        $source = file_get_contents(
            (new \ReflectionClass(Type_Ref_Registry::class))->getFileName()
        );

        static::__assert_true(
            strpos($source, 'Log::') === false,
            'Type_Ref_Registry emits no log line about a retired type ref'
        );
        static::__assert_true(
            strpos($source, 'Facades\\Log') === false,
            'Type_Ref_Registry does not even import the logger'
        );
    }

    /**
     * rsx:health carries NO type-ref row. The check is the manifest inventory rather than a
     * full rsx:health run: discovery is what decides whether a row can exist at all, and it
     * probes no services.
     */
    public static function test_health_declares_no_type_ref_check()
    {
        static::__retire_a_type_ref();

        foreach (Health_Check_Runner::discover() as $check) {
            static::__assert_true(
                stripos($check['label'], 'type ref') === false,
                'no health check is labelled for type refs (found: ' . $check['label'] . ')'
            );
            static::__assert_true(
                strpos($check['fqcn'], 'Database\\TypeRefs') === false,
                'no health check lives in the TypeRefs namespace (found: ' . $check['fqcn'] . ')'
            );
        }
    }

    /**
     * The one message every retired-type-ref failure carries points at the REPORT, and says
     * the registry row is not to be deleted.
     */
    public static function test_the_failure_message_points_at_the_orphan_report()
    {
        $message = Retired_Type_Ref::message(41, 'Retired_Poly_Test_Model');

        static::__assert_contains('rsx:type_refs:orphans', $message, 'the message names the report command');
        static::__assert_true(
            strpos($message, 'prune') === false,
            'the message no longer names a prune command - there is none'
        );
    }
}
