<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Debug\Rsx_Caller_Exception;
use App\RSpade\Core\Files\Imagick_Policy;

/**
 * File attachment icon resource management
 *
 * Answers "which picture stands for a file of this extension", in two renditions drawn from
 * ONE extension map (EXTENSION_ICONS below - there is no second list anywhere, in PHP or JS):
 *
 *   STYLE_COLOR   - the full-colour artwork, always a PNG on disk (resource/icons/*.png). This
 *                   is what stands in for a thumbnail that cannot be rendered, and it is the
 *                   only rendition ImageMagick ever reads.
 *   STYLE_OUTLINE - a square 24x24 stroke mark (resource/icons/outline/*.svg, Tabler Icons,
 *                   MIT) drawn in currentColor with no fills, for a list row or a chip beside
 *                   text. It is served as SVG bytes and inlined by the <File_Type_Icon>
 *                   component; it NEVER reaches ImageMagick.
 *
 * EVERY ICON THIS CLASS RASTERISES IS A PNG. ImageMagick is configured to read raster
 * coders only (resource/docker/imagemagick/policy.xml, and the "ImageMagick Coder Policy"
 * rsx:health row), because its SVG coder is a local-file-read primitive. The generic
 * colour icons are drawn as SVG (resource/icons/*.svg, the source artwork) and shipped as
 * 512px PNG rasters of those files beside them; README.md there has the command that
 * regenerates them. The outline SVGs are a separate, framework-owned set that is only ever
 * read as text and handed to a browser verbatim, so the coder policy is unaffected by them.
 */
class File_Attachment_Icons
{
    /** The full-colour PNG rendition (the default everywhere a style is accepted). */
    public const STYLE_COLOR = 'color';

    /** The square currentColor stroke rendition, delivered as SVG markup. */
    public const STYLE_OUTLINE = 'outline';

    /** The icon directory, relative to the project root. */
    private const ICON_DIR = 'system/app/RSpade/Core/Files/resource/icons';

    /**
     * THE extension map: lowercased extension => [colour PNG file, outline icon name].
     *
     * The outline name is a file in resource/icons/outline/ without its .svg suffix, and is the
     * Tabler Icons name it was vendored under. A Tabler file-type-* glyph is used where one
     * names the format; otherwise the closest analog (README.md in resource/icons lists the
     * choices). An extension absent from this map gets GENERIC_ICONS.
     */
    private const EXTENSION_ICONS = [
        // Brand-specific colour icons
        'pdf' => ['pdf.png', 'file-type-pdf'],
        'psd' => ['psd.png', 'photo'],
        'ai' => ['ai.png', 'file-ai'],

        // Images
        'jpg' => ['image.png', 'file-type-jpg'],
        'jpeg' => ['image.png', 'file-type-jpg'],
        'png' => ['image.png', 'file-type-png'],
        'gif' => ['image.png', 'photo'],
        'bmp' => ['image.png', 'file-type-bmp'],
        'svg' => ['image.png', 'file-type-svg'],
        'webp' => ['image.png', 'photo'],
        'ico' => ['image.png', 'photo'],
        'tiff' => ['image.png', 'photo'],
        'tif' => ['image.png', 'photo'],
        'heic' => ['image.png', 'photo'],
        'heif' => ['image.png', 'photo'],
        'raw' => ['image.png', 'photo'],
        'cr2' => ['image.png', 'photo'],
        'nef' => ['image.png', 'photo'],

        // Videos
        'mp4' => ['video.png', 'movie'],
        'avi' => ['video.png', 'movie'],
        'mov' => ['video.png', 'movie'],
        'wmv' => ['video.png', 'movie'],
        'flv' => ['video.png', 'movie'],
        'mkv' => ['video.png', 'movie'],
        'webm' => ['video.png', 'movie'],
        'mpeg' => ['video.png', 'movie'],
        'mpg' => ['video.png', 'movie'],
        'm4v' => ['video.png', 'movie'],
        '3gp' => ['video.png', 'movie'],

        // Audio
        'mp3' => ['audio.png', 'file-music'],
        'wav' => ['audio.png', 'file-music'],
        'flac' => ['audio.png', 'file-music'],
        'aac' => ['audio.png', 'file-music'],
        'ogg' => ['audio.png', 'file-music'],
        'm4a' => ['audio.png', 'file-music'],
        'wma' => ['audio.png', 'file-music'],
        'aiff' => ['audio.png', 'file-music'],
        'alac' => ['audio.png', 'file-music'],

        // Archives
        'zip' => ['archive.png', 'file-type-zip'],
        'rar' => ['archive.png', 'file-zip'],
        '7z' => ['archive.png', 'file-zip'],
        'tar' => ['archive.png', 'file-zip'],
        'gz' => ['archive.png', 'file-zip'],
        'bz2' => ['archive.png', 'file-zip'],
        'xz' => ['archive.png', 'file-zip'],
        'iso' => ['archive.png', 'disc'],
        'dmg' => ['archive.png', 'disc'],

        // Text files
        'txt' => ['text.png', 'file-type-txt'],
        'md' => ['text.png', 'file-text'],
        'rtf' => ['text.png', 'file-text'],
        'log' => ['text.png', 'file-text'],

        // Code files
        'php' => ['code.png', 'file-type-php'],
        'js' => ['code.png', 'file-type-js'],
        'ts' => ['code.png', 'file-type-ts'],
        'jsx' => ['code.png', 'file-type-jsx'],
        'tsx' => ['code.png', 'file-type-tsx'],
        'html' => ['code.png', 'file-type-html'],
        'css' => ['code.png', 'file-type-css'],
        'scss' => ['code.png', 'file-code'],
        'sass' => ['code.png', 'file-code'],
        'less' => ['code.png', 'file-code'],
        'json' => ['code.png', 'file-code'],
        'xml' => ['code.png', 'file-type-xml'],
        'yaml' => ['code.png', 'file-code'],
        'yml' => ['code.png', 'file-code'],
        'py' => ['code.png', 'file-code'],
        'java' => ['code.png', 'file-code'],
        'c' => ['code.png', 'file-code'],
        'cpp' => ['code.png', 'file-code'],
        'h' => ['code.png', 'file-code'],
        'cs' => ['code.png', 'file-code'],
        'rb' => ['code.png', 'file-code'],
        'go' => ['code.png', 'file-code'],
        'rs' => ['code.png', 'file-type-rs'],
        'swift' => ['code.png', 'file-code'],
        'kt' => ['code.png', 'file-code'],
        'sql' => ['code.png', 'file-type-sql'],
        'sh' => ['code.png', 'file-code'],
        'bash' => ['code.png', 'file-code'],

        // 3D Models
        'stl' => ['3d_model.png', 'file-3d'],
        'obj' => ['3d_model.png', 'file-3d'],
        'fbx' => ['3d_model.png', 'file-3d'],
        'dae' => ['3d_model.png', 'file-3d'],
        'blend' => ['3d_model.png', 'file-3d'],
        '3ds' => ['3d_model.png', 'file-3d'],
        'f3d' => ['3d_model.png', 'file-3d'],
        'step' => ['3d_model.png', 'file-3d'],
        'stp' => ['3d_model.png', 'file-3d'],

        // Documents
        'doc' => ['document.png', 'file-type-doc'],
        'docx' => ['document.png', 'file-type-docx'],
        'odt' => ['document.png', 'file-text'],
        'pages' => ['document.png', 'file-text'],

        // Spreadsheets
        'xls' => ['spreadsheet.png', 'file-type-xls'],
        'xlsx' => ['spreadsheet.png', 'file-excel'],
        'ods' => ['spreadsheet.png', 'file-spreadsheet'],
        'numbers' => ['spreadsheet.png', 'file-spreadsheet'],
        'csv' => ['spreadsheet.png', 'file-type-csv'],

        // Presentations
        'ppt' => ['presentation.png', 'file-type-ppt'],
        'pptx' => ['presentation.png', 'presentation'],
        'odp' => ['presentation.png', 'presentation'],
        'key' => ['presentation.png', 'presentation'],
    ];

    /** The generic icons for an extension EXTENSION_ICONS does not recognise. */
    private const GENERIC_ICONS = ['file.png', 'file'];

    /**
     * Get the icon resource path for a given file extension
     *
     * @param string|null $extension File extension (without dot), any case
     * @param string $style STYLE_COLOR (a PNG) or STYLE_OUTLINE (an SVG)
     * @return string Path to the icon file, relative to the project root
     */
    public static function get_icon_resource_by_file_extension($extension, string $style = self::STYLE_COLOR)
    {
        $icons = self::EXTENSION_ICONS[strtolower((string) $extension)] ?? self::GENERIC_ICONS;

        if ($style === self::STYLE_COLOR) {
            return self::ICON_DIR . '/' . $icons[0];
        }

        if ($style === self::STYLE_OUTLINE) {
            return self::ICON_DIR . '/outline/' . $icons[1] . '.svg';
        }

        throw new Rsx_Caller_Exception("Unknown file icon style '{$style}' - expected '" . self::STYLE_COLOR . "' or '" . self::STYLE_OUTLINE . "'");
    }

    /**
     * Get the outline icon for a file extension as SVG markup
     *
     * SECURITY: the markup is a framework-owned file under resource/icons/outline/, read as
     * text and returned verbatim. It never reaches ImageMagick (whose SVG coder stays disabled
     * - see the class docblock), and no uploaded byte can ever be selected here: the extension
     * only picks a key in EXTENSION_ICONS, and an unknown one gets the generic icon.
     *
     * @param string|null $extension File extension (without dot), any case
     * @return string The SVG document: 24x24 viewBox, strokes in currentColor, no fills
     */
    public static function get_outline_icon_svg($extension): string
    {
        return self::__read_outline_icon(basename(static::get_icon_resource_by_file_extension($extension, self::STYLE_OUTLINE), '.svg'));
    }

    /**
     * The outline map and artwork as the <File_Type_Icon> component consumes them
     *
     * BundleCompiler bakes this into every bundle (File_Type_Icon._define()), so the component
     * renders inline with no request per icon, from the same map the server resolves with.
     * Each distinct SVG appears once, keyed by icon name; the arrays are sorted so two
     * identical checkouts emit identical bundle bytes.
     *
     * @return array{extensions: array<string, string>, generic: string, icons: array<string, string>}
     */
    public static function get_outline_icon_payload(): array
    {
        $extensions = [];
        $icons = [];

        foreach (self::EXTENSION_ICONS as $extension => $pair) {
            $extensions[$extension] = $pair[1];
            $icons[$pair[1]] = true;
        }
        $icons[self::GENERIC_ICONS[1]] = true;

        ksort($extensions);
        ksort($icons);

        foreach (array_keys($icons) as $name) {
            $icons[$name] = self::__read_outline_icon($name);
        }

        return [
            'extensions' => $extensions,
            'generic' => self::GENERIC_ICONS[1],
            'icons' => $icons,
        ];
    }

    /**
     * Read one vendored outline icon by name.
     */
    private static function __read_outline_icon(string $name): string
    {
        $full_path = dirname(base_path()) . '/' . self::ICON_DIR . '/outline/' . $name . '.svg';

        if (!is_file($full_path)) {
            shouldnt_happen("Outline file type icon missing from the framework tree: {$full_path}");
        }

        return file_get_contents($full_path);
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

        Imagick_Policy::assert_safe();

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

            Imagick_Policy::assert_safe();

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
        Imagick_Policy::assert_safe();

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
