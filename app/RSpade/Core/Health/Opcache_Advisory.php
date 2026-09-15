<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Rsx;

/**
 * Opcache_Advisory - one line in the log, at most once every six hours, when the process
 * serving web requests has no OPcache.
 *
 * WHETHER A HOST RUNS OPcache IS THE HOST'S DECISION, not this framework's: it is a
 * php.ini setting on a machine RSpade does not own, and there is no reading of it that
 * makes an application refuse to serve. So this is not a health row and not an error -
 * rsx:health deliberately carries no OPcache check - it is a RECOMMENDATION, written
 * where an operator reading their logs will find it, and written rarely enough that it
 * never becomes something to filter out.
 *
 * WEB SAPI ONLY. OPcache is normally and correctly off for the CLI SAPI (opcache.enable_cli
 * defaults off, and a command is a single short-lived process with nothing to cache
 * between requests), so a CLI warning would be advice to make a change nobody should make.
 * That also makes the ini the right thing to read: opcache.enable is what the php-fpm
 * worker serving this request applies.
 *
 * BEST EFFORT THROUGHOUT. The throttle is RsxCache, which means Redis: a miss, an outage
 * or a refused write must never block the request or turn into a second log line, so a
 * failed write simply means the recommendation may be repeated sooner than six hours.
 *
 * THE THROTTLE IS SCOPED TO THE BUILD FOR FREE. RsxCache's build-scoped family folds
 * Manifest::get_build_key() into every key, so a rebuilt box says it once again - which is
 * the right moment to hear it, since a rebuild is when an operator is looking.
 */
class Opcache_Advisory
{
    /**
     * The throttle key. Build-scoped by RsxCache, so "once per six hours" is really
     * "once per six hours per build key".
     */
    public const CACHE_KEY = 'opcache_advisory_logged';

    /**
     * SIX HOURS is the cadence of a RECOMMENDATION, not a bound on any work: nothing
     * waits on it, nothing fails if it is late, and what it defers is one line of advice.
     * (The no-timeout mandate is about bounding WORK; this bounds how often optional
     * advice repeats.)
     */
    public const WINDOW_SECONDS = 21600;

    /**
     * The pre-dispatch check. Silent unless this is a web process with no OPcache.
     */
    public static function check(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (static::is_enabled()) {
            return;
        }

        static::_log_once(self::CACHE_KEY);
    }

    /**
     * Is OPcache enabled for the tier serving this request?
     *
     * ONE QUESTION, ONE READ: the opcache.enable ini value. That is the setting a php-fpm
     * worker applies and the exact thing the recommendation asks an operator to change,
     * so reading it is reading the answer. There is no second probe and no second code
     * path - opcache_get_status() is the live view of a cache that is already described
     * by this setting, and an extension that is not installed leaves the setting
     * undeclared, which reads as off.
     */
    public static function is_enabled(): bool
    {
        return static::_ini_enabled(ini_get('opcache.enable'));
    }

    /**
     * An opcache.enable ini value as a boolean. ini_get() answers false for a setting
     * nothing declares, and an undeclared setting is not an enabled one.
     *
     * @param mixed $value
     */
    public static function _ini_enabled($value): bool
    {
        if ($value === false || $value === null || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Write the recommendation unless this build key already had it inside the window.
     *
     * GET-THEN-SET, not set-if-absent: RsxCache exposes no add/NX primitive, and adding
     * one to the cache API for a log line is not the trade. The consequence is bounded
     * and stated - two requests arriving cold in the same instant can each write the line
     * once, after which the stamp holds for the window. It never spams.
     *
     * @param string $cache_key The throttle key (a parameter so the tests can drive the
     *                          window without touching the live stamp)
     * @return bool Whether the line was written
     */
    public static function _log_once(string $cache_key): bool
    {
        // A cache failure is answered as "not stamped yet", which is the safe direction:
        // the worst case is the advice repeating, and the alternative - treating an
        // outage as "already said" - is advice that silently never arrives.
        if (RsxCache::get($cache_key) !== null) {
            return false;
        }

        Log::warning(static::message());

        RsxCache::set($cache_key, time(), self::WINDOW_SECONDS);

        return true;
    }

    /**
     * The recommendation. One line plus the exact settings, because an operator reading a
     * log is not going to go and look up which two they are.
     */
    public static function message(): string
    {
        $message = '[OPCACHE] PHP OPcache is not enabled for this web process, so every PHP file is'
            . ' compiled again on every request. Recommended in php.ini: opcache.enable=1.';

        if (Rsx::is_production()) {
            $message .= ' On this sealed build also set opcache.validate_timestamps=0 - the build is'
                . ' immutable, so validating timestamps is a stat() per included file per request'
                . ' buying nothing; reload php-fpm after each php artisan rsx:build --force so the'
                . ' new build is compiled.';
        }

        $message .= ' Whether to run OPcache is the host\'s decision - this is a recommendation,'
            . ' logged at most once every 6 hours per build.';

        return $message;
    }
}
