<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FaceThumbnails\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Face-centred cover thumbnails end to end through the dynamic route, with a STUB detector in
 * place of the real one (resource/stub_detector.py, selected through
 * rsx.thumbnails.face_detection). The stub prints whatever exit code, stdout line and stderr a
 * test asks for, and logs every call, so each owner ruling is checked against what was stored
 * on the blob, which bytes were served, the Cache-Control sent, and which cache files exist.
 *
 * THE PICTURE. A 600x200 image of three vertical bands - red, green, blue. A 50x50 cover
 * thumbnail is drawn at 100x100 from the image scaled to 300x100, so the centre crop is the
 * GREEN band, and a crop around a face at x = 1/6 is the RED band. The colour at the centre of
 * the served thumbnail says which crop was cut.
 */
class Face_Thumbnail_Serving_Test extends Rsx_Test_Abstract
{
    private const WEEK = 'max-age=604800';
    private const HALF_HOUR = 'max-age=1800';

    /** @var array<int> */
    private static $created_attachment_ids = [];

    /** @var array<string> */
    private static $created_files = [];

    private static $saved_detector = null;

    public static function setup()
    {
        static::$saved_detector = config('rsx.thumbnails.face_detection');

        // The subject is cropping and caching, not authorization.
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
        config(['rsx.thumbnails.face_detection' => static::$saved_detector]);
    }

    // ============================================================================================
    // FIXTURES
    // ============================================================================================

    private static function __python(): string
    {
        $python = '/usr/bin/python3';
        if (!is_executable($python)) {
            static::__skip('the stub detector needs /usr/bin/python3, which every RSpade image carries');
        }

        return $python;
    }

    /**
     * Point the detector config at the stub, answering with this exit code, stdout and stderr.
     * Returns the path of the file the stub logs each call to.
     */
    private static function __stub(int $exit, ?string $stdout, string $stderr = ''): string
    {
        $spec_path = Rsx_Project_Paths::scratch_file('face_stub_spec', 'json');
        $log_path = Rsx_Project_Paths::scratch_file('face_stub_calls', 'log');
        static::$created_files[] = $spec_path;
        static::$created_files[] = $log_path;

        file_put_contents($spec_path, json_encode([
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'log' => $log_path,
        ]));

        config(['rsx.thumbnails.face_detection' => [
            'python' => static::__python(),
            'script' => dirname(__DIR__) . '/resource/stub_detector.py',
            'model' => $spec_path,
        ]]);

        return $log_path;
    }

    /** How many times the stub detector ran. */
    private static function __calls(string $log_path): int
    {
        return is_file($log_path) ? count(array_filter(explode("\n", (string) file_get_contents($log_path)))) : 0;
    }

    /** The three-band picture, unique bytes each call (one blob of its own). */
    private static function __band_png(): string
    {
        $image = new \Imagick();
        $image->newImage(600, 200, new \ImagickPixel('white'));
        foreach (['#ff0000' => 0, '#00ff00' => 200, '#0000ff' => 400] as $colour => $left) {
            $draw = new \ImagickDraw();
            $draw->setFillColor(new \ImagickPixel($colour));
            $draw->rectangle($left, 0, $left + 199, 199);
            $image->drawImage($draw);
        }
        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel(sprintf('#%02x%02x%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255))));
        $draw->point(random_int(0, 599), 199);
        $image->drawImage($draw);
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    private static function __make_attachment(string $bytes, string $filename): File_Attachment_Model
    {
        $attachment = File_Attachment_Model::create_from_string($bytes, $filename, ['site_id' => static::__site_id()]);
        static::$created_attachment_ids[] = $attachment->id;
        $storage = $attachment->resolve_storage();
        static::$created_files[] = $storage->get_full_path();

        foreach (['cover', 'cover-face', 'fit'] as $type) {
            static::$created_files[] = static::__cache_path($attachment, $type);
        }

        return $attachment;
    }

    private static function __cache_path(File_Attachment_Model $attachment, string $type): string
    {
        return File_Attachment_Controller::_get_cache_path(
            'dynamic',
            File_Attachment_Controller::_get_cache_filename_dynamic($type, 50, 50, $attachment->resolve_storage()->hash, $attachment->file_extension)
        );
    }

    private static function __storage(File_Attachment_Model $attachment): File_Storage_Model
    {
        return File_Storage_Model::find($attachment->file_storage_id);
    }

    /** Ask the dynamic route for a 50x50 thumbnail at the current content version. */
    private static function __thumbnail(File_Attachment_Model $attachment, string $type = 'cover', array $extra = ['face' => '1'])
    {
        Rsx_Request_Channel::reset();

        return File_Attachment_Controller::thumbnail(Request::create('/x', 'GET'), [
            'key' => $attachment->key,
            'type' => $type,
            'width' => 50,
            'height' => 50,
            'v' => $attachment->content_version(),
        ] + $extra);
    }

    private static function __bytes($response): string
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }

    /** 'red', 'green' or 'blue': the dominant channel at the centre of the served thumbnail. */
    private static function __centre_colour($response): string
    {
        $image = new \Imagick();
        $image->readImageBlob(static::__bytes($response));
        $pixel = $image->getImagePixelColor(intdiv($image->getImageWidth(), 2), intdiv($image->getImageHeight(), 2))->getColor();
        $channels = ['red' => $pixel['r'], 'green' => $pixel['g'], 'blue' => $pixel['b']];
        arsort($channels);

        return (string) array_key_first($channels);
    }

    private static function __cache_control($response): string
    {
        return (string) $response->headers->get('Cache-Control');
    }

    private static function __assert_face_columns(File_Attachment_Model $attachment, ?string $x, ?string $y, string $message): void
    {
        $storage = static::__storage($attachment);
        static::__assert_equals($x, $storage->face_x === null ? null : (string) $storage->face_x, "{$message} (face_x)");
        static::__assert_equals($y, $storage->face_y === null ? null : (string) $storage->face_y, "{$message} (face_y)");
    }

    // ============================================================================================
    // A WORKING DETECTOR
    // ============================================================================================

    // FACE-10
    public static function test_a_found_face_is_stored_and_the_crop_is_cut_around_it()
    {
        $calls = static::__stub(0, '{"status":"found","x":0.1667,"y":0.5,"score":0.97}');
        $attachment = static::__make_attachment(static::__band_png(), 'portrait.png');

        $response = static::__thumbnail($attachment);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_face_columns($attachment, '0.1667', '0.5000', 'the face centre is stored on the blob');
        static::__assert_equals('red', static::__centre_colour($response), 'the crop is cut around the face, not the centre');
        static::__assert_contains(self::WEEK, static::__cache_control($response), 'a face crop is cached by the browser like any thumbnail');
        static::__assert_contains('private', static::__cache_control($response));
        static::__assert_true(is_file(static::__cache_path($attachment, 'cover-face')), 'cached under the face key');
        static::__assert_false(is_file(static::__cache_path($attachment, 'cover')), 'nothing cached under the plain key');
        static::__assert_equals(1, static::__calls($calls), 'the detector ran once');

        $again = static::__thumbnail($attachment);
        static::__assert_equals('red', static::__centre_colour($again), 'the cached face crop is served again');
        static::__assert_contains(self::WEEK, static::__cache_control($again));
        static::__assert_equals(1, static::__calls($calls), 'a stored answer is never detected again');

        $plain = static::__thumbnail($attachment, 'cover', []);
        static::__assert_equals('green', static::__centre_colour($plain), 'without face=1 the same image is the centre crop');
    }

    // FACE-11
    public static function test_no_face_stores_minus_one_and_serves_the_plain_cover()
    {
        $calls = static::__stub(0, '{"status":"none"}');
        $attachment = static::__make_attachment(static::__band_png(), 'landscape.png');

        $response = static::__thumbnail($attachment);

        static::__assert_face_columns($attachment, '-1.0000', '-1.0000', 'no face is stored as -1, -1');
        static::__assert_equals('green', static::__centre_colour($response), 'the plain centre crop');
        static::__assert_contains(self::WEEK, static::__cache_control($response), 'a settled answer is cached for the week');
        static::__assert_true(is_file(static::__cache_path($attachment, 'cover')), 'cached under the plain key');
        static::__assert_false(is_file(static::__cache_path($attachment, 'cover-face')), 'nothing under the face key');

        static::__thumbnail($attachment);
        static::__assert_equals(1, static::__calls($calls), 'a stored "no face" is never detected again');
    }

    // FACE-12
    public static function test_an_unreadable_image_from_a_working_detector_is_stored_as_no_face()
    {
        static::__stub(0, '{"status":"failed","error":"unreadable image"}', "Corrupt JPEG data\n");
        $attachment = static::__make_attachment(static::__band_png(), 'odd.png');

        $response = static::__thumbnail($attachment);

        static::__assert_face_columns($attachment, '-1.0000', '-1.0000', '"failed" from a working detector is stored as -1, -1');
        static::__assert_equals('green', static::__centre_colour($response));
        static::__assert_contains(self::WEEK, static::__cache_control($response));
    }

    // ============================================================================================
    // AN UNAVAILABLE DETECTOR
    // ============================================================================================

    /**
     * The unavailable ruling, for one broken run: nothing stored, the plain bytes, half an hour,
     * the plain key only, a warning naming the reason - and the same again from the cache.
     */
    private static function __assert_unavailable(string $label, string $reason_fragment): void
    {
        $attachment = static::__make_attachment(static::__band_png(), 'unavailable.png');

        $log = Log::getFacadeRoot();
        Log::spy();

        try {
            $response = static::__thumbnail($attachment);
            Log::shouldHaveReceived('warning')->withArgs(
                fn ($message) => str_starts_with($message, 'Face detection unavailable: ') && str_contains($message, $reason_fragment)
            )->once();
        } finally {
            Log::swap($log);
        }

        static::__assert_equals(200, $response->getStatusCode(), "{$label}: still serves");
        static::__assert_face_columns($attachment, null, null, "{$label}: nothing is stored");
        static::__assert_equals('green', static::__centre_colour($response), "{$label}: the plain cover is served");
        static::__assert_contains(self::HALF_HOUR, static::__cache_control($response), "{$label}: kept for half an hour, even at the current version");
        static::__assert_contains('private', static::__cache_control($response), "{$label}: gated bytes stay private");
        static::__assert_false(str_contains(static::__cache_control($response), self::WEEK), "{$label}: not kept for a week");
        static::__assert_true(is_file(static::__cache_path($attachment, 'cover')), "{$label}: the plain cover is cached under the plain key");
        static::__assert_false(is_file(static::__cache_path($attachment, 'cover-face')), "{$label}: nothing is cached under the face key");

        $again = static::__thumbnail($attachment);
        static::__assert_equals('green', static::__centre_colour($again), "{$label}: the cached plain cover is served next time");
        static::__assert_contains(self::HALF_HOUR, static::__cache_control($again), "{$label}: and still for half an hour only");

        $plain = static::__thumbnail($attachment, 'cover', []);
        static::__assert_contains(self::WEEK, static::__cache_control($plain), "{$label}: a plain request for the same thumbnail keeps its week");
    }

    // FACE-13
    public static function test_exit_three_is_unavailable()
    {
        static::__stub(3, '{"status":"unavailable","error":"model not found: /nowhere.onnx"}');
        static::__assert_unavailable('exit 3', 'model not found');
    }

    // FACE-14
    public static function test_another_exit_code_is_unavailable()
    {
        static::__stub(1, '', "Traceback (most recent call last):\n  ImportError\n");
        static::__assert_unavailable('exit 1', 'ImportError');
    }

    // FACE-15
    public static function test_garbage_on_stdout_is_unavailable()
    {
        static::__stub(0, 'this is not json');
        static::__assert_unavailable('garbage stdout', 'malfunctioned');
    }

    // FACE-16
    public static function test_a_missing_python_is_unavailable()
    {
        config(['rsx.thumbnails.face_detection' => [
            'python' => Rsx_Project_Paths::tmp_path('no_such_face_detector/python'),
            'script' => dirname(__DIR__) . '/resource/stub_detector.py',
            'model' => Rsx_Project_Paths::tmp_path('no_such_face_detector/model.onnx'),
        ]]);
        static::__assert_unavailable('missing python', 'not installed');
    }

    // ============================================================================================
    // WHERE THE DETECTOR IS NEVER RUN
    // ============================================================================================

    // FACE-17
    public static function test_a_stored_position_is_used_without_running_the_detector()
    {
        // A detector that would fail the ruling if it ran: it answers "no face".
        $calls = static::__stub(0, '{"status":"none"}');
        $attachment = static::__make_attachment(static::__band_png(), 'stored.png');
        static::__storage($attachment)->record_face_position(0.1667, 0.5);

        $response = static::__thumbnail($attachment);

        static::__assert_equals('red', static::__centre_colour($response), 'the stored position decides the crop');
        static::__assert_equals(0, static::__calls($calls), 'the detector never ran');
    }

    // FACE-18
    public static function test_fit_and_non_images_never_run_the_detector()
    {
        $calls = static::__stub(0, '{"status":"found","x":0.1,"y":0.1,"score":0.9}');

        $image = static::__make_attachment(static::__band_png(), 'fit.png');
        $response = static::__thumbnail($image, 'fit');
        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_contains(self::WEEK, static::__cache_control($response), 'a fit thumbnail ignores face=1');
        static::__assert_face_columns($image, null, null, 'a fit thumbnail stores nothing');

        $text = static::__make_attachment('face thumbnails ' . bin2hex(random_bytes(8)), 'notes.txt');
        $response = static::__thumbnail($text);
        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_face_columns($text, null, null, 'a non-image stores nothing');
        static::__assert_false(is_file(static::__cache_path($text, 'cover-face')), 'a non-image has no face key');

        static::__assert_equals(0, static::__calls($calls), 'the detector never ran for fit or a non-image');
    }

    // ============================================================================================
    // URL BUILDERS AND CACHE NAMES
    // ============================================================================================

    // FACE-19
    public static function test_the_php_builder_asks_for_a_face_only_on_a_cover()
    {
        $attachment = static::__make_attachment(static::__band_png(), 'builder.png');
        $version = $attachment->content_version();

        static::__assert_equals(
            "/_thumbnail/dynamic/{$attachment->key}/cover/36/36?v={$version}&face=1",
            $attachment->get_thumbnail_url('cover', 36, 36, face: true),
            'a face-centred cover carries face=1 after the content version'
        );
        static::__assert_equals(
            "/_thumbnail/dynamic/{$attachment->key}/cover/36?v={$version}&face=1",
            $attachment->get_thumbnail_url('cover', 36, null, true),
            'a width-only face-centred cover carries it too'
        );
        static::__assert_equals(
            "/_thumbnail/dynamic/{$attachment->key}/fit/36/36?v={$version}",
            $attachment->get_thumbnail_url('fit', 36, 36, face: true),
            'a fit thumbnail is never cropped, so the flag is left off'
        );
        static::__assert_equals(
            "/_thumbnail/dynamic/{$attachment->key}/cover/36/36?v={$version}",
            $attachment->get_thumbnail_url('cover', 36, 36),
            'no face unless asked'
        );
    }

    // FACE-23
    public static function test_a_width_only_request_is_cached_apart_from_the_same_width_and_height()
    {
        static::__stub(0, '{"status":"none"}');
        $attachment = static::__make_attachment(static::__band_png(), 'widths.png');
        $hash = $attachment->resolve_storage()->hash;

        $width_only = File_Attachment_Controller::_get_cache_filename_dynamic('fit', 50, null, $hash, 'png');
        $square = File_Attachment_Controller::_get_cache_filename_dynamic('fit', 50, 50, $hash, 'png');
        static::__assert_not_equals($width_only, $square, 'an omitted height is never spelled as the width');
        static::__assert_contains("_{$hash}_", $width_only, 'the purge glob still finds a width-only name');
        static::__assert_contains('_r' . File_Attachment_Controller::THUMBNAIL_RENDER_REVISION . '.webp', $width_only, 'the name carries the render revision');
        static::$created_files[] = File_Attachment_Controller::_get_cache_path('dynamic', $width_only);

        $size = function ($response): string {
            $image = new \Imagick();
            $image->readImageBlob(static::__bytes($response));

            return $image->getImageWidth() . 'x' . $image->getImageHeight();
        };

        // Square first, so a shared name would hand the width-only request the square picture.
        $square_response = static::__thumbnail($attachment, 'fit', []);
        Rsx_Request_Channel::reset();
        $width_only_response = File_Attachment_Controller::thumbnail(Request::create('/x', 'GET'), [
            'key' => $attachment->key,
            'type' => 'fit',
            'width' => 50,
            'v' => $attachment->content_version(),
        ]);

        static::__assert_equals('100x100', $size($square_response), 'fit/50/50 fits the 3:1 picture inside a square box');
        static::__assert_equals('100x34', $size($width_only_response), 'fit/50 keeps the picture\'s own aspect ratio');
        static::__assert_true(is_file(File_Attachment_Controller::_get_cache_path('dynamic', $width_only)), 'the width-only picture is cached under its own name');
        static::__assert_true(is_file(File_Attachment_Controller::_get_cache_path('dynamic', $square)), 'the square one under its own');
    }
}
