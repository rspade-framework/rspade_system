<?php

namespace App\RSpade\Tests\Attachments\Php;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_Attachment_Handler_Abstract;

/**
 * Test fixture: a deterministic external attachment handler. fetch() writes a small solid-color
 * PNG to a temp file and returns its path; the framework then stores + links it. Test-controllable
 * via static state (call reset() in each test's setup).
 */
class Attachment_Fixture_Handler extends Rsx_Attachment_Handler_Abstract
{
    /** Number of times fetch() has been invoked (proves materialization happened). */
    public static int $fetch_count = 0;

    /** Which deterministic image variant fetch() should produce ('a' = 8x8 red, 'b' = 16x16 blue). */
    public static string $variant = 'a';

    public static function reset(): void
    {
        static::$fetch_count = 0;
        static::$variant = 'a';
    }

    public static function fetch(File_Attachment_Model $attachment): string
    {
        static::$fetch_count++;

        $tmp = tempnam(sys_get_temp_dir(), 'rsx_fixh_');
        file_put_contents($tmp, static::png_bytes(static::$variant));

        return $tmp;
    }

    /**
     * Deterministic PNG bytes for a variant. 'a' = 8x8 red, 'b' = 16x16 blue.
     *
     * @param string $variant
     * @return string
     */
    public static function png_bytes(string $variant): string
    {
        $size = $variant === 'b' ? 16 : 8;
        $color = $variant === 'b' ? 'blue' : 'red';

        $img = new \Imagick();
        $img->newImage($size, $size, new \ImagickPixel($color));
        $img->setImageFormat('png');
        $bytes = $img->getImageBlob();
        $img->destroy();

        return $bytes;
    }
}
