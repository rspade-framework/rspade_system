<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Imagick_Thumbnail_Renderer;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * An animated image is recognised as one, and its thumbnail is a still.
 *
 * The subject is resource/cube.gif - a turning cube, 24 frames, drawn by
 * resource/generate_cube_gif.php and committed as that script's output - because the facts
 * under test are read out of a file by the image library: the frame count, the per-frame
 * delays the duration is summed from, and which frame a thumbnail is cut from. Together they
 * are the contract <Attachment_Thumbnail $allow_animated> rests on - is_animated says the
 * picture moves, the thumbnail of it does not, and the original is what plays.
 */
class Animated_Image_Test extends Rsx_Test_Abstract
{
    private const FRAMES = 24;

    private static function __gif_path(): string
    {
        return dirname(__DIR__) . '/resource/cube.gif';
    }

    private static function __make_attachment(): File_Attachment_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        return File_Attachment_Model::create_from_disk(static::__gif_path(), [
            'file_name' => 'cube.gif',
            'site_id' => $site_id,
        ]);
    }

    /** ATTACH-ANIM-01 - an uploaded animated GIF is recorded as animated, with its frames and duration */
    public static function test_an_animated_gif_is_recorded_as_animated()
    {
        $attachment = static::__make_attachment();

        static::__assert_true((bool) $attachment->is_animated, 'the attachment says the image is animated');
        static::__assert_equals(self::FRAMES, (int) $attachment->frame_count);
        static::__assert_greater_than(0, (int) $attachment->duration, 'the duration is summed from the frame delays');
        static::__assert_equals('image/gif', $attachment->mime_type);
        static::__assert_true($attachment->is_image());
    }

    /** ATTACH-ANIM-02 - the flag reaches the browser on the record's payload */
    public static function test_the_animated_flag_is_on_the_payload()
    {
        $payload = static::__make_attachment()->toArray();

        static::__assert_true((bool) $payload['is_animated'], 'is_animated is what <Attachment_Thumbnail $allow_animated> reads');
    }

    /** ATTACH-ANIM-03 - the thumbnail of an animated image is ONE frame */
    public static function test_the_thumbnail_of_an_animated_image_is_a_single_frame()
    {
        $thumbnail = Imagick_Thumbnail_Renderer::render(static::__gif_path(), 120, 120);

        static::__assert_equals(1, $thumbnail->getNumberImages(), 'a thumbnail is a still: the first frame only');
    }

    /** ATTACH-ANIM-04 - the original, served for display, is the animation itself */
    public static function test_the_original_is_served_whole()
    {
        $attachment = static::__make_attachment();

        static::__assert_equals(
            hash_file('sha256', static::__gif_path()),
            hash('sha256', $attachment->file_storage->read_bytes()),
            'the stored bytes are the uploaded animation, every frame'
        );
        static::__assert_contains('/_inline/' . $attachment->key, $attachment->get_url());
    }
}
