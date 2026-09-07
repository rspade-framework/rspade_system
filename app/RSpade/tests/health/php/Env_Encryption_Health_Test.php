<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Health\Environment_Health_Checks;
use App\RSpade\Core\Prod\Rsx_Env_Symlink;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The "Env Encryption" health row: .env.encrypted sitting at the project root.
 *
 * The row exists because .env.encrypted is a SNAPSHOT that nothing keeps in step -
 * every key sync and every minted APP_KEY leaves it further behind - and on a
 * development box the heal rewrites .env routinely. On production it is the
 * deployed artifact and no warning is owed.
 *
 * Every branch is exercised against a THROWAWAY root, through the healer's path
 * seam (which is also the path seam the check reads), so no real .env or
 * .env.encrypted is read, written or even named.
 *
 * Pure logic, no DB.
 */
class Env_Encryption_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * A throwaway project layout with the healer pointed at it.
     *
     * @return array{0:string,1:string} [tmp_root, root_env]
     */
    private static function _make_layout(): array
    {
        $tmp = sys_get_temp_dir() . '/rsx_env_encryption_' . bin2hex(random_bytes(8));
        mkdir($tmp . '/system', 0777, true);

        Rsx_Env_Symlink::_testing_set_paths($tmp . '/system/.env', $tmp . '/.env');

        return [$tmp, $tmp . '/.env'];
    }

    private static function _cleanup(string $tmp): void
    {
        Rsx_Env_Symlink::_testing_reset();
        Rsx::clear_mode_cache();

        foreach ([$tmp . '/.env', $tmp . '/.env.encrypted'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($tmp . '/system')) {
            rmdir($tmp . '/system');
        }
        if (is_dir($tmp)) {
            rmdir($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // The seam itself: the check and the healer resolve the same path
    // -------------------------------------------------------------------------

    public static function test_the_encrypted_path_sits_beside_the_root_env()
    {
        [$tmp] = self::_make_layout();
        try {
            static::__assert_equals(
                $tmp . '/.env.encrypted',
                Rsx_Env_Symlink::get_root_env_encrypted_path(),
                'the encrypted file is a sibling of the root .env, never of system/.env'
            );
        } finally {
            self::_cleanup($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // No encrypted copy at all
    // -------------------------------------------------------------------------

    public static function test_no_encrypted_copy_is_ok()
    {
        [$tmp, $root_env] = self::_make_layout();
        try {
            file_put_contents($root_env, "APP_KEY=base64:live\n");

            $row = Environment_Health_Checks::env_encryption();

            static::__assert_equals('OK', $row['status']);
            static::__assert_contains('no encrypted copy', $row['detail']);
        } finally {
            self::_cleanup($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // Present on a development box -> WARN, with the remedy
    // -------------------------------------------------------------------------

    public static function test_encrypted_copy_on_a_development_box_warns()
    {
        [$tmp, $root_env] = self::_make_layout();
        try {
            file_put_contents($root_env, "APP_KEY=base64:live\n");
            file_put_contents($root_env . '.encrypted', 'ciphertext');
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

            $row = Environment_Health_Checks::env_encryption();

            static::__assert_equals('WARN', $row['status']);
            static::__assert_contains('development box', $row['detail']);
            static::__assert_contains('env:encrypt --force', $row['remediation'], 'the remedy is named');
        } finally {
            self::_cleanup($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // Present on a sealed-build box -> INFO, never a warning
    // -------------------------------------------------------------------------

    public static function test_encrypted_copy_on_production_is_only_informational()
    {
        [$tmp, $root_env] = self::_make_layout();
        try {
            file_put_contents($root_env, "APP_KEY=base64:live\n");
            file_put_contents($root_env . '.encrypted', 'ciphertext');
            Rsx::_testing_set_mode(Rsx::MODE_PRODUCTION);

            $row = Environment_Health_Checks::env_encryption();

            static::__assert_equals('INFO', $row['status']);
            static::__assert_true(!isset($row['remediation']), 'nothing to remediate on production');
        } finally {
            self::_cleanup($tmp);
        }
    }

    /** debug is a sealed build too - is_production() covers both. */
    public static function test_encrypted_copy_in_debug_mode_is_only_informational()
    {
        [$tmp, $root_env] = self::_make_layout();
        try {
            file_put_contents($root_env, "APP_KEY=base64:live\n");
            file_put_contents($root_env . '.encrypted', 'ciphertext');
            Rsx::_testing_set_mode(Rsx::MODE_DEBUG);

            $row = Environment_Health_Checks::env_encryption();

            static::__assert_equals('INFO', $row['status']);
        } finally {
            self::_cleanup($tmp);
        }
    }
}
