<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Imagick;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\Imagick_Policy;

/**
 * Media_Metadata - the DISPLAYED size (and running time) of an image or a video, read from its
 * bytes. The one implementation behind File_Attachment_Model's width / height / duration.
 *
 * DISPLAYED, NOT STORED. A phone stores a portrait photo's pixels sideways and says so in an
 * EXIF orientation tag; a phone video is encoded landscape with a rotation in its display
 * matrix. Every consumer of an attachment's size (a layout reserving space, an API client, an
 * aspect-ratio box) means the picture as it is SEEN, so that is what is reported:
 *
 *   images  ImageMagick's reading, swapped for EXIF orientations 5-8 (the four that turn the
 *           picture a quarter turn) - the same rule the thumbnailer's autoOrient() applies, so
 *           a stored size and a drawn thumbnail always agree. A HEIC/HEIF is decoded upright
 *           by libheif (its rotation is a container transform, applied on read) and reports
 *           orientation 1, so it needs no swap.
 *   videos  ffprobe's first video stream: width scaled by a non-square sample aspect ratio,
 *           then swapped when the rotation (the display matrix's side data, or the older
 *           `rotate` tag) is a quarter turn. Duration is the container's, else the stream's,
 *           rounded to whole seconds.
 *
 * ffprobe (apt `ffmpeg`) is part of the RSpade image. Where it is missing nothing is guessed:
 * the probe answers 'unavailable', the caller stores nothing and logs it, and the rsx:health
 * "ffprobe" row says why.
 *
 * NO TIMEOUT: ffprobe runs for as long as the file takes (engineering mandate).
 */
class Media_Metadata
{
    /**
     * Formats ImageMagick reads as several images that are NOT an animation: layers of a
     * document (PSD, XCF), pages (TIFF), sizes of an icon (ICO), and the extra top-level
     * images a HEIC/HEIF carries beside its primary one (a burst, a thumbnail).
     */
    public const NON_ANIMATED_FORMATS = ['psd', 'tiff', 'tif', 'ico', 'xcf', 'heic', 'heif'];

    /**
     * Read an image's metadata.
     *
     * @param string $path
     * @return array{width: int, height: int, is_animated: bool, frame_count: ?int, duration: ?int}
     * @throws \ImagickException when the bytes cannot be parsed
     */
    public static function read_image(string $path): array
    {
        Imagick_Policy::assert_safe();

        $imagick = new Imagick($path);

        try {
            [$width, $height] = static::upright_size(
                $imagick->getImageWidth(),
                $imagick->getImageHeight(),
                $imagick->getImageOrientation()
            );

            $format = strtolower($imagick->getImageFormat());
            $frame_count = $imagick->getNumberImages();

            if ($frame_count > 1 && !in_array($format, static::NON_ANIMATED_FORMATS, true)) {
                // An animation (GIF, WebP, APNG): its running time is the sum of the frame
                // delays, which ImageMagick reports in hundredths of a second.
                $total_delay = 0;
                $imagick->setFirstIterator();
                do {
                    $total_delay += $imagick->getImageDelay();
                } while ($imagick->nextImage());

                return [
                    'width' => $width,
                    'height' => $height,
                    'is_animated' => true,
                    'frame_count' => $frame_count,
                    'duration' => (int) round($total_delay / 100),
                ];
            }

            return [
                'width' => $width,
                'height' => $height,
                'is_animated' => false,
                'frame_count' => in_array($format, static::NON_ANIMATED_FORMATS, true) ? null : 1,
                'duration' => null,
            ];
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    /**
     * The size an image is SEEN at, given the size its pixels are stored at and its EXIF
     * orientation (an Imagick::ORIENTATION_* value; 0 = undefined). Orientations 5-8 turn the
     * picture a quarter turn, so width and height trade places; 1-4 (and none) do not.
     *
     * @param int $width
     * @param int $height
     * @param int $orientation
     * @return array{0: int, 1: int} [width, height]
     */
    public static function upright_size(int $width, int $height, int $orientation): array
    {
        $quarter_turns = [
            Imagick::ORIENTATION_LEFTTOP,      // 5
            Imagick::ORIENTATION_RIGHTTOP,     // 6
            Imagick::ORIENTATION_RIGHTBOTTOM,  // 7
            Imagick::ORIENTATION_LEFTBOTTOM,   // 8
        ];

        return in_array($orientation, $quarter_turns, true) ? [$height, $width] : [$width, $height];
    }

    /**
     * Read a video's metadata with ffprobe.
     *
     * @param string $path
     * @return array One of:
     *   ['status' => 'ok', 'width' => ?int, 'height' => ?int, 'duration' => ?int]
     *       (width/height null when the file has no video stream - an audio-only MP4)
     *   ['status' => 'unreadable', 'reason' => string]   ffprobe ran and could not read it
     *   ['status' => 'unavailable', 'reason' => string]  ffprobe is not installed
     */
    public static function probe_video(string $path): array
    {
        $ffprobe = static::find_ffprobe();
        if ($ffprobe === null) {
            return ['status' => 'unavailable', 'reason' => static::__not_found_reason()];
        }

        $process = new Process([
            $ffprobe,
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            '-select_streams', 'v:0',
            $path,
        ]);
        $process->setTimeout(null);
        $process->run();

        return static::_judge_probe($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * Judge one ffprobe run (see probe_video() for the answers). Public for the tests.
     *
     * @param int|null $exit_code
     * @param string $stdout ffprobe's JSON
     * @param string $stderr Quoted in the reason of an unreadable answer.
     * @return array
     */
    public static function _judge_probe(?int $exit_code, string $stdout, string $stderr): array
    {
        if ($exit_code !== 0) {
            return [
                'status' => 'unreadable',
                'reason' => 'ffprobe exited ' . ($exit_code === null ? '(none)' : $exit_code) . ': ' . mb_substr(trim($stderr), 0, 500),
            ];
        }

        $probe = json_decode($stdout, true);
        if (!is_array($probe)) {
            return ['status' => 'unreadable', 'reason' => 'ffprobe printed no JSON: ' . mb_substr(trim($stdout), 0, 300)];
        }

        $stream = null;
        foreach ($probe['streams'] ?? [] as $candidate) {
            if (is_array($candidate) && ($candidate['codec_type'] ?? null) === 'video') {
                $stream = $candidate;
                break;
            }
        }

        $duration = static::__seconds($probe['format']['duration'] ?? null)
            ?? static::__seconds($stream['duration'] ?? null);

        if ($stream === null) {
            if ($duration === null) {
                return ['status' => 'unreadable', 'reason' => 'ffprobe found neither a video stream nor a duration'];
            }

            return ['status' => 'ok', 'width' => null, 'height' => null, 'duration' => $duration];
        }

        $width = (int) ($stream['width'] ?? 0);
        $height = (int) ($stream['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return ['status' => 'unreadable', 'reason' => 'ffprobe reported no frame size for the video stream'];
        }

        [$width, $height] = static::displayed_video_size(
            $width,
            $height,
            static::__rotation($stream),
            is_string($stream['sample_aspect_ratio'] ?? null) ? $stream['sample_aspect_ratio'] : null
        );

        return ['status' => 'ok', 'width' => $width, 'height' => $height, 'duration' => $duration];
    }

    /**
     * The size a video frame is SEEN at: the coded width scaled by a non-square sample aspect
     * ratio, then width and height swapped when the rotation is a quarter turn (90 or 270, in
     * either direction).
     *
     * @param int $width Coded width
     * @param int $height Coded height
     * @param int|null $rotation Degrees, any sign; null = none
     * @param string|null $sample_aspect_ratio "N:D" as ffprobe prints it; null, "0:1" or "1:1" = square
     * @return array{0: int, 1: int} [width, height]
     */
    public static function displayed_video_size(int $width, int $height, ?int $rotation, ?string $sample_aspect_ratio): array
    {
        if ($sample_aspect_ratio !== null && preg_match('/^(\d+):(\d+)$/', $sample_aspect_ratio, $m)) {
            $numerator = (int) $m[1];
            $denominator = (int) $m[2];
            if ($numerator > 0 && $denominator > 0 && $numerator !== $denominator) {
                $width = (int) round($width * $numerator / $denominator);
            }
        }

        $turn = $rotation === null ? 0 : ((($rotation % 360) + 360) % 360);

        return ($turn === 90 || $turn === 270) ? [$height, $width] : [$width, $height];
    }

    /**
     * Locate ffprobe: the configured path (rsx.attachments.ffprobe_binary_path) when one is
     * set, else PATH, else the usual install locations. Null when there is none.
     *
     * @return string|null
     */
    public static function find_ffprobe(): ?string
    {
        $configured = config('rsx.attachments.ffprobe_binary_path');
        if (!empty($configured)) {
            return is_executable($configured) ? $configured : null;
        }

        $found = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v ffprobe 2>/dev/null')));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        foreach (['/usr/bin/ffprobe', '/usr/local/bin/ffprobe'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * rsx:health: is ffprobe installed, and does it run?
     *
     * FAIL when it is missing, as the LibreOffice row does for soffice: a video's size and
     * running time cannot be read without it (they stay empty, and the reason is logged).
     *
     * @return array{status: string, detail: string, remediation: ?string}
     */
    #[Health_Check('ffprobe')]
    public static function ffprobe_available(): array
    {
        $binary = static::find_ffprobe();
        $version = null;

        if ($binary !== null) {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(null);
            $process->run();
            $first_line = strtok((string) $process->getOutput(), "\n");
            $version = ($process->getExitCode() === 0 && is_string($first_line)) ? trim(explode(' Copyright', $first_line)[0]) : '';
        }

        return static::_availability_row($binary, $version);
    }

    /**
     * The health row for what ffprobe_available() found. Public for the tests.
     *
     * @param string|null $binary The binary found, or null
     * @param string|null $version Its `-version` first line; '' when it did not run
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _availability_row(?string $binary, ?string $version): array
    {
        $remediation = 'apt-get install -y --no-install-recommends ffmpeg (the RSpade image carries it: rebuild the image and recreate the container), '
            . 'or point rsx.attachments.ffprobe_binary_path at an install';

        if ($binary === null) {
            return [
                'status' => 'FAIL',
                'detail' => 'ffprobe not found: ' . static::__not_found_reason() . ' - an uploaded video keeps no width, height or duration',
                'remediation' => $remediation,
            ];
        }

        if ($version === null || $version === '') {
            return [
                'status' => 'FAIL',
                'detail' => "ffprobe at {$binary} does not run (-version failed) - an uploaded video keeps no width, height or duration",
                'remediation' => $remediation,
            ];
        }

        return ['status' => 'OK', 'detail' => "{$version} at {$binary}", 'remediation' => null];
    }

    /**
     * A 16x16 grey HEIC (HEVC-coded), the smallest proof that this box decodes a phone photo.
     */
    private const HEIC_PROBE_BASE64 = 'AAAAHGZ0eXBoZWljAAAAAG1pZjFoZWljbWlhZgAAAXZtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAAA5waXRtAAAAAAABAAAAImlsb2MAAAAAREAAAQABAAAAAAGaAAEAAAAAAAAAHQAAACNpaW5mAAAAAAABAAAAFWluZmUCAAAAAAEAAGh2YzEAAAAA9mlwcnAAAADWaXBjbwAAAHFodmNDAQQIAAAAAAAAAAAAHvAA/Pz4+AAADwMgAAEAF0ABDAH//wQIAAADAJ/4AAADAAAeugJAIQABACZCAQEECAAAAwCf+AAAAwAAHsCCBBZbqrprmwIAAAMAAgAAAwACECIAAQAGRAHBc8GJAAAAFGlzcGUAAAAAAAAAQAAAAEAAAAAoY2xhcAAAABAAAAABAAAAEAAAAAH////QAAAAAv///9AAAAACAAAAE2NvbHJuY2x4AAEADQAGgAAAAA5waXhpAAAAAAEIAAAAGGlwbWEAAAAAAAAAAQABBYECgwQFAAAAJW1kYXQAAAAZKAGuJkcjgO82feIocxvrN4BiMNpNY8dA7A==';

    /**
     * rsx:health: does ImageMagick decode a HEIC photo? Decodes a 16x16 HEIC held in this
     * class. ImageMagick's HEIC coder (libheif) needs an HEVC decoder plugin
     * (libheif-plugin-libde265) that the imagemagick package does not install; without it a
     * HEIC upload cannot be read, so it keeps no size, no thumbnail and no preview. FAIL, as
     * the ffprobe row is.
     *
     * @return array{status: string, detail: string, remediation: ?string}
     */
    #[Health_Check('HEIC Decoding')]
    public static function heic_decoding(): array
    {
        $error = null;
        $size = null;

        try {
            Imagick_Policy::assert_safe();
            $image = new Imagick();
            $image->readImageBlob(base64_decode(static::HEIC_PROBE_BASE64));
            $size = $image->getImageWidth() . 'x' . $image->getImageHeight();
            $image->destroy();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null || $size !== '16x16') {
            return [
                'status' => 'FAIL',
                'detail' => 'ImageMagick cannot decode a HEIC photo (' . ($error ?? "read as {$size}") . ') - a HEIC upload keeps no size, thumbnail or preview',
                'remediation' => 'apt-get install -y --no-install-recommends libheif-plugin-libde265 (the RSpade image carries it: rebuild the image and recreate the container)',
            ];
        }

        return ['status' => 'OK', 'detail' => 'ImageMagick decodes HEIC/HEIF', 'remediation' => null];
    }

    /** Why find_ffprobe() found nothing, for a log line or a health row. */
    private static function __not_found_reason(): string
    {
        $configured = config('rsx.attachments.ffprobe_binary_path');

        return !empty($configured)
            ? "the configured rsx.attachments.ffprobe_binary_path '{$configured}' is not an executable"
            : 'no ffprobe on PATH, /usr/bin or /usr/local/bin';
    }

    /** A stream's rotation in degrees: the display matrix's side data, else the `rotate` tag. */
    private static function __rotation(array $stream): ?int
    {
        foreach ($stream['side_data_list'] ?? [] as $side_data) {
            if (is_array($side_data) && isset($side_data['rotation']) && is_numeric($side_data['rotation'])) {
                return (int) round((float) $side_data['rotation']);
            }
        }

        $tag = $stream['tags']['rotate'] ?? null;

        return is_numeric($tag) ? (int) round((float) $tag) : null;
    }

    /** ffprobe's "1.500000" as whole seconds, or null when absent or not a number. */
    private static function __seconds($value): ?int
    {
        if (!is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (int) round((float) $value);
    }
}
