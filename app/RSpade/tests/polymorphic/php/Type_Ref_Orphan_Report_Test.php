<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Polymorphic\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Orphan_Report;
use App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * `php artisan rsx:type_refs:orphans` and its engine, Type_Ref_Orphan_Report.
 *
 * The report answers ONE question: which DATA rows hold a type id that can no longer be
 * resolved to a model class. It deliberately says nothing about the `_type_refs` rows
 * themselves - a row whose class is gone is inert, permanent, and never to be deleted.
 *
 * Three ids are planted on portal_notifications.subject_type:
 *   - a VANISHED-CLASS id (a _type_refs row naming a class no file declares);
 *   - a DANGLING id (no _type_refs row at all);
 *   - a HEALTHY id (Site_Model), which must NOT appear.
 *
 * Everything happens inside the per-test transaction, with the registry's cached views
 * dropped around each test so the rolled-back rows never outlive it.
 */
class Type_Ref_Orphan_Report_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    /** A class name no file in the codebase declares. */
    private const VANISHED_CLASS = 'Vanished_Orphan_Test_Model';

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    public static function teardown(): void
    {
        Type_Ref_Registry::_reset_cached_state();
    }

    /** A _type_refs row for a class that does not exist. Returns its id. */
    private static function __plant_vanished_type_ref(): int
    {
        DB::insert(
            'INSERT INTO _type_refs (class_name, table_name, created_at, updated_at)'
            . ' VALUES (?, NULL, NOW(3), NOW(3))',
            [self::VANISHED_CLASS]
        );

        Type_Ref_Registry::_reset_cached_state();

        return (int) DB::select('SELECT id FROM _type_refs WHERE class_name = ?', [self::VANISHED_CLASS])[0]->id;
    }

    /** An id far past the registry's high-water mark - no _type_refs row names it. */
    private static function __dangling_type_ref_id(): int
    {
        $max = (int) DB::select('SELECT COALESCE(MAX(id), 0) AS max_id FROM _type_refs')[0]->max_id;

        return $max + 5000;
    }

    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'orphan_report_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    /**
     * A notification row whose subject_type holds a RAW type-ref id (written with SQL - the
     * cast refuses to write a class that no longer exists, which is the whole point).
     */
    private static function __make_notification_pointing_at(int $type_ref_id): void
    {
        $user = static::__make_portal_user();

        $row = new Portal_Notification_Model();
        $row->site_id = self::SITE_ID;
        $row->portal_user_id = $user->id;
        $row->type = 'orphan_report_test';
        $row->save();

        DB::update(
            'UPDATE portal_notifications SET subject_type = ?, subject_id = ? WHERE id = ?',
            [$type_ref_id, self::SITE_ID, $row->id]
        );
    }

    /** The single finding for portal_notifications.subject_type, or null. */
    private static function __notification_finding(array $findings): ?array
    {
        foreach ($findings as $finding) {
            if ($finding['table'] === 'portal_notifications' && $finding['column'] === 'subject_type') {
                return $finding;
            }
        }

        return null;
    }

    // =====================================================================
    // The scan
    // =====================================================================

    public static function test_scan_counts_a_vanished_class_id_and_a_dangling_id_and_ignores_a_healthy_one()
    {
        $vanished_id = static::__plant_vanished_type_ref();
        $dangling_id = static::__dangling_type_ref_id();
        $healthy_id = Type_Ref_Registry::class_to_id('Site_Model');

        static::__make_notification_pointing_at($vanished_id);
        static::__make_notification_pointing_at($dangling_id);
        static::__make_notification_pointing_at($healthy_id);

        $finding = static::__notification_finding(Type_Ref_Orphan_Report::scan());

        static::__assert_not_null($finding, 'the column with orphaned rows is reported');
        static::__assert_equals(2, $finding['count'], 'the healthy row is not counted');

        static::__assert_array_has_key($vanished_id, $finding['type_ids'], 'the vanished-class id is listed');
        static::__assert_equals(
            self::VANISHED_CLASS,
            $finding['type_ids'][$vanished_id],
            'a registered-but-vanished id is labelled with the class it names'
        );

        static::__assert_array_has_key($dangling_id, $finding['type_ids'], 'the dangling id is listed');
        static::__assert_null(
            $finding['type_ids'][$dangling_id],
            'an id with no _type_refs row has no class name'
        );

        static::__assert_false(
            array_key_exists($healthy_id, $finding['type_ids']),
            'a resolvable id is never an orphan'
        );
    }

    public static function test_the_select_text_returns_exactly_the_offending_rows()
    {
        $vanished_id = static::__plant_vanished_type_ref();
        $dangling_id = static::__dangling_type_ref_id();

        static::__make_notification_pointing_at($vanished_id);
        static::__make_notification_pointing_at($dangling_id);

        $finding = static::__notification_finding(Type_Ref_Orphan_Report::scan());
        static::__assert_not_null($finding, 'the column with orphaned rows is reported');

        $ids = [$vanished_id, $dangling_id];
        sort($ids);

        $expected = 'SELECT * FROM portal_notifications WHERE subject_type IN (' . implode(', ', $ids) . ')'
            . '  -- ' . implode(', ', array_map(
                static fn ($id) => $id === $vanished_id ? self::VANISHED_CLASS : $id . ' (no _type_refs row)',
                $ids
            ));

        static::__assert_equals($expected, $finding['select'], 'the printed SELECT is exact');

        // And it is a real query that returns exactly those rows - the report never runs it,
        // so this is the proof that what it prints is pasteable.
        $rows = DB::select($finding['select']);
        static::__assert_count(2, $rows, 'the printed SELECT returns exactly the offending rows');
    }

    // =====================================================================
    // The command
    // =====================================================================

    public static function test_the_command_prints_the_count_and_the_select()
    {
        $vanished_id = static::__plant_vanished_type_ref();
        static::__make_notification_pointing_at($vanished_id);

        Artisan::call('rsx:type_refs:orphans');
        $output = Artisan::output();

        static::__assert_contains('portal_notifications.subject_type - 1 row', $output, 'the table, column and count');
        static::__assert_contains(
            'SELECT * FROM portal_notifications WHERE subject_type IN (' . $vanished_id . ')',
            $output,
            'the pasteable SELECT'
        );
        static::__assert_contains(self::VANISHED_CLASS, $output, 'the vanished class name as a trailing comment');
    }

    public static function test_the_json_shape()
    {
        $vanished_id = static::__plant_vanished_type_ref();
        static::__make_notification_pointing_at($vanished_id);

        Artisan::call('rsx:type_refs:orphans', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        static::__assert_true(is_array($payload), 'the --json output parses as a JSON array');

        $entry = null;
        foreach ($payload as $row) {
            if ($row['table'] === 'portal_notifications' && $row['column'] === 'subject_type') {
                $entry = $row;
            }
        }

        static::__assert_not_null($entry, 'the offending column is present in the payload');
        static::__assert_equals(1, $entry['count'], 'count');
        static::__assert_equals(
            self::VANISHED_CLASS,
            $entry['type_ids'][(string) $vanished_id],
            'type_ids maps the id to the class name it names'
        );
        static::__assert_contains('SELECT * FROM portal_notifications', $entry['select'], 'select');
    }

    public static function test_a_clean_database_reports_nothing_and_exits_zero()
    {
        static::__assert_empty(Type_Ref_Orphan_Report::scan(), 'nothing is planted, nothing is orphaned');

        $exit_code = Artisan::call('rsx:type_refs:orphans');
        $output = Artisan::output();

        static::__assert_equals(0, $exit_code, 'the report always exits 0');
        static::__assert_contains(
            '[OK] No polymorphic rows point at a vanished model.',
            $output,
            'the clean message'
        );
    }

    public static function test_the_report_exits_zero_even_with_orphans()
    {
        $vanished_id = static::__plant_vanished_type_ref();
        static::__make_notification_pointing_at($vanished_id);

        static::__assert_equals(
            0,
            Artisan::call('rsx:type_refs:orphans'),
            'orphaned rows are information, not a failed command'
        );
    }
}
