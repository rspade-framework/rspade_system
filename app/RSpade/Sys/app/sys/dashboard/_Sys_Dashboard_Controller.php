<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Task\Task_Status;
use App\RSpade\Core\Task\Task_Worker_Registry;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Dashboard screen's endpoints: the headline numbers and the health report.
 *
 * Two endpoints, not one, because the two answers cost wildly different amounts:
 * the summary is a handful of counts, while the health report runs every
 * #[Health_Check] - active probes of MySQL, Redis, the lock server, the relay, the
 * mail transport and the site's own URL among them. The screen loads each in its own
 * region, so the tiles never wait on the probes.
 *
 * Every count is INSTALL-WIDE: the panel serves the whole box, not the developer's
 * current tenant, so a site-scoped model is read inside without_site_scope().
 */
#[Auth('is_sysadmin')]
class _Sys_Dashboard_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The headline numbers.
     *
     * failed_tasks_24h counts one-shot rows that went terminal FAILED within the last
     * day, dated by completed_at (Task_Instance::mark_failed() and the stuck-task reaper
     * both stamp it). A failing cron TRACKER is not among them: it is recycled to
     * pending with consecutive_failures + 1, and is the Tasks screen's schedule view.
     *
     * sites excludes site 0, the Default site - an infrastructure FK target for
     * sessionless writes, never a tenant (Site_Model_Abstract::booted()).
     */
    #[Ajax_Endpoint]
    public static function summary(Request $request, array $params = [])
    {
        return [
            'mode_label' => Rsx::get_mode_label(),
            'is_sealed' => Rsx_Prod_Seal::is_sealed(),
            'build_key' => Manifest::get_build_key(),
            'live_workers' => Task_Worker_Registry::live_count(),
            'failed_tasks_24h' => DB::table('_tasks')
                ->where('status', Task_Status::FAILED)
                ->where('completed_at', '>=', now()->subDay())
                ->count(),
            'pending_mail' => Email_Queue_Model::without_site_scope(fn () => Email_Queue_Model::pending_count()),
            'mail_delivery' => Rsx_Mail_Transport::delivery_mode(),
            'sites' => Site_Model::where('id', '!=', 0)->count(),
            'login_users' => Login_User_Model::count(),
        ];
    }

    /**
     * The health report, exactly as rsx:health --json would describe this box's mode:
     * {mode, rows[{label, status, detail, remediation}], skipped[]}.
     */
    #[Ajax_Endpoint]
    public static function health(Request $request, array $params = [])
    {
        return Health_Check_Runner::report();
    }
}
