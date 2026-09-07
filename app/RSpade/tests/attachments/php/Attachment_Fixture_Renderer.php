<?php

namespace App\RSpade\Tests\Attachments\Php;

use Exception;
use Imagick;
use App\RSpade\Core\Files\Rsx_Thumbnail_Renderer_Abstract;

/**
 * Test fixture: a thumbnail renderer that returns a deterministic Imagick raster (proving the
 * registry dispatched to it), or throws when $should_throw is set (proving the pipeline's explicit
 * icon substitution on renderer failure).
 */
class Attachment_Fixture_Renderer extends Rsx_Thumbnail_Renderer_Abstract
{
    /** Number of times render() was invoked. */
    public static int $render_count = 0;

    /** When true, render() throws (to exercise the pipeline's icon substitution). */
    public static bool $should_throw = false;

    public static function reset(): void
    {
        static::$render_count = 0;
        static::$should_throw = false;
    }

    public static function render(string $source_path, int $max_width, int $max_height): Imagick
    {
        static::$render_count++;

        if (static::$should_throw) {
            throw new Exception('Fixture renderer deliberate failure');
        }

        $image = new Imagick();
        $image->newImage(24, 24, new \ImagickPixel('green'));
        $image->setImageFormat('png');

        return $image;
    }
}
