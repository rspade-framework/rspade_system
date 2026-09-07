<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use ReflectionClass;
use ReflectionMethod;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins B4.1: migrate:normalize_schema must NOT perform its own logical rollback and
 * must NOT falsely report "rolled back successfully".
 *
 * migrate:normalize_schema used to, on failure, invoke migrate:rollback and - on that
 * command's exit code 0 - print "[OK] Database rolled back successfully!". That was a
 * lie two ways: the framework strips every migration down() method (MigrationValidator)
 * so migrate:rollback no-ops (exit 0) while reverting NOTHING, and it duplicated the
 * one real, tested recovery (the physical MySQL-datadir snapshot restore owned by
 * Maint_Migrate::rollback_snapshot). The command's catch block must now ONLY report the
 * error, disable query echo, and re-throw so the snapshot owner performs the datadir
 * rollback.
 *
 * These are source-structure assertions against the command's catch path. They cannot
 * damage the database (no migration/DDL is executed), and they fail if anyone
 * reintroduces the logical-rollback recovery or the false success message.
 *
 * NOTE: the destructive end-to-end integration test (force a real normalize failure
 * during "php artisan migrate" and assert the physical MySQL-datadir snapshot restore
 * ran) requires a real MySQL datadir and is a deferred infra-harness follow-up - see
 * test_catalog.md. It is intentionally NOT attempted here.
 *
 * No database access - skip the per-test transaction.
 */
class Normalize_Schema_Rollback_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // Full class name as a string so the reference survives the linter's unused-import
    // pruning (a bare ::class of an imported class reads as an unused use statement).
    const COMMAND_CLASS = 'App\\RSpade\\Commands\\Migrate\\Migrate_Normalize_Schema_Command';

    /**
     * Read the full source of the normalize command via reflection.
     */
    protected static function __command_source(): string
    {
        $reflection = new ReflectionClass(self::COMMAND_CLASS);
        $file = $reflection->getFileName();

        return file_get_contents($file);
    }

    /**
     * Extract the source of handle() from the "catch (Exception $e)" clause to the end
     * of the method - i.e. the failure/recovery path under test.
     */
    protected static function __handle_catch_source(): string
    {
        $method = new ReflectionMethod(self::COMMAND_CLASS, 'handle');
        $file_lines = file($method->getFileName());

        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $method->getStartLine() + 1;
        $handle_source = implode('', array_slice($file_lines, $start, $length));

        $catch_pos = strpos($handle_source, 'catch (Exception $e)');
        static::__assert_true($catch_pos !== false, 'handle() must have a catch (Exception $e) block');

        return substr($handle_source, $catch_pos);
    }

    // -------------------------------------------------------------------------
    // (i) It does NOT call migrate:rollback (no logical rollback anywhere)
    // -------------------------------------------------------------------------

    public static function test_command_does_not_invoke_logical_rollback()
    {
        $source = static::__command_source();
        static::__assert_true(
            strpos($source, 'migrate:rollback') === false,
            'Normalize command must not invoke migrate:rollback - the real recovery is the snapshot restore'
        );
    }

    public static function test_command_does_not_reference_artisan_facade()
    {
        // The only Artisan usage was the deleted logical rollback; the import and call
        // must both be gone.
        $source = static::__command_source();
        static::__assert_true(
            strpos($source, 'Artisan::call') === false,
            'Normalize command must not call Artisan::call (logical rollback removed)'
        );
    }

    // -------------------------------------------------------------------------
    // (ii) It does NOT emit a "rolled back successfully" line
    // -------------------------------------------------------------------------

    public static function test_command_does_not_claim_rollback_success()
    {
        $source = static::__command_source();
        static::__assert_true(
            stripos($source, 'rolled back successfully') === false,
            'Normalize command must not falsely report a successful rollback'
        );
    }

    // -------------------------------------------------------------------------
    // (iii) The catch block re-throws (and only reports + disables echo)
    // -------------------------------------------------------------------------

    public static function test_catch_block_rethrows_exception()
    {
        $catch = static::__handle_catch_source();
        static::__assert_true(
            strpos($catch, 'throw $e;') !== false,
            'The catch block must re-throw the original exception'
        );
    }

    public static function test_catch_block_disables_query_echo()
    {
        $catch = static::__handle_catch_source();
        static::__assert_true(
            strpos($catch, 'AppServiceProvider::disable_query_echo()') !== false,
            'The catch block must disable query echoing before re-throwing'
        );
    }

    public static function test_catch_block_has_no_recovery_logic()
    {
        // The catch path must not attempt any rollback of its own - no logical rollback
        // call, no false success message.
        $catch = static::__handle_catch_source();
        static::__assert_true(
            strpos($catch, 'migrate:rollback') === false,
            'The catch block must not invoke a logical migration rollback'
        );
        static::__assert_true(
            stripos($catch, 'rolled back successfully') === false,
            'The catch block must not emit a false rollback-success message'
        );
    }
}
