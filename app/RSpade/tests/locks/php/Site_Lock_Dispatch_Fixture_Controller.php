<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Auth\RsxAuth;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Lib\Messaging\Mail\Email_Blocked_Address_Model;

/**
 * Dispatchable surfaces for Site_Lock_Dispatch_Test: Ajax endpoints and page routes in each
 * site lock mode, which write a framework site-scoped row (_email_blocked_addresses, on the
 * session's site) or report the lock state they ran under. Indexed only under a test run.
 *
 * probe() is what every "report" surface returns: the lock this process holds through the
 * lock layer, every raw site lock hold and its count, the database transaction level, and
 * whether a write would be enforced.
 *
 * The controller's pre_dispatch records probe() and, when the call's params carry '_pre',
 * acts: 'write' writes params['pre_email'] with no lock, 'section' writes it inside a
 * Site_Lock::write() section, 'leak' takes the site's raw read lock and never releases it.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Site_Lock_Dispatch_Fixture_Controller extends Rsx_Controller_Abstract
{
    /** probe() as the controller's pre_dispatch saw it, for the last call. */
    public static ?array $pre_dispatch_seen = null;

    /** The run Task::dispatch() returned inside writer_dispatches_then_throws(). */
    public static ?int $dispatched_task_id = null;

    #[Replaceable]
    public static function pre_dispatch(Request $request, array $params = [])
    {
        static::$pre_dispatch_seen = static::probe();
        $site_id = (int) Session::get_site_id();

        switch ($params['_pre'] ?? null) {
            case 'write':
                static::__block((string) $params['pre_email']);
                break;
            case 'section':
                Site_Lock::write($site_id, fn () => static::__block((string) $params['pre_email']));
                break;
            case 'leak':
                RsxLocks::site_read_lock($site_id);
                break;
        }

        return null;
    }

    /** The lock state the caller runs under. */
    public static function probe(): array
    {
        $held = Site_Lock::_held();

        return [
            'mode' => $held['mode'] ?? null,
            'site_id' => $held['site_id'] ?? null,
            'holds' => RsxLocks::site_lock_hold_counts(),
            'token' => $held['token'] ?? null,
            'level' => DB::transactionLevel(),
            'enforcing' => Site_Lock::_is_enforcing(),
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Ajax
    // -----------------------------------------------------------------------------------------

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_writes(Request $request, array $params = [])
    {
        static::__block((string) $params['email']);

        return true;
    }

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_writes(Request $request, array $params = [])
    {
        static::__block((string) $params['email']);

        if (!empty($params['throw'])) {
            throw new RuntimeException('writer_writes threw after writing');
        }

        return static::probe();
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_probe(Request $request, array $params = [])
    {
        return static::probe();
    }

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_probe(Request $request, array $params = [])
    {
        return static::probe();
    }

    /** A reader calling a writer in-process: a lock is never upgraded. */
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_calls_writer(Request $request, array $params = [])
    {
        return Ajax::internal('Site_Lock_Dispatch_Fixture_Controller', 'writer_probe');
    }

    /** A writer calling a reader and a writer in-process: both run inside its hold. */
    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_calls_both(Request $request, array $params = [])
    {
        return [
            'outer' => static::probe(),
            'reader' => Ajax::internal('Site_Lock_Dispatch_Fixture_Controller', 'reader_probe'),
            'writer' => Ajax::internal('Site_Lock_Dispatch_Fixture_Controller', 'writer_probe'),
        ];
    }

    /**
     * A #[Writer] calling a library that takes and releases the raw site locks itself, then
     * writing: the automatic write lock outlives the library's releases.
     */
    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_uses_library(Request $request, array $params = [])
    {
        $site_id = (int) Session::get_site_id();

        $library = function () use ($site_id) {
            RsxLocks::release_lock(RsxLocks::site_write_lock($site_id));
            RsxLocks::release_lock(RsxLocks::site_read_lock($site_id));
            Site_Lock::write($site_id, fn () => null);
            Site_Lock::read($site_id, fn () => null);
        };

        $library();
        $after_library = static::probe();

        static::__block((string) $params['email']);

        return $after_library;
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_changes_site(Request $request, array $params = [])
    {
        Session::set_temporary_site_id((int) $params['site_id']);

        return true;
    }

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function reader_enters_site(Request $request, array $params = [])
    {
        RsxAuth::enter_site((int) $params['site_id']);

        return true;
    }

    #[Ajax_Endpoint]
    #[Manual_Lock]
    #[Auth('public')]
    public static function manual_enters_site(Request $request, array $params = [])
    {
        $before = static::probe();

        RsxAuth::enter_site((int) $params['site_id']);

        return ['before' => $before, 'site_id' => Session::get_site_id(), 'after' => static::probe()];
    }

    #[Ajax_Endpoint]
    #[Writer]
    #[Auth('public')]
    public static function writer_dispatches_then_throws(Request $request, array $params = [])
    {
        static::$dispatched_task_id = Task::dispatch('Site_Lock_Fixture_Service', 'leak_transaction');

        throw new RuntimeException('writer_dispatches_then_throws threw after dispatching');
    }

    /**
     * A #[Manual_Lock] endpoint taking the raw site write lock, writing, releasing it EARLY,
     * then ($params['then']) returning, throwing, or asking for the lock again.
     */
    #[Ajax_Endpoint]
    #[Manual_Lock]
    #[Auth('public')]
    public static function manual_raw_lock(Request $request, array $params = [])
    {
        $site_id = (int) Session::get_site_id();
        $before = DB::transactionLevel();

        $token = RsxLocks::site_write_lock($site_id);
        $inside = DB::transactionLevel();
        static::__block((string) $params['email']);
        RsxLocks::release_lock($token);

        if (($params['then'] ?? '') === 'throw') {
            throw new RuntimeException('manual_raw_lock threw after releasing early');
        }

        if (($params['then'] ?? '') === 'relock') {
            RsxLocks::site_write_lock($site_id);
        }

        return ['before' => $before, 'inside' => $inside, 'after_release' => DB::transactionLevel()];
    }

    // -----------------------------------------------------------------------------------------
    // Page routes
    // -----------------------------------------------------------------------------------------

    #[Route('/_test/site-lock/route-probe')]
    #[Auth('public')]
    public static function route_probe(Request $request, array $params = [])
    {
        return ['type' => 'json', 'data' => static::probe()];
    }

    #[Route('/_test/site-lock/route-writer-probe')]
    #[Writer]
    #[Auth('public')]
    public static function route_writer_probe(Request $request, array $params = [])
    {
        return ['type' => 'json', 'data' => static::probe()];
    }

    private static function __block(string $email): void
    {
        $row = new Email_Blocked_Address_Model();
        $row->email = $email;
        $row->reason = 'site lock dispatch test';
        $row->save();
    }
}
