<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\Dashboard\_Sys_Dashboard_Controller;

/**
 * The Dashboard's summary endpoint: its shape, and that every count is install-wide.
 *
 * The panel serves the whole box, so a count of a site-scoped model must not narrow to
 * the developer's own tenant - the test runs as user 1 on site 1 and plants rows under
 * a SECOND site. Runs in the default per-test transaction; the second site and every
 * planted row are created inside it.
 */
class Sys_Dashboard_Summary_Test extends Rsx_Test_Abstract
{
    private const KEYS = [
        'mode_label', 'is_sealed', 'build_key', 'live_workers', 'failed_tasks_24h',
        'pending_mail', 'mail_delivery', 'sites', 'login_users',
    ];

    public static function setup()
    {
        static::__acting_as_user(1);
    }

    private static function __summary(): array
    {
        return _Sys_Dashboard_Controller::summary(Request::create('/'), []);
    }

    private static function __second_site_id(): int
    {
        $site = new Site_Model();
        $site->slug = 'sys-dash-' . uniqid();
        $site->name = 'Sys Dashboard Second Site';
        $site->save();

        return (int) $site->id;
    }

    /**
     * RP-DASH-01 - The summary carries exactly the tile keys, with the right types.
     */
    public static function test_the_summary_shape()
    {
        $summary = static::__summary();

        static::__assert_equals(self::KEYS, array_keys($summary), 'the summary keys');
        static::__assert_true(is_string($summary['mode_label']) && $summary['mode_label'] !== '', 'mode_label is a label');
        static::__assert_true(is_bool($summary['is_sealed']), 'is_sealed is a bool');
        static::__assert_true(is_string($summary['build_key']) && $summary['build_key'] !== '', 'build_key is set');
        static::__assert_true(is_string($summary['mail_delivery']), 'mail_delivery is the mode word');

        foreach (['live_workers', 'failed_tasks_24h', 'pending_mail', 'sites', 'login_users'] as $key) {
            static::__assert_true(is_int($summary[$key]) && $summary[$key] >= 0, "{$key} is a count");
        }
    }

    /**
     * RP-DASH-02 - Pending mail counts every site's queue: a pending row queued under a
     * second site moves the count for a developer acting on site 1; a sent row does not.
     */
    public static function test_pending_mail_counts_another_sites_row()
    {
        $before = static::__summary()['pending_mail'];
        $other_site_id = static::__second_site_id();

        foreach ([Email_Queue_Model::STATUS_PENDING, Email_Queue_Model::STATUS_SENT] as $status_id) {
            DB::table('_email_queue')->insert([
                'site_id' => $other_site_id,
                'to_address' => 'sys-dash-' . uniqid() . '@example.com',
                'subject' => 'Sys dashboard probe',
                'email_class' => 'Sys_Dashboard_Probe_Email',
                'status_id' => $status_id,
            ]);
        }

        static::__assert_equals($before + 1, static::__summary()['pending_mail'], 'the second site\'s pending row is counted, the sent one is not');
    }

    /**
     * RP-DASH-03 - The site count excludes site 0 (the Default site is an FK target, not
     * a tenant) and counts a new site.
     */
    public static function test_the_site_count_excludes_site_zero()
    {
        static::__assert_not_null(Site_Model::find(0), 'the baseline carries site 0');

        $live_rows = DB::table('sites')->whereNull('deleted_at')->count();
        static::__assert_equals($live_rows - 1, static::__summary()['sites'], 'every live site but site 0');

        static::__second_site_id();
        static::__assert_equals($live_rows, static::__summary()['sites'], 'a new site is counted');
    }

    /**
     * RP-DASH-04 - Failed tasks count one-shot FAILED rows completed within 24 hours; an
     * older failure and a recent completion do not count.
     */
    public static function test_failed_tasks_count_the_last_day()
    {
        $before = static::__summary()['failed_tasks_24h'];

        $rows = [
            [Task_Status::FAILED, now()->subHours(2)],
            [Task_Status::FAILED, now()->subDays(2)],
            [Task_Status::COMPLETED, now()->subHours(2)],
        ];

        foreach ($rows as [$status, $completed_at]) {
            DB::table('_tasks')->insert([
                'class' => 'Sys_Dashboard_Probe_Service',
                'method' => 'probe',
                'status' => $status,
                'completed_at' => $completed_at,
            ]);
        }

        static::__assert_equals($before + 1, static::__summary()['failed_tasks_24h'], 'only the recent failure is counted');
    }
}
