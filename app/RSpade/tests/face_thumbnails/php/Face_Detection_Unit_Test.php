<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FaceThumbnails\Php;

use App\RSpade\Core\Files\Face_Detection;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The pure parts of face-centred thumbnails: the crop arithmetic, how one detector run is
 * judged from its exit code and stdout, which query values ask for a face, and the health row
 * built from a probe verdict. Nothing here runs a process or touches the database.
 */
class Face_Detection_Unit_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // FACE-01
    public static function test_the_crop_puts_the_face_forty_percent_down_and_clamps_into_the_image()
    {
        // A 100x100 image, a 100x50 box: [face y, expected first row].
        foreach ([[0.10, 0], [0.60, 40], [0.90, 50]] as [$face_y, $top]) {
            [$x, $y] = Face_Detection::crop_offset(100, 100, 100, 50, 0.5, $face_y);
            static::__assert_equals(0, $x, "face y {$face_y}: a box as wide as the image starts at column 0");
            static::__assert_equals($top, $y, "face y {$face_y}: the box covers rows {$top}-" . ($top + 50));
        }
    }

    // FACE-02
    public static function test_the_crop_centres_the_face_horizontally_and_clamps_at_both_edges()
    {
        // A 100x100 image, a 50x100 box: [face x, expected first column].
        foreach ([[0.50, 25], [0.10, 0], [0.90, 50], [0.40, 15]] as [$face_x, $left]) {
            [$x, $y] = Face_Detection::crop_offset(100, 100, 50, 100, $face_x, 0.5);
            static::__assert_equals($left, $x, "face x {$face_x}: the box covers columns {$left}-" . ($left + 50));
            static::__assert_equals(0, $y, "face x {$face_x}: a box as tall as the image starts at row 0");
        }
    }

    // FACE-03
    public static function test_a_run_is_an_answer_only_with_exit_zero_and_a_well_formed_line()
    {
        static::__assert_equals(
            ['status' => 'found', 'x' => 0.25, 'y' => 0.75],
            Face_Detection::_judge_run(0, "{\"status\":\"found\",\"x\":0.25,\"y\":0.75,\"score\":0.93}\n", ''),
            'exit 0 + found is a face centre'
        );
        static::__assert_equals(['status' => 'none'], Face_Detection::_judge_run(0, '{"status":"none"}', ''), 'exit 0 + none');
        static::__assert_equals(
            ['status' => 'failed', 'error' => 'unreadable image'],
            Face_Detection::_judge_run(0, '{"status":"failed","error":"unreadable image"}', ''),
            'exit 0 + failed'
        );
        static::__assert_equals(
            ['status' => 'none'],
            Face_Detection::_judge_run(0, '{"status":"none"}', "Corrupt JPEG data: 12 extraneous bytes\n"),
            'stderr is never read for the verdict: a decoder warning beside a good answer changes nothing'
        );
        static::__assert_equals(
            ['status' => 'found', 'x' => 1.0, 'y' => 0.0],
            Face_Detection::_judge_run(0, '{"status":"found","x":1,"y":0}', ''),
            'integer edges are fractions too'
        );
    }

    // FACE-04
    public static function test_every_other_run_is_unavailable_and_carries_its_evidence()
    {
        $cases = [
            'exit 3 (the script cannot run)' => [3, '{"status":"unavailable","error":"cannot import cv2"}', '', 'cannot import cv2'],
            'exit 1 with a traceback' => [1, '', "Traceback (most recent call last):\n  boom", 'Traceback'],
            'exit 0 with garbage' => [0, 'Segmentation fault', '', 'malfunctioned'],
            'exit 0 with nothing' => [0, '', '', 'malfunctioned'],
            'exit 0 with two lines' => [0, "{\"status\":\"none\"}\n{\"status\":\"none\"}", '', 'malfunctioned'],
            'exit 0 with an unknown status' => [0, '{"status":"maybe"}', '', 'unknown status'],
            'exit 0 with a centre outside the image' => [0, '{"status":"found","x":1.5,"y":0.5}', '', 'outside the image'],
            'exit 0 with a centre that is not a number' => [0, '{"status":"found","x":"0.5","y":0.5}', '', 'outside the image'],
            'no exit code (signalled)' => [null, '', '', 'exit none'],
        ];

        foreach ($cases as $label => [$exit, $stdout, $stderr, $needle]) {
            $verdict = Face_Detection::_judge_run($exit, $stdout, $stderr);
            static::__assert_equals('unavailable', $verdict['status'], "{$label}: unavailable, never an answer");
            static::__assert_contains($needle, $verdict['reason'], "{$label}: the reason carries the evidence");
        }

        $verdict = Face_Detection::_judge_run(1, '', 'libpng warning: iCCP');
        static::__assert_contains('stderr: libpng warning: iCCP', $verdict['reason'], 'an unavailable run quotes its stderr');
    }

    // FACE-05
    public static function test_only_one_and_true_ask_for_a_face()
    {
        foreach (['1', 'true', 'TRUE', 'True'] as $value) {
            static::__assert_true(File_Attachment_Controller::_face_requested(['face' => $value]), "face={$value} asks for a face");
        }
        foreach (['0', '', 'false', 'yes', 'on'] as $value) {
            static::__assert_false(File_Attachment_Controller::_face_requested(['face' => $value]), "face={$value} does not");
        }
        static::__assert_false(File_Attachment_Controller::_face_requested([]), 'no face parameter does not');
        static::__assert_false(File_Attachment_Controller::_face_requested(['face' => ['1']]), 'an array face parameter does not');
    }

    // FACE-06
    public static function test_the_health_row_is_ok_only_for_a_clean_none()
    {
        $row = Face_Detection::_health_row(['status' => 'none'], '4.10.0');
        static::__assert_equals('OK', $row['status']);
        static::__assert_contains('OpenCV 4.10.0', $row['detail'], 'the OK row names the OpenCV version');

        $row = Face_Detection::_health_row(['status' => 'unavailable', 'reason' => 'the detector reported it cannot run: model not found'], null);
        static::__assert_equals('WARN', $row['status'], 'a broken detector degrades thumbnails, so WARN, not FAIL');
        static::__assert_contains('model not found', $row['detail'], 'the WARN row says why');
        static::__assert_contains('Rebuild the RSpade image', (string) $row['remediation'], 'the WARN row says how to fix it');

        $row = Face_Detection::_health_row(['status' => 'found', 'x' => 0.5, 'y' => 0.5], null);
        static::__assert_equals('WARN', $row['status'], 'a face found in a face-free probe is a broken detector');
    }
}
