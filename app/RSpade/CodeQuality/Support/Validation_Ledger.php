<?php

namespace App\RSpade\CodeQuality\Support;

use App\RSpade\Core\Manifest\Manifest;

/**
 * The ONE store of "this file already passed this check".
 *
 * A code-quality rule that is expensive to run - an AST parse, a whole-tree index, a
 * subprocess lint - wants to remember that it has already judged a file and found it
 * clean. Every such rule used to invent its own memory, and the framework ended up with
 * one flag file per source file per rule: 7885 transient files after a build, 1254 of
 * them zero-byte `.lintpass` markers whose entire content was their own existence. That
 * is a lot of inodes to say a boolean.
 *
 * This class is that memory, once, for every rule: ONE var_export'd PHP array at
 *
 *     storage/rsx-tmp/persistent/validation_ledger.php
 *
 * shaped
 *
 *     ['version' => 1, 'rules' => [ <rule id> => [ <file hash> => 1 ] ]]
 *
 * It is deliberately the same shape and the same mechanics as manifest_data.php: a
 * var_export'd array included once per process (so OPcache serves it where OPcache is
 * on), written atomically through a temp file plus rename.
 *
 * THE KEY IS THE MANIFEST FILE HASH, NOT THE PATH. A caller passes
 * `$metadata['hash']` - the value the manifest already computed for that file (in
 * development md5 of path+size+mtime, in production a content hash; the ledger does not
 * care which, only that it changes when the file does). Keying on the hash rather than
 * on the path plus an mtime comparison is what lets a verdict SURVIVE a manifest clear:
 * the manifest can be rebuilt from scratch and every unchanged file still carries the
 * hash that vouches for it.
 *
 * WHEN A VERDICT MUST NOT SURVIVE. A rule whose answer depends on something other than
 * the file's own bytes - a whole-tree index, a config value - must fold that something
 * into its rule id, so that a change to it retires the old verdicts instead of letting
 * them vouch for a stale premise. NAME-RESERVED-02 does exactly this: its rule id is
 * `NAME-RESERVED-02@<hash of the reserved-name index>`.
 *
 * CONCURRENCY: last writer wins, and nothing is lost but a few re-checks. Two processes
 * that flush at the same moment each hold a FULL array (loaded at start, added to since),
 * so the loser's own passes are the only entries that can be dropped, and a dropped entry
 * costs exactly one re-run of a check that will pass again. There is no lock, because a
 * lock around a memo is more expensive than the thing it protects.
 *
 * See: rsx:man code_quality, rsx:man storage_directories.
 */
class Validation_Ledger
{
    /** The on-disk shape's version. A mismatch discards the file rather than migrating it. */
    private const VERSION = 1;

    /** Loaded ledger: ['version' => int, 'rules' => [rule_id => [hash => 1]]]. */
    private static ?array $store = null;

    /** True when a pass has been recorded that is not on disk yet. */
    private static bool $dirty = false;

    /**
     * Passes recorded by THIS process, as [rule_id][hash]. The prune keeps these whatever
     * the manifest says: a caller that just judged a file is better evidence that the file
     * matters than the manifest is (the syntax-lint stages, for one, reach a handful of
     * files the manifest does not index).
     */
    private static array $recorded_here = [];

    /** True once the shutdown flush has been registered (once per process). */
    private static bool $shutdown_registered = false;

    /**
     * Test seam: an alternate ledger file, so a test never writes over the developer's own
     * memo. Null is the real path. Setting it discards whatever is loaded in memory.
     */
    private static ?string $path_override = null;

    /**
     * Has $file_hash already passed $rule_id?
     */
    public static function has_passed(string $rule_id, string $file_hash): bool
    {
        if ($file_hash === '') {
            return false;
        }

        static::__load();

        return isset(static::$store['rules'][$rule_id][$file_hash]);
    }

    /**
     * Record that $file_hash passed $rule_id. In memory only - flush() writes.
     */
    public static function record_pass(string $rule_id, string $file_hash): void
    {
        if ($file_hash === '') {
            return;
        }

        static::__load();

        if (isset(static::$store['rules'][$rule_id][$file_hash])) {
            return;
        }

        static::__retire_superseded_generations($rule_id);

        static::$store['rules'][$rule_id][$file_hash] = 1;
        static::$recorded_here[$rule_id][$file_hash] = 1;
        static::$dirty = true;

        if (!static::$shutdown_registered) {
            static::$shutdown_registered = true;
            register_shutdown_function([static::class, 'flush']);
        }
    }

    /**
     * A GENERATIONAL rule id is `<rule id>@<fingerprint>` - the shape a rule uses when its
     * answer depends on something beyond the file's own bytes (NAME-RESERVED-02 folds in the
     * hash of the framework's reserved-name index). Recording under one generation retires
     * every older one, so the ledger holds ONE generation per rule rather than accumulating a
     * full set of dead verdicts every time the framework's names move.
     */
    private static function __retire_superseded_generations(string $rule_id): void
    {
        $at = strpos($rule_id, '@');

        if ($at === false) {
            return;
        }

        $prefix = substr($rule_id, 0, $at + 1);

        foreach (array_keys(static::$store['rules']) as $existing) {
            if ($existing !== $rule_id && str_starts_with($existing, $prefix)) {
                unset(static::$store['rules'][$existing]);
                unset(static::$recorded_here[$existing]);
                static::$dirty = true;
            }
        }
    }

    /**
     * Drop every verdict recorded under $rule_id.
     *
     * The retirement path for a rule whose premise changed in a way its id does not
     * capture, and the way a test starts from a known state.
     */
    public static function forget_rule(string $rule_id): void
    {
        static::__load();

        if (!isset(static::$store['rules'][$rule_id])) {
            return;
        }

        unset(static::$store['rules'][$rule_id]);
        static::$dirty = true;
    }

    /**
     * Write the ledger if anything changed.
     *
     * Registered as a shutdown function on the first record_pass(), AND called explicitly
     * at the end of the quality pass - a fatal later in the same process must not cost the
     * work already done.
     */
    public static function flush(): void
    {
        if (!static::$dirty || static::$store === null) {
            return;
        }

        static::__prune_against_manifest();

        $path = static::path();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $body = "<?php\n\nreturn " . var_export(static::$store, true) . ";\n";

        $temp = $path . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temp, $body) === false) {
            return;
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);

            return;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        static::$dirty = false;
    }

    /**
     * Drop every hash the manifest no longer knows, EXCEPT the ones this process just
     * recorded.
     *
     * Called by flush(). Deliberately simple: a file hash whose file has changed leaves a
     * dead entry behind, and without this the ledger grows forever. It only runs when the
     * manifest is ALREADY loaded in this process - building it just to prune would be a far
     * larger cost than the bytes it reclaims.
     */
    public static function prune(array $live_hashes): void
    {
        static::__load();

        $live = array_flip($live_hashes);

        foreach (static::$store['rules'] as $rule_id => $hashes) {
            $kept = array_intersect_key($hashes, $live) + (static::$recorded_here[$rule_id] ?? []);

            if (count($kept) === count($hashes)) {
                continue;
            }

            static::$store['rules'][$rule_id] = $kept;
            static::$dirty = true;
        }
    }

    /**
     * Forget everything, on disk and in memory. rsx:clean's entry point.
     */
    public static function clear(): void
    {
        static::$store = ['version' => self::VERSION, 'rules' => []];
        static::$recorded_here = [];
        static::$dirty = false;

        $path = static::path();

        if (file_exists($path)) {
            @unlink($path);
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * Point the ledger at another file (null restores the real one) and forget everything
     * loaded. The PHP-test seam, and the ONLY caller that may move the path.
     */
    public static function _use_path_for_tests(?string $path): void
    {
        static::$path_override = $path;
        static::$store = null;
        static::$recorded_here = [];
        static::$dirty = false;
    }

    /**
     * Where the ledger lives.
     */
    public static function path(): string
    {
        if (static::$path_override !== null) {
            return static::$path_override;
        }

        if (function_exists('storage_path')) {
            return storage_path('rsx-tmp/persistent/validation_ledger.php');
        }

        return '/var/www/html/storage/rsx-tmp/persistent/validation_ledger.php';
    }

    /**
     * Load the ledger once per process.
     */
    private static function __load(): void
    {
        if (static::$store !== null) {
            return;
        }

        static::$store = ['version' => self::VERSION, 'rules' => []];

        $path = static::path();

        if (!file_exists($path)) {
            return;
        }

        $loaded = @include $path;

        if (!is_array($loaded) || ($loaded['version'] ?? null) !== self::VERSION || !isset($loaded['rules'])) {
            // A shape we do not recognize is discarded, not migrated: the entire content is
            // a memo, and re-earning it costs one slow pass.
            return;
        }

        static::$store = ['version' => self::VERSION, 'rules' => (array) $loaded['rules']];
    }

    /**
     * Prune against the manifest, but only when this process already has it.
     */
    private static function __prune_against_manifest(): void
    {
        if (!class_exists(Manifest::class) || !Manifest::$_has_init) {
            return;
        }

        $live = [];

        foreach (Manifest::$data['data']['files'] ?? [] as $metadata) {
            if (isset($metadata['hash'])) {
                $live[] = $metadata['hash'];
            }
        }

        if (empty($live)) {
            return;
        }

        static::prune($live);
    }
}
