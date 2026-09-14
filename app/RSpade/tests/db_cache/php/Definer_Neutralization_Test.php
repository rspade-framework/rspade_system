<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DbCache\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Rsx_Data_Wipe;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Every dump the framework produces is restorable under any account on any host.
 *
 * mysqldump names the owning account of every trigger, view, routine and event -
 * DEFINER=`rspade`@`localhost` - and a dump carrying it restores under no other account
 * (ERROR 1227: SET_USER_ID / SUPER needed to assume a definer). The shipped schema cache
 * carried the developer box's account and every production install's first migration
 * failed mid-restore (a downstream field report, 2026-08-24). Rsx_Data_Wipe::mysqldump_command()
 * is now the ONE dump invocation, and it rewrites every definer to DEFINER=CURRENT_USER.
 *
 * The text-level tests push fixture lines through the filter segment exactly as a
 * pipeline would; the last test builds a real trigger in the test database, runs the real
 * command against it, and reads the dump. No transactions: DDL commits implicitly, and the
 * scratch table is dropped in teardown.
 */
class Definer_Neutralization_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const TABLE = '_definer_neutralization_probe';
    private const TRIGGER = '_definer_neutralization_probe_bi';

    public static function teardown()
    {
        DB::connection('test')->statement('DROP TRIGGER IF EXISTS ' . self::TRIGGER);
        DB::connection('test')->statement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    /** Push $sql through the filter segment the way a dump pipeline does. */
    private static function __filter(string $sql): string
    {
        $command = 'printf %s ' . escapeshellarg($sql) . Rsx_Data_Wipe::definer_filter_segment();
        $output = [];
        $exit = 0;
        \exec_safe($command, $output, $exit);

        static::__assert_equals(0, $exit, 'sed ran: ' . implode("\n", $output));

        return implode("\n", $output);
    }

    private static function __test_client_flags(): string
    {
        $conn = config('database.connections.test');

        return '-h' . escapeshellarg((string) $conn['host'])
            . ' -P' . escapeshellarg((string) $conn['port'])
            . ' -u' . escapeshellarg((string) $conn['username']);
    }

    // -------------------------------------------------------------------------
    // the filter, on the exact forms mysqldump emits
    // -------------------------------------------------------------------------

    public static function test_trigger_definer_comment_is_rewritten()
    {
        $line = '/*!50003 CREATE*/ /*!50017 DEFINER=`rspade`@`localhost`*/ /*!50003 TRIGGER `t_bi` BEFORE INSERT ON `t` FOR EACH ROW SET NEW.n = 1 */;;';

        static::__assert_equals(
            '/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER `t_bi` BEFORE INSERT ON `t` FOR EACH ROW SET NEW.n = 1 */;;',
            static::__filter($line)
        );
    }

    public static function test_view_definer_comment_is_rewritten()
    {
        $line = '/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */';

        static::__assert_equals('/*!50013 DEFINER=CURRENT_USER SQL SECURITY DEFINER */', static::__filter($line));
    }

    public static function test_routine_create_definer_is_rewritten()
    {
        $line = 'CREATE DEFINER=`app_user`@`db.internal.example` PROCEDURE `p`() BEGIN END';

        static::__assert_equals('CREATE DEFINER=CURRENT_USER PROCEDURE `p`() BEGIN END', static::__filter($line));
    }

    public static function test_insert_lines_are_never_rewritten()
    {
        $line = "INSERT INTO `notes` VALUES (1,'DEFINER=`rspade`@`localhost` is literal data here');";

        static::__assert_equals($line, static::__filter($line), 'data is stored byte-for-byte');
    }

    public static function test_lines_without_a_definer_pass_unchanged()
    {
        $line = 'CREATE TABLE `users` (`id` bigint NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB;';

        static::__assert_equals($line, static::__filter($line));
    }

    // -------------------------------------------------------------------------
    // the one command
    // -------------------------------------------------------------------------

    public static function test_mysqldump_command_is_pipefail_dump_then_filter()
    {
        $command = Rsx_Data_Wipe::mysqldump_command('somedb', '-hexample -P3306 -uwho');

        static::__assert_true(str_starts_with($command, 'set -o pipefail; mysqldump -hexample -P3306 -uwho '), 'pipefail, then mysqldump with the caller\'s client flags: ' . $command);
        static::__assert_true(str_contains($command, " --no-tablespaces --single-transaction --quick --lock-tables=false 'somedb'"), 'the framework dump options and the database: ' . $command);
        static::__assert_true(str_ends_with($command, Rsx_Data_Wipe::definer_filter_segment()), 'the definer filter is the last segment');
    }

    public static function test_a_real_dump_of_a_real_trigger_names_no_account()
    {
        $test_db = (string) config('database.connections.test.database');
        $password = (string) config('database.connections.test.password');

        DB::connection('test')->statement('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY AUTO_INCREMENT, n INT)');
        DB::connection('test')->statement('CREATE TRIGGER ' . self::TRIGGER . ' BEFORE INSERT ON ' . self::TABLE . ' FOR EACH ROW SET NEW.n = 1');

        $output = [];
        $exit = 0;
        \exec_safe(
            Rsx_Data_Wipe::mysqldump_command($test_db, static::__test_client_flags()) . ' 2>/dev/null',
            $output,
            $exit,
            $password === '' ? [] : ['MYSQL_PWD' => $password]
        );
        $dump = implode("\n", $output);

        static::__assert_equals(0, $exit, 'the dump pipeline succeeds');
        static::__assert_true(str_contains($dump, 'TRIGGER `' . self::TRIGGER . '`'), 'the trigger is in the dump');
        static::__assert_true(str_contains($dump, 'DEFINER=CURRENT_USER'), 'its definer is CURRENT_USER');
        static::__assert_true(preg_match('/DEFINER=`[^`]*`@`[^`]*`/', $dump) === 0, 'no account is named anywhere in the dump');
    }
}
