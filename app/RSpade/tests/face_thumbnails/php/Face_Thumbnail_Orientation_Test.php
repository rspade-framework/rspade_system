<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FaceThumbnails\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\Face_Detection;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Every thumbnail is drawn UPRIGHT, the image the face detector reads too - and the real
 * detector, where the RSpade image installed it, runs.
 */
class Face_Thumbnail_Orientation_Test extends Rsx_Test_Abstract
{
    /** @var array<int> */
    private static $created_attachment_ids = [];

    /** @var array<string> */
    private static $created_files = [];

    public static function setup()
    {
        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => true]);
    }

    public static function teardown()
    {
        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        foreach (static::$created_files as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        static::$created_files = [];

        Event_Registry::_clear_test_handlers();
        Rsx_Request_Channel::reset();
    }

    /**
     * A 60x40 JPEG, left half red and right half blue, tagged EXIF orientation 6 ("rotate 90
     * clockwise to view") and carrying a GPS block: seen upright it is 40x60 with red on top
     * and blue below.
     *
     * ImageMagick writes no orientation into a JPEG without an EXIF profile, so the tag goes in
     * as a minimal APP1 segment: a big-endian TIFF header and one IFD entry, 0x0112 = 6.
     */
    private static function __orientation_6_jpeg(): string
    {
        $image = new \Imagick();
        $image->newImage(60, 40, new \ImagickPixel('#0000ff'));
        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel('#ff0000'));
        $draw->rectangle(0, 0, 29, 39);
        $image->drawImage($draw);
        // One random pixel in a corner, so each call is a blob of its own.
        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel(sprintf('#%02x%02x%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255))));
        $draw->point(59, 39);
        $image->drawImage($draw);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(95);
        $jpeg = $image->getImageBlob();

        // IFD0 holds the orientation and a pointer to a GPS IFD (latitude reference "N"), so
        // the file also carries the kind of location data a thumbnail must never repeat.
        $tiff = "MM\x00\x2a\x00\x00\x00\x08"                            // big-endian TIFF header, IFD0 at offset 8
            . "\x00\x02"                                               // IFD0: two entries
            . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"           // Orientation, SHORT, 1, value 6
            . "\x88\x25\x00\x04\x00\x00\x00\x01\x00\x00\x00\x26"           // GPSInfo, LONG, 1, the GPS IFD at offset 38
            . "\x00\x00\x00\x00"                                       // no next IFD
            . "\x00\x01"                                               // GPS IFD: one entry
            . "\x00\x01\x00\x02\x00\x00\x00\x02N\x00\x00\x00"              // GPSLatitudeRef, ASCII, 2, "N"
            . "\x00\x00\x00\x00";                                      // no next IFD
        $payload = "Exif\x00\x00" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    /** The dominant channel of one pixel: 'red', 'green' or 'blue'. */
    private static function __colour(\Imagick $image, int $x, int $y): string
    {
        $pixel = $image->getImagePixelColor($x, $y)->getColor();
        $channels = ['red' => $pixel['r'], 'green' => $pixel['g'], 'blue' => $pixel['b']];
        arsort($channels);

        return (string) array_key_first($channels);
    }

    // FACE-20
    public static function test_an_exif_rotated_jpeg_is_thumbnailed_upright()
    {
        $bytes = static::__orientation_6_jpeg();
        $probe = new \Imagick();
        $probe->readImageBlob($bytes);
        static::__assert_equals(\Imagick::ORIENTATION_RIGHTTOP, $probe->getImageOrientation(), 'the fixture carries orientation 6');
        static::__assert_equals('N', $probe->getImageProperty('exif:GPSLatitudeRef'), 'the fixture carries a GPS block');

        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);
        $attachment = File_Attachment_Model::create_from_string($bytes, 'sideways.jpg', ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;
        $hash = $attachment->resolve_storage()->hash;
        static::$created_files[] = $attachment->resolve_storage()->get_full_path();
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

        $thumbnail = new \Imagick();
        $thumbnail->readImageBlob((string) $response->getContent());
        $width = $thumbnail->getImageWidth();
        $height = $thumbnail->getImageHeight();

        // A source this small is drawn at its own size (the never-upscale rule), so the
        // thumbnail IS the upright picture: 40 wide, 60 tall. Drawn sideways it would be 60x40.
        static::__assert_equals([40, 60], [$width, $height], 'upright: the thumbnail is a 40x60 portrait');

        $middle = intdiv($width, 2);
        static::__assert_equals('red', static::__colour($thumbnail, $middle, (int) round($height * 0.15)), 'upright: the left half of the sensor image is now the top');
        static::__assert_equals('blue', static::__colour($thumbnail, $middle, (int) round($height * 0.85)), 'upright: the right half is now the bottom');
    }

    // FACE-22
    public static function test_a_thumbnail_carries_no_orientation_tag_and_no_gps()
    {
        $bytes = static::__orientation_6_jpeg();

        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);
        $attachment = File_Attachment_Model::create_from_string($bytes, 'located.jpg', ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;
        $hash = $attachment->resolve_storage()->hash;
        static::$created_files[] = $attachment->resolve_storage()->get_full_path();
        static::$created_files[] = File_Attachment_Controller::_get_cache_path(
            'dynamic',
            File_Attachment_Controller::_get_cache_filename_dynamic('cover', 30, 30, $hash, $attachment->file_extension)
        );

        Rsx_Request_Channel::reset();
        $response = File_Attachment_Controller::thumbnail(Request::create('/x', 'GET'), [
            'key' => $attachment->key,
            'type' => 'cover',
            'width' => 30,
            'height' => 30,
            'v' => $attachment->content_version(),
        ]);
        static::__assert_equals(200, $response->getStatusCode());

        $webp = (string) $response->getContent();
        static::__assert_false(str_contains($webp, 'EXIF'), 'the WebP has no EXIF chunk');
        static::__assert_false(str_contains($webp, 'XMP '), 'the WebP has no XMP chunk');

        $thumbnail = new \Imagick();
        $thumbnail->readImageBlob($webp);
        static::__assert_equals([], $thumbnail->getImageProperties('exif:*'), 'no EXIF property survives: no orientation tag, no GPS');
        static::__assert_false(
            in_array($thumbnail->getImageOrientation(), [\Imagick::ORIENTATION_RIGHTTOP, \Imagick::ORIENTATION_LEFTBOTTOM], true),
            'no orientation tag that a browser would apply a second time'
        );
    }

    // FACE-21
    public static function test_the_real_detector_answers_none_on_a_face_free_image()
    {
        $python = (string) config('rsx.thumbnails.face_detection.python');
        $model = (string) config('rsx.thumbnails.face_detection.model');
        if (!is_executable($python) || !is_file($model)) {
            static::__skip(
                'the face detector is not installed in this container (' . $python . '); it is part of the RSpade image, '
                . 'which the rsx:test sibling containers are built from - this container predates it'
            );
        }

        $probe = Rsx_Project_Paths::scratch_file('face_detection_real_probe', 'jpg');
        static::$created_files[] = $probe;
        $image = new \Imagick();
        $image->newImage(320, 240, new \ImagickPixel('#6f8faf'));
        $image->setImageFormat('jpeg');
        $image->writeImage($probe);

        static::__assert_equals(['status' => 'none'], Face_Detection::detect($probe), 'a face-free image is a clean none');

        $row = Face_Detection::health();
        static::__assert_equals('OK', $row['status'], 'the health row is OK where the detector runs: ' . $row['detail']);
        static::__assert_contains('OpenCV', $row['detail'], 'and names the OpenCV version');
    }
}
