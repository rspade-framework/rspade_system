<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Revisions;

use RuntimeException;
use App\RSpade\Core\Revisions\Revision_Dictionary;

/**
 * Revision_Codec - the storage format of the `changes` blob on a `_revisions` row.
 *
 * A revision document is a small JSON string, `{"field":[before,after], ...}`. There are
 * a great many of them and they are read rarely, so the format optimises for stored size
 * first and write cost second. Every payload is SELF-DESCRIBING: two leading bytes say
 * how the rest was produced, so a row written years ago still decodes without consulting
 * anything but its own prefix.
 *
 *   byte 0  the codec (CODEC_RAW / CODEC_DEFLATE / CODEC_DEFLATE_DICT / CODEC_ZSTD /
 *           CODEC_ZSTD_DICT)
 *   byte 1  the _revision_dictionaries.id the payload was compressed against, or 0 for
 *           none. This is why a dictionary id may never exceed 255 - Revision_Dictionary
 *           refuses to mint one that would not fit here.
 *
 * WHY TWO COMPRESSORS AND A SIZE RULE. Measured on real revision documents against a
 * schema-derived dictionary (docs.dev/revisions/compression_survey_results.tsv and
 * bench_zstd.php):
 *
 *   - At 138 bytes - the common case, a one-field edit - deflate WITH the dictionary
 *     produces 45 bytes where zstd with the same dictionary produces 60. Deflate's
 *     window is seeded directly with the dictionary bytes and it has almost no frame
 *     overhead, and at this size the frame IS the payload. Small documents are deflate.
 *   - At 35 KB - a bulk write, a wide record, a long text field - zstd produces 884
 *     bytes where deflate level 6 produces 1177. Once there is enough input for a real
 *     entropy stage, zstd wins outright. Large documents are zstd.
 *   - Between the two the winner depends on the document, and compressing twice on a
 *     few-KB input costs tens of microseconds. In that band we simply do both and keep
 *     whichever is smaller.
 *
 * NEVER A HIGH COMPRESSION LEVEL ON THE WRITE PATH. zstd level 19 measured 10.8 ms per
 * document against 0.02-0.2 ms for level 3, for a few percent of size. A revision is
 * written inside the user's request; 10 ms per record write is not a trade this
 * subsystem is allowed to make. Level 3 (zstd) and level 6 (deflate) are the settled
 * write-path levels.
 *
 * RAW IS AN ESCAPE, NOT A MODE. If no codec makes the document smaller - it is tiny, or
 * it is already incompressible - the bytes are stored verbatim under CODEC_RAW. Storing
 * a "compressed" form that is bigger than its input is the one outcome a compressor must
 * never be allowed to commit to disk.
 *
 * encode() and decode() are byte-exact inverses for ANY input string, including binary
 * and invalid UTF-8. Nothing here inspects the payload as text.
 */
class Revision_Codec
{
    /** Stored verbatim: nothing shrank it. */
    public const CODEC_RAW = 0;

    /** Raw deflate, level 6, no dictionary. */
    public const CODEC_DEFLATE = 1;

    /** Raw deflate, level 6, seeded with a _revision_dictionaries row. */
    public const CODEC_DEFLATE_DICT = 2;

    /** zstd level 3, no dictionary. */
    public const CODEC_ZSTD = 3;

    /** zstd level 3, seeded with a _revision_dictionaries row. */
    public const CODEC_ZSTD_DICT = 4;

    /**
     * Documents shorter than this are compressed with deflate ONLY.
     *
     * Measured: 138-byte document -> deflate+dict 45 B, zstd+dict 60 B. Below ~1 KB the
     * zstd frame header is a fixed cost that the entropy stage has no room to earn back,
     * and deflate's dictionary-seeded window has no header at all.
     */
    public const SMALL_MAX = 1024;

    /**
     * Documents up to this length are compressed with BOTH and the smaller kept.
     *
     * Measured: neither codec wins the 1-3 KB band across the sample - at 2386 bytes
     * deflate+dict produced 655 B while zstd's frame advantage has not yet opened up.
     * Compressing twice here costs tens of microseconds, which is cheaper than storing
     * the wrong one forever.
     */
    public const BOTH_MAX = 3072;

    /** Write-path deflate level. Level 9 bought 6% of size for 30% more time; not taken. */
    public const DEFLATE_LEVEL = 6;

    /** Write-path zstd level. Level 19 measured 10.8 ms per document - never on a write. */
    public const ZSTD_LEVEL = 3;

    /**
     * Is the zstd extension usable? Null means "ask the runtime".
     *
     * THIS IS THE ONE extension_loaded() IN THE SUBSYSTEM, and it is a seam rather than a
     * capability probe: the deflate-only branch below has to be reachable from a test, and
     * a test cannot unload an extension. Production always answers true - zstd is a
     * declared member of Rsx_Php_Requirements::REQUIRED_EXTENSIONS and the boot check
     * refuses a box without it - so this is never a degraded second code path in service.
     * Only _set_zstd_available_for_tests() ever assigns it.
     */
    private static ?bool $_zstd_available = null;

    /**
     * Compress a revision document into its stored form.
     *
     * @param string $json The revision document (any bytes; nothing here reads it as text)
     * @return string Two prefix bytes followed by the payload
     */
    public static function encode(string $json): string
    {
        $dictionary = Revision_Dictionary::current();
        $length = strlen($json);

        $candidates = [];

        if ($length < self::SMALL_MAX) {
            $candidates[] = self::_deflate_codec($dictionary);
        } elseif ($length <= self::BOTH_MAX) {
            $candidates[] = self::_deflate_codec($dictionary);
            $candidates[] = self::_zstd_codec($dictionary);
        } else {
            $candidates[] = self::_zstd_codec($dictionary);
        }

        $best = null;

        foreach ($candidates as $codec) {
            $encoded = self::_encode_with($codec, $json);

            if ($best === null || strlen($encoded) < strlen($best)) {
                $best = $encoded;
            }
        }

        // The raw escape. $best carries the same two prefix bytes the raw form carries, so
        // the comparison is payload against payload.
        if ($best === null || strlen($best) >= $length + 2) {
            return self::_encode_with(self::CODEC_RAW, $json);
        }

        return $best;
    }

    /**
     * Restore a revision document from its stored form.
     *
     * Throws on a prefix this build cannot honour - an unknown codec byte, or a
     * dictionary id with no row behind it. A revision that cannot be decoded is a fact
     * about the data, and saying so is the only correct answer; there is nothing to
     * degrade to.
     */
    public static function decode(string $stored): string
    {
        if (strlen($stored) < 2) {
            throw new RuntimeException('Revision_Codec::decode(): stored payload is shorter than its two-byte prefix (' . strlen($stored) . ' bytes).');
        }

        $codec = ord($stored[0]);
        $dictionary_id = ord($stored[1]);
        $payload = substr($stored, 2);

        switch ($codec) {
            case self::CODEC_RAW:
                return $payload;

            case self::CODEC_DEFLATE:
                return gzinflate($payload);

            case self::CODEC_DEFLATE_DICT:
                $handle = inflate_init(ZLIB_ENCODING_RAW, ['dictionary' => Revision_Dictionary::bytes_for($dictionary_id)]);

                return inflate_add($handle, $payload, ZLIB_FINISH);

            case self::CODEC_ZSTD:
                return zstd_uncompress($payload);

            case self::CODEC_ZSTD_DICT:
                return zstd_uncompress_dict($payload, Revision_Dictionary::bytes_for($dictionary_id));
        }

        throw new RuntimeException('Revision_Codec::decode(): unknown codec byte ' . $codec . ' - this payload was written by a build that knows a format this one does not.');
    }

    /**
     * Which codec byte does a payload carry? The read side of the format, for tests and
     * for anything reporting on stored sizes.
     */
    public static function codec_of(string $stored): int
    {
        if (strlen($stored) < 2) {
            throw new RuntimeException('Revision_Codec::codec_of(): stored payload is shorter than its two-byte prefix.');
        }

        return ord($stored[0]);
    }

    /**
     * Which dictionary id does a payload carry? 0 means none.
     */
    public static function dictionary_id_of(string $stored): int
    {
        if (strlen($stored) < 2) {
            throw new RuntimeException('Revision_Codec::dictionary_id_of(): stored payload is shorter than its two-byte prefix.');
        }

        return ord($stored[1]);
    }

    /**
     * Encode with one NAMED codec, prefix included - the seam encode() picks through and
     * the seam a test drives directly to prove a branch it would otherwise have to
     * construct an input to reach.
     */
    public static function _encode_with(int $codec, string $json): string
    {
        switch ($codec) {
            case self::CODEC_RAW:
                return chr(self::CODEC_RAW) . chr(0) . $json;

            case self::CODEC_DEFLATE:
                return chr(self::CODEC_DEFLATE) . chr(0) . gzdeflate($json, self::DEFLATE_LEVEL);

            case self::CODEC_ZSTD:
                return chr(self::CODEC_ZSTD) . chr(0) . zstd_compress($json, self::ZSTD_LEVEL);

            case self::CODEC_DEFLATE_DICT:
                $dictionary = self::_require_dictionary('CODEC_DEFLATE_DICT');
                $handle = deflate_init(ZLIB_ENCODING_RAW, [
                    'level' => self::DEFLATE_LEVEL,
                    'dictionary' => $dictionary['bytes'],
                ]);

                return chr(self::CODEC_DEFLATE_DICT) . chr($dictionary['id']) . deflate_add($handle, $json, ZLIB_FINISH);

            case self::CODEC_ZSTD_DICT:
                $dictionary = self::_require_dictionary('CODEC_ZSTD_DICT');

                return chr(self::CODEC_ZSTD_DICT) . chr($dictionary['id'])
                    . zstd_compress_dict($json, $dictionary['bytes'], self::ZSTD_LEVEL);
        }

        throw new RuntimeException('Revision_Codec::_encode_with(): unknown codec ' . $codec . '.');
    }

    /**
     * Force the zstd branch on or off for the duration of a test; null restores the
     * runtime answer. Production never calls this.
     */
    public static function _set_zstd_available_for_tests(?bool $available): void
    {
        self::$_zstd_available = $available;
    }

    /**
     * The deflate variant to use: dictionary-seeded when a current dictionary exists.
     */
    private static function _deflate_codec(?array $dictionary): int
    {
        return $dictionary === null ? self::CODEC_DEFLATE : self::CODEC_DEFLATE_DICT;
    }

    /**
     * The zstd variant to use - or the deflate one when zstd is unavailable, which off a
     * test seam it never is (see $_zstd_available).
     */
    private static function _zstd_codec(?array $dictionary): int
    {
        if (!self::_zstd_available()) {
            return self::_deflate_codec($dictionary);
        }

        return $dictionary === null ? self::CODEC_ZSTD : self::CODEC_ZSTD_DICT;
    }

    private static function _zstd_available(): bool
    {
        if (self::$_zstd_available !== null) {
            return self::$_zstd_available;
        }

        return extension_loaded('zstd');
    }

    /**
     * @return array{id: int, bytes: string}
     */
    private static function _require_dictionary(string $codec_name): array
    {
        $dictionary = Revision_Dictionary::current();

        if ($dictionary === null) {
            throw new RuntimeException('Revision_Codec: ' . $codec_name . ' was requested but there is no current revision dictionary. The post-migrate step in Maint_Migrate builds one.');
        }

        return $dictionary;
    }
}
