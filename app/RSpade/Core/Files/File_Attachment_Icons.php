<?php

namespace App\RSpade\Core\Files;

/**
 * File attachment icon resource management
 *
 * Provides static methods for determining the appropriate icon resource
 * for different file types based on file extension.
 *
 * EVERY ICON THIS CLASS RASTERISES IS A PNG. ImageMagick is configured to read raster
 * coders only (resource/docker/imagemagick/policy.xml, and the "ImageMagick Coder Policy"
 * rsx:health row), because its SVG coder is a local-file-read primitive. The generic
 * icons are drawn as SVG (resource/icons/*.svg, the source artwork) and shipped as
 * 512px PNG rasters of those files beside them; README.md there has the command that
 * regenerates them.
 */
class File_Attachment_Icons
{
    /**
     * Get the icon resource path for a given file extension
     *
     * @param string $extension File extension (without dot)
     * @return string Relative path to icon file
     */
    public static function get_icon_resource_by_file_extension($extension)
    {
        $extension = strtolower($extension);
        $base_path = 'system/app/RSpade/Core/Files/resource/icons';

        $icon_map = [
            // Brand-specific PNG icons
            'pdf' => 'pdf.png',
            'psd' => 'psd.png',
            'ai' => 'ai.png',

            // Images - generic
            'jpg' => 'image.png',
            'jpeg' => 'image.png',
            'png' => 'image.png',
            'gif' => 'image.png',
            'bmp' => 'image.png',
            'svg' => 'image.png',
            'webp' => 'image.png',
            'ico' => 'image.png',
            'tiff' => 'image.png',
            'tif' => 'image.png',
            'heic' => 'image.png',
            'heif' => 'image.png',
            'raw' => 'image.png',
            'cr2' => 'image.png',
            'nef' => 'image.png',

            // Videos
            'mp4' => 'video.png',
            'avi' => 'video.png',
            'mov' => 'video.png',
            'wmv' => 'video.png',
            'flv' => 'video.png',
            'mkv' => 'video.png',
            'webm' => 'video.png',
            'mpeg' => 'video.png',
            'mpg' => 'video.png',
            'm4v' => 'video.png',
            '3gp' => 'video.png',

            // Audio
            'mp3' => 'audio.png',
            'wav' => 'audio.png',
            'flac' => 'audio.png',
            'aac' => 'audio.png',
            'ogg' => 'audio.png',
            'm4a' => 'audio.png',
            'wma' => 'audio.png',
            'aiff' => 'audio.png',
            'alac' => 'audio.png',

            // Archives
            'zip' => 'archive.png',
            'rar' => 'archive.png',
            '7z' => 'archive.png',
            'tar' => 'archive.png',
            'gz' => 'archive.png',
            'bz2' => 'archive.png',
            'xz' => 'archive.png',
            'iso' => 'archive.png',
            'dmg' => 'archive.png',

            // Text files
            'txt' => 'text.png',
            'md' => 'text.png',
            'rtf' => 'text.png',
            'log' => 'text.png',

            // Code files
            'php' => 'code.png',
            'js' => 'code.png',
            'ts' => 'code.png',
            'jsx' => 'code.png',
            'tsx' => 'code.png',
            'html' => 'code.png',
            'css' => 'code.png',
            'scss' => 'code.png',
            'sass' => 'code.png',
            'less' => 'code.png',
            'json' => 'code.png',
            'xml' => 'code.png',
            'yaml' => 'code.png',
            'yml' => 'code.png',
            'py' => 'code.png',
            'java' => 'code.png',
            'c' => 'code.png',
            'cpp' => 'code.png',
            'h' => 'code.png',
            'cs' => 'code.png',
            'rb' => 'code.png',
            'go' => 'code.png',
            'rs' => 'code.png',
            'swift' => 'code.png',
            'kt' => 'code.png',
            'sql' => 'code.png',
            'sh' => 'code.png',
            'bash' => 'code.png',

            // 3D Models
            'stl' => '3d_model.png',
            'obj' => '3d_model.png',
            'fbx' => '3d_model.png',
            'dae' => '3d_model.png',
            'blend' => '3d_model.png',
            '3ds' => '3d_model.png',
            'f3d' => '3d_model.png',
            'step' => '3d_model.png',
            'stp' => '3d_model.png',

            // Documents
            'doc' => 'document.png',
            'docx' => 'document.png',
            'odt' => 'document.png',
            'pages' => 'document.png',

            // Spreadsheets
            'xls' => 'spreadsheet.png',
            'xlsx' => 'spreadsheet.png',
            'ods' => 'spreadsheet.png',
            'numbers' => 'spreadsheet.png',
            'csv' => 'spreadsheet.png',

            // Presentations
            'ppt' => 'presentation.png',
            'pptx' => 'presentation.png',
            'odp' => 'presentation.png',
            'key' => 'presentation.png',
        ];

        $icon_file = $icon_map[$extension] ?? 'file.png';
        return $base_path . '/' . $icon_file;
    }

    /**
     * Get icon as PNG at specified dimensions
     *
     * Loads the appropriate icon file (always a PNG) for the given extension
     * and converts it to PNG at the target dimensions using "fit" mode
     * (maintains aspect ratio, no cropping).
     *
     * @param string $extension File extension (without dot)
     * @param int $width Target width in pixels
     * @param int $height Target height in pixels
     * @return string Binary PNG data
     */
    public static function get_icon_as_png($extension, $width, $height)
    {
        // Get the icon file path (relative from RSX root, which is one level up from Laravel base_path)
        $icon_path = static::get_icon_resource_by_file_extension($extension);
        $full_path = dirname(base_path()) . '/' . ltrim($icon_path, '/');

        if (!file_exists($full_path)) {
            shouldnt_happen("File type icon missing from the framework tree: {$full_path}");
        }

        $image = new \Imagick();
        $image->readImage($full_path);

        // Get original dimensions
        $original_width = $image->getImageWidth();
        $original_height = $image->getImageHeight();

        // Calculate scaling to fit within bounds (maintain aspect ratio)
        $scale_width = $width / $original_width;
        $scale_height = $height / $original_height;
        $scale = min($scale_width, $scale_height);

        $new_width = (int)round($original_width * $scale);
        $new_height = (int)round($original_height * $scale);

        // Resize image to fit
        $image->resizeImage($new_width, $new_height, \Imagick::FILTER_LANCZOS, 1);

        // Create transparent canvas at target dimensions
        $canvas = new \Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel('transparent'));
        $canvas->setImageFormat('png');

        // Center the resized image on canvas
        $offset_x = (int)round(($width - $new_width) / 2);
        $offset_y = (int)round(($height - $new_height) / 2);
        $canvas->compositeImage($image, \Imagick::COMPOSITE_OVER, $offset_x, $offset_y);

        // Set PNG compression
        $canvas->setImageCompressionQuality(85);

        // Get binary data
        $png_data = $canvas->getImageBlob();

        // Clean up
        $image->destroy();
        $canvas->destroy();

        return $png_data;
    }

    /**
     * Render icon as thumbnail in WebP format
     *
     * Creates a thumbnail-style image for files that don't have an actual thumbnail.
     * For small requests (< 72x72), uses the icon scaled to fill the dimensions.
     * For larger requests, embeds a 64x64 icon centered on a white canvas.
     *
     * CACHING ARCHITECTURE:
     * ---------------------
     * This function does NOT cache results internally. When thumbnail caching is
     * implemented, the cache should be managed at the STORAGE LAYER, not here.
     *
     * The caching strategy will be:
     * - Cache location: Dedicated thumbnail cache directory
     * - Cache key pattern: {extension}_{width}x{height}.webp
     * - Deduplication: Multiple files of same type share cached thumbnails
     * - Cache invalidation: By icon file modification time
     *
     * Example cache structure:
     *   /storage/thumbnail-cache/icons/pdf_200x200.webp
     *   /storage/thumbnail-cache/icons/stl_100x100.webp
     *
     * This allows:
     * 1. All PDF files share the same 200x200 icon thumbnail
     * 2. Cache can be pre-warmed for common sizes
     * 3. Icon updates invalidate all cached thumbnails for that type
     * 4. Separation from file-specific thumbnail caches
     *
     * When implementing caching, add the cache layer in the controller's thumbnail
     * endpoint BEFORE calling this method, checking for cached icon thumbnails by
     * extension and dimensions.
     *
     * @param string $extension File extension (without dot)
     * @param int $width Target width in pixels
     * @param int $height Target height in pixels
     * @return string Binary WebP data
     */
    public static function render_icon_as_thumbnail($extension, $width, $height)
    {
        // For small thumbnails (< 72x72), use the icon itself as source
        if ($width < 72 || $height < 72) {
            // Get icon as PNG
            $icon_path = static::get_icon_resource_by_file_extension($extension);
            $full_path = dirname(base_path()) . '/' . ltrim($icon_path, '/');

            if (!file_exists($full_path)) {
                shouldnt_happen("File type icon missing from the framework tree: {$full_path}");
            }

            $image = new \Imagick();
            $image->readImage($full_path);

            // Get original dimensions
            $original_width = $image->getImageWidth();
            $original_height = $image->getImageHeight();

            // Calculate scaling to cover entire area (may crop)
            $scale_width = $width / $original_width;
            $scale_height = $height / $original_height;
            $scale = max($scale_width, $scale_height);

            $new_width = (int)round($original_width * $scale);
            $new_height = (int)round($original_height * $scale);

            // Resize image to cover
            $image->resizeImage($new_width, $new_height, \Imagick::FILTER_LANCZOS, 1);

            // Crop to exact dimensions if needed
            if ($new_width > $width || $new_height > $height) {
                $offset_x = (int)round(($new_width - $width) / 2);
                $offset_y = (int)round(($new_height - $height) / 2);
                $image->cropImage($width, $height, $offset_x, $offset_y);
            }

            // Convert to WebP
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(85);

            $webp_data = $image->getImageBlob();
            $image->destroy();

            return $webp_data;
        }

        // For larger thumbnails, embed 64x64 icon on white canvas
        // Get icon as 64x64 PNG
        $icon_png = static::get_icon_as_png($extension, 64, 64);

        // Load the 64x64 icon
        $icon = new \Imagick();
        $icon->readImageBlob($icon_png);

        // Create white canvas at target dimensions
        $canvas = new \Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel('white'));
        $canvas->setImageFormat('webp');

        // Center the 64x64 icon on canvas
        $offset_x = (int)round(($width - 64) / 2);
        $offset_y = (int)round(($height - 64) / 2);
        $canvas->compositeImage($icon, \Imagick::COMPOSITE_OVER, $offset_x, $offset_y);

        // Set WebP compression
        $canvas->setImageCompressionQuality(85);

        // Get binary data
        $webp_data = $canvas->getImageBlob();

        // Clean up
        $icon->destroy();
        $canvas->destroy();

        return $webp_data;
    }
}
