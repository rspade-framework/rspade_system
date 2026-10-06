<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Api;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Api_Route_Usage - when each external API route pattern was last called (_api_route_usage).
 *
 * One row per ROUTE PATTERN (/api/v1/contacts/:id, never the concrete URL), with the pattern's
 * version. Api_Dispatcher records a call once an authenticated key has matched a route and
 * cleared its scope - a request with no valid key, or for no route, says nothing about who
 * still depends on an endpoint, so it is not counted.
 *
 * It exists to answer the question that decides whether an API version can be retired - "is
 * anything still calling v1?" - in one query (last_called_for_version()), where the request
 * log would need a scan of its unindexed path column.
 *
 * WRITES AT MOST ONCE A MINUTE PER PATTERN. The timestamp is refreshed only when the stored
 * one is more than a minute old; inside that minute the write changes nothing, so a busy
 * endpoint is not rewritten on every request. The minute is the RESOLUTION of the answer
 * (owner-specified): last_called_at may trail the latest call by up to a minute, which is
 * nothing to a question asked about weeks.
 */
class Api_Route_Usage
{
    /**
     * Record a call to the route at $pattern.
     *
     * One statement: insert the row, or refresh last_called_at only when it is more than a
     * minute old - an unchanged row is not written.
     *
     * @param string $pattern The matched route pattern (the manifest's, with its :tokens).
     */
    public static function record(string $pattern): void
    {
        DB::statement(
            'INSERT INTO _api_route_usage (pattern, version, last_called_at, created_at, updated_at)'
            . ' VALUES (?, ?, NOW(3), NOW(3), NOW(3))'
            . ' ON DUPLICATE KEY UPDATE'
            . ' updated_at = IF(last_called_at < NOW(3) - INTERVAL 1 MINUTE, NOW(3), updated_at),'
            . ' last_called_at = IF(last_called_at < NOW(3) - INTERVAL 1 MINUTE, NOW(3), last_called_at)',
            [$pattern, Api_Catalog::parse_version($pattern)]
        );
    }

    /**
     * Every recorded route pattern of one version with when it was last called, most recent
     * first. Empty means no authenticated key has called that version.
     *
     * The set is bounded by the routes the API declares, so it is returned whole.
     *
     * @return array<int, array{pattern: string, last_called_at: string}>
     */
    public static function last_called_for_version(int $version): array
    {
        return DB::table('_api_route_usage')
            ->where('version', $version)
            ->orderByDesc('last_called_at')
            ->get(['pattern', 'last_called_at'])
            ->map(fn ($row) => ['pattern' => $row->pattern, 'last_called_at' => Rsx_Time::to_iso($row->last_called_at)])
            ->all();
    }
}
