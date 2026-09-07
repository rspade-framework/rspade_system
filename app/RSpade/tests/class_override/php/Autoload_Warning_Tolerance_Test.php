<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Autoloader;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Mechanism 1 - the scoped include-warning tolerance that lets composer's stale
 * committed classmap soft-fail through to the RSX autoloader instead of being
 * promoted to a fatal by Laravel's HandleExceptions.
 *
 * Covers the pure decision predicate (_should_tolerate_classloader_warning) and the
 * installed handler (_handle_php_error) - both branches: swallow vs delegate.
 *
 * Pure logic, no DB.
 */
class Autoload_Warning_Tolerance_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const CLASSLOADER = '/var/www/html/system/vendor/composer/ClassLoader.php';

    // -------------------------------------------------------------------------
    // Predicate: _should_tolerate_classloader_warning()
    // -------------------------------------------------------------------------

    // (a) include warning whose errfile ends vendor/composer/ClassLoader.php -> tolerated
    public static function test_predicate_tolerates_classloader_include_warning()
    {
        static::__assert_true(
            Autoloader::_should_tolerate_classloader_warning(E_WARNING, self::CLASSLOADER),
            'E_WARNING from composer ClassLoader.php must be tolerated (not promoted)'
        );
    }

    // (b) identical warning level from ANY other file -> NOT tolerated (promoted as before)
    public static function test_predicate_does_not_tolerate_other_file_warning()
    {
        static::__assert_false(
            Autoloader::_should_tolerate_classloader_warning(E_WARNING, '/var/www/html/rsx/models/foo_model.php'),
            'E_WARNING from a non-ClassLoader file must keep existing fail-loud behavior'
        );
    }

    // (c) an E_ERROR-level from the ClassLoader.php path -> NOT swallowed
    public static function test_predicate_does_not_tolerate_error_level_from_classloader()
    {
        static::__assert_false(
            Autoloader::_should_tolerate_classloader_warning(E_ERROR, self::CLASSLOADER),
            'A non-warning level from ClassLoader.php must never be swallowed'
        );
    }

    // Path normalization: a Windows-style backslash path still matches by suffix
    public static function test_predicate_normalizes_backslash_paths()
    {
        static::__assert_true(
            Autoloader::_should_tolerate_classloader_warning(E_WARNING, 'C:\\app\\vendor\\composer\\ClassLoader.php'),
            'Backslash separators must be normalized before the suffix match'
        );
    }

    // A different vendor file (same dir prefix, wrong basename) must not match
    public static function test_predicate_requires_exact_classloader_basename()
    {
        static::__assert_false(
            Autoloader::_should_tolerate_classloader_warning(E_WARNING, '/var/www/html/system/vendor/composer/autoload_real.php'),
            'Only ClassLoader.php (where the bare include lives) may be tolerated'
        );
    }

    // -------------------------------------------------------------------------
    // Handler: _handle_php_error() - swallow branch
    // -------------------------------------------------------------------------

    // A matching warning is swallowed: handler returns true and does NOT throw.
    public static function test_handler_swallows_classloader_warning_without_throwing()
    {
        $result = Autoloader::_handle_php_error(
            E_WARNING,
            'include(/gone/File_Attachment_Model.php): Failed to open stream: No such file or directory',
            self::CLASSLOADER,
            577
        );

        static::__assert_true($result === true, 'Swallowed warning must return true (handled, no promotion)');
    }

    // -------------------------------------------------------------------------
    // Handler: _handle_php_error() - delegate branch
    // -------------------------------------------------------------------------

    // A non-matching warning is delegated to the previously registered handler.
    // We inject a recording spy as the previous handler (restored afterward) so the
    // assertion does not depend on Laravel's exact promotion behavior.
    public static function test_handler_delegates_non_matching_error_to_previous_handler()
    {
        $ref = new \ReflectionProperty(Autoloader::class, 'previous_error_handler');
        $ref->setAccessible(true);
        $saved = $ref->getValue();

        $delegated = false;
        $spy = function ($errno, $errstr, $errfile = '', $errline = 0) use (&$delegated) {
            $delegated = true;

            return false;
        };

        try {
            $ref->setValue(null, $spy);

            Autoloader::_handle_php_error(
                E_WARNING,
                'Undefined variable $x',
                '/var/www/html/rsx/app/frontend/some_action.php',
                42
            );

            static::__assert_true($delegated, 'Non-matching warning must be delegated to the previous handler');
        } finally {
            $ref->setValue(null, $saved);
        }
    }

    // The swallow branch must NOT delegate (the previous handler is never consulted).
    public static function test_handler_does_not_delegate_when_swallowing()
    {
        $ref = new \ReflectionProperty(Autoloader::class, 'previous_error_handler');
        $ref->setAccessible(true);
        $saved = $ref->getValue();

        $delegated = false;
        $spy = function ($errno, $errstr, $errfile = '', $errline = 0) use (&$delegated) {
            $delegated = true;

            return false;
        };

        try {
            $ref->setValue(null, $spy);

            Autoloader::_handle_php_error(
                E_WARNING,
                'include(...): Failed to open stream',
                self::CLASSLOADER,
                577
            );

            static::__assert_false($delegated, 'A tolerated warning must be swallowed, never delegated');
        } finally {
            $ref->setValue(null, $saved);
        }
    }
}
