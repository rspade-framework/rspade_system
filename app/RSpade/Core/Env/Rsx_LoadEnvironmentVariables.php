<?php

namespace App\RSpade\Core\Env;

use Dotenv\Exception\InvalidFileException;
use Dotenv\Loader\Loader;
use Dotenv\Parser\Entry;
use Dotenv\Parser\Parser;
use Dotenv\Parser\Value;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Env;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Laravel's environment bootstrapper, with the .env PARSE cached in a production-like mode.
 *
 * Laravel builds each bootstrapper through the container, and bootstrap/app.php binds
 * LoadEnvironmentVariables::class to this class - so the kernels' bootstrapper lists and the
 * `bootstrapped:` event names (afterLoadingEnvironment(), which the $HOSTNAME substitution
 * hangs on) stay exactly Laravel's. Built on public API only - the binding, the
 * bootstrapper's protected methods, and phpdotenv's Parser, Entry, Value and Loader - so a
 * composer update of either changes nothing here unless that API breaks, and then it fails
 * at boot.
 *
 * DEVELOPMENT is Laravel's path exactly: parse .env on every boot, no cache read or written.
 *
 * DEBUG and PRODUCTION (decided by the pre-boot mode reader, before .env is loaded):
 *   - the cache (Rsx_Project_Paths::env_cache_file(), in tmp/) is used when its mtime is
 *     NEWER than .env's - two stats, the OPcache revalidate model. Strictly newer: a cache
 *     written in the same second as an edit is stale, and is rewritten a second later;
 *   - otherwise .env is parsed and loaded, and the cache is written (temp file + rename);
 *   - an unwritable tmp/ means no cache, and every boot parses - correct, only slower.
 *
 * WHAT IS CACHED IS THE PARSE, NOT THE VALUES. The cache holds phpdotenv's parser output -
 * each name, its raw value, and the positions of any ${OTHER} references - as an array
 * literal OPcache keeps as bytecode. Loading hands the rebuilt entries to phpdotenv's own
 * Loader, the call Dotenv::load() makes after parsing, so references still resolve against
 * the live environment on every boot and a real environment variable still wins. Only the
 * parse is skipped.
 *
 * A Laravel config cache is never used (config:cache and the commands around it are
 * restricted): the config files are evaluated every boot, so env() is never frozen.
 *
 * #[Instantiatable]: Laravel makes the bootstrapper as an instance and calls bootstrap() on it.
 */
#[Instantiatable]
class Rsx_LoadEnvironmentVariables extends LoadEnvironmentVariables
{
    /** The cache format; a cache of any other version is ignored and rewritten. */
    public const CACHE_VERSION = 1;

    /**
     * @param Application $app
     * @return void
     */
    public function bootstrap(Application $app)
    {
        if ($app->configurationIsCached()) {
            throw new \RuntimeException(
                'A Laravel config cache exists at ' . $app->getCachedConfigPath() . ".\n"
                . 'RSpade never caches config (env() would be frozen at the moment it was built). '
                . 'Delete that file; php artisan rsx:build --force rebuilds what a production box serves.'
            );
        }

        require_once dirname(__DIR__, 4) . '/bootstrap/rsx_mode.php';

        if (!rsx_preboot_mode_is_production_like()) {
            parent::bootstrap($app);

            return;
        }

        $this->checkForSpecificEnvironmentFile($app);

        $env_file = $app->environmentFilePath();
        $entries = static::cached_entries($env_file);

        if ($entries === null) {
            try {
                $entries = static::parse_file($env_file);
            } catch (InvalidFileException $e) {
                $this->writeErrorAndDie($e);
            }

            static::write_cache($env_file, $entries);
        }

        (new Loader())->load(Env::getRepository(), $entries);
    }

    /**
     * The parsed entries of $env_file, or [] when it does not exist (Dotenv::safeLoad()'s
     * answer to a missing file).
     *
     * @return array<int, Entry>
     * @throws InvalidFileException
     */
    public static function parse_file(string $env_file): array
    {
        if (!is_file($env_file)) {
            return [];
        }

        return (new Parser())->parse((string) file_get_contents($env_file));
    }

    /**
     * The cached entries for $env_file, or null when there is no cache newer than it.
     *
     * @return array<int, Entry>|null
     */
    public static function cached_entries(string $env_file): ?array
    {
        $cache_file = Rsx_Project_Paths::env_cache_file();
        $cache_mtime = @filemtime($cache_file);

        if ($cache_mtime === false) {
            return null;
        }

        $env_mtime = @filemtime($env_file);
        if ($env_mtime !== false && $cache_mtime <= $env_mtime) {
            return null;
        }

        $cached = include $cache_file;
        if (!is_array($cached) || ($cached['version'] ?? null) !== self::CACHE_VERSION
            || ($cached['env_file'] ?? null) !== $env_file) {
            return null;
        }

        return array_map([static::class, 'entry_from_array'], $cached['entries']);
    }

    /**
     * Write the cache of $entries for $env_file. Silent when tmp/ cannot be written: the
     * next boot parses again, which is the correct answer on a read-only box.
     *
     * @param array<int, Entry> $entries
     */
    public static function write_cache(string $env_file, array $entries): void
    {
        $cache_file = Rsx_Project_Paths::env_cache_file();
        $payload = [
            'version' => self::CACHE_VERSION,
            'env_file' => $env_file,
            'entries' => array_map([static::class, 'entry_to_array'], $entries),
        ];

        $temp_file = $cache_file . '.' . getmypid() . '.tmp';
        $source = "<?php\n// Generated by Rsx_LoadEnvironmentVariables from " . $env_file . " - the parsed .env, values unresolved.\n"
            . 'return ' . var_export($payload, true) . ";\n";

        if (@file_put_contents($temp_file, $source) === false) {
            return;
        }
        if (!@rename($temp_file, $cache_file)) {
            @unlink($temp_file);

            return;
        }
        opcache_invalidate($cache_file, true);
    }

    /**
     * One entry as plain data: [name, raw chars, reference positions], the value null for a
     * bare name.
     *
     * @return array{0: string, 1: ?string, 2: ?array<int, int>}
     */
    public static function entry_to_array(Entry $entry): array
    {
        $value = $entry->getValue()->getOrElse(null);

        return [$entry->getName(), $value?->getChars(), $value === null ? null : array_values(array_reverse($value->getVars()))];
    }

    /**
     * The entry entry_to_array() describes, rebuilt through phpdotenv's public constructors:
     * the characters in order, with an empty variable-flagged append at each reference
     * position, which is how Value records one.
     *
     * @param array{0: string, 1: ?string, 2: ?array<int, int>} $data
     */
    public static function entry_from_array(array $data): Entry
    {
        [$name, $chars, $positions] = $data;

        if ($chars === null) {
            return new Entry($name);
        }

        $value = Value::blank();
        $cursor = 0;
        foreach ($positions as $position) {
            $value = $value->append(mb_substr($chars, $cursor, $position - $cursor, 'UTF-8'), false)->append('', true);
            $cursor = $position;
        }

        return new Entry($name, $value->append(mb_substr($chars, $cursor, null, 'UTF-8'), false));
    }
}
