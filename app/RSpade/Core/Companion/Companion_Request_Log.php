<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use Illuminate\Support\Facades\Log;
use Throwable;
use App\RSpade\Core\Companion\Companion_Service_Abstract;
use App\RSpade\Core\Companion\Companion_Store;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Companion_Request_Log - the companion realm's request log.
 *
 * ONE LINE PER CALL IN A FILE PLUS REDIS COUNTERS, NEVER A MYSQL ROW. A companion runs for
 * months and calls continuously, and a table row per call is the constant write load the
 * feature exists to avoid. Bodies are never recorded.
 *
 *   storage/logs/companion_requests.log   one JSON line per request, refused ones included;
 *                                         a top-level *.log, so rsx:logrotate rotates it
 *   stats:<name>:<YYYYmmddHHii> (DB 4)    a hash per companion and UTC minute, for AUTHENTICATED
 *                                         calls only: <endpoint>:calls, :errors, :ms
 *
 * Counters are evictable, a lost one is a gap in a chart, and a request that worked is never
 * turned into one that failed by the act of counting it.
 *
 * THE SDK'S PROBE is not an error. ready() posts to the reserved, never-declared
 * Companion_Service_Abstract::SDK_PROBE_ENDPOINT, and an authenticated 404 there is the answer
 * it wants: the line keeps its 404 with no error code, and the counters count the call and no
 * error.
 */
class Companion_Request_Log
{
    /**
     * Record one finished request.
     *
     * @param string|null $name The companion, null for a daemon call or a malformed path
     * @param string|null $endpoint The endpoint or daemon verb
     * @param string|null $instance_id
     * @param int|null $site_id
     * @param int $status
     * @param string|null $code The error code, null on success
     * @param int $ms
     * @param bool $authenticated Whether the signature was accepted
     */
    public static function record(
        ?string $name,
        ?string $endpoint,
        ?string $instance_id,
        ?int $site_id,
        int $status,
        ?string $code,
        int $ms,
        bool $authenticated
    ): void {
        if ($authenticated && $endpoint === Companion_Service_Abstract::SDK_PROBE_ENDPOINT && $status === 404) {
            $code = null;
        }

        $line = json_encode([
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'name' => $name,
            'endpoint' => $endpoint,
            'instance' => $instance_id,
            'site_id' => $site_id,
            'status' => $status,
            'code' => $code,
            'ms' => $ms,
        ], JSON_UNESCAPED_SLASHES);

        // A log that cannot be written is a warning, never the request's failure.
        if (@file_put_contents(Rsx_Project_Paths::companion_request_log_file(), $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            Log::warning('Companion request log could not be written: ' . $line);
        }

        if ($authenticated && $name !== null && $endpoint !== null) {
            static::__count($name, $endpoint, $status, $ms);
        }
    }

    /**
     * One minute's counters. A catch by design: see the class docblock.
     */
    private static function __count(string $name, string $endpoint, int $status, int $ms): void
    {
        try {
            $redis = Companion_Store::connection();

            if ($redis === null) {
                return;
            }

            $key = Companion_Store::request_stats_key($name, gmdate('YmdHi'));
            $retention_seconds = (int) config('rsx.companions.request_stats_retention_hours') * 3600;

            $pipe = $redis->pipeline();
            $pipe->hIncrBy($key, $endpoint . ':calls', 1);
            $pipe->hIncrBy($key, $endpoint . ':ms', $ms);
            if ($status >= 400 && !($endpoint === Companion_Service_Abstract::SDK_PROBE_ENDPOINT && $status === 404)) {
                $pipe->hIncrBy($key, $endpoint . ':errors', 1);
            }
            $pipe->expire($key, $retention_seconds);
            $pipe->exec();
        } catch (Throwable $e) {
            Log::warning('Companion request counters were not recorded: ' . $e->getMessage());
        }
    }
}
