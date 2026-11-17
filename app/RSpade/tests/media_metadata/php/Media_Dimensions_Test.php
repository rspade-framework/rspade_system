<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\MediaMetadata\Php;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * An image's or video's width and height are the DISPLAYED size, its duration is read too, and
 * when they are not stored they are read from the bytes the first time a payload carries them.
 */
class Media_Dimensions_Test extends Rsx_Test_Abstract
{
    /** @var array<int> */
    private static $created_attachment_ids = [];

    private static $saved_ffprobe = null;

    public static function setup()
    {
        static::$saved_ffprobe = config('rsx.attachments.ffprobe_binary_path');
        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn ($data) => true]);
    }

    public static function teardown()
    {
        config(['rsx.attachments.ffprobe_binary_path' => static::$saved_ffprobe]);

        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->force_destroy();
            }
        }
        static::$created_attachment_ids = [];

        Event_Registry::_clear_test_handlers();
    }

    private static function __site_id(): int
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        return $site_id;
    }

    private static function __create(string $bytes, string $name): File_Attachment_Model
    {
        $attachment = File_Attachment_Model::create_from_string($bytes, $name, ['site_id' => static::__site_id()]);
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    /** A 60x40 JPEG tagged EXIF orientation 6: seen upright it is 40x60. Unique bytes. */
    private static function __orientation_6_jpeg(): string
    {
        $image = new \Imagick();
        $image->newImage(60, 40, new \ImagickPixel(sprintf('#%02x%02x%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255))));
        $image->setImageFormat('jpeg');
        $jpeg = $image->getImageBlob();

        $tiff = "MM\x00\x2a\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
        $payload = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /** The committed fixture: 64x32 coded, display rotation 90, 1.5 seconds. Unique bytes. */
    private static function __rotated_mp4(): string
    {
        // Bytes appended after the last box are ignored by every reader, and make a blob of its own.
        return (string) file_get_contents(dirname(__DIR__) . '/resource/rotated_90.mp4');
    }

    /** Clear the stored size as the migration does, and read the row back fresh. */
    private static function __clear(File_Attachment_Model $attachment): File_Attachment_Model
    {
        DB::table('_file_attachments')->where('id', $attachment->id)->update([
            'width' => null,
            'height' => null,
            'duration' => null,
            'updated_at' => '2026-01-02 03:04:05.000',
        ]);

        return File_Attachment_Model::find($attachment->id);
    }

    private static function __row(File_Attachment_Model $attachment): object
    {
        return DB::table('_file_attachments')->where('id', $attachment->id)->first(['width', 'height', 'duration', 'updated_at']);
    }

    // DIMS-01
    public static function test_an_orientation_6_jpeg_stores_its_upright_size()
    {
        $attachment = static::__create(static::__orientation_6_jpeg(), 'portrait.jpg');

        static::__assert_equals(File_Attachment_Model::FILE_TYPE_IMAGE, (int) $attachment->file_type_id);
        static::__assert_equals([40, 60], [(int) $attachment->width, (int) $attachment->height], 'stored upright: 40 wide, 60 tall (the pixels are 60x40)');
    }

    // DIMS-02
    public static function test_a_missing_image_size_is_read_on_demand_and_stored()
    {
        $attachment = static::__clear(static::__create(static::__orientation_6_jpeg(), 'cleared.jpg'));
        static::__assert_true($attachment->needs_media_metadata(), 'a cleared image owes its size');

        $payload = File_Attachment_Model::fetch($attachment->id);
        static::__assert_equals([40, 60], [$payload['width'], $payload['height']], 'fetch() carries the size read from the bytes, upright');

        $row = static::__row($attachment);
        static::__assert_equals([40, 60], [(int) $row->width, (int) $row->height], 'and it is stored');
        static::__assert_equals('2026-01-02 03:04:05.000', $row->updated_at, 'without moving updated_at (the record was not edited)');

        static::__assert_false(File_Attachment_Model::find($attachment->id)->needs_media_metadata(), 'nothing is owed afterwards');
    }

    // DIMS-03
    public static function test_a_rotated_video_stores_its_displayed_size_and_duration()
    {
        $attachment = static::__create(static::__rotated_mp4() . random_hash(), 'phone.mp4');

        static::__assert_equals(File_Attachment_Model::FILE_TYPE_VIDEO, (int) $attachment->file_type_id, 'a video stays a video');
        static::__assert_false((bool) $attachment->preview_unavailable, 'and is not degraded to a generic file');
        static::__assert_equals([32, 64, 2], [(int) $attachment->width, (int) $attachment->height, (int) $attachment->duration], 'the 64x32 frame turned 90 degrees is 32x64; 1.5 s rounds to 2');

        $attachment = static::__clear($attachment);
        static::__assert_true($attachment->needs_media_metadata(), 'a cleared video owes its metadata');
        $attachment->ensure_media_metadata();
        static::__assert_equals([32, 64, 2], [$attachment->width, $attachment->height, $attachment->duration], 'read again on demand');
        $row = static::__row($attachment);
        static::__assert_equals([32, 64, 2], [(int) $row->width, (int) $row->height, (int) $row->duration], 'and stored');
    }

    // DIMS-04
    public static function test_a_missing_ffprobe_stores_nothing_and_says_so()
    {
        config(['rsx.attachments.ffprobe_binary_path' => '/nonexistent/ffprobe']);

        $log = Log::getFacadeRoot();
        Log::spy();
        try {
            $attachment = static::__create(static::__rotated_mp4() . random_hash(), 'unread.mp4');
            Log::shouldHaveReceived('warning')->withArgs(
                fn ($message) => str_starts_with($message, 'Video metadata not read for attachment') && str_contains($message, '/nonexistent/ffprobe')
            )->once();
        } finally {
            Log::swap($log);
            // setup() / teardown() run once per class: restored here, before anything else runs.
            config(['rsx.attachments.ffprobe_binary_path' => static::$saved_ffprobe]);
        }

        static::__assert_equals(File_Attachment_Model::FILE_TYPE_VIDEO, (int) $attachment->file_type_id, 'still a video');
        static::__assert_false((bool) $attachment->preview_unavailable, 'a missing tool is not a broken file');
        static::__assert_equals([null, null, null], [$attachment->width, $attachment->height, $attachment->duration], 'nothing guessed');

        // ffprobe back: the next read fills them in.
        $fresh = File_Attachment_Model::find($attachment->id)->ensure_media_metadata();
        static::__assert_equals([32, 64, 2], [$fresh->width, $fresh->height, $fresh->duration], 'read on demand once ffprobe is there');
    }

    // DIMS-05
    public static function test_the_migration_clears_images_and_videos_only()
    {
        $gif = new \Imagick();
        foreach (['#ff0000', '#00ff00'] as $colour) {
            $gif->newImage(8, 8, new \ImagickPixel($colour));
            $gif->setImageFormat('gif');
            $gif->setImageDelay(50);
        }

        $rows = [
            'image' => static::__create(static::__orientation_6_jpeg(), 'a.jpg'),
            'animated' => static::__create($gif->getImagesBlob() . '', 'b.gif'),
            'video' => static::__create(static::__rotated_mp4() . random_hash(), 'c.mp4'),
            'document' => static::__create('A document ' . random_hash(), 'd.txt'),
            'other' => static::__create(random_bytes(64), 'e.bin'),
        ];
        static::__assert_equals(File_Attachment_Model::FILE_TYPE_ANIMATED_IMAGE, (int) $rows['animated']->file_type_id, 'the GIF fixture is animated');
        static::__assert_equals(File_Attachment_Model::FILE_TYPE_DOCUMENT, (int) $rows['document']->file_type_id);
        static::__assert_equals(File_Attachment_Model::FILE_TYPE_OTHER, (int) $rows['other']->file_type_id);

        // Every row carries values, so the WHERE alone decides what is cleared.
        DB::table('_file_attachments')->whereIn('id', array_map(fn ($a) => $a->id, $rows))->update(['width' => 11, 'height' => 12, 'duration' => 13]);

        $files = glob(base_path('database/migrations/*_clear_stored_media_dimensions_on_file_attachments.php'));
        static::__assert_count(1, $files, 'the migration exists');
        $migration = require $files[0];
        $migration->up();

        foreach ($rows as $label => $attachment) {
            $row = static::__row($attachment);
            $values = [$row->width, $row->height, $row->duration];
            if (in_array($label, ['image', 'animated', 'video'], true)) {
                static::__assert_equals([null, null, null], $values, "{$label}: cleared, to be read again by the current rule");
            } else {
                static::__assert_equals([11, 12, 13], array_map('intval', $values), "{$label}: untouched");
            }
        }
    }

    // DIMS-06
    public static function test_the_migration_restores_videos_demoted_to_generic_files()
    {
        $rows = [
            'demoted video' => static::__create(static::__rotated_mp4() . random_hash(), 'f.mp4'),
            'video' => static::__create(static::__rotated_mp4() . random_hash(), 'g.mp4'),
            'demoted image' => static::__create(static::__orientation_6_jpeg(), 'h.jpg'),
            'other' => static::__create(random_bytes(64), 'i.bin'),
        ];
        static::__assert_equals('video/mp4', $rows['demoted video']->mime_type, 'the fixture sniffs as a video');

        // A video uploaded while video metadata was read by ImageMagick was demoted the way an
        // unparseable image is: OTHER, preview unavailable, no size.
        foreach (['demoted video', 'demoted image'] as $label) {
            DB::table('_file_attachments')->where('id', $rows[$label]->id)->update([
                'file_type_id' => File_Attachment_Model::FILE_TYPE_OTHER,
                'preview_unavailable' => 1,
            ]);
        }
        // Every row carries values, so the WHERE alone decides what changes.
        DB::table('_file_attachments')->whereIn('id', array_map(fn ($a) => $a->id, $rows))->update(['width' => 11, 'height' => 12, 'duration' => 13]);

        $before = [];
        foreach ($rows as $label => $attachment) {
            $before[$label] = DB::table('_file_attachments')->where('id', $attachment->id)->first(['file_type_id', 'preview_unavailable', 'width', 'height', 'duration', 'updated_at']);
        }

        $files = glob(base_path('database/migrations/*_reclassify_degraded_videos_on_file_attachments.php'));
        static::__assert_count(1, $files, 'the migration exists');
        $migration = require $files[0];
        $migration->up();

        $video = DB::table('_file_attachments')->where('id', $rows['demoted video']->id)->first(['file_type_id', 'preview_unavailable', 'width', 'height', 'duration']);
        static::__assert_equals(File_Attachment_Model::FILE_TYPE_VIDEO, (int) $video->file_type_id, 'a demoted video is a video again');
        static::__assert_equals(0, (int) $video->preview_unavailable, 'and previewable');
        static::__assert_equals([null, null, null], [$video->width, $video->height, $video->duration], 'its metadata is left to be read on demand');

        foreach (['video', 'demoted image', 'other'] as $label) {
            $row = DB::table('_file_attachments')->where('id', $rows[$label]->id)->first(['file_type_id', 'preview_unavailable', 'width', 'height', 'duration', 'updated_at']);
            static::__assert_equals((array) $before[$label], (array) $row, "{$label}: untouched");
        }

        $fresh = File_Attachment_Model::find($rows['demoted video']->id);
        static::__assert_true($fresh->is_video(), 'the model reads it as a video');
        $fresh->ensure_media_metadata();
        static::__assert_equals([32, 64, 2], [$fresh->width, $fresh->height, $fresh->duration], 'its displayed size and duration are read on demand');
    }
}
