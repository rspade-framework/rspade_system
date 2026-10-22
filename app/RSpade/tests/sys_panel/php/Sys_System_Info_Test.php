<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\SysPanel\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Front_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Sys\App\Sys\SystemInfo\_Sys_System_Info;
use App\RSpade\Sys\App\Sys\SystemInfo\_Sys_System_Info_Controller;

/**
 * The System Info screen: the report's shape, the blob-store figure against the
 * database, the pure parsers and the disk de-duplication, and the gate on both the
 * Ajax endpoint and the phpinfo() route.
 */
class Sys_System_Info_Test extends Rsx_Test_Abstract
{
    /** The gate cases sign a browser in and out. */
    protected static $acts_as_browser = true;

    public static function teardown()
    {
        app()->instance('request', Request::create('/'));
        Rsx_Request_Channel::reset();
        static::__reset_session();
    }

    /**
     * RP-INFO-01 - The report carries every card's group with its keys; the container
     * block follows Rsx::is_rspade_container(); the project root is always the first
     * disk and no disk leaks its st_dev.
     */
    public static function test_the_report_shape()
    {
        static::__acting_as_user(1);

        $report = _Sys_System_Info_Controller::report(Request::create('/'), []);

        static::__assert_equals(
            ['server', 'cpu_memory', 'disks', 'file_storage', 'php', 'opcache', 'extensions', 'container'],
            array_keys($report)
        );
        static::__assert_equals(
            ['os_name', 'hostname', 'kernel', 'uptime_seconds', 'uptime_human', 'load_average', 'install_path'],
            array_keys($report['server'])
        );
        static::__assert_equals(dirname(base_path()), $report['server']['install_path']);
        static::__assert_equals(
            ['cores', 'cpu_model', 'mem_total', 'mem_used', 'mem_cached', 'mem_free', 'mem_available', 'swap_total', 'swap_used', 'swap_free'],
            array_keys($report['cpu_memory'])
        );
        static::__assert_equals(PHP_VERSION, $report['php']['version']);
        static::__assert_true(in_array('Core', $report['extensions']['extensions'], true), 'Core is a loaded extension');

        static::__assert_not_empty($report['disks'], 'at least the project root');
        static::__assert_equals('project', $report['disks'][0]['key']);
        foreach ($report['disks'] as $disk) {
            static::__assert_false(array_key_exists('dev', $disk), 'st_dev is not part of the answer');
        }

        $container = $report['container'];
        static::__assert_equals(Rsx::is_rspade_container(), $container['is_rspade_container']);
        if ($container['is_rspade_container']) {
            static::__assert_equals(array_keys(_Sys_System_Info::FPM_POOLS), array_column($container['fpm_pools'], 'pool'));
        } else {
            static::__assert_null($container['fpm_pools']);
            static::__assert_null($container['services']);
        }
    }

    /**
     * RP-INFO-02 - The blob store's size is the SUM of _file_storage.size, and its blob
     * count the row count, read from the database.
     */
    public static function test_file_storage_matches_the_sum_query()
    {
        $row = DB::selectOne('SELECT COUNT(*) AS blobs, COALESCE(SUM(size), 0) AS bytes FROM _file_storage');

        static::__assert_equals(
            ['bytes' => (int) $row->bytes, 'blobs' => (int) $row->blobs],
            _Sys_System_Info::file_storage()
        );
    }

    /**
     * RP-INFO-03 - A path on the project root's device is omitted; one on another
     * device, or whose device could not be read, is kept; with no project device
     * everything is kept.
     */
    public static function test_select_disks_drops_the_project_roots_device()
    {
        $entry = fn (string $key, ?int $dev) => ['key' => $key, 'dev' => $dev, 'path' => '/' . $key];

        $kept = _Sys_System_Info::select_disks([$entry('project', 10), $entry('root', 10), $entry('storage', 10)]);
        static::__assert_equals([['key' => 'project', 'path' => '/project']], $kept, 'all on one device: the project root speaks for it');

        $kept = _Sys_System_Info::select_disks([$entry('project', 10), $entry('root', 20), $entry('storage', 10)]);
        static::__assert_equals(['project', 'root'], array_column($kept, 'key'), 'the root is on another device');

        $kept = _Sys_System_Info::select_disks([$entry('project', 10), $entry('root', null), $entry('storage', 30)]);
        static::__assert_equals(['project', 'root', 'storage'], array_column($kept, 'key'), 'an unreadable device is kept');

        $kept = _Sys_System_Info::select_disks([$entry('project', null), $entry('root', 10), $entry('storage', 10)]);
        static::__assert_equals(['project', 'root', 'storage'], array_column($kept, 'key'), 'no project device: nothing is shown to be the same');
    }

    /**
     * RP-INFO-04 - The parsers, on fixed text.
     */
    public static function test_the_parsers()
    {
        // meminfo -> bytes, and the free(1) arithmetic
        $mem = _Sys_System_Info::parse_meminfo(
            "MemTotal:       1000 kB\nMemFree:         100 kB\nMemAvailable:    600 kB\nBuffers:          50 kB\n"
            . "Cached:          300 kB\nSReclaimable:     50 kB\nSwapTotal:       400 kB\nSwapFree:        300 kB\nHugePages_Total:       0\n"
        );
        static::__assert_equals(1000 * 1024, $mem['MemTotal']);
        static::__assert_equals(0, $mem['HugePages_Total']);
        $figures = _Sys_System_Info::memory_figures($mem);
        static::__assert_equals(400 * 1024, $figures['mem_cached'], 'buffers + cached + reclaimable');
        static::__assert_equals(500 * 1024, $figures['mem_used'], 'total - free - cache');
        static::__assert_equals(100 * 1024, $figures['swap_used']);
        static::__assert_null(_Sys_System_Info::memory_figures([])['mem_used'], 'nothing read: null, not zero');

        // os-release
        static::__assert_equals('Ubuntu 24.04.1 LTS', _Sys_System_Info::parse_os_release("NAME=\"Ubuntu\"\nPRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\n"));
        static::__assert_equals('Alpine 3.20', _Sys_System_Info::parse_os_release("NAME=Alpine\nVERSION=3.20\n"));
        static::__assert_null(_Sys_System_Info::parse_os_release("# nothing\n"));

        // a php-fpm pool file
        $pool = _Sys_System_Info::parse_pool_config(
            "[www]\nuser = www-data\n; pm.max_children = 99\npm = dynamic\npm.max_children = 20\nlisten = /run/php/php-fpm.sock\npm.max_children = 21\n"
        );
        static::__assert_equals('dynamic', $pool['pm']);
        static::__assert_equals('21', $pool['pm.max_children'], 'a later line wins; a comment is not a setting');
        static::__assert_equals('/run/php/php-fpm.sock', $pool['listen']);

        // supervisorctl status, an error line skipped
        $services = _Sys_System_Info::parse_supervisor_status(
            "nginx                            RUNNING   pid 12, uptime 1:00:00\nmysql                            STOPPED   Not started\n"
            . "unix:///var/run/supervisor.sock no such file\n"
        );
        static::__assert_equals([
            ['name' => 'nginx', 'state' => 'RUNNING', 'description' => 'pid 12, uptime 1:00:00'],
            ['name' => 'mysql', 'state' => 'STOPPED', 'description' => 'Not started'],
        ], $services);

        // mountinfo and the longest mount-point prefix
        $mounts = _Sys_System_Info::parse_mountinfo(
            "1 0 0:1 / / rw - ext4 /dev/sda1 rw\n"
            . "2 1 0:2 / /var/www rw shared:1 - btrfs /dev/sdb1 rw\n"
            . "3 1 0:3 / /var/www2 rw - xfs /dev/sdc1 rw\n"
            . "4 1 0:4 / /mnt/a\\040b rw - ext4 /dev/sdd1 rw\n"
        );
        static::__assert_equals('/mnt/a b', $mounts[3]['mount_point'], 'octal escapes decoded');
        static::__assert_equals('/dev/sdb1', _Sys_System_Info::mount_for('/var/www/html', $mounts)['source']);
        static::__assert_equals('/dev/sdc1', _Sys_System_Info::mount_for('/var/www2', $mounts)['source']);
        static::__assert_equals('/dev/sda1', _Sys_System_Info::mount_for('/var/www-other', $mounts)['source'], 'a prefix is a whole path segment');

        // opcache.restrict_api
        static::__assert_false(_Sys_System_Info::opcache_api_restricted('', '/anything'));
        static::__assert_false(_Sys_System_Info::opcache_api_restricted('/var/www', '/var/www/html/system/public/index.php'));
        static::__assert_true(_Sys_System_Info::opcache_api_restricted('/opt/admin', '/var/www/html/system/public/index.php'));
    }

    /**
     * RP-INFO-05 - The phpinfo() route answers a developer with PHP's page; a signed-in
     * identity that is not a developer is refused it (403) and refused the report.
     */
    public static function test_the_gate()
    {
        static::__acting_as_user(1);
        $response = static::__dispatch_phpinfo();
        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_true(str_contains((string) $response->getContent(), 'PHP Version'), 'the page is phpinfo()');

        static::__acting_as_non_developer();

        static::__assert_equals(403, static::__dispatch_phpinfo()->getStatusCode(), 'a non-developer is refused phpinfo');

        $envelope = static::__ajax('_Sys_System_Info_Controller', 'report');
        static::__assert_equals(Ajax::ERROR_UNAUTHORIZED, $envelope['error_code'] ?? null, 'a non-developer is refused the report: ' . json_encode($envelope));
    }

    private static function __dispatch_phpinfo()
    {
        Rsx_Bundle_Abstract::$_has_rendered = null;
        $path = '/_sys/system-info/phpinfo';

        return Dispatcher::dispatch($path, 'GET', [], Request::create($path, 'GET'));
    }

    /**
     * Sign in an identity that is not a developer, holding an enabled membership on
     * user 1's site (the shape Sys_Panel_Gate_Test probes with).
     */
    private static function __acting_as_non_developer(): void
    {
        $anchor = User_Model::without_site_scope(fn () => User_Model::find(1));
        $site_id = (int) $anchor->site_id;

        $login_user = new Login_User_Model();
        $login_user->email = 'sys-info-probe-' . random_hash(8) . '@rspade.test';
        $login_user->password = Hash::make(random_hash(16));
        $login_user->is_activated = 1;
        $login_user->is_verified = 1;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        Session::set_site_id($site_id);

        $membership = new User_Model();
        $membership->login_user_id = $login_user->id;
        $membership->email = $login_user->email;
        $membership->first_name = 'Info';
        $membership->last_name = 'Probe';
        $membership->is_enabled = 1;
        $membership->save();

        Session::impersonate($site_id, (int) $login_user->id, (int) $membership->id);

        static::__assert_false(Session::is_developer(), 'the probe identity is not a developer');
    }

    /**
     * One call through the browser transport, carrying a real CSRF token so a refusal
     * is the gate's.
     */
    private static function __ajax(string $controller, string $action): array
    {
        Session::get_session_id();

        $request = Request::create('/_ajax/' . $controller . '/' . $action, 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_CSRF_TOKEN' => (string) Session::get_csrf_token(),
        ]);
        app()->instance('request', $request);

        $response = Rsx_Front_Controller::handle($request);

        return json_decode($response->getContent(), true) ?? ['raw' => $response->getContent()];
    }
}
