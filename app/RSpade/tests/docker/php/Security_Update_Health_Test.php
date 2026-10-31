<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use App\RSpade\Core\Health\Security_Update_Health_Checks;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The rsx:health "Security Updates" row reads the status rspade-security-update writes:
 * OK after a check that found nothing or installed and reloaded, WARN when no check has run,
 * when it is switched off, when the last check failed, and when installed packages wait for
 * a container restart. Driven against a scratch status file.
 */
class Security_Update_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static string $file = '';

    public static function setup()
    {
        static::$file = sys_get_temp_dir() . '/security_updates_' . uniqid() . '.json';
        Security_Update_Health_Checks::$status_file_for_tests = static::$file;
    }

    public static function teardown()
    {
        Security_Update_Health_Checks::$status_file_for_tests = null;
        @unlink(static::$file);
    }

    private static function __row(?array $status): array
    {
        @unlink(static::$file);

        if ($status !== null) {
            file_put_contents(static::$file, json_encode($status + ['checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'mode' => 'scheduled']));
        }

        return Security_Update_Health_Checks::security_updates();
    }

    public static function test_the_row_follows_the_status()
    {
        if (!Rsx::is_rspade_container()) {
            static::__skip('the row reads the status only inside the RSpade container');
            return;
        }

        static::__assert_equals('WARN', static::__row(null)['status'], 'no check has run');
        static::__assert_equals('OK', static::__row(['result' => 'current'])['status'], 'up to date');
        static::__assert_contains('up to date', static::__row(['result' => 'current'])['detail']);
        static::__assert_equals('OK', static::__row(['result' => 'updated', 'restart_recommended' => false])['status'], 'installed and reloaded');
        static::__assert_equals('WARN', static::__row(['result' => 'updated', 'restart_recommended' => true])['status'], 'waiting for a restart');
        static::__assert_contains('RSX_AUTO_SECURITY_UPDATES', static::__row(['result' => 'disabled'])['detail'], 'switched off');
        static::__assert_equals('WARN', static::__row(['result' => 'failed'])['status'], 'the last check failed');
    }
}
