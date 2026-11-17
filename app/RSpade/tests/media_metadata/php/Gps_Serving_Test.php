<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\MediaMetadata\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\Exif_Gps;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Zip_Stream;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A photo's GPS position is withheld from every client handed its ORIGINAL bytes, and kept in
 * the stored file: the blob, its hash and deduplication stay as uploaded, and server code reads
 * the position with get_gps_position(). Only an image that carries GPS is altered, losslessly
 * and in place (same length, the orientation tag and other EXIF kept); every other file is
 * served byte for byte.
 */
class Gps_Serving_Test extends Rsx_Test_Abstract
{
    /** @var array<int> */
    private static $created_attachment_ids = [];

    private static $saved_switch = null;

    /** The latitude 37/1, 46/1, 2970/100 as big-endian rationals - what a client must not get. */
    private const LATITUDE_MM = "\x00\x00\x00\x25\x00\x00\x00\x01\x00\x00\x00\x2e\x00\x00\x00\x01\x00\x00\x0b\x9a\x00\x00\x00\x64";

    public static function setup()
    {
        static::$saved_switch = config('rsx.files.strip_gps_when_served');
        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn ($data) => true]);
        Event_Registry::_set_test_handlers('file.download.authorize', [fn ($data) => true]);
    }

    public static function teardown()
    {
        config(['rsx.files.strip_gps_when_served' => static::$saved_switch]);

        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->force_destroy();
            }
        }
        static::$created_attachment_ids = [];

        Event_Registry::_clear_test_handlers();
        Rsx_Request_Channel::reset();
    }

    // ============================================================================================
    // FIXTURES
    // ============================================================================================

    /**
     * An EXIF TIFF: IFD0 = Make "Cam", Orientation 6, GPSInfo, and one tag AFTER GPSInfo (so
     * removing GPSInfo has an entry to move up); the GPS directory holds N 37 46' 29.70" and
     * W 122 25' 9.80" (rationals stored out of line).
     */
    private static function __tiff(bool $big_endian): string
    {
        $s = fn (int $v) => $big_endian ? pack('n', $v) : pack('v', $v);
        $l = fn (int $v) => $big_endian ? pack('N', $v) : pack('V', $v);
        $entry = fn (int $tag, int $type, int $count, string $value) => $s($tag) . $s($type) . $l($count) . str_pad($value, 4, "\x00");

        $tiff = ($big_endian ? 'MM' : 'II') . $s(42) . $l(8);
        // IFD0 at 8: 4 entries -> 54 bytes; the GPS directory at 62.
        $tiff .= $s(4)
            . $entry(0x010F, 2, 4, "Cam\x00")
            . $entry(0x0112, 3, 1, $s(6))
            . $entry(0x8825, 4, 1, $l(62))
            . $entry(0xC4A5, 7, 4, "PIM\x00")
            . $l(0);
        // GPS directory at 62: 4 entries -> 54 bytes; latitude at 116, longitude at 140.
        $tiff .= $s(4)
            . $entry(0x0001, 2, 2, "N\x00")
            . $entry(0x0002, 5, 3, $l(116))
            . $entry(0x0003, 2, 2, "W\x00")
            . $entry(0x0004, 5, 3, $l(140))
            . $l(0);
        foreach ([[37, 1], [46, 1], [2970, 100], [122, 1], [25, 1], [980, 100]] as [$numerator, $denominator]) {
            $tiff .= $l($numerator) . $l($denominator);
        }

        return $tiff;
    }

    /** A 60x40 JPEG carrying $tiff as its EXIF, unique bytes each call. */
    private static function __jpeg_with_exif(?string $tiff): string
    {
        $image = new \Imagick();
        $image->newImage(60, 40, new \ImagickPixel(sprintf('#%02x%02x%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255))));
        $image->setImageFormat('jpeg');
        $jpeg = $image->getImageBlob();
        if ($tiff === null) {
            return $jpeg;
        }

        $payload = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    private static function __create(string $bytes, string $name): File_Attachment_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $attachment = File_Attachment_Model::create_from_string($bytes, $name, ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    /** The bytes a response hands the client. */
    private static function __body($response): string
    {
        if ($response instanceof BinaryFileResponse) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }
        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }

    /** /_inline and /_download of an attachment, as the routes answer them. */
    private static function __served(File_Attachment_Model $attachment): array
    {
        $params = ['key' => $attachment->key, 'v' => $attachment->content_version()];
        $served = [];
        foreach (['inline', 'download_file'] as $route) {
            Rsx_Request_Channel::reset();
            $served[$route] = File_Attachment_Controller::$route(Request::create('/x', 'GET'), $params);
            static::__assert_equals(200, $served[$route]->getStatusCode(), "{$route} answers");
        }

        return $served;
    }

    // ============================================================================================
    // THE EDIT
    // ============================================================================================

    // GPS-01
    public static function test_the_gps_directory_is_removed_and_everything_else_kept()
    {
        foreach ([true, false] as $big_endian) {
            $order = $big_endian ? 'MM' : 'II';
            $tiff = static::__tiff($big_endian);
            $stripped = Exif_Gps::strip_tiff($tiff);

            static::__assert_not_null($stripped, "{$order}: a TIFF with GPS is changed");
            static::__assert_equals(strlen($tiff), strlen($stripped), "{$order}: and keeps its length, so every offset stays valid");

            $count = unpack($big_endian ? 'n' : 'v', substr($stripped, 8, 2))[1];
            static::__assert_equals(3, $count, "{$order}: IFD0 lost one entry");
            $tags = [];
            for ($i = 0; $i < $count; $i++) {
                $tags[] = unpack($big_endian ? 'n' : 'v', substr($stripped, 10 + $i * 12, 2))[1];
            }
            static::__assert_equals([0x010F, 0x0112, 0xC4A5], $tags, "{$order}: Make, Orientation and the tag after GPSInfo remain, in order");
            static::__assert_equals(substr($tiff, 10 + 3 * 12, 12), substr($stripped, 10 + 2 * 12, 12), "{$order}: the entry after GPSInfo moved up one slot intact");
            static::__assert_equals(str_repeat("\x00", 12), substr($stripped, 10 + 3 * 12, 12), "{$order}: the freed slot is zeros");
            static::__assert_equals(str_repeat("\x00", strlen($tiff) - 62), substr($stripped, 62), "{$order}: the GPS directory and its coordinates are zeros");

            static::__assert_null(Exif_Gps::strip_tiff($stripped), "{$order}: a stripped TIFF has nothing left to strip");
        }
    }

    // GPS-02
    public static function test_a_jpeg_keeps_its_orientation_and_camera_and_loses_its_position()
    {
        $jpeg = static::__jpeg_with_exif(static::__tiff(true));
        $before = new \Imagick();
        $before->readImageBlob($jpeg);
        static::__assert_equals('N', $before->getImageProperty('exif:GPSLatitudeRef'), 'the fixture carries a position');

        $stripped = Exif_Gps::strip_bytes($jpeg);
        static::__assert_not_null($stripped, 'the JPEG is changed');
        static::__assert_equals(strlen($jpeg), strlen($stripped), 'no byte added or removed');
        static::__assert_false(str_contains($stripped, self::LATITUDE_MM), 'the latitude bytes are gone');
        static::__assert_equals(substr($jpeg, -200), substr($stripped, -200), 'the image data is untouched');

        $after = new \Imagick();
        $after->readImageBlob($stripped);
        static::__assert_equals([], $after->getImageProperties('exif:GPS*'), 'ImageMagick reads no GPS field');
        static::__assert_equals(\Imagick::ORIENTATION_RIGHTTOP, $after->getImageOrientation(), 'the orientation tag stays');
        static::__assert_equals('Cam', $after->getImageProperty('exif:Make'), 'the other EXIF fields stay');
    }

    // GPS-03
    public static function test_png_and_webp_exif_chunks_are_stripped()
    {
        foreach (['png', 'webp'] as $format) {
            $image = new \Imagick();
            $image->newImage(30, 20, new \ImagickPixel('#336699'));
            $image->setImageProfile('exif', "Exif\x00\x00" . static::__tiff(true));
            $image->setImageFormat($format);
            $bytes = $image->getImageBlob();
            static::__assert_true(str_contains($bytes, self::LATITUDE_MM), "{$format}: the fixture carries the latitude");

            $stripped = Exif_Gps::strip_bytes($bytes);
            static::__assert_not_null($stripped, "{$format}: changed");
            static::__assert_equals(strlen($bytes), strlen($stripped), "{$format}: same length");
            static::__assert_false(str_contains($stripped, self::LATITUDE_MM), "{$format}: the latitude bytes are gone");
            static::__assert_null(Exif_Gps::strip_bytes($stripped), "{$format}: nothing left to strip");

            $reread = new \Imagick();
            $reread->readImageBlob($stripped);
            static::__assert_equals([30, 20], [$reread->getImageWidth(), $reread->getImageHeight()], "{$format}: still a readable image");

            if ($format === 'png') {
                $at = strpos($stripped, 'eXIf');
                $length = unpack('N', substr($stripped, $at - 4, 4))[1];
                $crc = unpack('N', substr($stripped, $at + 4 + $length, 4))[1];
                static::__assert_equals(crc32(substr($stripped, $at, 4 + $length)), $crc, 'png: the eXIf chunk CRC matches its new content');
            }
        }
    }

    // GPS-04
    public static function test_a_heic_exif_item_is_stripped_in_place()
    {
        $heic = (string) file_get_contents(dirname(__DIR__) . '/resource/sideways_with_gps.heic');
        $gps_ref = "\x00\x01\x00\x02\x00\x00\x00\x02N\x00";
        static::__assert_true(str_contains($heic, $gps_ref), 'the fixture HEIC carries GPSLatitudeRef "N" in its Exif item');

        $stripped = Exif_Gps::strip_bytes($heic);
        static::__assert_not_null($stripped, 'the HEIC is changed');
        static::__assert_equals(strlen($heic), strlen($stripped), 'same length: iloc offsets stay valid');
        static::__assert_false(str_contains($stripped, $gps_ref), 'the GPS field is gone');
        static::__assert_null(Exif_Gps::strip_bytes($stripped), 'nothing left to strip');

        $image = new \Imagick();
        $image->readImageBlob($stripped);
        static::__assert_equals([40, 60], [$image->getImageWidth(), $image->getImageHeight()], 'libheif still decodes it, upright');
    }

    // GPS-05
    public static function test_patches_applied_chunk_by_chunk_equal_the_whole_edit()
    {
        $jpeg = static::__jpeg_with_exif(static::__tiff(false));
        $patches = Exif_Gps::patches($jpeg);
        static::__assert_greater_than(0, count($patches), 'there is something to patch');

        foreach ([1, 7, 64, 4096] as $chunk_size) {
            $streamed = '';
            for ($offset = 0; $offset < strlen($jpeg); $offset += $chunk_size) {
                $streamed .= Exif_Gps::apply_patches(substr($jpeg, $offset, $chunk_size), $offset, $patches);
            }
            static::__assert_equals(Exif_Gps::strip_bytes($jpeg), $streamed, "streamed in {$chunk_size}-byte chunks, the result is the same file");
        }
    }

    // ============================================================================================
    // STORED, SERVED, READ
    // ============================================================================================

    // GPS-06
    public static function test_the_stored_original_keeps_its_position_and_the_server_can_read_it()
    {
        $jpeg = static::__jpeg_with_exif(static::__tiff(false));
        $attachment = static::__create($jpeg, 'located.jpg');
        $storage = $attachment->resolve_storage();

        static::__assert_equals(hash('sha256', $jpeg), $storage->hash, 'the blob is the uploaded bytes: same hash, same deduplication');
        static::__assert_equals($jpeg, (string) file_get_contents($storage->get_full_path()), 'byte for byte');

        $position = $attachment->get_gps_position();
        static::__assert_not_null($position, 'the position is readable server-side');
        static::__assert_equals_approx(37.774917, $position['latitude'], 0.000001, 'N 37 46\' 29.70"');
        static::__assert_equals_approx(-122.419389, $position['longitude'], 0.000001, 'W 122 25\' 9.80" is negative');

        $plain = static::__create(static::__jpeg_with_exif(null), 'plain.jpg');
        static::__assert_null($plain->get_gps_position(), 'an image with no position answers null');
        $text = static::__create('notes ' . random_hash(), 'notes.txt');
        static::__assert_null($text->get_gps_position(), 'so does a file that is not an image');
    }

    // GPS-07
    public static function test_inline_and_download_serve_the_image_without_its_position()
    {
        $jpeg = static::__jpeg_with_exif(static::__tiff(true));
        $attachment = static::__create($jpeg, 'located.jpg');
        $expected = Exif_Gps::strip_bytes($jpeg);

        foreach (static::__served($attachment) as $route => $response) {
            $body = static::__body($response);
            static::__assert_equals($expected, $body, "{$route}: the original with its GPS block removed in place");
            static::__assert_equals((string) strlen($jpeg), $response->headers->get('Content-Length'), "{$route}: the same length as stored");
            static::__assert_equals('image/jpeg', $response->headers->get('Content-Type'), "{$route}: the stored mime");
            static::__assert_contains($route === 'inline' ? 'inline' : 'attachment', (string) $response->headers->get('Content-Disposition'), "{$route}: its disposition");
            static::__assert_contains('private', (string) $response->headers->get('Cache-Control'), "{$route}: the gated cache policy still applies");
            static::__assert_equals("default-src 'none'; style-src 'unsafe-inline'; sandbox", $response->headers->get('Content-Security-Policy'), "{$route}: still hardened");

            $image = new \Imagick();
            $image->readImageBlob($body);
            static::__assert_equals(\Imagick::ORIENTATION_RIGHTTOP, $image->getImageOrientation(), "{$route}: the orientation tag is kept");
            static::__assert_equals([], $image->getImageProperties('exif:GPS*'), "{$route}: no GPS field");
        }

        static::__assert_equals($jpeg, (string) file_get_contents($attachment->resolve_storage()->get_full_path()), 'serving never touches the stored file');

        $storage = $attachment->resolve_storage();
        static::__assert_equals($expected, static::__body($storage->download_response('x.jpg', 'image/jpeg')), 'File_Storage_Model::download_response() withholds it too');
        static::__assert_equals($expected, static::__body($storage->inline_response('x.jpg', 'image/jpeg')), 'and inline_response()');
    }

    // GPS-08
    public static function test_a_heic_is_served_without_its_position()
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);
        $path = dirname(__DIR__) . '/resource/sideways_with_gps.heic';
        $attachment = File_Attachment_Model::create_from_disk($path, ['site_id' => $site_id, 'filename' => 'IMG_0002.heic']);
        static::$created_attachment_ids[] = $attachment->id;

        $expected = Exif_Gps::strip_bytes((string) file_get_contents($path));
        foreach (static::__served($attachment) as $route => $response) {
            static::__assert_equals($expected, static::__body($response), "{$route}: the HEIC without its GPS field");
        }
    }

    // GPS-09
    public static function test_files_without_gps_are_served_byte_for_byte()
    {
        $plain = static::__jpeg_with_exif(null);
        $no_gps_exif = static::__jpeg_with_exif("MM\x00\x2a\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00");
        $text = 'Not an image ' . random_hash() . "\n";

        foreach ([[$plain, 'plain.jpg'], [$no_gps_exif, 'oriented.jpg'], [$text, 'notes.txt']] as [$bytes, $name]) {
            $attachment = static::__create($bytes, $name);
            foreach (static::__served($attachment) as $route => $response) {
                static::__assert_instance_of(BinaryFileResponse::class, $response, "{$name} {$route}: a plain file response (range support kept)");
                static::__assert_equals($bytes, static::__body($response), "{$name} {$route}: byte for byte");
            }
        }
    }

    // GPS-10
    public static function test_the_switch_off_serves_the_stored_bytes()
    {
        // setup() / teardown() run once per class: the switch is restored here.
        config(['rsx.files.strip_gps_when_served' => false]);
        try {
            $jpeg = static::__jpeg_with_exif(static::__tiff(true));
            $attachment = static::__create($jpeg, 'kept.jpg');

            foreach (static::__served($attachment) as $route => $response) {
                static::__assert_equals($jpeg, static::__body($response), "{$route}: rsx.files.strip_gps_when_served = false serves the original as stored");
            }
        } finally {
            config(['rsx.files.strip_gps_when_served' => static::$saved_switch]);
        }
    }

    // GPS-11
    public static function test_a_zip_member_is_withheld_its_position()
    {
        $jpeg = static::__jpeg_with_exif(static::__tiff(true));
        $attachment = static::__create($jpeg, 'located.jpg');
        $path = $attachment->resolve_storage()->get_full_path();

        $archive = '';
        $zip = new Zip_Stream();
        $zip->open(function (string $bytes) use (&$archive) {
            $archive .= $bytes;
        });
        $zip->add_file_from_path('located.jpg', $path, 'image/jpeg', File_Storage_Model::gps_patches_for_serving($path, 'located.jpg'));
        $zip->finish();

        $file = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($file, $archive);
        try {
            $reader = new \ZipArchive();
            static::__assert_true($reader->open($file) === true, 'a well-formed archive');
            static::__assert_equals(Exif_Gps::strip_bytes($jpeg), $reader->getFromName('located.jpg'), 'the member is the image without its GPS block (and its CRC matches)');
            $reader->close();
        } finally {
            unlink($file);
        }
    }

    // GPS-12
    public static function test_malformed_exif_is_served_as_stored_with_a_warning()
    {
        // IFD0's offset points far past the end of the TIFF.
        $jpeg = static::__jpeg_with_exif("MM\x00\x2a\x00\x00\x40\x00");
        static::__assert_throws(\RuntimeException::class, fn () => Exif_Gps::patches($jpeg), 'outside the structure');

        $path = tempnam(sys_get_temp_dir(), 'gps');
        file_put_contents($path, $jpeg);
        $log = Log::getFacadeRoot();
        Log::spy();
        try {
            static::__assert_equals([], File_Storage_Model::gps_patches_for_serving($path, 'broken.jpg'), 'nothing patched: served as stored');
            Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, "GPS not withheld from 'broken.jpg'"))->once();
        } finally {
            Log::swap($log);
            unlink($path);
        }
    }
}
