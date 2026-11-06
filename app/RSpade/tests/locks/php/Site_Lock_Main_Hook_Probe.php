<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Php;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;

/**
 * Stands in for the application's Main during Site_Lock_Dispatch_Test: records the lock
 * state its pre_dispatch() and unhandled_route() run under, and runs what the test hands it.
 *
 * It deliberately does NOT extend Main_Abstract: an application has exactly one Main and the
 * framework refuses a second at boot. The test points the manifest's Main_Abstract subclass
 * entry at this class for the duration of the call and restores it after, so the
 * dispatcher's own lookup is what finds it.
 */
class Site_Lock_Main_Hook_Probe
{
    /** What pre_dispatch() runs after recording, or null. Its return value is the hook's. */
    public static ?Closure $pre_dispatch = null;

    /** What unhandled_route() answers, or null to decline. */
    public static ?Closure $unhandled_route = null;

    /** [hook => state] as each hook last saw it. */
    public static array $seen = [];

    public static function reset(): void
    {
        static::$pre_dispatch = null;
        static::$unhandled_route = null;
        static::$seen = [];
    }

    public static function pre_dispatch(Request $request, array $params)
    {
        static::$seen['pre_dispatch'][] = static::__state();

        return static::$pre_dispatch === null ? null : (static::$pre_dispatch)();
    }

    public static function unhandled_route(Request $request, array $params)
    {
        static::$seen['unhandled_route'][] = static::__state();

        return static::$unhandled_route === null ? null : (static::$unhandled_route)();
    }

    private static function __state(): array
    {
        return [
            'enforcing' => Site_Lock::_is_enforcing(),
            'holds' => RsxLocks::site_lock_hold_counts(),
            'held' => Site_Lock::_held(),
            'level' => DB::transactionLevel(),
        ];
    }
}
