<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Debug;

use App\RSpade\Core\Auth\Login_Throttle;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;

/**
 * Browser_Log_Budget - how much a browser may write to the server log, and what it may write.
 *
 * Three public endpoints turn a browser's report into log lines: the browser-error sink
 * (Debugger::log_browser_errors(), on in the template), the CSP violation collector
 * (Csp_Report_Controller) and the console_debug sink (Debugger::log_console_messages(), when
 * console_debug's laravel_log output is on). They are public because an error or a refusal
 * happens on a sign-in page too - which makes each an anonymous log writer, and this class is
 * what keeps that from filling a disk or forging a line.
 *
 * THE QUOTA is counted per client address (an IPv6 address by its /64,
 * Login_Throttle::address_bucket()) in Rsx_Counter's fixed windows (redis), stricter for a
 * caller who is not signed in:
 *
 *                      10 minutes   2 hours   a day
 *     signed in            60         200      400
 *     not signed in        30          60      200
 *
 * Browser errors and CSP reports SHARE one quota (SINK_ERRORS), and spend it per UNIQUE
 * ENTRY: admit() lets an exact log string through at most once per address per 30 minutes
 * (DEDUP_WINDOW_SECONDS, keyed by the string's xxh128 hash - a fast non-cryptographic hash is
 * all a dedup key needs), and only a first sighting spends a slot. The console_debug sink has
 * its own quota, spent per CALL (spend()).
 *
 * Whatever is not admitted is DISCARDED SILENTLY - past the quota, a duplicate, and any
 * failure of the counter store (redis down, or any other error reaching it) alike. Redis
 * being down is close to a fatal state for the system, and a browser's report is never worth
 * logging unbounded, so the sinks FAIL CLOSED: nothing is written. These are volume limits, not
 * timeouts: a real page with a real bug reports a handful of entries per load, so the
 * numbers leave room for a busy person and none for a script. Precision is not the goal - the
 * counters are fixed windows and the checks are not atomic with each other.
 *
 * WHAT IS WRITTEN is bounded too: at most MAX_ENTRIES_PER_CALL entries per call (one line
 * says how many were dropped), each field cut to its own length, control characters removed
 * from single-line fields, and every line of a multi-line field (a stack trace) indented - so
 * nothing a browser sends can start a line that reads as a log entry of its own.
 */
class Browser_Log_Budget
{
    /** The browser-error sink, and the CSP collector, which shares its quota. */
    public const SINK_ERRORS = 'errors';

    /** The console_debug sink. */
    public const SINK_CONSOLE = 'console';

    /** Calls per window, by tier: window seconds => calls. */
    private const SIGNED_IN_LIMITS = [600 => 60, 7200 => 200, 86400 => 400];
    private const ANONYMOUS_LIMITS = [600 => 30, 7200 => 60, 86400 => 200];

    /** How long an exact entry is remembered per address: a repeat inside it is dropped. */
    public const DEDUP_WINDOW_SECONDS = 1800;

    /** Entries written from one call; the rest are counted in one line. */
    public const MAX_ENTRIES_PER_CALL = 20;

    /** Field lengths, in characters. */
    public const MAX_MESSAGE = 2000;
    public const MAX_LOCATION = 500;
    public const MAX_USER_AGENT = 300;
    public const MAX_STACK = 8000;

    /**
     * May this exact log entry be written? True only for its FIRST sighting from this address
     * in DEDUP_WINDOW_SECONDS, and only when that spends a slot of $sink's quota. False -
     * write nothing - for a repeat, past the quota, or when the counter store cannot answer.
     */
    public static function admit(string $sink, string $entry): bool
    {
        try {
            $seen = Rsx_Counter::increment("browser_log:seen:{$sink}:" . self::__address_hash() . ':' . hash('xxh128', $entry), self::DEDUP_WINDOW_SECONDS);
        } catch (\Throwable $e) {
            return false;
        }

        return $seen === 1 && static::spend($sink);
    }

    /**
     * Spend one slot of $sink's quota for this caller; false - write nothing - when any window
     * is exhausted or the counter store cannot answer.
     */
    public static function spend(string $sink): bool
    {
        try {
            $signed_in = Session::is_logged_in() || Portal_Session::is_logged_in();
            $limits = $signed_in ? self::SIGNED_IN_LIMITS : self::ANONYMOUS_LIMITS;

            $within = true;
            foreach ($limits as $window => $limit) {
                $count = Rsx_Counter::increment(self::__key($sink, $signed_in, $window), $window);

                // 0 is the counter store answering nothing (redis down).
                if ($count === 0 || $count > $limit) {
                    $within = false;
                }
            }

            return $within;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Forget this caller's spending on $sink, both tiers (not the dedup memory, which a test
     * avoids by sending strings of its own). FRAMEWORK INTERNAL - the test harness, whose calls
     * all come from one address.
     */
    public static function _reset(string $sink): void
    {
        foreach ([true, false] as $signed_in) {
            foreach (array_keys($signed_in ? self::SIGNED_IN_LIMITS : self::ANONYMOUS_LIMITS) as $window) {
                Rsx_Counter::reset(self::__key($sink, $signed_in, $window));
            }
        }
    }

    private static function __key(string $sink, bool $signed_in, int $window): string
    {
        return "browser_log:{$sink}:" . ($signed_in ? 'user' : 'anon') . ":{$window}:" . self::__address_hash();
    }

    private static function __address_hash(): string
    {
        return hash('xxh128', Login_Throttle::address_bucket(Session::get_client_ip() ?? 'CLI'));
    }

    /**
     * The entries of one call that are written, and how many were dropped.
     *
     * @return array{0: array, 1: int}
     */
    public static function entries($entries): array
    {
        if (!is_array($entries)) {
            return [[], 0];
        }

        $entries = array_values($entries);

        return [array_slice($entries, 0, self::MAX_ENTRIES_PER_CALL), max(0, count($entries) - self::MAX_ENTRIES_PER_CALL)];
    }

    /**
     * A value as ONE log line's worth: a string (anything else JSON-encoded), control
     * characters replaced by spaces, cut to $max.
     */
    public static function line($value, int $max): string
    {
        $text = self::__text($value);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';

        return self::__cut($text, $max);
    }

    /**
     * A multi-line value (a stack trace): cut to $max, control characters other than the line
     * break removed, and every line indented, so none can begin a log entry.
     */
    public static function block($value, int $max): string
    {
        $text = self::__cut(str_replace(["\r\n", "\r"], "\n", self::__text($value)), $max);
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $text) ?? '';

        return '    ' . str_replace("\n", "\n    ", $text);
    }

    private static function __text($value): string
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function __cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . ' ...(truncated)' : $text;
    }
}
