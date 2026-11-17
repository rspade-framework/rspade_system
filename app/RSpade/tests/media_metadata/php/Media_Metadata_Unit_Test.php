<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\MediaMetadata\Php;

use App\RSpade\Core\Files\Media_Metadata;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The displayed-size rules and the ffprobe verdicts, as pure functions: no file, no database.
 */
class Media_Metadata_Unit_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // MEDIA-01
    public static function test_an_image_turned_a_quarter_turn_swaps_its_size()
    {
        foreach ([0, 1, 2, 3, 4] as $orientation) {
            static::__assert_equals([60, 40], Media_Metadata::upright_size(60, 40, $orientation), "orientation {$orientation} keeps the stored size");
        }
        foreach ([5, 6, 7, 8] as $orientation) {
            static::__assert_equals([40, 60], Media_Metadata::upright_size(60, 40, $orientation), "orientation {$orientation} is a quarter turn: width and height trade places");
        }
    }

    // MEDIA-02
    public static function test_a_rotated_video_swaps_its_size()
    {
        static::__assert_equals([64, 32], Media_Metadata::displayed_video_size(64, 32, null, null), 'no rotation');
        static::__assert_equals([64, 32], Media_Metadata::displayed_video_size(64, 32, 0, '1:1'), 'zero degrees, square pixels');
        static::__assert_equals([64, 32], Media_Metadata::displayed_video_size(64, 32, 180, null), 'a half turn keeps the size');
        static::__assert_equals([32, 64], Media_Metadata::displayed_video_size(64, 32, 90, null), '90 swaps');
        static::__assert_equals([32, 64], Media_Metadata::displayed_video_size(64, 32, -90, null), '-90 (a phone held the other way) swaps');
        static::__assert_equals([32, 64], Media_Metadata::displayed_video_size(64, 32, 270, null), '270 swaps');
        static::__assert_equals([32, 64], Media_Metadata::displayed_video_size(64, 32, 450, null), 'a full turn more is the same quarter turn');
        static::__assert_equals([96, 32], Media_Metadata::displayed_video_size(64, 32, null, '3:2'), 'a non-square sample aspect ratio widens the frame');
        static::__assert_equals([32, 96], Media_Metadata::displayed_video_size(64, 32, 90, '3:2'), 'scaled first, then turned');
        static::__assert_equals([64, 32], Media_Metadata::displayed_video_size(64, 32, null, '0:1'), '0:1 means unknown: square');
    }

    // MEDIA-03
    public static function test_ffprobe_answers_are_judged()
    {
        $stream = ['codec_type' => 'video', 'width' => 64, 'height' => 32, 'duration' => '1.400000'];

        static::__assert_equals(
            ['status' => 'ok', 'width' => 32, 'height' => 64, 'duration' => 2],
            Media_Metadata::_judge_probe(0, json_encode([
                'streams' => [$stream + ['side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => -90]]]],
                'format' => ['duration' => '1.500000'],
            ]), ''),
            'the display matrix rotation swaps; the container duration wins and is rounded'
        );

        static::__assert_equals(
            ['status' => 'ok', 'width' => 32, 'height' => 64, 'duration' => 1],
            Media_Metadata::_judge_probe(0, json_encode([
                'streams' => [$stream + ['tags' => ['rotate' => '270']]],
                'format' => [],
            ]), ''),
            'the older rotate tag swaps; the stream duration stands in for a missing container one'
        );

        static::__assert_equals(
            ['status' => 'ok', 'width' => null, 'height' => null, 'duration' => 3],
            Media_Metadata::_judge_probe(0, json_encode(['streams' => [], 'format' => ['duration' => '3.2']]), ''),
            'no video stream (audio only): no size, the duration still counts'
        );

        $refused = Media_Metadata::_judge_probe(1, '', 'moov atom not found');
        static::__assert_equals('unreadable', $refused['status'], 'a failed run is unreadable');
        static::__assert_contains('moov atom not found', $refused['reason'], 'and quotes stderr');

        static::__assert_equals('unreadable', Media_Metadata::_judge_probe(0, 'not json', '')['status'], 'garbage is unreadable');
        static::__assert_equals('unreadable', Media_Metadata::_judge_probe(0, json_encode(['streams' => [], 'format' => []]), '')['status'], 'nothing to read is unreadable');
        static::__assert_equals(
            'unreadable',
            Media_Metadata::_judge_probe(0, json_encode(['streams' => [['codec_type' => 'video', 'width' => 0, 'height' => 0]], 'format' => ['duration' => '1']]), '')['status'],
            'a video stream with no frame size is unreadable'
        );
    }

    // MEDIA-04
    public static function test_the_ffprobe_health_row()
    {
        $missing = Media_Metadata::_availability_row(null, null);
        static::__assert_equals('FAIL', $missing['status'], 'missing is a FAIL, as soffice is');
        static::__assert_contains('ffmpeg', $missing['remediation'], 'the remedy names the package');

        static::__assert_equals('FAIL', Media_Metadata::_availability_row('/usr/bin/ffprobe', '')['status'], 'present but not running is a FAIL');

        $ok = Media_Metadata::_availability_row('/usr/bin/ffprobe', 'ffprobe version 6.1.1');
        static::__assert_equals('OK', $ok['status']);
        static::__assert_contains('/usr/bin/ffprobe', $ok['detail'], 'names the binary');
    }

    // MEDIA-05
    public static function test_ffprobe_and_heic_are_present_and_healthy_here()
    {
        $row = Media_Metadata::ffprobe_available();
        static::__assert_equals('OK', $row['status'], 'ffprobe runs in the RSpade image: ' . $row['detail']);

        $row = Media_Metadata::heic_decoding();
        static::__assert_equals('OK', $row['status'], 'ImageMagick decodes HEIC in the RSpade image: ' . $row['detail']);
    }
}
