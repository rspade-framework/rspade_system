<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedCache\Php;

use App\RSpade\Core\Cache\File_Content_Cache;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Unit tests for the ONE per-source-file derived cache.
 *
 * The helper replaced nine hand-rolled schemes - four different answers to "what is the
 * key", four different staleness tests, one directory each - so the properties under test
 * are exactly the ones those schemes each had to get right on their own: the path shape is
 * predictable, a stored artifact comes back, a CHANGED source misses, two variants of the
 * same source do not collide, the sweep removes only what is dead, and a reader never sees
 * a half-written entry.
 *
 * Every row works in a throwaway namespace, so a run never disturbs the box's own build
 * caches.
 */
class File_Content_Cache_Test extends Rsx_Test_Abstract
{
    // Pure filesystem work - no database access.
    protected static $use_database_transactions = false;

    /** The scratch namespace and source directory for one row. */
    private static ?string $namespace = null;
    private static ?string $source_dir = null;

    private static function __begin(): void
    {
        $token = uniqid();

        self::$namespace = 'test-derived-' . $token;
        self::$source_dir = storage_path('rsx-tmp') . '/derived_cache_fixture_' . $token;

        ensure_directory(self::$source_dir);
    }

    private static function __end(): void
    {
        if (self::$namespace !== null) {
            File_Content_Cache::clear(self::$namespace);
            @rmdir(File_Content_Cache::namespace_dir(self::$namespace));
        }

        if (self::$source_dir !== null && is_dir(self::$source_dir)) {
            foreach (glob(self::$source_dir . '/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir(self::$source_dir);
        }

        self::$namespace = null;
        self::$source_dir = null;
    }

    /**
     * Write a source file and return its path.
     */
    private static function __source(string $name, string $content): string
    {
        $path = self::$source_dir . '/' . $name;

        file_put_contents($path, $content);

        return $path;
    }

    // =====================================================================
    // Path shape
    // =====================================================================

    /**
     * The path is storage/rsx-tmp/derived/<namespace>/<hash><variant>.<ext>, and nothing
     * a caller passes can escape the namespace directory.
     */
    public static function test_path_shape()
    {
        self::__begin();

        try {
            $source = self::__source('a.js', 'const a = 1;');

            $path = File_Content_Cache::path(self::$namespace, $source, '_modern_abc', 'js');
            $hash = File_Content_Cache::hash_of($source);

            static::__assert_contains(
                '/rsx-tmp/derived/' . self::$namespace . '/',
                $path,
                'an entry lives under storage/rsx-tmp/derived/<namespace>/'
            );

            static::__assert_equals(
                $hash . '_modern_abc.js',
                basename($path),
                'the filename is <hash><variant>.<ext>'
            );

            // A variant may legitimately contain dots (a parser version is `2.3.61`), so
            // the property under test is that it cannot contain a SEPARATOR: whatever is
            // passed, the entry stays one filename inside its own namespace directory.
            $escaped = File_Content_Cache::path(self::$namespace, $source, '_../../etc', 'js');

            static::__assert_equals(
                File_Content_Cache::namespace_dir(self::$namespace),
                dirname($escaped),
                'a separator in a variant cannot walk out of the namespace directory'
            );

            static::__assert_false(
                str_contains(basename($escaped), '/'),
                'a variant is a NAME, never a path'
            );
        } finally {
            self::__end();
        }
    }

    // =====================================================================
    // Roundtrip
    // =====================================================================

    /**
     * put/get/forget: what was stored comes back, and what was forgotten does not.
     */
    public static function test_get_put_forget_roundtrip()
    {
        self::__begin();

        try {
            $source = self::__source('b.js', 'const b = 2;');

            static::__assert_null(
                File_Content_Cache::get(self::$namespace, $source, '', 'js'),
                'an empty namespace holds nothing'
            );

            $path = File_Content_Cache::put(self::$namespace, $source, '', 'js', 'DERIVED-B');

            static::__assert_true(file_exists($path), 'put() returns the path it wrote');

            static::__assert_equals(
                'DERIVED-B',
                File_Content_Cache::get(self::$namespace, $source, '', 'js'),
                'a stored artifact comes back byte for byte'
            );

            File_Content_Cache::forget(self::$namespace, $source, '', 'js');

            static::__assert_null(
                File_Content_Cache::get(self::$namespace, $source, '', 'js'),
                'a forgotten entry is gone'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * The hash-addressed twins reach the same entry as the path-addressed ones.
     */
    public static function test_hash_addressed_twins()
    {
        self::__begin();

        try {
            $source = self::__source('c.js', 'const c = 3;');
            $hash = File_Content_Cache::hash_of($source);

            File_Content_Cache::put_for_hash(self::$namespace, $hash, '', 'json', '{"c":3}');

            static::__assert_equals(
                '{"c":3}',
                File_Content_Cache::get(self::$namespace, $source, '', 'json'),
                'put_for_hash() and get() address the same entry when the hash is the file\'s own'
            );

            // An EXPLICIT hash - the shape the reflection cache uses, where the key is the
            // manifest's own sha1 and there is no source file to derive it from.
            File_Content_Cache::put_for_hash(self::$namespace, 'a-manifest-sha1', '', 'json', '{"m":1}');

            static::__assert_equals(
                '{"m":1}',
                File_Content_Cache::get_for_hash(self::$namespace, 'a-manifest-sha1', '', 'json'),
                'get_for_hash() reads an entry stored under a hash the helper did not derive'
            );

            static::__assert_null(
                File_Content_Cache::get_for_hash(self::$namespace, 'never-stored', '', 'json'),
                'an unknown hash is a miss'
            );
        } finally {
            self::__end();
        }
    }

    // =====================================================================
    // Invalidation
    // =====================================================================

    /**
     * A CHANGED source misses. In development the key folds in size and mtime; in a sealed
     * build it folds in the content. Either way the derived name moves when the file does,
     * which is the whole staleness mechanism - there is no comparison to get wrong.
     */
    public static function test_changed_source_misses()
    {
        self::__begin();

        try {
            $source = self::__source('d.js', 'const d = 4;');

            File_Content_Cache::put(self::$namespace, $source, '', 'js', 'DERIVED-OLD');

            static::__assert_equals(
                'DERIVED-OLD',
                File_Content_Cache::get(self::$namespace, $source, '', 'js'),
                'the entry is a hit before the source moves'
            );

            // Change the content AND the mtime, so the row proves a miss in either mode.
            file_put_contents($source, 'const d = 4; const d2 = 44;');
            touch($source, time() + 5);
            clearstatcache(true, $source);

            static::__assert_null(
                File_Content_Cache::get(self::$namespace, $source, '', 'js'),
                'a changed source misses - the derived name moved with it'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * The optional source-mtime guard rejects an entry older than its source, which is the
     * extra test two of the migrated caches applied on top of their hash hit.
     */
    public static function test_source_mtime_guard()
    {
        self::__begin();

        try {
            $source = self::__source('e.js', 'const e = 5;');

            $path = File_Content_Cache::put(self::$namespace, $source, '', 'js', 'DERIVED-E');

            // Age the cache entry behind the source without moving the source's own key.
            touch($path, filemtime($source) - 60);
            clearstatcache(true, $path);

            static::__assert_equals(
                'DERIVED-E',
                File_Content_Cache::get(self::$namespace, $source, '', 'js'),
                'without the guard an existing entry is a hit'
            );

            static::__assert_null(
                File_Content_Cache::get(self::$namespace, $source, '', 'js', true),
                'with the guard an entry older than its source is refused'
            );
        } finally {
            self::__end();
        }
    }

    /**
     * Two variants of the same source are two entries. The variant is where a compile
     * target, a toolchain fingerprint or a parser version lives, and mixing them up is
     * exactly the failure that served a wrong parser's output for months.
     */
    public static function test_variant_separation()
    {
        self::__begin();

        try {
            $source = self::__source('f.js', 'const f = 6;');

            File_Content_Cache::put(self::$namespace, $source, '_modern_v1', 'js', 'MODERN-V1');
            File_Content_Cache::put(self::$namespace, $source, '_es5_v1', 'js', 'ES5-V1');

            static::__assert_equals(
                'MODERN-V1',
                File_Content_Cache::get(self::$namespace, $source, '_modern_v1', 'js'),
                'each variant reads back its own artifact'
            );

            static::__assert_equals(
                'ES5-V1',
                File_Content_Cache::get(self::$namespace, $source, '_es5_v1', 'js'),
                'a second variant of the same source is a separate entry'
            );

            static::__assert_null(
                File_Content_Cache::get(self::$namespace, $source, '_modern_v2', 'js'),
                'a moved fingerprint is a MISS, not a stale hit'
            );
        } finally {
            self::__end();
        }
    }

    // =====================================================================
    // Sweeping
    // =====================================================================

    /**
     * sweep() removes entries whose hash is not live, keeps the ones that are (every
     * variant of a live hash included), and treats an EMPTY live set as "I do not know",
     * never as "nothing is live".
     */
    public static function test_sweep_removes_only_dead_hashes()
    {
        self::__begin();

        try {
            $live_source = self::__source('live.js', 'const live = 1;');
            $dead_source = self::__source('dead.js', 'const dead = 1;');

            $live_hash = File_Content_Cache::hash_of($live_source);

            File_Content_Cache::put(self::$namespace, $live_source, '_a', 'js', 'LIVE-A');
            File_Content_Cache::put(self::$namespace, $live_source, '_b', 'js', 'LIVE-B');
            File_Content_Cache::put(self::$namespace, $dead_source, '', 'js', 'DEAD');

            static::__assert_equals(
                0,
                File_Content_Cache::sweep(self::$namespace, []),
                'an empty live set removes nothing - "unknown" is not "nothing is live"'
            );

            $removed = File_Content_Cache::sweep(self::$namespace, [$live_hash]);

            static::__assert_equals(1, $removed, 'exactly the dead entry is removed');

            static::__assert_equals(
                'LIVE-A',
                File_Content_Cache::get(self::$namespace, $live_source, '_a', 'js'),
                'a live hash keeps its entries'
            );

            static::__assert_equals(
                'LIVE-B',
                File_Content_Cache::get(self::$namespace, $live_source, '_b', 'js'),
                'EVERY variant of a live hash survives the sweep'
            );

            static::__assert_null(
                File_Content_Cache::get(self::$namespace, $dead_source, '', 'js'),
                'an entry whose source the manifest no longer knows is gone'
            );
        } finally {
            self::__end();
        }
    }

    // =====================================================================
    // Atomicity
    // =====================================================================

    /**
     * A partial write is never visible: the entry is staged and renamed, so the path either
     * does not exist or holds the COMPLETE artifact. A build killed mid-write therefore
     * leaves no torn file for the next build to serve as a cache hit.
     */
    public static function test_write_is_atomic()
    {
        self::__begin();

        try {
            $source = self::__source('g.js', 'const g = 7;');

            $first = str_repeat('A', 200000);
            $second = str_repeat('B', 200000);

            $path = File_Content_Cache::put(self::$namespace, $source, '', 'js', $first);

            $inode_before = fileinode($path);
            clearstatcache(true, $path);

            File_Content_Cache::put(self::$namespace, $source, '', 'js', $second);
            clearstatcache(true, $path);

            static::__assert_not_equals(
                $inode_before,
                fileinode($path),
                'the entry is REPLACED by rename, not truncated and rewritten in place - '
                . 'a concurrent reader holding the old file still reads a complete artifact'
            );

            static::__assert_equals(
                $second,
                File_Content_Cache::get(self::$namespace, $source, '', 'js'),
                'the replacement is the complete new artifact'
            );

            static::__assert_equals(
                strlen($second),
                strlen(File_Content_Cache::get(self::$namespace, $source, '', 'js')),
                'no truncated tail survives the replacement'
            );
        } finally {
            self::__end();
        }
    }
}
