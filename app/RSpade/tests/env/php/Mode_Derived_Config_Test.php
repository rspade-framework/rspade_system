<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Env\Php;

use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * RSX_MODE is the SINGLE mode switch: Laravel's app.env and app.debug are DERIVED
 * from it in config/app.php, and the APP_ENV / APP_DEBUG env keys are read nowhere.
 *
 * HOW THIS IS TESTED. The derivation lives in a config FILE, and a config file is
 * evaluated once, during bootstrap - the container's copy cannot be re-derived by
 * changing an env var afterwards. So the test re-evaluates the real file: it sets
 * RSX_MODE across all three surfaces Laravel's env repository consults (putenv,
 * $_ENV, $_SERVER - the same three Rsx_Prod_Env::_apply_mode_to_process writes),
 * re-`require`s system/config/app.php, and reads the returned array. That exercises
 * the SHIPPED expression rather than a copy of it, and touches no file on disk.
 *
 * Every mutation is unwound in a finally, and the process environment is restored to
 * exactly what it was (including "was not set at all").
 *
 * Pure logic, no DB.
 */
class Mode_Derived_Config_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Evaluate the REAL config/app.php with RSX_MODE forced to $mode. */
    protected static function __app_config_for_mode(string $mode): array
    {
        $had = array_key_exists('RSX_MODE', $_ENV);
        $previous = $_ENV['RSX_MODE'] ?? null;

        putenv("RSX_MODE={$mode}");
        $_ENV['RSX_MODE'] = $mode;
        $_SERVER['RSX_MODE'] = $mode;

        try {
            return require base_path('config/app.php');
        } finally {
            if ($had) {
                putenv("RSX_MODE={$previous}");
                $_ENV['RSX_MODE'] = $previous;
                $_SERVER['RSX_MODE'] = $previous;
            } else {
                putenv('RSX_MODE');
                unset($_ENV['RSX_MODE'], $_SERVER['RSX_MODE']);
            }
        }
    }

    // -------------------------------------------------------------------------
    // The derivation, one mode per test
    // -------------------------------------------------------------------------

    public static function test_development_derives_local_and_debug_on()
    {
        $config = static::__app_config_for_mode(Rsx::MODE_DEVELOPMENT);

        static::__assert_equals('local', $config['env'], 'development is a local environment');
        static::__assert_true($config['debug'], 'development runs with debug on');
    }

    /**
     * debug is the sealed DIAGNOSTIC build - unminified, sourcemaps and console_debug()
     * survive by design - so it keeps debug ON and a local environment. It is a
     * production-LIKE build, never a production environment.
     */
    public static function test_debug_mode_derives_local_and_debug_on()
    {
        $config = static::__app_config_for_mode(Rsx::MODE_DEBUG);

        static::__assert_equals('local', $config['env'], 'the sealed debug build is still a local environment');
        static::__assert_true($config['debug'], 'the sealed debug build keeps debug output - that is its purpose');
    }

    public static function test_production_derives_production_and_debug_off()
    {
        $config = static::__app_config_for_mode(Rsx::MODE_PRODUCTION);

        static::__assert_equals('production', $config['env'], 'production mode IS the production environment');
        static::__assert_true($config['debug'] === false, 'production mode forces debug off');
    }

    /**
     * The reason the derivation exists: there is no second switch. An APP_ENV or
     * APP_DEBUG line in the environment must not move either value.
     */
    public static function test_app_env_and_app_debug_are_ignored()
    {
        $keys = ['APP_' . 'ENV' => 'production', 'APP_' . 'DEBUG' => 'true'];
        $restore = [];

        foreach ($keys as $key => $value) {
            $restore[$key] = array_key_exists($key, $_ENV) ? $_ENV[$key] : null;
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $config = static::__app_config_for_mode(Rsx::MODE_DEVELOPMENT);

            static::__assert_equals('local', $config['env'], 'a stale production env key must not move app.env');
            static::__assert_true($config['debug'], 'a stale debug env key must not move app.debug');
        } finally {
            foreach ($keys as $key => $value) {
                if ($restore[$key] === null) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv("{$key}={$restore[$key]}");
                    $_ENV[$key] = $restore[$key];
                    $_SERVER[$key] = $restore[$key];
                }
            }
        }
    }

    /**
     * The BOOTED container agrees with the running mode. This is the one assertion that
     * proves the derivation is what actually reached config(), not just what the file
     * would return if re-evaluated.
     */
    public static function test_booted_config_matches_the_running_mode()
    {
        $mode = Rsx::get_mode();

        static::__assert_equals(
            $mode === Rsx::MODE_PRODUCTION ? 'production' : 'local',
            config('app.env'),
            'config(app.env) must follow the running RSX_MODE'
        );
        static::__assert_equals(
            $mode !== Rsx::MODE_PRODUCTION,
            (bool) config('app.debug'),
            'config(app.debug) must follow the running RSX_MODE'
        );
    }
}
