<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Revision_Dictionary - the compression dictionary every revision document is written
 * against.
 *
 * A revision document is a few hundred bytes of JSON whose bytes are almost entirely
 * PREDICTABLE FROM THE SCHEMA: the punctuation of a JSON object, the column names of
 * this database, the labels of this application's enums. A general-purpose compressor
 * cannot exploit that, because it has never seen any of it before this 138-byte input
 * arrived. A dictionary hands it the whole vocabulary up front, and the measured effect
 * is a 138-byte document going to 45 bytes instead of 94.
 *
 * The dictionary is DERIVED, never authored: build_tokens() reads the live schema and
 * the manifest, so it is automatically correct for whatever application this framework
 * is running. It is rebuilt on a cadence (config rsx.revisions.dictionary_max_age_days)
 * by the post-migrate step, because a schema that has gained tables has gained
 * vocabulary.
 *
 * A REBUILD NEVER INVALIDATES ANYTHING. Every stored payload names the dictionary it was
 * written with in its second prefix byte, so old revisions keep decoding against the old
 * row forever. That is also why a dictionary row is never updated or deleted: it is
 * append-only, and the newest row is simply the one new writes use.
 *
 * THE 255 CEILING. That prefix byte is one byte. A dictionary id above 255 could not be
 * recorded, so regenerate_if_stale() refuses to mint one rather than writing revisions
 * that cannot be read back. At a 30-day cadence the ceiling is roughly twenty years.
 */
class Revision_Dictionary
{
    /** The largest id that fits in the codec's one-byte dictionary field. */
    public const MAX_DICTIONARY_ID = 255;

    /**
     * The dictionary is truncated to its TAIL at this size.
     *
     * zlib's window is 32 KB and it is seeded from the END of the dictionary buffer, so
     * bytes beyond that are not merely wasted, they are unreachable. Truncating from the
     * front is therefore the same decision as ordering tokens hottest-last (see
     * build_tokens()): what survives is what matches most often.
     */
    public const MAX_DICTIONARY_BYTES = 32768;

    private const CACHE_KEY_CURRENT = 'revisions.dictionary.current';

    private const CACHE_KEY_PREFIX = 'revisions.dictionary.';

    /**
     * The current dictionary, or null when none has ever been built.
     *
     * @return array{id: int, bytes: string}|null
     */
    public static function current(): ?array
    {
        $cached = RsxCache::remember(self::CACHE_KEY_CURRENT, function () {
            $row = DB::table('_revision_dictionaries')->orderBy('id', 'desc')->first();

            if ($row === null) {
                // RsxCache::remember() treats null as a miss, so "there is no dictionary"
                // is cached as an explicit marker rather than as nothing.
                return ['id' => 0, 'bytes' => ''];
            }

            return ['id' => (int) $row->id, 'bytes' => (string) $row->bytes];
        });

        if ($cached['id'] === 0) {
            return null;
        }

        return $cached;
    }

    /**
     * The bytes of ONE dictionary by id - the read path of every stored payload's prefix.
     *
     * Throws when the id has no row. A revision naming a dictionary this database does
     * not have is unreadable, and reporting that is the only honest answer.
     */
    public static function bytes_for(int $id): string
    {
        $bytes = RsxCache::remember(self::CACHE_KEY_PREFIX . $id, function () use ($id) {
            $row = DB::table('_revision_dictionaries')->where('id', $id)->first();

            return $row === null ? '' : (string) $row->bytes;
        });

        if ($bytes === '') {
            throw new RuntimeException('Revision_Dictionary::bytes_for(): no _revision_dictionaries row with id ' . $id . '. A revision written against it cannot be decoded.');
        }

        return $bytes;
    }

    /**
     * Build a new dictionary when the current one is missing or past its age, otherwise
     * do nothing.
     *
     * Called by the post-migrate step in Maint_Migrate::execute_migrations(), which is
     * the only moment the schema is guaranteed to be at its tip.
     *
     * @param int|null $max_age_days Overrides config('rsx.revisions.dictionary_max_age_days').
     *                               The seam a test uses to force either decision.
     * @return int|null The new dictionary id, or null when the current one is still fresh
     */
    public static function regenerate_if_stale(?int $max_age_days = null): ?int
    {
        $max_age_days = $max_age_days ?? (int) config('rsx.revisions.dictionary_max_age_days');

        $row = DB::table('_revision_dictionaries')->orderBy('id', 'desc')->first();

        if ($row !== null) {
            $age_seconds = time() - strtotime((string) $row->created_at);

            if ($age_seconds < $max_age_days * 86400) {
                return null;
            }
        }

        $max_id = (int) DB::table('_revision_dictionaries')->max('id');

        if ($max_id >= self::MAX_DICTIONARY_ID) {
            throw new RuntimeException('Revision_Dictionary: the next dictionary id would exceed ' . self::MAX_DICTIONARY_ID . ', which is the largest value the codec can record in its one-byte dictionary prefix. No further dictionary can be minted without changing the storage format.');
        }

        $tokens = static::build_tokens();
        $bytes = static::build_bytes($tokens);

        $id = (int) DB::table('_revision_dictionaries')->insertGetId([
            'bytes' => $bytes,
            'token_hash' => sha1(implode("\n", $tokens)),
            'token_count' => count($tokens),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        static::_reset_cache();

        return $id;
    }

    /**
     * The dictionary's vocabulary, ordered COLDEST FIRST and HOTTEST LAST.
     *
     * THE ORDER IS THE POINT. zlib emits a match as a (distance, length) pair and spends
     * fewer bits the SHORTER the distance, and the dictionary is loaded so that its last
     * byte is nearest the data. So a token's position in this list is its price: the
     * tokens at the end - the JSON punctuation that appears in every document, `id`,
     * `site_id`, `created_at` - are the ones that must be cheapest, and the ones at the
     * start - an enum label that appears in a handful of documents a year - are the ones
     * that can afford to be expensive. Reversing this list would not break anything; it
     * would just compress worse, silently, which is why it is written down here.
     *
     * The three sources, coldest to hottest:
     *
     *   1. Every enum label declared by any model's $enums. Read from the manifest by
     *      reflection - static properties only, nothing is instantiated.
     *   2. Every column name in this database, from information_schema, in ONE query.
     *      Emitted in the shape a revision document actually contains, `"name":[`.
     *   3. The JSON structure itself, plus the handful of column names that appear in
     *      nearly every record: id, the *_id foreign keys, the timestamps, site_id,
     *      status_id.
     *
     * @return array<int, string>
     */
    public static function build_tokens(): array
    {
        $enum_labels = static::_collect_enum_labels();
        $columns = static::_collect_column_names();

        // The always-present columns, hottest last within their own group.
        $hot_columns = ['status_id', 'site_id', 'updated_at', 'created_at', 'id'];

        $foreign_keys = [];
        $ordinary = [];

        foreach ($columns as $column) {
            if (in_array($column, $hot_columns, true)) {
                continue;
            }

            if (str_ends_with($column, '_id')) {
                $foreign_keys[] = $column;

                continue;
            }

            $ordinary[] = $column;
        }

        $tokens = [];

        foreach ($enum_labels as $label) {
            $tokens[] = '"' . $label . '"';
        }

        foreach ($ordinary as $column) {
            $tokens[] = '"' . $column . '":[';
        }

        foreach ($foreign_keys as $column) {
            $tokens[] = '"' . $column . '":[';
        }

        foreach ($hot_columns as $column) {
            $tokens[] = '"' . $column . '":[';
        }

        // The structural vocabulary, last because every document contains all of it.
        // The two datetime skeletons are shapes, not dates: what matches is the
        // punctuation and the leading digits, which is most of a timestamp's bytes.
        foreach ([
            '"2026-01-01 00:00:00"',
            '"2026-01-01T00:00:00Z"',
            '[null,',
            'false',
            'true',
            'null',
            '",',
            '],"',
            '":[',
            '{"',
        ] as $structural) {
            $tokens[] = $structural;
        }

        return $tokens;
    }

    /**
     * Flatten the token list into the raw byte buffer both compressors are seeded with,
     * keeping the TAIL when it is longer than the usable window.
     */
    public static function build_bytes(array $tokens): string
    {
        $bytes = implode('', $tokens);

        if (strlen($bytes) > self::MAX_DICTIONARY_BYTES) {
            $bytes = substr($bytes, -self::MAX_DICTIONARY_BYTES);
        }

        return $bytes;
    }

    /**
     * Drop every cached view of the dictionaries - the current pointer and every by-id
     * entry. The next read goes to the table.
     *
     * The seam for tests (whose inserted rows are rolled back and must not survive in
     * Redis) and for the post-migrate step, which has just changed which row is current.
     */
    public static function _reset_cache(): void
    {
        RsxCache::delete(self::CACHE_KEY_CURRENT);

        // Every id the format can express, not merely the ids present: a bytes_for()
        // miss is cached too, and a reset that left one behind would keep a row that
        // now exists invisible.
        for ($id = 0; $id <= self::MAX_DICTIONARY_ID; $id++) {
            RsxCache::delete(self::CACHE_KEY_PREFIX . $id);
        }
    }

    /**
     * Every column name in the current database, in ONE information_schema query.
     *
     * @return array<int, string>
     */
    private static function _collect_column_names(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY COLUMN_NAME',
            [DB::connection()->getDatabaseName()]
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[] = (string) $row->column_name;
        }

        return $columns;
    }

    /**
     * Every distinct enum label declared by any model, read from the manifest's record of
     * which classes declare a static $enums and then by reflection on the class itself.
     * Nothing is instantiated: a model constructor touches the database.
     *
     * @return array<int, string>
     */
    private static function _collect_enum_labels(): array
    {
        $labels = [];

        foreach (Manifest::get_all() as $entry) {
            if (($entry['extension'] ?? null) !== 'php') {
                continue;
            }

            if (!isset($entry['static_properties']['enums'])) {
                continue;
            }

            // Visibility comes from the manifest rather than from a reflection probe:
            // getStaticPropertyValue() throws on a non-public property, and a model that
            // hid its $enums is not declaring a vocabulary for anybody.
            if (($entry['static_properties']['enums']['visibility'] ?? null) !== 'public') {
                continue;
            }

            $enums = (new ReflectionClass($entry['fqcn']))->getStaticPropertyValue('enums');

            // The abstract model bases declare the property with no map in it.
            if (!is_array($enums)) {
                continue;
            }

            foreach ($enums as $definitions) {
                if (!is_array($definitions)) {
                    continue;
                }

                foreach ($definitions as $definition) {
                    if (is_array($definition) && isset($definition['label']) && is_string($definition['label'])) {
                        $labels[$definition['label']] = true;
                    }
                }
            }
        }

        $labels = array_keys($labels);
        sort($labels);

        return $labels;
    }
}
