<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\MediaMetadata\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\Face_Detection;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A HEIC photo end to end: classified as an image, measured upright, thumbnailed upright,
 * previewed as a WebP drawn from it (no browser but Safari shows HEIC), and handed to the face
 * detector as an upright JPEG copy (OpenCV cannot read HEIC).
 *
 * THE FIXTURE (resource/sideways_with_gps.heic): a 60x40 picture, left half red and right half
 * blue, stored with a quarter-turn transform and an Exif item carrying a GPS field. Seen upright
 * it is 40x60, red above blue.
 */
class Heic_Support_Test extends Rsx_Test_Abstract
{
    /** @var array<int> */
    private static $created_attachment_ids = [];

    /** @var array<string> */
    private static $created_files = [];

    private static $saved_detector = null;

    public static function setup()
    {
        static::$saved_detector = config('rsx.thumbnails.face_detection');
        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn ($data) => true]);
    }

    public static function teardown()
    {
        config(['rsx.thumbnails.face_detection' => static::$saved_detector]);

        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        foreach (static::$created_files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        static::$created_files = [];

        Event_Registry::_clear_test_handlers();
        Rsx_Request_Channel::reset();
    }

    private static function __heic(): File_Attachment_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $attachment = File_Attachment_Model::create_from_disk(
            dirname(__DIR__) . '/resource/sideways_with_gps.heic',
            ['site_id' => $site_id, 'filename' => 'IMG_0001.HEIC']
        );
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    /** The dominant channel of one pixel: 'red', 'green' or 'blue'. */
    private static function __colour(\Imagick $image, int $x, int $y): string
    {
        $pixel = $image->getImagePixelColor($x, $y)->getColor();
        $channels = ['red' => $pixel['r'], 'green' => $pixel['g'], 'blue' => $pixel['b']];
        arsort($channels);

        return (string) array_key_first($channels);
    }

    private static function __assert_upright(\Imagick $image, string $label): void
    {
        static::__assert_equals([40, 60], [$image->getImageWidth(), $image->getImageHeight()], "{$label}: a 40x60 portrait");
        static::__assert_equals('red', static::__colour($image, 20, 9), "{$label}: red on top");
        static::__assert_equals('blue', static::__colour($image, 20, 51), "{$label}: blue below");
    }

    // HEIC-01
    public static function test_a_heic_is_an_image_measured_upright()
    {
        $attachment = static::__heic();

        static::__assert_equals('image/heic', $attachment->mime_type, 'libmagic sniffs it as HEIC');
        static::__assert_equals(File_Attachment_Model::FILE_TYPE_IMAGE, (int) $attachment->file_type_id, 'classified as an image');
        static::__assert_true($attachment->is_image(), 'is_image()');
        static::__assert_false((bool) $attachment->preview_unavailable, 'readable, not degraded');
        static::__assert_equals('heic', $attachment->file_extension);
        static::__assert_equals([40, 60], [(int) $attachment->width, (int) $attachment->height], 'stored at its upright size');
        static::__assert_false((bool) $attachment->is_animated, 'a still photo');
    }

    // HEIC-02
    public static function test_a_heic_thumbnail_is_drawn_upright()
    {
        $attachment = static::__heic();
        $hash = $attachment->resolve_storage()->hash;
        static::$created_files[] = File_Attachment_Controller::_get_cache_path(
            'dynamic',
            File_Attachment_Controller::_get_cache_filename_dynamic('fit', 40, 40, $hash, $attachment->file_extension)
        );

        Rsx_Request_Channel::reset();
        $response = File_Attachment_Controller::thumbnail(Request::create('/x', 'GET'), [
            'key' => $attachment->key,
            'type' => 'fit',
            'width' => 40,
            'height' => 40,
            'v' => $attachment->content_version(),
        ]);
        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_equals('image/webp', $response->headers->get('Content-Type'), 'a raster thumbnail, not the extension icon');

        $thumbnail = new \Imagick();
        $thumbnail->readImageBlob((string) $response->getContent());
        static::__assert_upright($thumbnail, 'the thumbnail');
    }

    // HEIC-03
    public static function test_a_heic_previews_as_a_drawn_webp_and_downloads_as_itself()
    {
        $attachment = static::__heic();

        static::__assert_false($attachment->can_open_inline(), 'a browser tab cannot show HEIC');
        $display = $attachment->get_display_url();
        static::__assert_contains("/_thumbnail/dynamic/{$attachment->key}/fit/", $display, 'the preview shows a WebP the thumbnail pipeline draws');
        static::__assert_contains('?v=' . $attachment->content_version(), $display, 'carrying the content version');

        $info = File_Preview_Controller::get_preview_info(Request::create('/x', 'POST'), ['attachment_id' => $attachment->id]);
        static::__assert_equals('Image_Viewer', $info['viewer']);
        static::__assert_equals($display, $info['urls']['display'], 'Image_Viewer is handed the drawn WebP');
        static::__assert_equals($attachment->get_url(), $info['urls']['inline'], 'the inline URL is still the original');

        $png = new \Imagick();
        $png->newImage(10, 10, new \ImagickPixel('#123456'));
        $png->setImageFormat('png');
        $png_attachment = File_Attachment_Model::create_from_string($png->getImageBlob() . '', 'x' . random_hash() . '.png', ['site_id' => $attachment->site_id]);
        static::$created_attachment_ids[] = $png_attachment->id;
        static::__assert_equals($png_attachment->get_url(), $png_attachment->get_display_url(), 'a PNG is shown as itself');

        $text = File_Attachment_Model::create_from_string('text ' . random_hash(), 'notes.txt', ['site_id' => $attachment->site_id]);
        static::$created_attachment_ids[] = $text->id;
        static::__assert_null($text->get_display_url(), 'a non-image has no display URL');
    }

    // HEIC-04
    public static function test_the_detector_copy_of_a_heic_is_an_upright_jpeg()
    {
        $attachment = static::__heic();
        $copy = Face_Detection::_upright_jpeg_copy($attachment->resolve_storage()->get_full_path());
        static::$created_files[] = $copy;

        $image = new \Imagick($copy);
        static::__assert_equals('JPEG', $image->getImageFormat(), 'a JPEG, which OpenCV reads');
        static::__assert_false(
            in_array($image->getImageOrientation(), [5, 6, 7, 8], true),
            'no orientation tag that OpenCV would apply a second time'
        );
        static::__assert_upright($image, 'the detector copy');
    }

    // HEIC-05
    public static function test_the_detector_is_handed_a_converted_copy_and_it_is_cleaned_up()
    {
        $python = '/usr/bin/python3';
        if (!is_executable($python)) {
            static::__skip('the stub detector needs /usr/bin/python3, which every RSpade image carries');
        }

        $spec_path = Rsx_Project_Paths::scratch_file('face_stub_spec', 'json');
        $log_path = Rsx_Project_Paths::scratch_file('face_stub_calls', 'log');
        static::$created_files[] = $spec_path;
        static::$created_files[] = $log_path;
        file_put_contents($spec_path, json_encode(['exit' => 0, 'stdout' => '{"status":"none"}', 'log' => $log_path]));
        // setup() / teardown() run once per class: the real detector config is restored below.
        config(['rsx.thumbnails.face_detection' => [
            'python' => $python,
            'script' => dirname(__DIR__, 2) . '/face_thumbnails/resource/stub_detector.py',
            'model' => $spec_path,
        ]]);
        try {
            static::__assert_converted_and_direct($log_path);
        } finally {
            config(['rsx.thumbnails.face_detection' => static::$saved_detector]);
        }
    }

    /** HEIC-05's body, run with the stub detector configured. */
    private static function __assert_converted_and_direct(string $log_path): void
    {
        $heic_path = static::__heic()->resolve_storage()->get_full_path();
        static::__assert_equals(['status' => 'none'], Face_Detection::detect($heic_path), 'the run is answered');

        $seen = trim((string) file_get_contents($log_path));
        static::__assert_true(str_ends_with($seen, '.jpg'), "the detector was handed a JPEG copy, not the HEIC ({$seen})");
        static::__assert_false($seen === $heic_path, 'never the stored HEIC itself');
        static::__assert_false(is_file($seen), 'and the copy is deleted after the run');

        // A JPEG is read by OpenCV itself: no copy.
        $jpeg_path = Rsx_Project_Paths::scratch_file('face_direct', 'jpg');
        static::$created_files[] = $jpeg_path;
        $image = new \Imagick();
        $image->newImage(20, 20, new \ImagickPixel('#808080'));
        $image->setImageFormat('jpeg');
        $image->writeImage($jpeg_path);
        unlink($log_path);
        Face_Detection::detect($jpeg_path);
        static::__assert_equals($jpeg_path, trim((string) file_get_contents($log_path)), 'a JPEG is handed over as it is');
    }

    // HEIC-06
    public static function test_the_real_detector_reads_a_heic_through_the_copy()
    {
        $python = (string) config('rsx.thumbnails.face_detection.python');
        $model = (string) config('rsx.thumbnails.face_detection.model');
        if (!is_executable($python) || !is_file($model)) {
            static::__skip('the face detector is not installed in this container (' . $python . '); it is part of the RSpade image');
        }

        $heic_path = static::__heic()->resolve_storage()->get_full_path();
        static::__assert_equals(['status' => 'none'], Face_Detection::detect($heic_path), 'a face-free HEIC is a clean none - read, not "failed"');
    }
}
