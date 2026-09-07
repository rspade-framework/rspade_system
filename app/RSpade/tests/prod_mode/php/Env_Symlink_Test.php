<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * @REALPATH-EXCEPTION - These tests assert symlink RESOLUTION behavior of the
 * env healer; realpath's symlink-following is exactly what is under test.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Prod\Rsx_Env_Symlink;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit coverage for Rsx_Env_Symlink (the .env symlink healer). Every test runs
 * against a throwaway temp layout injected via the path seam
 * (_testing_set_paths); the real .env files are NEVER touched. Seams are always
 * restored and the temp layout removed in a finally block.
 *
 * The temp layout mirrors the real one: <tmp>/system/.env is the "system" side
 * (what Laravel loads) and <tmp>/.env is the authoritative "root" side, so the
 * healer computes the same relative "../.env" symlink target as in production.
 *
 * Pure logic, no DB.
 */
class Env_Symlink_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Build an empty temp layout and point the healer at it.
     *
     * @return array{0:string,1:string,2:string} [tmp_root, system_env, root_env]
     */
    private static function _make_layout(): array
    {
        $tmp = sys_get_temp_dir() . '/rsx_env_heal_' . bin2hex(random_bytes(8));
        mkdir($tmp . '/system', 0777, true);

        $system_env = $tmp . '/system/.env';
        $root_env = $tmp . '/.env';

        Rsx_Env_Symlink::_testing_set_paths($system_env, $root_env);

        return [$tmp, $system_env, $root_env];
    }

    private static function _rmtree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // already healthy
    // -------------------------------------------------------------------------

    public static function test_already_healthy_is_a_noop()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($root_env, "APP_KEY=base64:abc\n");
            symlink('../.env', $system_env);

            $before = readlink($system_env);
            $report = Rsx_Env_Symlink::heal();

            static::__assert_equals('already_healthy', $report['status']);
            static::__assert_count(0, $report['actions'], 'no actions on a healthy layout');
            static::__assert_true(is_link($system_env), 'system/.env is still a symlink');
            static::__assert_equals($before, readlink($system_env), 'symlink target unchanged');
            static::__assert_equals("APP_KEY=base64:abc\n", file_get_contents($root_env), 'root untouched');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // wrong-target symlink
    // -------------------------------------------------------------------------

    public static function test_wrong_target_symlink_is_repointed()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($root_env, "APP_KEY=root\n");
            file_put_contents($tmp . '/system/other.env', "APP_KEY=other\n");
            symlink('other.env', $system_env);

            $report = Rsx_Env_Symlink::heal();

            static::__assert_equals('healed', $report['status']);
            static::__assert_true(is_link($system_env), 'still a symlink after repoint');
            static::__assert_equals(realpath($root_env), realpath($system_env), 'now resolves to the root .env');
            static::__assert_equals('../.env', readlink($system_env), 'relative target');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // regular-file merge (the core case)
    // -------------------------------------------------------------------------

    public static function test_regular_file_merge_root_wins_and_unique_appended()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            // Root: has a comment, a blank line, and two keys (authoritative).
            $root_original = "# root header comment\nAPP_KEY=root_key\n\nRSX_MODE=production\n";
            file_put_contents($root_env, $root_original);

            // System: conflicts on RSX_MODE, and carries a unique ONEDRIVE key.
            file_put_contents(
                $system_env,
                "APP_KEY=root_key\nRSX_MODE=development\nONEDRIVE_SECRET=\"quoted value\"\n"
            );

            $report = Rsx_Env_Symlink::heal();

            static::__assert_equals('healed', $report['status']);

            // Unique key was merged.
            static::__assert_count(1, $report['merged_keys']);
            static::__assert_equals('ONEDRIVE_SECRET', $report['merged_keys'][0]);

            // Conflicting key: root wins, system value reported as discarded.
            static::__assert_count(1, $report['overridden_keys']);
            static::__assert_equals('RSX_MODE', $report['overridden_keys'][0]['key']);
            static::__assert_equals('production', $report['overridden_keys'][0]['root_value']);
            static::__assert_equals('development', $report['overridden_keys'][0]['system_value']);

            // Root file: original bytes preserved verbatim as a prefix.
            $new_root = file_get_contents($root_env);
            static::__assert_equals(
                $root_original,
                substr($new_root, 0, strlen($root_original)),
                'root comments/blank lines/keys preserved byte-for-byte above the appended block'
            );
            static::__assert_contains('# merged from system/.env by rsx env healer', $new_root);
            static::__assert_contains('ONEDRIVE_SECRET="quoted value"', $new_root);
            // The conflicting key must NOT have been re-appended (root already has it).
            static::__assert_true(
                substr_count($new_root, 'RSX_MODE=') === 1,
                'conflicting key not duplicated into root'
            );

            // system/.env is now a symlink to the root.
            static::__assert_true(is_link($system_env), 'system/.env replaced with a symlink');
            static::__assert_equals(realpath($root_env), realpath($system_env));

            // Backup exists and is 0600.
            static::__assert_not_empty($report['backup_path']);
            static::__assert_true(is_file($report['backup_path']), 'backup written');
            static::__assert_equals('0600', substr(sprintf('%o', fileperms($report['backup_path'])), -4));
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // root missing -> move + symlink
    // -------------------------------------------------------------------------

    public static function test_root_missing_moves_content_then_symlinks()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            $system_content = "APP_KEY=only_in_system\nRSX_MODE=development\n";
            file_put_contents($system_env, $system_content);
            // No root .env on disk.

            $report = Rsx_Env_Symlink::heal();

            static::__assert_equals('created', $report['status']);
            static::__assert_true(is_file($root_env), 'root .env created');
            static::__assert_equals($system_content, file_get_contents($root_env), 'content moved verbatim');
            static::__assert_true(is_link($system_env), 'system/.env is now a symlink');
            static::__assert_equals(realpath($root_env), realpath($system_env));
            static::__assert_not_empty($report['backup_path']);
            static::__assert_equals('0600', substr(sprintf('%o', fileperms($report['backup_path'])), -4));
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // system missing (root exists) -> create symlink
    // -------------------------------------------------------------------------

    public static function test_system_missing_creates_symlink()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($root_env, "APP_KEY=root\n");
            // No system/.env.

            $report = Rsx_Env_Symlink::heal();

            static::__assert_equals('healed', $report['status']);
            static::__assert_true(is_link($system_env), 'system/.env symlink created');
            static::__assert_equals(realpath($root_env), realpath($system_env));
            static::__assert_null($report['backup_path'], 'nothing to back up');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // neither exists -> fail loud
    // -------------------------------------------------------------------------

    public static function test_both_missing_throws()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            static::__assert_throws(
                \RuntimeException::class,
                function () {
                    Rsx_Env_Symlink::heal();
                },
                'unbootable'
            );
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // dry-run mutates nothing
    // -------------------------------------------------------------------------

    public static function test_dry_run_reports_but_does_not_mutate()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            $root_original = "APP_KEY=root\nRSX_MODE=production\n";
            file_put_contents($root_env, $root_original);
            $system_original = "RSX_MODE=development\nEXTRA_KEY=1\n";
            file_put_contents($system_env, $system_original);

            $report = Rsx_Env_Symlink::get_drift_report();

            static::__assert_false($report['healthy'], 'drift detected');
            static::__assert_equals('regular_file', $report['state']);
            static::__assert_equals('healed', $report['status'], 'status is what heal WOULD produce');
            static::__assert_equals(['EXTRA_KEY'], $report['merged_keys']);
            static::__assert_count(1, $report['overridden_keys']);

            // Nothing changed on disk.
            static::__assert_false(is_link($system_env), 'system/.env is still a real file');
            static::__assert_equals($root_original, file_get_contents($root_env), 'root untouched');
            static::__assert_equals($system_original, file_get_contents($system_env), 'system untouched');
            static::__assert_false(
                is_file($system_env . Rsx_Env_Symlink::BACKUP_SUFFIX),
                'no backup written on a dry run'
            );
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // file_put_contents_safe writes THROUGH a symlink (never replaces the link)
    // -------------------------------------------------------------------------

    /**
     * Regression: file_put_contents_safe's temp+rename used to REPLACE a symlink
     * destination with a regular file - so every Rsx_Prod_Env::set_mode() write to
     * the healed system/.env symlink silently re-materialized the drift the healer
     * had just fixed. The write must land in the TARGET and the link must survive.
     */
    public static function test_file_put_contents_safe_writes_through_symlink()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($root_env, "RSX_MODE=development\n");
            symlink('../.env', $system_env);

            $bytes = file_put_contents_safe($system_env, "RSX_MODE=debug\n");

            static::__assert_true($bytes !== false, 'write succeeded');
            static::__assert_true(is_link($system_env), 'the symlink SURVIVED the write');
            static::__assert_equals(
                "RSX_MODE=debug\n",
                file_get_contents($root_env),
                'content landed in the symlink TARGET (the root .env)'
            );
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }
}
