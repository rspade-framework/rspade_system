<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Revisions\Php;

use RuntimeException;
use App\RSpade\Core\Revisions\Revision_Codec;
use App\RSpade\Core\Revisions\Revision_Dictionary;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Pins the STORAGE FORMAT of a revision document: the two-byte prefix, the size rule
 * that chooses a codec, the raw escape, and the two refusals on read.
 *
 * The format is the durable part of this subsystem - a row written today is decoded by
 * whatever build reads it in five years - so every branch is driven directly rather than
 * inferred from an end-to-end write. `_encode_with()` is the seam that makes each codec
 * reachable without constructing an input that happens to select it, and
 * `_set_zstd_available_for_tests()` is the seam that makes the deflate branch reachable
 * without unloading an extension.
 */
class Revision_Codec_Test extends Rsx_Test_Abstract
{
    /** A document in the common shape: a couple of fields, each a [before, after] pair. */
    private const SAMPLE = '{"status_id":[1,3],"name":["Acme Industries","Acme Industries LLC"],"updated_at":["2026-01-01 00:00:00","2026-02-02 12:00:00"]}';

    public static function setup()
    {
        // The dictionary rows live in the test database; a cached view built from the
        // developer database would decode against the wrong bytes.
        Revision_Dictionary::_reset_cache();
    }

    public static function teardown()
    {
        Revision_Codec::_set_zstd_available_for_tests(null);
        Revision_Dictionary::_reset_cache();
    }

    /**
     * Every codec is a byte-exact round trip, and every one records itself in byte 0.
     */
    public static function test_round_trip_per_codec()
    {
        $codecs = [
            Revision_Codec::CODEC_RAW,
            Revision_Codec::CODEC_DEFLATE,
            Revision_Codec::CODEC_DEFLATE_DICT,
            Revision_Codec::CODEC_ZSTD,
            Revision_Codec::CODEC_ZSTD_DICT,
        ];

        foreach ($codecs as $codec) {
            $encoded = Revision_Codec::_encode_with($codec, self::SAMPLE);

            static::__assert_equals($codec, Revision_Codec::codec_of($encoded), 'codec byte for codec ' . $codec);
            static::__assert_equals(self::SAMPLE, Revision_Codec::decode($encoded), 'round trip for codec ' . $codec);
        }
    }

    /**
     * A dictionary codec records WHICH dictionary in byte 1; a dictionary-free one
     * records 0.
     */
    public static function test_prefix_carries_the_dictionary_id()
    {
        $current = Revision_Dictionary::current();
        static::__assert_not_null($current, 'the test baseline must carry a revision dictionary');

        $with_dictionary = Revision_Codec::_encode_with(Revision_Codec::CODEC_DEFLATE_DICT, self::SAMPLE);
        static::__assert_equals($current['id'], Revision_Codec::dictionary_id_of($with_dictionary));

        $without = Revision_Codec::_encode_with(Revision_Codec::CODEC_DEFLATE, self::SAMPLE);
        static::__assert_equals(0, Revision_Codec::dictionary_id_of($without));
    }

    /**
     * Under SMALL_MAX the encoder uses deflate and nothing else - the measured winner at
     * 138 bytes (deflate+dict 45 B against zstd+dict 60 B).
     */
    public static function test_small_documents_use_deflate()
    {
        static::__assert_true(strlen(self::SAMPLE) < Revision_Codec::SMALL_MAX, 'the sample must sit in the small band');

        $encoded = Revision_Codec::encode(self::SAMPLE);

        static::__assert_equals(Revision_Codec::CODEC_DEFLATE_DICT, Revision_Codec::codec_of($encoded));
        static::__assert_equals(self::SAMPLE, Revision_Codec::decode($encoded));
        static::__assert_true(strlen($encoded) < strlen(self::SAMPLE), 'the dictionary must actually shrink a small document');
    }

    /**
     * Above BOTH_MAX the encoder uses zstd - the measured winner at 35 KB (884 B against
     * deflate's 1177 B).
     */
    public static function test_large_documents_use_zstd()
    {
        $large = '{"notes":["' . str_repeat('lorem ipsum dolor sit amet ', 1500) . '","' . str_repeat('lorem ipsum dolor sit amet consectetur ', 1500) . '"]}';
        static::__assert_greater_than(Revision_Codec::BOTH_MAX, strlen($large));

        $encoded = Revision_Codec::encode($large);

        static::__assert_equals(Revision_Codec::CODEC_ZSTD_DICT, Revision_Codec::codec_of($encoded));
        static::__assert_equals($large, Revision_Codec::decode($encoded));
    }

    /**
     * In the 1-3 KB band both codecs run and the SMALLER result is what gets stored - so
     * the stored payload is never larger than either candidate on its own.
     */
    public static function test_both_band_keeps_the_smaller_candidate()
    {
        $document = '{"summary":["' . str_repeat('the quick brown fox jumps over the lazy dog ', 20) . '","' . str_repeat('pack my box with five dozen liquor jugs ', 20) . '"]}';

        static::__assert_greater_than(Revision_Codec::SMALL_MAX, strlen($document));
        static::__assert_true(strlen($document) <= Revision_Codec::BOTH_MAX, 'the document must sit in the both band');

        $encoded = Revision_Codec::encode($document);

        $deflate = Revision_Codec::_encode_with(Revision_Codec::CODEC_DEFLATE_DICT, $document);
        $zstd = Revision_Codec::_encode_with(Revision_Codec::CODEC_ZSTD_DICT, $document);

        static::__assert_equals(min(strlen($deflate), strlen($zstd)), strlen($encoded), 'the band must store the smaller of the two candidates');
        static::__assert_equals($document, Revision_Codec::decode($encoded));
    }

    /**
     * Incompressible bytes are stored verbatim under CODEC_RAW rather than as a
     * "compressed" payload that is larger than its input.
     */
    public static function test_incompressible_input_escapes_to_raw()
    {
        $noise = random_bytes(512);

        $encoded = Revision_Codec::encode($noise);

        static::__assert_equals(Revision_Codec::CODEC_RAW, Revision_Codec::codec_of($encoded));
        static::__assert_equals(strlen($noise) + 2, strlen($encoded), 'raw is the input plus exactly the two prefix bytes');
        static::__assert_equals($noise, Revision_Codec::decode($encoded));
    }

    /**
     * The codec reads bytes, not text. Invalid UTF-8 and embedded NUL survive intact.
     */
    public static function test_binary_input_is_byte_exact()
    {
        $binary = "\x00\xff\xfe{\"a\":[\"\xc3\x28\",\"\x00\x00\"]}" . str_repeat("\x00\x01\x02", 40);

        $encoded = Revision_Codec::encode($binary);

        static::__assert_equals($binary, Revision_Codec::decode($encoded), 'byte-exact round trip on binary input');
    }

    /**
     * With zstd unavailable the encoder produces deflate everywhere, including in the
     * bands that would otherwise choose zstd - and the result still round trips.
     */
    public static function test_deflate_is_used_when_zstd_is_absent()
    {
        Revision_Codec::_set_zstd_available_for_tests(false);

        try {
            $large = '{"notes":["' . str_repeat('lorem ipsum dolor sit amet ', 1500) . '"]}';

            $encoded = Revision_Codec::encode($large);

            static::__assert_equals(Revision_Codec::CODEC_DEFLATE_DICT, Revision_Codec::codec_of($encoded));
            static::__assert_equals($large, Revision_Codec::decode($encoded));
        } finally {
            Revision_Codec::_set_zstd_available_for_tests(null);
        }
    }

    /**
     * A prefix this build cannot honour is an error, never a guess.
     */
    public static function test_unknown_codec_byte_throws()
    {
        $forged = chr(97) . chr(0) . 'whatever';

        static::__assert_throws(RuntimeException::class, function () use ($forged) {
            Revision_Codec::decode($forged);
        }, 'unknown codec byte');
    }

    /**
     * A dictionary id with no row behind it is an error too: the payload genuinely
     * cannot be decoded, and saying so is the only honest answer.
     */
    public static function test_unknown_dictionary_id_throws()
    {
        $encoded = Revision_Codec::_encode_with(Revision_Codec::CODEC_DEFLATE_DICT, self::SAMPLE);
        $forged = $encoded[0] . chr(Revision_Dictionary::MAX_DICTIONARY_ID) . substr($encoded, 2);

        static::__assert_throws(RuntimeException::class, function () use ($forged) {
            Revision_Codec::decode($forged);
        }, 'no _revision_dictionaries row with id');
    }

    /**
     * A payload too short to carry its own prefix is refused rather than read past.
     */
    public static function test_truncated_payload_throws()
    {
        static::__assert_throws(RuntimeException::class, function () {
            Revision_Codec::decode('x');
        }, 'shorter than its two-byte prefix');
    }
}
