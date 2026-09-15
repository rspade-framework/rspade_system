<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Health\Opcache_Advisory;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The once-per-six-hours OPcache recommendation written during boot.
 *
 * WHAT IS BEING PROVED is the throttle, not the probe: a first call writes the line, a
 * second inside the window does not, and a key belonging to another build writes again.
 * The window is driven by the stamp itself - never by waiting - because six hours is the
 * cadence of a recommendation and a test that slept for it would be a test nobody runs.
 *
 * THE BUILD-KEY DIMENSION is RsxCache's, not this class's: the build-scoped family folds
 * Manifest::get_build_key() into every key, so a rebuilt box is a different redis key by
 * construction. A distinct key suffix is that same mechanism one level up, which is what
 * the third test drives - a test cannot mint a second build key without rebuilding the
 * manifest under itself.
 *
 * Runs under the CLI SAPI, so check() is also the CLI assertion: it must write nothing
 * here however this box's OPcache is configured.
 *
 * No database access - skip the per-test transaction.
 */
class Opcache_Advisory_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Throttle keys this test wrote, cleared afterwards. */
    private static $keys = [];

    /** Lines the advisory wrote while the spy was listening. */
    private static $captured = [];

    private static function __key(): string
    {
        $key = 'opcache_advisory_test_' . random_hash(12);
        self::$keys[] = $key;

        return $key;
    }

    private static function __cleanup(): void
    {
        foreach (self::$keys as $key) {
            RsxCache::delete($key);
        }

        self::$keys = [];
        self::$captured = [];
    }

    /**
     * Run $fn with a listener collecting the advisory's own log lines.
     */
    private static function __capture(callable $fn): array
    {
        self::$captured = [];

        Log::listen(function ($message) {
            if (str_contains($message->message, '[OPCACHE]')) {
                self::$captured[] = $message->message;
            }
        });

        $fn();

        return self::$captured;
    }

    // -------------------------------------------------------------------------
    // The throttle
    // -------------------------------------------------------------------------

    public static function test_the_first_call_logs_and_the_second_inside_the_window_does_not()
    {
        try {
            $key = self::__key();

            $first = self::__capture(static fn () => static::__assert_true(
                Opcache_Advisory::_log_once($key),
                'the first call writes the recommendation'
            ));

            static::__assert_count(1, $first, 'exactly one line, never a burst');

            $second = self::__capture(static fn () => static::__assert_false(
                Opcache_Advisory::_log_once($key),
                'a second call inside the window writes nothing'
            ));

            static::__assert_count(0, $second, 'the window is silent');
        } finally {
            self::__cleanup();
        }
    }

    public static function test_another_build_key_logs_again()
    {
        try {
            $first = self::__key();
            $second = self::__key();

            Opcache_Advisory::_log_once($first);

            static::__assert_true(
                Opcache_Advisory::_log_once($second),
                'a different scope has its own window - a rebuild says it once more'
            );

            static::__assert_null(
                RsxCache::get(self::__key()),
                'a scope nothing has stamped reads as unstamped'
            );
        } finally {
            self::__cleanup();
        }
    }

    public static function test_the_stamp_carries_the_window()
    {
        try {
            $key = self::__key();
            Opcache_Advisory::_log_once($key);

            static::__assert_not_null(RsxCache::get($key), 'the stamp is what closes the window');
            static::__assert_equals(21600, Opcache_Advisory::WINDOW_SECONDS, 'six hours');
        } finally {
            self::__cleanup();
        }
    }

    // -------------------------------------------------------------------------
    // The SAPI guard
    // -------------------------------------------------------------------------

    public static function test_a_cli_process_is_never_advised()
    {
        try {
            // OPcache is normally and correctly off for the CLI SAPI, so a warning here
            // would be advice to make a change nobody should make. The suite runs under
            // cli, so calling check() directly IS the assertion.
            $lines = self::__capture(static fn () => Opcache_Advisory::check());

            static::__assert_count(0, $lines, 'a command never receives the recommendation');
            static::__assert_null(
                RsxCache::get(Opcache_Advisory::CACHE_KEY),
                'and it does not consume the live window either'
            );
        } finally {
            self::__cleanup();
        }
    }

    // -------------------------------------------------------------------------
    // The probe and the message
    // -------------------------------------------------------------------------

    public static function test_an_undeclared_ini_reads_as_off()
    {
        // ini_get() answers false for a setting nothing declares. Reading that as "on"
        // would silence the recommendation on exactly the boxes that need it.
        static::__assert_false(Opcache_Advisory::_ini_enabled(false), 'undeclared is not enabled');
        static::__assert_false(Opcache_Advisory::_ini_enabled('0'), '0 is not enabled');
        static::__assert_true(Opcache_Advisory::_ini_enabled('1'), '1 is enabled');
        static::__assert_true(Opcache_Advisory::_ini_enabled('On'), 'On is enabled');
    }

    public static function test_the_message_names_the_setting_and_its_own_cadence()
    {
        $message = Opcache_Advisory::message();

        static::__assert_contains('opcache.enable=1', $message, 'the operator is told the exact setting');
        static::__assert_contains('recommendation', $message, 'it says what it is - not an error');
        static::__assert_contains('6 hours', $message, 'and how often it will say it');
    }
}
