<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Ide\Php;

use App\RSpade\Core\Ide\Ide_Bridge_Token;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Framework test for Ide_Bridge_Token - the local-file grant for the IDE bridge
 * (app/RSpade/Core/Ide/Ide_Bridge_Token.php).
 *
 * Each test points the token machinery at a throwaway bridge directory under
 * storage/rsx-tmp (via the rsx.ide_integration.bridge_path config the class reads),
 * so the real storage/rsx-ide-bridge grant is never touched. The suite runs in
 * development mode (the box default), which is what gates ensure().
 */
class Ide_Bridge_Token_Test extends Rsx_Test_Abstract
{
    // Filesystem + config behavior only - no database.
    protected static $use_database_transactions = false;

    /** Absolute bridge dirs created during the run, cleaned up in teardown. */
    private static $created_dirs = [];

    public static function teardown()
    {
        foreach (self::$created_dirs as $dir) {
            self::__rmrf($dir);
        }
        self::$created_dirs = [];
        Rsx::clear_mode_cache();
    }

    /**
     * Create a fresh, empty bridge directory and point the config at it. Returns the
     * absolute path. The class resolves its dir against the directory CONTAINING
     * storage/ (dirname(storage_path()) - volatile storage lives at the project root),
     * so the config value stays a 'storage/...'-prefixed path.
     */
    private static function __fresh_bridge(): string
    {
        $relative = 'storage/rsx-tmp/ide_bridge_test_' . random_hash(8);
        config([
            'rsx.ide_integration.bridge_path' => $relative,
            'rsx.ide_integration.enabled' => true,
        ]);
        $abs = dirname(storage_path()) . '/' . $relative;
        self::$created_dirs[] = $abs;

        return $abs;
    }

    private static function __rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        // scandir (not glob) so dotfiles like .htaccess are removed too, else the
        // directory stays non-empty and rmdir leaves it behind.
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $f = $dir . '/' . $entry;
            is_dir($f) ? self::__rmrf($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    public static function test_ensure_creates_single_grant_document()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();

        $tokens = glob($dir . '/ide-grant-*.token') ?: [];
        static::__assert_count(1, $tokens, 'ensure() must create exactly one grant token');

        $document = json_decode((string) file_get_contents($tokens[0]), true);
        static::__assert_true(is_array($document), 'the grant file must be a JSON document');

        static::__assert_true(
            (bool) preg_match('/^[0-9a-f]{64}$/', $document['secret'] ?? ''),
            'grant secret must be 64 lowercase hex chars'
        );
    }

    /**
     * The whole reason app_url is in the document: the IDE cannot resolve a literal
     * $HOSTNAME in APP_URL correctly, because it would substitute the workstation's
     * name rather than the server's. What is written here must therefore be the
     * RESOLVED address, never the token.
     */
    public static function test_grant_carries_the_resolved_app_url()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();

        $tokens = glob($dir . '/ide-grant-*.token') ?: [];
        $document = json_decode((string) file_get_contents($tokens[0]), true);

        $app_url = $document['app_url'] ?? '';
        static::__assert_equals(rtrim((string) config('app.url'), '/'), $app_url);
        static::__assert_false(
            str_contains($app_url, '$HOSTNAME'),
            'the written URL must be resolved, not the literal $HOSTNAME token'
        );
    }

    /**
     * A file that is not a grant document cannot authenticate anything, so ensure()
     * replaces it outright - filename and secret both re-rolled.
     */
    public static function test_ensure_replaces_an_unparseable_grant()
    {
        $dir = self::__fresh_bridge();
        @mkdir($dir, 0700, true);
        $stale = $dir . '/ide-grant-deadbeef.token';
        file_put_contents($stale, 'not-a-grant-document');

        Ide_Bridge_Token::ensure();

        static::__assert_true(!file_exists($stale), 'an unparseable grant must be removed');

        $tokens = glob($dir . '/ide-grant-*.token') ?: [];
        static::__assert_count(1, $tokens, 'exactly one grant must remain');

        $document = json_decode((string) file_get_contents($tokens[0]), true);
        static::__assert_true(
            (bool) preg_match('/^[0-9a-f]{64}$/', $document['secret'] ?? ''),
            'the replacement must carry a fresh 64-hex secret'
        );
    }

    public static function test_ensure_writes_passive_guards()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();

        static::__assert_true(is_file($dir . '/index.php'), 'ensure() must drop an index.php 404 guard');
        static::__assert_true(is_file($dir . '/.htaccess'), 'ensure() must drop an .htaccess deny guard');

        $htaccess = (string) file_get_contents($dir . '/.htaccess');
        static::__assert_contains('Require all denied', $htaccess);
    }

    public static function test_ensure_clears_retired_artifacts()
    {
        $dir = self::__fresh_bridge();
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/auth-legacy.json', '{"session":"x"}');
        file_put_contents($dir . '/domain.txt', 'https://old.example');

        Ide_Bridge_Token::ensure();

        static::__assert_true(!file_exists($dir . '/auth-legacy.json'), 'retired auth-*.json must be removed');
        static::__assert_true(!file_exists($dir . '/domain.txt'), 'retired domain.txt must be removed');
    }

    public static function test_ensure_is_idempotent_keeps_same_token()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();
        $first = glob($dir . '/ide-grant-*.token') ?: [];
        $first_content = trim((string) file_get_contents($first[0]));

        Ide_Bridge_Token::ensure();
        $second = glob($dir . '/ide-grant-*.token') ?: [];

        static::__assert_count(1, $second, 'a second ensure() must not add another token');
        static::__assert_equals($first[0], $second[0], 'token filename must be stable across calls');

        // The SECRET is what must be stable, not the bytes: ensure() rewrites the
        // document on every web boot to refresh app_url. issued_at is carried forward
        // rather than re-stamped, so a refresh cannot promote a grant's ordering.
        $before = json_decode($first_content, true);
        $after = json_decode((string) file_get_contents($second[0]), true);

        static::__assert_equals($before['secret'], $after['secret'], 'the secret must be stable across calls');
        static::__assert_equals($before['issued_at'], $after['issued_at'], 'issued_at must not be re-stamped by a refresh');
    }

    public static function test_current_token_returns_established_content()
    {
        $dir = self::__fresh_bridge();

        static::__assert_null(Ide_Bridge_Token::current_token(), 'no token established yet');

        Ide_Bridge_Token::ensure();

        $tokens = glob($dir . '/ide-grant-*.token') ?: [];
        $document = json_decode((string) file_get_contents($tokens[0]), true);
        static::__assert_equals($document['secret'], Ide_Bridge_Token::current_token());
    }

    // =====================================================================
    // Rotation
    // =====================================================================

    /**
     * The property the whole scheme rests on: a rotation is never observable to a
     * client as a failure, because the grant it last read is still accepted.
     */
    public static function test_rotate_keeps_the_previous_grant_alive()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();
        $first = self::__only_secret($dir);

        Ide_Bridge_Token::rotate();

        $active = Ide_Bridge_Token::active_grant_files();
        static::__assert_count(2, $active, 'the newest and the previous grant both stay');

        $secrets = array_map(
            static fn ($f) => json_decode((string) file_get_contents($f), true)['secret'] ?? '',
            $active
        );
        static::__assert_true(in_array($first, $secrets, true), 'the previous secret must still be active');
    }

    /**
     * Never more than ACTIVE_GRANTS, however many times it runs.
     */
    public static function test_rotate_retires_everything_beyond_two()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();
        for ($i = 0; $i < 5; $i++) {
            Ide_Bridge_Token::rotate();
        }

        $tokens = glob($dir . '/' . 'ide-grant-*.token') ?: [];
        static::__assert_count(
            Ide_Bridge_Token::ACTIVE_GRANTS,
            $tokens,
            'repeated rotation must not accumulate grants'
        );
    }

    /**
     * A rotation mints a genuinely new secret rather than rewriting the old one.
     */
    public static function test_rotate_mints_a_new_secret()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();
        $before = self::__only_secret($dir);

        $result = Ide_Bridge_Token::rotate();

        static::__assert_equals('rotated', $result['mode']);
        static::__assert_true(!empty($result['minted']), 'rotate() must report the file it minted');

        $newest = Ide_Bridge_Token::active_grant_files()[0];
        $after = json_decode((string) file_get_contents($newest), true)['secret'] ?? '';
        static::__assert_true($after !== $before, 'the newest grant must carry a fresh secret');
    }

    /**
     * ensure() is the CRON-LESS GUARANTEE: it mints only when nothing is established,
     * so a box whose operator never enabled the scheduler keeps its one grant forever.
     * A later ensure() must not disturb a rotated pair either.
     */
    public static function test_ensure_never_disturbs_an_established_pair()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();
        Ide_Bridge_Token::rotate();
        $before = glob($dir . '/ide-grant-*.token') ?: [];
        sort($before);

        Ide_Bridge_Token::ensure();

        $after = glob($dir . '/ide-grant-*.token') ?: [];
        sort($after);
        static::__assert_equals($before, $after, 'ensure() must mint nothing while a grant exists');
    }

    /**
     * The single secret of a cron-less box is not a rotation casualty: with the
     * scheduler never running, nothing expires it.
     */
    public static function test_a_grant_survives_indefinitely_without_rotation()
    {
        $dir = self::__fresh_bridge();

        Ide_Bridge_Token::ensure();
        $minted = self::__only_secret($dir);

        // Many web boots, no scheduler.
        for ($i = 0; $i < 10; $i++) {
            Ide_Bridge_Token::ensure();
        }

        static::__assert_equals($minted, self::__only_secret($dir), 'the bootstrap grant must persist');
        static::__assert_equals($minted, Ide_Bridge_Token::current_token());
    }

    /**
     * The secret of the one grant in $dir.
     */
    private static function __only_secret(string $dir): string
    {
        $tokens = glob($dir . '/ide-grant-*.token') ?: [];
        return json_decode((string) file_get_contents($tokens[0]), true)['secret'] ?? '';
    }

    public static function test_bridge_dir_honors_config_path()
    {
        $dir = self::__fresh_bridge();
        static::__assert_equals($dir, Ide_Bridge_Token::bridge_dir());
    }

    // -----------------------------------------------------------------------------
    // The grant is the SHARED development credential (IDE bridge + rsx:debug dev-auth)
    // -----------------------------------------------------------------------------

    /**
     * The store exists for rsx:debug even on a tree that has switched the IDE bridge
     * off - that switch belongs to the BRIDGE, not to the credential. Before the split
     * a developer with rsx.ide_integration.enabled = false had no grant at all, and
     * rsx:debug could not sign anything.
     */
    public static function test_the_grant_store_is_ensured_with_the_ide_bridge_disabled()
    {
        $dir = self::__fresh_bridge();
        config(['rsx.ide_integration.enabled' => false]);

        Ide_Bridge_Token::ensure();
        static::__assert_count(0, glob($dir . '/ide-grant-*.token') ?: [], 'ensure() is the BRIDGE entry point and stays gated');

        Ide_Bridge_Token::ensure_grant_store();
        static::__assert_count(1, glob($dir . '/ide-grant-*.token') ?: [], 'the STORE is ensured regardless of the bridge switch');
        static::__assert_count(1, Ide_Bridge_Token::active_secrets());
    }

    /**
     * A file holding a secret must never be web-servable, whichever consumer created it.
     */
    public static function test_the_grant_store_carries_the_static_serve_guards()
    {
        $dir = self::__fresh_bridge();
        config(['rsx.ide_integration.enabled' => false]);

        Ide_Bridge_Token::ensure_grant_store();

        static::__assert_true(file_exists($dir . '/index.php'), 'the 404 index guard is written');
        static::__assert_true(file_exists($dir . '/.htaccess'), 'the deny guard is written');
    }

    /**
     * active_secrets() is what every verifier consults: the ACTIVE_GRANTS newest
     * secrets, newest issued_at FIRST, so a minter always signs with the current one.
     */
    public static function test_active_secrets_are_newest_first_and_capped()
    {
        $dir = self::__fresh_bridge();

        static::__assert_equals([], Ide_Bridge_Token::active_secrets(), 'no store, no secrets');

        Ide_Bridge_Token::ensure();
        $first = self::__only_secret($dir);
        static::__assert_equals([$first], Ide_Bridge_Token::active_secrets());

        Ide_Bridge_Token::rotate();
        $second = Ide_Bridge_Token::active_secrets();
        static::__assert_count(2, $second, 'the rotated pair is both active');
        static::__assert_equals($first, $second[1], 'the previous grant sorts second');
        static::__assert_not_equals($first, $second[0], 'the fresh grant sorts first');

        Ide_Bridge_Token::rotate();
        $third = Ide_Bridge_Token::active_secrets();
        static::__assert_count(2, $third, 'never more than ACTIVE_GRANTS');
        static::__assert_false(in_array($first, $third, true), 'the retired secret is gone');
        static::__assert_equals($second[0], $third[1], 'yesterday\'s newest is today\'s previous');
    }

    /**
     * Outside development there is no development credential, full stop - the answer is
     * empty even if files are sitting on disk from before a mode flip.
     */
    public static function test_active_secrets_are_empty_outside_development()
    {
        self::__fresh_bridge();
        Ide_Bridge_Token::ensure();
        static::__assert_count(1, Ide_Bridge_Token::active_secrets());

        foreach ([Rsx::MODE_DEBUG, Rsx::MODE_PRODUCTION] as $mode) {
            Rsx::_testing_set_mode($mode);
            $secrets = Ide_Bridge_Token::active_secrets();
            Rsx::clear_mode_cache();

            static::__assert_equals([], $secrets, "no development credential exists in {$mode} mode");
        }
    }
}
