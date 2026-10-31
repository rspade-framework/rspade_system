<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\Performance;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;
use App\RSpade\Core\Health\Rsx_Container_Sizing;
use App\RSpade\Core\Metrics\Rsx_Metrics;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The Performance screen's endpoints: what requests and task runs have used (Rsx_Metrics),
 * the budget the container sized the machine for (Rsx_Container_Sizing), and php-fpm's live
 * pool status.
 *
 * Two endpoints, because the second reaches another process: the snapshot is Redis reads,
 * while the pool status asks php-fpm itself, which may be too busy to answer.
 */
#[Auth('is_sysadmin')]
class _Sys_Performance_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /** php-fpm's status page per pool, on the container's loopback-only nginx listener. */
    public const FPM_STATUS_URL = 'http://127.0.0.1:3201/_fpm_status/';

    /** The pools, as the status URL names them. */
    public const FPM_POOLS = ['web', 'ajax'];

    /**
     * SANCTIONED WAIT (owner-approved 2026-10-31): how long the pool status may take to
     * answer, connect and read. php-fpm serves its status page from a worker of the pool
     * being described, so a pool with every worker busy cannot answer until one frees up -
     * and that pool is the one the screen most needs to show. The screen asks again every 15
     * seconds, so waiting longer than a fraction of that only stacks requests on a saturated
     * pool; past the wait the tile reads "no answer - every worker may be busy", which is
     * itself the answer. It bounds a WAIT on another process and truncates no work.
     */
    public const FPM_STATUS_WAIT_SECONDS = 2;

    /**
     * Test seam: called as fn(string $pool): ?string instead of the HTTP fetch, answering the
     * status page's JSON body or null. Nothing outside a test run may set it.
     *
     * @var callable|null
     */
    public static $fpm_fetcher_for_tests = null;

    /**
     * Everything the screen draws but the pool status:
     * {enabled, kinds: {<kind>: {recent[], summary{}}}, minutes[], budget|null}.
     * minutes is the last hour, oldest first (Rsx_Metrics::minutes()); budget is null when
     * nothing sized this box.
     */
    #[Ajax_Endpoint]
    public static function get_snapshot(Request $request, array $params = [])
    {
        $kinds = [];
        foreach (Rsx_Metrics::KINDS as $kind) {
            $kinds[$kind] = [
                'recent' => Rsx_Metrics::recent($kind, 10),
                'summary' => Rsx_Metrics::average_recent($kind),
            ];
        }

        return [
            'enabled' => Rsx_Metrics::enabled(),
            'kinds' => $kinds,
            'minutes' => Rsx_Metrics::minutes(60),
            'budget' => static::budget(),
        ];
    }

    /**
     * php-fpm's own view of each pool: {pools: {web|ajax: {active, idle, total, queue,
     * max_queue, max_children_reached, accepted, since_seconds} | null}}. A pool that did not
     * answer is null.
     */
    #[Ajax_Endpoint]
    public static function get_fpm_status(Request $request, array $params = [])
    {
        $pools = [];
        foreach (self::FPM_POOLS as $pool) {
            $pools[$pool] = static::parse_fpm_status(static::__fetch_fpm_status($pool));
        }

        return ['pools' => $pools];
    }

    /**
     * The budget as the screen draws it, or null when nothing sized this box:
     * {php_ram_mb, php_ram_and_swap_mb, php_fpm_total, web, ajax, pools_sized, tasks, scheduled, on_demand,
     * memory_limit_mb, typical_mb, planned_typical_mb, worker_ceiling, ceiling}.
     */
    public static function budget(): ?array
    {
        $sizing = Rsx_Container_Sizing::read();
        if ($sizing === null) {
            return null;
        }

        $php_fpm = (int) $sizing['php_fpm']['total'];
        $tasks = (int) $sizing['tasks']['total'];
        $typical = (int) $sizing['php']['typical_mb'];

        return [
            'php_ram_mb' => (int) $sizing['php']['ram_mb'],
            'php_ram_and_swap_mb' => (int) $sizing['php']['ram_and_swap_mb'],
            'php_fpm_total' => $php_fpm,
            'web' => (int) $sizing['php_fpm']['web'],
            'ajax' => (int) $sizing['php_fpm']['ajax'],
            'pools_sized' => (bool) $sizing['php_fpm']['pools_sized'],
            'tasks' => $tasks,
            'scheduled' => (int) $sizing['tasks']['scheduled'],
            'on_demand' => (int) $sizing['tasks']['on_demand'],
            'memory_limit_mb' => (int) $sizing['php']['memory_limit_mb'],
            'typical_mb' => $typical,
            'planned_typical_mb' => ($php_fpm + $tasks) * $typical,
            'worker_ceiling' => $php_fpm + $tasks,
            'ceiling' => (string) $sizing['ceilings']['winner'],
        ];
    }

    /**
     * php-fpm's JSON status body as the screen's fields, or null for no answer or a body
     * that is not one.
     */
    public static function parse_fpm_status(?string $body): ?array
    {
        $status = $body === null ? null : json_decode($body, true);
        if (!is_array($status) || !isset($status['active processes'])) {
            return null;
        }

        return [
            'active' => (int) $status['active processes'],
            'idle' => (int) ($status['idle processes'] ?? 0),
            'total' => (int) ($status['total processes'] ?? 0),
            'queue' => (int) ($status['listen queue'] ?? 0),
            'max_queue' => (int) ($status['max listen queue'] ?? 0),
            'max_children_reached' => (int) ($status['max children reached'] ?? 0),
            'accepted' => (int) ($status['accepted conn'] ?? 0),
            'since_seconds' => (int) ($status['start since'] ?? 0),
        ];
    }

    private static function __fetch_fpm_status(string $pool): ?string
    {
        if (static::$fpm_fetcher_for_tests !== null) {
            return (static::$fpm_fetcher_for_tests)($pool);
        }

        // An outbound call to another process, which may not be listening at all (a box
        // outside the RSpade container): no answer is the null the screen draws.
        try {
            $response = Http::connectTimeout(self::FPM_STATUS_WAIT_SECONDS)
                ->timeout(self::FPM_STATUS_WAIT_SECONDS)
                ->get(self::FPM_STATUS_URL . $pool . '?json');
        } catch (Throwable $e) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }
}
