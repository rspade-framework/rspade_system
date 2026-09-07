<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Cache;

/**
 * The ONE way a per-source-file DERIVED ARTIFACT is cached on disk.
 *
 * A build subsystem that turns one source file into one derived artifact - a babel
 * transform, a parsed AST, a compiled jqhtml template, a sanitized copy for the
 * code-quality rules, a reflection extract - wants to remember that artifact so the next
 * build does not recompute it. Every such subsystem used to invent its own directory, its
 * own filename scheme and its own staleness test: nine hand-rolled schemes, four different
 * answers to "what is the key", and 5000+ loose files whose only common property was that
 * `rsx:clean` deleted them.
 *
 * This class is that memory, once, for all of them:
 *
 *     storage/rsx-tmp/derived/<namespace>/<hash><variant>.<ext>
 *
 * ONE KEY SCHEME. <hash> is `_rsx_file_hash_for_build($source_path)` - the framework's
 * single file-identity helper, which branches on RSX_MODE exactly once (development:
 * path + size + mtime, cheap and local; production/debug: the project-RELATIVE path plus
 * the content, so a derived name is identical in two byte-identical checkouts and a sealed
 * build stays deterministic). A cache does not get an opinion about this: if it needs a
 * different identity it passes an explicit hash through the `*_for_hash()` twins (the
 * reflection cache does - it is keyed by the manifest's own sha1, which it already had).
 *
 * THE VARIANT IS THE PREMISE. Anything the artifact depends on BEYOND the source bytes -
 * a compile target, a toolchain fingerprint, a parser version - goes in `$variant`, which
 * is appended to the hash. That is what makes a toolchain upgrade retire every entry it
 * invalidates instead of serving the old tool's work under a key that never moved. A
 * variant is a caller-chosen string; the convention is a leading underscore
 * (`_modern_<fingerprint>`), and anything outside [A-Za-z0-9._-] is replaced so a variant
 * can never escape its namespace directory.
 *
 * STALENESS. The hash IS the staleness test in development (mtime is inside it) and in
 * production (content is inside it). `get()` takes an optional `$guard_source_mtime` for
 * the two callers that additionally refused a cache file older than its source before this
 * class existed; keeping it costs one stat and one recompute, and losing it would be a
 * weakening.
 *
 * SWEEPING. Nothing here expires on a clock. `sweep($namespace, $live_hashes)` removes the
 * entries whose hash is not in a live set, and is called by whoever ALREADY has the
 * manifest - the manifest build's own Phase 7 - because building the manifest just to
 * prune would cost more than the bytes it reclaims. `rsx:clean` wipes rsx-tmp wholesale
 * and needs no wiring at all.
 *
 * See: rsx:man storage_directories, rsx:man code_quality.
 */
class File_Content_Cache
{
    /** Root of the derived tree, relative to the storage directory. */
    private const ROOT = 'rsx-tmp/derived';

    /**
     * The identity of a source file, for cache-key purposes.
     *
     * Exactly `_rsx_file_hash_for_build()` - named here so a caller reads the intent
     * ("what this cache keys by") rather than the mechanism.
     */
    public static function hash_of(string $source_path): string
    {
        return _rsx_file_hash_for_build($source_path);
    }

    /**
     * Where the entry derived from $source_path lives.
     */
    public static function path(string $namespace, string $source_path, string $variant = '', string $ext = 'txt'): string
    {
        return static::path_for_hash($namespace, static::hash_of($source_path), $variant, $ext);
    }

    /**
     * Where the entry for an EXPLICIT hash lives.
     */
    public static function path_for_hash(string $namespace, string $hash, string $variant = '', string $ext = 'txt'): string
    {
        return static::namespace_dir($namespace)
            . '/' . static::__sanitize($hash) . static::__sanitize($variant)
            . '.' . static::__sanitize(ltrim($ext, '.'));
    }

    /**
     * The directory one namespace's entries live in. Created on demand.
     */
    public static function namespace_dir(string $namespace): string
    {
        $directory = storage_path(self::ROOT . '/' . static::__sanitize($namespace));

        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        return $directory;
    }

    /**
     * The cached content, or null on a miss.
     *
     * $guard_source_mtime additionally rejects an entry written before the source file was
     * last touched - the extra test two of the migrated caches applied on top of their hash
     * hit, kept so no cache gets weaker in this move.
     */
    public static function get(
        string $namespace,
        string $source_path,
        string $variant = '',
        string $ext = 'txt',
        bool $guard_source_mtime = false
    ): ?string {
        $path = static::path($namespace, $source_path, $variant, $ext);

        return static::__read($path, $guard_source_mtime ? $source_path : null);
    }

    /**
     * The cached content for an EXPLICIT hash, or null on a miss.
     */
    public static function get_for_hash(
        string $namespace,
        string $hash,
        string $variant = '',
        string $ext = 'txt',
        ?string $guard_against_source = null
    ): ?string {
        $path = static::path_for_hash($namespace, $hash, $variant, $ext);

        return static::__read($path, $guard_against_source);
    }

    /**
     * Store the derived artifact. Returns the path it was written to.
     *
     * The write is atomic (file_put_contents_safe stages and renames), so a reader never
     * sees a half-written entry and a killed build leaves no torn file behind.
     */
    public static function put(
        string $namespace,
        string $source_path,
        string $variant,
        string $ext,
        string $content
    ): string {
        return static::put_for_hash($namespace, static::hash_of($source_path), $variant, $ext, $content);
    }

    /**
     * Store the derived artifact under an EXPLICIT hash. Returns the path.
     */
    public static function put_for_hash(
        string $namespace,
        string $hash,
        string $variant,
        string $ext,
        string $content
    ): string {
        static::namespace_dir($namespace);

        $path = static::path_for_hash($namespace, $hash, $variant, $ext);

        file_put_contents_safe($path, $content);

        return $path;
    }

    /**
     * Drop one entry.
     */
    public static function forget(string $namespace, string $source_path, string $variant = '', string $ext = 'txt'): void
    {
        static::forget_for_hash($namespace, static::hash_of($source_path), $variant, $ext);
    }

    /**
     * Drop one entry addressed by hash.
     */
    public static function forget_for_hash(string $namespace, string $hash, string $variant = '', string $ext = 'txt'): void
    {
        $path = static::path_for_hash($namespace, $hash, $variant, $ext);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Remove every entry in $namespace whose hash is not in $live_hashes.
     *
     * The hash is the filename up to the variant, and a variant always begins with a
     * character the hash alphabet does not contain, so the split needs no bookkeeping: an
     * entry belongs to the source whose hash prefixes its name.
     *
     * An EMPTY live set removes nothing. "I could not work out what is live" and
     * "nothing is live" are different statements, and only one of them is ever true here.
     *
     * @param array<int,string> $live_hashes
     * @return int entries removed
     */
    public static function sweep(string $namespace, array $live_hashes): int
    {
        if (empty($live_hashes)) {
            return 0;
        }

        $directory = storage_path(self::ROOT . '/' . static::__sanitize($namespace));

        if (!is_dir($directory)) {
            return 0;
        }

        $live = array_flip($live_hashes);
        $removed = 0;

        foreach (glob($directory . '/*') ?: [] as $entry) {
            if (!is_file($entry)) {
                continue;
            }

            $name = basename($entry);
            $hash = strtok($name, '_.');

            if ($hash === false || isset($live[$hash])) {
                continue;
            }

            if (@unlink($entry)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Sweep every namespace present on disk against one live set.
     *
     * The manifest build's entry point: it holds the only complete answer to "which source
     * files still exist", and one union of every identity those files can be keyed by (the
     * build hash AND the manifest's sha1) covers every namespace at once - a superset is
     * harmless, since sweeping only ever removes what is in no live set at all.
     *
     * @param array<int,string> $live_hashes
     * @return int entries removed
     */
    public static function sweep_all(array $live_hashes): int
    {
        $root = storage_path(self::ROOT);

        if (!is_dir($root) || empty($live_hashes)) {
            return 0;
        }

        $removed = 0;

        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $removed += static::sweep(basename($directory), $live_hashes);
        }

        return $removed;
    }

    /**
     * Empty one namespace.
     */
    public static function clear(string $namespace): void
    {
        $directory = storage_path(self::ROOT . '/' . static::__sanitize($namespace));

        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
    }

    /**
     * Read one entry, applying the optional source-mtime guard.
     */
    private static function __read(string $path, ?string $guard_against_source): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        if ($guard_against_source !== null && file_exists($guard_against_source)) {
            if (filemtime($path) < filemtime($guard_against_source)) {
                return null;
            }
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * Keep every path component inside its own directory: a namespace, hash, variant or
     * extension is a NAME, never a path, so a separator in one is a bug, not a hierarchy.
     */
    private static function __sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
    }
}
