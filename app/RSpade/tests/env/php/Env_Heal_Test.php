<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Env\Php;

use RuntimeException;
use App\RSpade\Core\Prod\Rsx_Env_Symlink;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Coverage for the developer-bootstrap env heal (Rsx_Env_Symlink::full_heal /
 * boot_heal): creating .env from the TRACKED project-root .env.dist, the
 * development-only key sync, credential validation and the APP_KEY rule.
 *
 * Every test builds a throwaway project layout in a temp directory and points
 * the healer at it through the path seam, so the real .env files are never
 * touched. The layout mirrors the real one exactly - <tmp>/.env(.dist) is the
 * project root, <tmp>/system/.env(.dist) is the framework's side, and
 * <tmp>/storage/rsx-tmp holds the boot stamp - because the class derives every
 * one of those paths from the two it is given.
 *
 * The symlink invariant itself is covered by prod_mode/php/Env_Symlink_Test.
 *
 * Pure logic, no DB.
 */
class Env_Heal_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * A minimal .env.dist that satisfies credential validation.
     */
    private const DIST = "APP_NAME=RSpade\nAPP_KEY=\nRSX_MODE=development\n"
        . "DB_HOST=127.0.0.1\nDB_DATABASE=rspade\nDB_USERNAME=rspade\nDB_PASSWORD=rspadepass\n"
        . "REDIS_HOST=127.0.0.1\n";

    /**
     * The same template carrying a POPULATED APP_KEY. A key is declared exactly
     * once: two definitions of one key is a malformed file, not a fixture.
     */
    private static function _dist_with_key(string $key): string
    {
        return str_replace("APP_KEY=\n", 'APP_KEY=' . $key . "\n", self::DIST);
    }

    /**
     * Build an empty temp layout and point the healer at it.
     *
     * @return array{0:string,1:string,2:string} [tmp_root, system_env, root_env]
     */
    private static function _make_layout(): array
    {
        $tmp = sys_get_temp_dir() . '/rsx_env_full_heal_' . bin2hex(random_bytes(8));
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
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());

                continue;
            }
            rmdir($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Read one key's raw value out of an env file (null when absent).
     */
    private static function _read_key(string $path, string $key): ?string
    {
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (strncmp($line, $key . '=', strlen($key) + 1) === 0) {
                return substr($line, strlen($key) + 1);
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // (a) .env recreated from the ROOT dist
    // -------------------------------------------------------------------------

    public static function test_missing_env_is_recreated_from_the_root_dist()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            $dist = self::_dist_with_key('base64:already-set');
            file_put_contents($tmp . '/.env.dist', $dist);

            $report = Rsx_Env_Symlink::full_heal();

            static::__assert_true(is_file($root_env), '.env was created');
            static::__assert_equals($dist, file_get_contents($root_env), 'byte-identical to the root .env.dist');
            static::__assert_false($report['app_key_minted'], 'a populated APP_KEY is never replaced');
            static::__assert_contains('Created .env from .env.dist.', implode("\n", $report['actions']));
            static::__assert_true(is_link($system_env), 'the symlink invariant was restored too');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (b) no dist at all - refuse, and NEVER seed from the framework skeleton
    // -------------------------------------------------------------------------

    public static function test_missing_root_dist_throws_and_never_seeds_from_the_framework()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            // The framework's own copy exists and is deliberately ignored: it is
            // the key-sync source, never a seed. Seeding a root .env from it is
            // the shadowing bug this rule exists to prevent.
            file_put_contents($tmp . '/system/.env.dist', self::DIST);

            $e = static::__assert_throws(
                RuntimeException::class,
                static fn () => Rsx_Env_Symlink::full_heal(),
                'RSpade convention'
            );

            static::__assert_contains('cp system/.env.dist .env.dist', $e->getMessage(), 'names the recovery');
            static::__assert_false(is_file($root_env), '.env was NOT created');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (c) a populated APP_KEY in the dist survives verbatim
    // -------------------------------------------------------------------------

    public static function test_populated_app_key_is_carried_over_untouched()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            $key = 'base64:' . base64_encode(random_bytes(32));
            file_put_contents($tmp . '/.env.dist', self::_dist_with_key($key));

            $report = Rsx_Env_Symlink::full_heal();

            static::__assert_equals($key, self::_read_key($root_env, 'APP_KEY'), 'key byte-identical');
            static::__assert_false($report['app_key_minted'], 'nothing was minted');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (d) blank APP_KEY in development - exactly one key minted, and reported
    // -------------------------------------------------------------------------

    public static function test_blank_app_key_is_minted_once_in_development()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/.env.dist', self::DIST);

            $report = Rsx_Env_Symlink::full_heal();

            static::__assert_true($report['app_key_minted'], 'a key was minted');
            $key = (string) self::_read_key($root_env, 'APP_KEY');
            static::__assert_true(str_starts_with($key, 'base64:'), 'minted key is base64: prefixed');
            static::__assert_equals(32, strlen(base64_decode(substr($key, 7), true)), '32 random bytes');

            $minted_lines = 0;
            foreach (file($root_env, FILE_IGNORE_NEW_LINES) as $line) {
                if (strncmp($line, 'APP_KEY=', 8) === 0) {
                    $minted_lines++;
                }
            }
            static::__assert_equals(1, $minted_lines, 'exactly one APP_KEY line');

            $mint_actions = 0;
            foreach ($report['actions'] as $action) {
                if (str_contains($action, 'Minted APP_KEY')) {
                    $mint_actions++;
                }
            }
            static::__assert_equals(1, $mint_actions, 'reported exactly once');

            // A second heal has nothing left to do with the key.
            $second = Rsx_Env_Symlink::full_heal();
            static::__assert_false($second['app_key_minted'], 'idempotent');
            static::__assert_equals($key, self::_read_key($root_env, 'APP_KEY'), 'key unchanged');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (e) a healthy environment with a fresh stamp is not examined at all
    // -------------------------------------------------------------------------

    public static function test_boot_heal_short_circuits_on_a_fresh_stamp()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/.env.dist', self::_dist_with_key('base64:kept'));
            file_put_contents($root_env, self::_dist_with_key('base64:kept'));
            symlink('../.env', $system_env);

            // One heal writes the stamp; nothing has changed since.
            Rsx_Env_Symlink::full_heal();
            static::__assert_true(
                is_file($tmp . '/storage/rsx-tmp/env_heal.stamp'),
                'the heal wrote its stamp'
            );

            $before = file_get_contents($root_env);
            $report = Rsx_Env_Symlink::boot_heal();

            static::__assert_equals('skipped', $report['status'], 'the stamp short-circuits the heal');
            static::__assert_count(0, $report['actions'], 'nothing reported');
            static::__assert_equals($before, file_get_contents($root_env), '.env untouched');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    public static function test_boot_heal_runs_when_the_stamp_is_stale()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/.env.dist', self::_dist_with_key('base64:kept'));
            file_put_contents($root_env, self::_dist_with_key('base64:kept'));
            symlink('../.env', $system_env);

            Rsx_Env_Symlink::full_heal();
            $stamp = $tmp . '/storage/rsx-tmp/env_heal.stamp';

            // .env.dist grows a key IN THE SAME SECOND as the heal that preceded
            // it - the exact case an mtime comparison cannot see.
            file_put_contents($tmp . '/.env.dist', "NEW_SETTING=on\n", FILE_APPEND);

            $report = Rsx_Env_Symlink::boot_heal();

            static::__assert_equals('on', self::_read_key($root_env, 'NEW_SETTING'), 'the new key arrived');
            static::__assert_true(in_array('NEW_SETTING', $report['synced_keys']['root_env'], true), 'reported');

            $after = Rsx_Env_Symlink::boot_heal();
            static::__assert_equals('skipped', $after['status'], 'the stamp was refreshed');
            static::__assert_true(is_file($stamp), 'stamp still present');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (f) an unwritable target is loud, never a silent no-op
    // -------------------------------------------------------------------------

    public static function test_a_failed_copy_is_loud()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/.env.dist', self::DIST);

            // A directory where the file must go: copy() cannot write it, at any
            // privilege level, which is exactly what "the copy failed" means.
            //
            // The exception CLASS is not the assertion. Pre-boot - where this heal
            // does most of its work - there is no error handler and copy() simply
            // returns false, so the class's own checked throw fires; inside the
            // booted framework the same warning is already an ErrorException before
            // that line is reached. Both are loud, which is the requirement.
            mkdir($root_env);

            static::__assert_throws(
                \Throwable::class,
                static fn () => Rsx_Env_Symlink::full_heal()
            );

            static::__assert_false(is_file($root_env), 'no .env was left behind');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (g) key sync: system dist -> root dist -> root env, KEYS only
    // -------------------------------------------------------------------------

    public static function test_key_sync_reaches_both_files_and_never_overwrites()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/system/.env.dist', "FRAMEWORK_NEW=default\nDB_HOST=999.999.999.999\n");
            file_put_contents($tmp . '/.env.dist', self::DIST . "PROJECT_ONLY=dist_value\n");
            file_put_contents($root_env, "APP_KEY=base64:kept\n" . self::DIST);
            symlink('../.env', $system_env);

            $report = Rsx_Env_Symlink::full_heal();

            static::__assert_equals('default', self::_read_key($tmp . '/.env.dist', 'FRAMEWORK_NEW'), 'reached .env.dist');
            static::__assert_equals('default', self::_read_key($root_env, 'FRAMEWORK_NEW'), 'reached .env');
            static::__assert_equals('dist_value', self::_read_key($root_env, 'PROJECT_ONLY'), 'root dist key reached .env');

            // Values are NEVER touched: DB_HOST exists everywhere already.
            static::__assert_equals('127.0.0.1', self::_read_key($root_env, 'DB_HOST'), 'existing value kept');
            static::__assert_equals('127.0.0.1', self::_read_key($tmp . '/.env.dist', 'DB_HOST'), 'existing dist value kept');

            static::__assert_true(in_array('FRAMEWORK_NEW', $report['synced_keys']['root_dist'], true), 'reported for .env.dist');
            static::__assert_true(in_array('PROJECT_ONLY', $report['synced_keys']['root_env'], true), 'reported for .env');

            // Every key lands exactly once, and a second run adds nothing.
            $second = Rsx_Env_Symlink::full_heal();
            static::__assert_count(0, $second['synced_keys']['root_env'], 'idempotent');
            static::__assert_count(0, $second['synced_keys']['root_dist'], 'idempotent');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    public static function test_sync_runs_before_validation_so_a_required_key_can_arrive()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            // REDIS_HOST is required and is missing from BOTH project files - it
            // exists only in the framework's newer template. Validation must see
            // the file the sync has already repaired, not the one it was handed.
            file_put_contents($tmp . '/system/.env.dist', "REDIS_HOST=127.0.0.1\n");
            $without_redis = "APP_KEY=base64:kept\nRSX_MODE=development\n"
                . "DB_HOST=127.0.0.1\nDB_DATABASE=rspade\nDB_USERNAME=rspade\nDB_PASSWORD=\n";
            file_put_contents($tmp . '/.env.dist', $without_redis);
            file_put_contents($root_env, $without_redis);
            symlink('../.env', $system_env);

            $report = Rsx_Env_Symlink::full_heal();

            static::__assert_equals('127.0.0.1', self::_read_key($root_env, 'REDIS_HOST'), 'arrived via the sync');
            static::__assert_true(in_array('REDIS_HOST', $report['synced_keys']['root_env'], true), 'reported');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (h) credential validation
    // -------------------------------------------------------------------------

    public static function test_missing_db_database_is_refused_by_name()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            $broken = "APP_KEY=base64:kept\nRSX_MODE=development\n"
                . "DB_HOST=127.0.0.1\nDB_USERNAME=rspade\nDB_PASSWORD=rspadepass\nREDIS_HOST=127.0.0.1\n";
            file_put_contents($tmp . '/.env.dist', $broken);
            file_put_contents($root_env, $broken);
            symlink('../.env', $system_env);

            static::__assert_throws(
                RuntimeException::class,
                static fn () => Rsx_Env_Symlink::full_heal(),
                'DB_DATABASE'
            );
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    public static function test_empty_db_password_and_passwordless_redis_are_legal()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            $env = "APP_KEY=base64:kept\nRSX_MODE=development\n"
                . "DB_HOST=127.0.0.1\nDB_DATABASE=rspade\nDB_USERNAME=rspade\nDB_PASSWORD=\n"
                . "REDIS_HOST=\nREDIS_PASSWORD=\n";
            file_put_contents($tmp . '/.env.dist', $env);
            file_put_contents($root_env, $env);
            symlink('../.env', $system_env);

            $report = Rsx_Env_Symlink::full_heal();

            static::__assert_false($report['app_key_minted'], 'nothing to do');
            static::__assert_equals('', (string) self::_read_key($root_env, 'DB_PASSWORD'), 'left empty');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (j) an ENCRYPTED .env with no .env is refused - never seeded over
    // -------------------------------------------------------------------------

    public static function test_encrypted_env_without_a_plain_env_is_refused()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/.env.dist', self::_dist_with_key('base64:from_the_dist'));
            file_put_contents($root_env . '.encrypted', 'not-really-ciphertext');
            $dist_before = file_get_contents($tmp . '/.env.dist');

            $e = static::__assert_throws(
                RuntimeException::class,
                static fn () => Rsx_Env_Symlink::full_heal(),
                '.env is encrypted'
            );

            static::__assert_contains('env:decrypt', $e->getMessage(), 'names the way out');
            static::__assert_false(is_file($root_env), '.env was NOT created from the dist');
            static::__assert_equals($dist_before, file_get_contents($tmp . '/.env.dist'), '.env.dist untouched');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    /**
     * The refusal is unconditional: with no .env there is no RSX_MODE to read, so
     * "production is exempt" is not a decision the heal is in a position to make.
     */
    public static function test_encrypted_env_refusal_does_not_depend_on_a_mode()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/.env.dist', str_replace(
                'RSX_MODE=development',
                'RSX_MODE=production',
                self::_dist_with_key('base64:from_the_dist')
            ));
            file_put_contents($root_env . '.encrypted', 'not-really-ciphertext');

            static::__assert_throws(
                RuntimeException::class,
                static fn () => Rsx_Env_Symlink::full_heal(),
                '.env is encrypted'
            );

            static::__assert_false(is_file($root_env), '.env was NOT created');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (k) both present: .env wins and the heal is entirely normal
    // -------------------------------------------------------------------------

    public static function test_an_encrypted_copy_beside_a_real_env_changes_nothing()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/.env.dist', self::DIST . "NEW_SETTING=on\n");
            file_put_contents($root_env, self::_dist_with_key('base64:live'));
            file_put_contents($root_env . '.encrypted', 'stale-ciphertext');
            symlink('../.env', $system_env);

            $report = Rsx_Env_Symlink::full_heal();

            static::__assert_equals('base64:live', self::_read_key($root_env, 'APP_KEY'), 'the live .env won');
            static::__assert_false($report['app_key_minted'], 'nothing minted');
            static::__assert_equals('on', self::_read_key($root_env, 'NEW_SETTING'), 'the key sync still ran');
            static::__assert_equals(
                'stale-ciphertext',
                file_get_contents($root_env . '.encrypted'),
                'the encrypted copy is never touched by the heal - which is why it goes stale'
            );
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // (i) outside development: no sync, no mint
    // -------------------------------------------------------------------------

    public static function test_non_development_neither_syncs_nor_mints()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/system/.env.dist', "FRAMEWORK_NEW=default\n");
            file_put_contents($tmp . '/.env.dist', self::DIST);
            $env = "APP_KEY=base64:kept\nRSX_MODE=production\n"
                . "DB_HOST=127.0.0.1\nDB_DATABASE=rspade\nDB_USERNAME=rspade\nDB_PASSWORD=p\nREDIS_HOST=127.0.0.1\n";
            file_put_contents($root_env, $env);
            symlink('../.env', $system_env);

            $report = Rsx_Env_Symlink::full_heal();

            static::__assert_equals($env, file_get_contents($root_env), 'nothing was written');
            static::__assert_count(0, $report['synced_keys']['root_env'], 'no sync outside development');
            static::__assert_false($report['app_key_minted'], 'no mint outside development');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }

    public static function test_non_development_refuses_an_empty_app_key()
    {
        [$tmp, $system_env, $root_env] = self::_make_layout();
        try {
            file_put_contents($tmp . '/.env.dist', self::DIST);
            $env = "APP_KEY=\nRSX_MODE=production\n"
                . "DB_HOST=127.0.0.1\nDB_DATABASE=rspade\nDB_USERNAME=rspade\nDB_PASSWORD=p\nREDIS_HOST=127.0.0.1\n";
            file_put_contents($root_env, $env);
            symlink('../.env', $system_env);

            static::__assert_throws(
                RuntimeException::class,
                static fn () => Rsx_Env_Symlink::full_heal(),
                'APP_KEY is empty'
            );

            static::__assert_equals('', (string) self::_read_key($root_env, 'APP_KEY'), 'nothing was minted');
        } finally {
            Rsx_Env_Symlink::_testing_reset();
            self::_rmtree($tmp);
        }
    }
}
