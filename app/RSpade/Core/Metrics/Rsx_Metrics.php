<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Metrics;

use Redis;
use Throwable;
use App\RSpade\Core\Database\Rsx_Connection_Scope;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Framework\Framework_Maintenance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Metrics - how much memory and time each request and task run takes (Redis database 1).
 *
 * Every page request, Ajax request, external API call and worker-run task records ONE entry
 * when it ends: its peak memory, how long it ran, and what it was (a controller action, the
 * first Ajax call of a batch, an API route pattern, a task's Service::method). The /_sys
 * Performance screen reads it back (rsx:man sys_panel).
 *
 * WHAT IS KEPT, per kind (KINDS):
 *
 *   recent:<kind>       a list of the last RECENT_KEEP entries, newest first
 *                       ("<unix ts>|<peak KB>|<ms>|<label>")
 *   min:<YYYYmmddHHii>  a hash per UTC minute, kept MINUTE_TTL_SECONDS:
 *                         <kind>:count     entries that ENDED in the minute
 *                         <kind>:busy_ms   milliseconds of work done IN the minute
 *                         <kind>:mem_ms    peak MB x milliseconds IN the minute
 *                         <kind>:peak_kb   the largest peak of an entry ending in the minute
 *
 * A run is spread over every minute it spanned (at most the last MAX_SPAN_MINUTES), so a
 * minute's busy_ms / 60000 is the average number of processes of that kind at work in it and
 * mem_ms / 60000 the average memory they held - which is what the screen charts against the
 * machine's budget.
 *
 * Keys are prefixed 'rsx_metrics:<Rsx_Connection_Scope::token()>:', so environments sharing
 * one Redis never mix. Database 1 is this class's alone (the database map is in the RsxCache
 * header); like everything in Redis it is evictable, and a lost entry is a gap in a chart.
 *
 * THE MONITOR NEVER ALTERS WHAT IT MEASURES. record() is called after the response has been
 * sent (a web request) or after the run (a task), and swallows every failure: a Redis that
 * cannot answer, the maintenance window, anything at all - a request that worked is never
 * turned into one that failed by the act of measuring it. That catch is the one deliberate
 * exception to the no-blanket-catch rule in this class; the readers fail loud.
 *
 * Off with rsx.metrics.enabled = false.
 */
class Rsx_Metrics
{
    /** Redis database holding metrics. See the RsxCache class header for the database map. */
    private const METRICS_DB = 1;

    public const KIND_PAGE = 'page';
    public const KIND_AJAX = 'ajax';
    public const KIND_API = 'api';
    public const KIND_TASK = 'task';

    /** Every kind, in the order the screen lists them. */
    public const KINDS = [self::KIND_PAGE, self::KIND_AJAX, self::KIND_API, self::KIND_TASK];

    /** Entries kept per kind in the recent list. */
    public const RECENT_KEEP = 100;

    /** How long a minute's hash is kept: two hours, twice what the screen charts. */
    public const MINUTE_TTL_SECONDS = 7200;

    /** The recent lists of a kind nothing has recorded for a week are dropped. */
    private const RECENT_TTL_SECONDS = 604800;

    /** The most minutes one entry is spread over: a longer run counts its last hour. */
    public const MAX_SPAN_MINUTES = 60;

    /** peak_kb kept as the maximum: HSET only when larger. */
    private const PEAK_MAX_SCRIPT = "local v = tonumber(redis.call('HGET', KEYS[1], ARGV[1]) or '0') "
        . "if tonumber(ARGV[2]) > v then redis.call('HSET', KEYS[1], ARGV[1], ARGV[2]) end return 1";

    private static ?Redis $__redis = null;

    /** The web request being measured: [kind, started_at], or null. */
    private static ?array $__request = null;

    /** The request's name, set once by the dispatcher (page, API) */
    private static ?string $__request_label = null;

    /** The first Ajax call of the request, and how many more followed it. */
    private static ?string $__ajax_label = null;

    private static int $__ajax_more = 0;

    public static function enabled(): bool
    {
        return (bool) config('rsx.metrics.enabled', true);
    }

    // -----------------------------------------------------------------------------------
    // Web requests
    // -----------------------------------------------------------------------------------

    /**
     * Measure this web request: record it when the process finishes with it. Called once by
     * the front controller after classifying the request. Assets are not measured, nor is
     * anything outside php-fpm (a test or a command driving the front controller in-process).
     *
     * A shutdown function, not the kernel's terminate phase, so a request that ended in exit()
     * or a fatal error - running out of memory above all - is measured too. The response has
     * been sent by then (Symfony's Response::send() finishes the FastCGI request), so the
     * client never waits on it.
     */
    public static function begin_request(string $channel): void
    {
        $kind = [
            Rsx_Request_Channel::PAGE => self::KIND_PAGE,
            Rsx_Request_Channel::AJAX => self::KIND_AJAX,
            Rsx_Request_Channel::API => self::KIND_API,
        ][$channel] ?? null;

        if ($kind === null || PHP_SAPI === 'cli' || self::$__request !== null || !static::enabled()) {
            return;
        }

        self::$__request = [$kind, (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))];
        register_shutdown_function([static::class, '_end_request']);
    }

    /** Name the request (a page's action, an API route pattern). The first name stands. */
    public static function name_request(string $label): void
    {
        self::$__request_label ??= $label;
    }

    /** An Ajax call ran: the first names an Ajax request, the rest are counted. */
    public static function name_ajax_call(string $label): void
    {
        if (self::$__ajax_label === null) {
            self::$__ajax_label = $label;
        } else {
            self::$__ajax_more++;
        }
    }

    /** FRAMEWORK INTERNAL - the shutdown function begin_request() registers. */
    public static function _end_request(): void
    {
        if (self::$__request === null) {
            return;
        }

        [$kind, $started_at] = self::$__request;
        self::$__request = null;

        $label = $kind === self::KIND_AJAX && self::$__ajax_label !== null
            ? self::$__ajax_label . (self::$__ajax_more > 0 ? ' +' . self::$__ajax_more : '')
            : (self::$__request_label ?? '(no route) ' . (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '?'), '?'));

        static::record($kind, $label, (int) (memory_get_peak_usage(true) / 1024), $started_at, microtime(true));
    }

    /**
     * Record a worker-run task's run. The worker calls this after each run, with the peak
     * reset before it began (one process serves many runs).
     */
    public static function record_task_run(string $label, float $started_at, float $ended_at): void
    {
        if (Rsx_Test_Abstract::suite_is_running()) {
            return;
        }

        static::record(self::KIND_TASK, $label, (int) (memory_get_peak_usage(true) / 1024), $started_at, $ended_at);
    }

    // -----------------------------------------------------------------------------------
    // Recording
    // -----------------------------------------------------------------------------------

    /**
     * Record one entry. Never throws and never fails its caller (see the class header).
     *
     * @param string $kind One of KINDS
     * @param string $label What ran
     * @param int $peak_kb Its peak memory, KB
     * @param float $started_at Unix time it started (microtime(true))
     * @param float $ended_at Unix time it ended
     */
    public static function record(string $kind, string $label, int $peak_kb, float $started_at, float $ended_at): void
    {
        try {
            if (!static::enabled() || !in_array($kind, self::KINDS, true)) {
                return;
            }

            $redis = static::__connection();
            if ($redis === null) {
                return;
            }

            $prefix = static::__prefix();
            $ended_at = max($ended_at, $started_at);
            $ms = (int) round(($ended_at - $started_at) * 1000);
            $peak_mb = $peak_kb / 1024;
            $label = mb_substr(str_replace(["\n", "\r", '|'], ' ', $label), 0, 200);

            $redis->multi(Redis::PIPELINE);

            $recent = $prefix . 'recent:' . $kind;
            $redis->lPush($recent, ((int) $ended_at) . '|' . $peak_kb . '|' . $ms . '|' . $label);
            $redis->lTrim($recent, 0, self::RECENT_KEEP - 1);
            $redis->expire($recent, self::RECENT_TTL_SECONDS);

            foreach (static::split_by_minute($started_at, $ended_at) as $minute => $minute_ms) {
                $key = $prefix . 'min:' . $minute;
                $redis->hIncrBy($key, $kind . ':busy_ms', $minute_ms);
                $redis->hIncrBy($key, $kind . ':mem_ms', (int) round($peak_mb * $minute_ms));
                $redis->expire($key, self::MINUTE_TTL_SECONDS);
            }

            $end_key = $prefix . 'min:' . gmdate('YmdHi', (int) $ended_at);
            $redis->hIncrBy($end_key, $kind . ':count', 1);
            $redis->eval(self::PEAK_MAX_SCRIPT, [$end_key, $kind . ':peak_kb', $peak_kb], 1);
            $redis->expire($end_key, self::MINUTE_TTL_SECONDS);

            $redis->exec();
        } catch (Throwable $e) {
            // The one sanctioned catch: the monitor never fails what it measures.
            self::$__redis = null;
        }
    }

    /**
     * The milliseconds of [$started_at, $ended_at] that fall in each UTC minute, keyed
     * 'YmdHi', for at most the last MAX_SPAN_MINUTES minutes.
     *
     * @return array<string, int>
     */
    public static function split_by_minute(float $started_at, float $ended_at): array
    {
        $started_at = max($started_at, $ended_at - self::MAX_SPAN_MINUTES * 60 + 0.001);
        $split = [];
        $cursor = $started_at;

        while ($cursor < $ended_at) {
            $minute_start = floor($cursor / 60) * 60;
            $until = min($ended_at, $minute_start + 60);
            $split[gmdate('YmdHi', (int) $minute_start)] = (int) round(($until - $cursor) * 1000);
            $cursor = $until;
        }

        // An instantaneous entry still lands in its minute.
        if ($split === []) {
            $split[gmdate('YmdHi', (int) $ended_at)] = 0;
        }

        return $split;
    }

    // -----------------------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------------------

    /**
     * The newest $count entries of a kind, newest first: {at (ISO UTC), peak_mb, ms, label}.
     *
     * @return array<int, array>
     */
    public static function recent(string $kind, int $count = 10): array
    {
        $redis = static::__connection();
        if ($redis === null) {
            return [];
        }

        $entries = [];
        foreach ($redis->lRange(static::__prefix() . 'recent:' . $kind, 0, $count - 1) ?: [] as $line) {
            $parts = explode('|', (string) $line, 4);
            if (count($parts) !== 4) {
                continue;
            }
            $entries[] = [
                'at' => gmdate('Y-m-d\TH:i:s\Z', (int) $parts[0]),
                'peak_mb' => round(((int) $parts[1]) / 1024, 1),
                'ms' => (int) $parts[2],
                'label' => $parts[3],
            ];
        }

        return $entries;
    }

    /**
     * The kept entries of a kind (up to RECENT_KEEP), summarised:
     * {count, avg_peak_mb, max_peak_mb, avg_ms, max_ms}. Zeros when there are none.
     */
    public static function average_recent(string $kind): array
    {
        $entries = static::recent($kind, self::RECENT_KEEP);
        $count = count($entries);

        if ($count === 0) {
            return ['count' => 0, 'avg_peak_mb' => 0, 'max_peak_mb' => 0, 'avg_ms' => 0, 'max_ms' => 0];
        }

        $peaks = array_column($entries, 'peak_mb');
        $times = array_column($entries, 'ms');

        return [
            'count' => $count,
            'avg_peak_mb' => round(array_sum($peaks) / $count, 1),
            'max_peak_mb' => max($peaks),
            'avg_ms' => (int) round(array_sum($times) / $count),
            'max_ms' => max($times),
        ];
    }

    /**
     * The last $count minutes, oldest first, ending with the minute of $until (now when null):
     * {minute (ISO UTC), kinds: {<kind>: {count, avg_workers, avg_mem_mb, peak_mb}}}.
     *
     * @return array<int, array>
     */
    public static function minutes(int $count = 60, ?int $until = null): array
    {
        $redis = static::__connection();
        $last = (int) (floor(($until ?? time()) / 60) * 60);
        $prefix = static::__prefix();

        $stamps = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $stamps[] = $last - $i * 60;
        }

        $hashes = [];
        if ($redis !== null) {
            $redis->multi(Redis::PIPELINE);
            foreach ($stamps as $stamp) {
                $redis->hGetAll($prefix . 'min:' . gmdate('YmdHi', $stamp));
            }
            $hashes = $redis->exec() ?: [];
        }

        $rows = [];
        foreach ($stamps as $index => $stamp) {
            $hash = is_array($hashes[$index] ?? null) ? $hashes[$index] : [];
            $kinds = [];
            foreach (self::KINDS as $kind) {
                $kinds[$kind] = [
                    'count' => (int) ($hash[$kind . ':count'] ?? 0),
                    'avg_workers' => round(((int) ($hash[$kind . ':busy_ms'] ?? 0)) / 60000, 3),
                    'avg_mem_mb' => round(((int) ($hash[$kind . ':mem_ms'] ?? 0)) / 60000, 1),
                    'peak_mb' => round(((int) ($hash[$kind . ':peak_kb'] ?? 0)) / 1024, 1),
                ];
            }
            $rows[] = ['minute' => gmdate('Y-m-d\TH:i:00\Z', $stamp), 'kinds' => $kinds];
        }

        return $rows;
    }

    /**
     * Forget everything recorded in this scope. FRAMEWORK INTERNAL - the test harness.
     */
    public static function _reset(): void
    {
        $redis = static::__connection();
        if ($redis === null) {
            return;
        }

        $iterator = null;
        while (($keys = $redis->scan($iterator, static::__prefix() . '*', 500)) !== false) {
            if ($keys !== []) {
                $redis->del($keys);
            }
            if ($iterator === 0) {
                break;
            }
        }
    }

    // -----------------------------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------------------------

    private static function __prefix(): string
    {
        return 'rsx_metrics:' . Rsx_Connection_Scope::token() . ':';
    }

    /**
     * This class's own connection, selected to METRICS_DB - a second connection rather than
     * a select() on the cache's, so no cache key can be written into the wrong database. The
     * connection rules are RsxCache's (REDIS_SOCKET, else REDIS_HOST/REDIS_PORT). Null in the
     * maintenance window, when Redis is stopped.
     *
     * No connect timeout of its own: every request has already connected to the same server
     * through RsxCache while booting, so a server that is not answering ended the request
     * long before it reached here.
     */
    private static function __connection(): ?Redis
    {
        if (Framework_Maintenance::is_active()) {
            return null;
        }

        if (self::$__redis !== null) {
            return self::$__redis;
        }

        $redis = new Redis();
        $socket = env('REDIS_SOCKET', null);
        $connected = ($socket && file_exists($socket))
            ? $redis->connect($socket)
            : $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));

        if (!$connected) {
            shouldnt_happen('Failed to connect to Redis for performance metrics');
        }

        $redis->select(self::METRICS_DB);
        self::$__redis = $redis;

        return $redis;
    }
}
