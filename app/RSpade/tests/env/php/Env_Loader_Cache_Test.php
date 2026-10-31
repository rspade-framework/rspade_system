<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Env\Php;

use Dotenv\Loader\Loader;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Env;
use App\RSpade\Core\Env\Rsx_LoadEnvironmentVariables;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_LoadEnvironmentVariables: Laravel's environment bootstrapper with the .env PARSE cached in a
 * production-like mode (tmp/env_cache.php, used while it is newer than .env).
 *
 * Pinned: the cached parse loads to exactly what phpdotenv's own load produces, ${OTHER}
 * references included, and those still resolve against the LIVE environment; freshness is
 * strictly-newer mtime; an unwritable tmp/ writes nothing and throws nothing; a production
 * boot writes the cache and loads from it, while a development boot never touches it; and
 * the loader is what Laravel actually makes for LoadEnvironmentVariables.
 *
 * Each test points tmp/ at a scratch directory (Rsx_Project_Paths::_override) and works on a
 * scratch .env, so the box's own environment and cache are never read or written.
 */
class Env_Loader_Cache_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FIXTURE = "# comment\n"
        . "PLAIN=value\n"
        . "QUOTED=\"two words\"\n"
        . "SINGLE='no \${EXPANSION} here'\n"
        . "REF=\"\${ENVTEST_LIVE_BASE}/path\"\n"
        . "TWO_REFS=\${PLAIN}-\${ENVTEST_LIVE_BASE}\n"
        . "UTF8=\"caf\u{e9} \${PLAIN}\"\n"
        . "EMPTY=\n"
        . "export EXPORTED=yes\n";

    /** @var array scratch state for the running test */
    private static array $scratch = [];

    private static function __scratch(): string
    {
        // teardown() runs once per class, so the previous test's scratch is undone here.
        static::__cleanup();

        $root = Rsx_Project_Paths::tmp_path('env_loader_test_' . random_hash(8));
        ensure_directory($root . '/tmp');
        file_put_contents($root . '/.env', self::FIXTURE);

        self::$scratch = ['root' => $root, 'overrides' => Rsx_Project_Paths::_overrides()];
        Rsx_Project_Paths::_override(['tmp' => $root . '/tmp']);

        return $root;
    }

    public static function teardown()
    {
        static::__cleanup();
    }

    private static function __cleanup(): void
    {
        if (!empty(self::$scratch)) {
            Rsx_Project_Paths::_clear_overrides();
            if (!empty(self::$scratch['overrides'])) {
                Rsx_Project_Paths::_override(self::$scratch['overrides']);
            }
            exec_safe('rm -rf ' . escapeshellarg(self::$scratch['root']));
            self::$scratch = [];
        }
    }

    /**
     * A fresh immutable array-backed repository whose $live values were there BEFORE it -
     * written through the adapter, as a real process environment variable is, so the
     * immutable writer treats them as externally defined.
     */
    private static function __repository(array $live = [])
    {
        $adapter = ArrayAdapter::create()->get();
        foreach ($live as $name => $value) {
            $adapter->write($name, $value);
        }

        return RepositoryBuilder::createWithNoAdapters()->addAdapter($adapter)->immutable()->make();
    }

    public static function test_the_cached_parse_loads_exactly_what_phpdotenv_loads()
    {
        $entries = Rsx_LoadEnvironmentVariables::parse_file(static::__scratch() . '/.env');
        $round_tripped = array_map(
            [Rsx_LoadEnvironmentVariables::class, 'entry_from_array'],
            array_map([Rsx_LoadEnvironmentVariables::class, 'entry_to_array'], $entries)
        );

        foreach ($entries as $index => $entry) {
            $value = $entry->getValue()->getOrElse(null);
            $rebuilt = $round_tripped[$index]->getValue()->getOrElse(null);
            static::__assert_equals($value?->getChars(), $rebuilt?->getChars(), 'chars of ' . $entry->getName());
            static::__assert_equals($value?->getVars(), $rebuilt?->getVars(), 'reference positions of ' . $entry->getName());
        }

        $live = ['ENVTEST_LIVE_BASE' => '/srv'];
        $from_cache = (new Loader())->load(static::__repository($live), $round_tripped);
        $from_phpdotenv = (new Loader())->load(static::__repository($live), $entries);

        static::__assert_equals($from_phpdotenv, $from_cache, 'the same values, resolved the same way');
        static::__assert_equals('/srv/path', $from_cache['REF']);
        static::__assert_equals('value-/srv', $from_cache['TWO_REFS']);
        static::__assert_equals("caf\u{e9} value", $from_cache['UTF8'], 'a reference after a multibyte character');
        static::__assert_equals('no ${EXPANSION} here', $from_cache['SINGLE'], 'single quotes stay literal');
    }

    public static function test_references_resolve_against_the_live_environment_at_load_time()
    {
        $root = static::__scratch();
        Rsx_LoadEnvironmentVariables::write_cache($root . '/.env', Rsx_LoadEnvironmentVariables::parse_file($root . '/.env'));
        touch(Rsx_Project_Paths::env_cache_file(), time() + 5);

        $first = (new Loader())->load(static::__repository(['ENVTEST_LIVE_BASE' => '/one']), Rsx_LoadEnvironmentVariables::cached_entries($root . '/.env'));
        $second = (new Loader())->load(static::__repository(['ENVTEST_LIVE_BASE' => '/two']), Rsx_LoadEnvironmentVariables::cached_entries($root . '/.env'));

        static::__assert_equals('/one/path', $first['REF']);
        static::__assert_equals('/two/path', $second['REF'], 'the same cache, a different live value - nothing was frozen');
    }

    public static function test_real_environment_values_still_win_over_the_cached_file()
    {
        $root = static::__scratch();
        $entries = Rsx_LoadEnvironmentVariables::parse_file($root . '/.env');
        $round_tripped = array_map([Rsx_LoadEnvironmentVariables::class, 'entry_from_array'], array_map([Rsx_LoadEnvironmentVariables::class, 'entry_to_array'], $entries));

        $repository = static::__repository(['PLAIN' => 'from-the-process']);
        (new Loader())->load($repository, $round_tripped);

        static::__assert_equals('from-the-process', $repository->get('PLAIN'), 'an immutable repository keeps the real environment\'s value');
    }

    public static function test_the_cache_is_used_only_while_strictly_newer_than_env()
    {
        $root = static::__scratch();
        $env_file = $root . '/.env';
        $cache_file = Rsx_Project_Paths::env_cache_file();

        static::__assert_null(Rsx_LoadEnvironmentVariables::cached_entries($env_file), 'no cache yet');

        Rsx_LoadEnvironmentVariables::write_cache($env_file, Rsx_LoadEnvironmentVariables::parse_file($env_file));
        static::__assert_true(is_file($cache_file), 'written to tmp/');

        touch($env_file, 1000);
        touch($cache_file, 1000);
        static::__assert_null(Rsx_LoadEnvironmentVariables::cached_entries($env_file), 'the same second is stale');

        touch($cache_file, 1001);
        static::__assert_not_null(Rsx_LoadEnvironmentVariables::cached_entries($env_file), 'newer is fresh');

        touch($env_file, 1002);
        static::__assert_null(Rsx_LoadEnvironmentVariables::cached_entries($env_file), 'an edited .env makes it stale');

        touch($cache_file, 1003);
        static::__assert_null(Rsx_LoadEnvironmentVariables::cached_entries($root . '/other.env'), 'a cache of another file is never used');
    }

    public static function test_an_unwritable_tmp_writes_nothing_and_throws_nothing()
    {
        $root = static::__scratch();
        // tmp/ is a regular FILE: nothing can be created under it, whoever runs the test.
        exec_safe('rm -rf ' . escapeshellarg($root . '/tmp'));
        file_put_contents($root . '/tmp', 'not a directory');

        Rsx_LoadEnvironmentVariables::write_cache($root . '/.env', Rsx_LoadEnvironmentVariables::parse_file($root . '/.env'));

        static::__assert_false(is_file(Rsx_Project_Paths::env_cache_file()), 'no cache');
        static::__assert_null(Rsx_LoadEnvironmentVariables::cached_entries($root . '/.env'), 'so every boot parses');
    }

    public static function test_a_production_boot_writes_and_then_reads_the_cache_and_a_development_boot_never_touches_it()
    {
        $root = static::__scratch();
        $suffix = strtoupper(random_hash(8));
        $dev_name = 'ENVTEST_DEV_' . $suffix;
        $prod_name = 'ENVTEST_PROD_' . $suffix;
        $later_name = 'ENVTEST_LATER_' . $suffix;

        // The running application, its environment path pointed at the scratch .env for the
        // span of the test. (A second Application would replace the process's container.)
        $app = app();
        $previous_path = $app->environmentPath();
        $previous_mode = getenv('RSX_MODE');

        try {
            $app->useEnvironmentPath($root);

            file_put_contents($root . '/.env', "{$dev_name}=from-file\n");
            putenv('RSX_MODE=development');
            (new Rsx_LoadEnvironmentVariables())->bootstrap($app);
            static::__assert_equals('from-file', Env::get($dev_name), 'development loads .env as Laravel does');
            static::__assert_false(is_file(Rsx_Project_Paths::env_cache_file()), 'and writes no cache');

            file_put_contents($root . '/.env', "{$prod_name}=from-file\n");
            touch($root . '/.env', time() - 10);
            putenv('RSX_MODE=production');
            (new Rsx_LoadEnvironmentVariables())->bootstrap($app);
            static::__assert_equals('from-file', Env::get($prod_name), 'a production boot loads .env');
            static::__assert_true(is_file(Rsx_Project_Paths::env_cache_file()), 'and writes the cache');

            // Change the file but keep it OLDER than the cache: the next production boot
            // answers from the cache, so the new key never appears.
            file_put_contents($root . '/.env', "{$later_name}=from-file\n");
            touch($root . '/.env', time() - 10);
            (new Rsx_LoadEnvironmentVariables())->bootstrap($app);
            static::__assert_null(Env::get($later_name), 'the cached parse answered, not the file');

            // Make the file NEWER: the next boot parses it and rewrites the cache.
            touch($root . '/.env', time() + 60);
            (new Rsx_LoadEnvironmentVariables())->bootstrap($app);
            static::__assert_equals('from-file', Env::get($later_name), 'a newer .env is parsed');
            static::__assert_contains($later_name, (string) file_get_contents(Rsx_Project_Paths::env_cache_file()), 'and the cache is rewritten from it');
        } finally {
            $app->useEnvironmentPath($previous_path);
            putenv($previous_mode === false ? 'RSX_MODE' : 'RSX_MODE=' . $previous_mode);
        }
    }

    public static function test_laravel_makes_this_loader_for_its_environment_bootstrapper()
    {
        static::__assert_equals(Rsx_LoadEnvironmentVariables::class, get_class(app(LoadEnvironmentVariables::class)));
    }
}
