<?php

namespace App\RSpade\Core\Files;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Imagick_Policy;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Face_Detection - where is the face in a stored image, for face-centred cover thumbnails.
 *
 * The detector is a small Python script over OpenCV's YuNet model, installed in the RSpade
 * container image (config rsx.thumbnails.face_detection: python, script, model). It is run as
 * argv [python, script, model, image] and answers with exactly one JSON line on stdout; the
 * contract is the header of resource/docker/face_detection/detect_face.py.
 *
 * THE ANSWER IS STORED ON THE BLOB (_file_storage.face_x / face_y), so the detector runs once
 * per distinct image, the first time a face-centred thumbnail of it is asked for:
 *
 *   found                x, y stored (fractions of the upright image)
 *   none / failed        -1, -1 stored: a WORKING detector found no face, or could not read
 *                        the image - either way the plain centre crop is right for it
 *   unavailable          NOTHING stored, and a warning logged. The detector could not run (not
 *                        installed, OpenCV or the model would not load, or it malfunctioned),
 *                        which says nothing about the image, so the next request asks again.
 *
 * A run is judged ONLY by its exit code and its stdout line. stderr is never read for the
 * verdict (an image decoder may print warnings there on a run that answered correctly); it is
 * quoted in the warning when the run is judged unavailable. Exit 0 with found / none / failed is
 * an answer, exit 3 is the script's own "unavailable", and anything else - another exit code,
 * no line, an unparseable line, an answer out of range - is a malfunction, treated as
 * unavailable.
 *
 * THE DETECTOR READS JPEG AND PNG. Any other image (HEIC, HEIF, TIFF, GIF, WebP...) is handed
 * to it as an upright JPEG copy drawn by ImageMagick (_upright_jpeg_copy()), deleted after the
 * run; one ImageMagick cannot read either is answered "failed" (no face) without a run.
 *
 * NO TIMEOUT: the detector runs for as long as the image takes (engineering mandate).
 */
class Face_Detection
{
    /** The detector script's exit code for "OpenCV or the model could not be loaded". */
    public const EXIT_UNAVAILABLE = 3;

    /**
     * Cache-Control max-age of a face-centred request answered with the plain thumbnail
     * because the detector is unavailable: long enough that a page of avatars does not re-ask
     * on every view, short enough that a repaired detector is used within half an hour.
     */
    public const UNAVAILABLE_MAX_AGE_SECONDS = 1800;

    /** Mimes the detector's OpenCV reads itself; every other image is handed over converted. */
    public const DETECTOR_READABLE_MIMES = ['image/jpeg', 'image/png'];

    /** The detector scales an image's long side down to this; a converted copy is made no larger. */
    public const DETECTOR_MAX_SIDE = 1280;

    /**
     * The face centre of a stored image, detecting it on first ask.
     *
     * @param File_Storage_Model $storage The blob the image is (the answer is stored on it).
     * @param string $image_path The blob's ORIGINAL image file, never a thumbnail of it.
     * @return array{0: float, 1: float}|null [x, y] fractions of the upright image; [-1.0, -1.0]
     *                                         when it has no face (or is unreadable); null when
     *                                         the detector is unavailable.
     */
    public static function face_center(File_Storage_Model $storage, string $image_path): ?array
    {
        $stored = $storage->face_position();
        if ($stored !== null) {
            return $stored;
        }

        $verdict = static::detect($image_path);

        if ($verdict['status'] === 'unavailable') {
            Log::warning('Face detection unavailable: ' . $verdict['reason']);

            return null;
        }

        if ($verdict['status'] === 'found') {
            $storage->record_face_position($verdict['x'], $verdict['y']);
        } else {
            $storage->record_face_position(-1.0, -1.0);
        }

        return $storage->face_position();
    }

    /**
     * Run the detector on one image and judge the run. Stores nothing.
     *
     * @param string $image_path
     * @return array One of:
     *   ['status' => 'found', 'x' => float, 'y' => float]
     *   ['status' => 'none']
     *   ['status' => 'failed', 'error' => string]
     *   ['status' => 'unavailable', 'reason' => string]
     */
    public static function detect(string $image_path): array
    {
        $python = (string) config('rsx.thumbnails.face_detection.python');
        $script = (string) config('rsx.thumbnails.face_detection.script');
        $model = (string) config('rsx.thumbnails.face_detection.model');

        if ($python === '' || !is_file($python) || !is_executable($python)) {
            return static::__unavailable("the detector's python is not installed at '{$python}' (rsx.thumbnails.face_detection.python)");
        }
        if ($script === '' || !is_file($script)) {
            return static::__unavailable("the detector script is not installed at '{$script}' (rsx.thumbnails.face_detection.script)");
        }

        // OpenCV reads JPEG (applying its EXIF orientation, as the thumbnailer does) and PNG.
        // Anything else - a HEIC from a phone, a TIFF, a GIF - is handed over as an upright
        // JPEG copy ImageMagick draws from it: the same decode and the same autoOrient() the
        // thumbnail is cut from, so a stored position lands where the thumbnail shows the face.
        $mime = (string) mime_content_type($image_path);
        $converted = null;
        if (!in_array($mime, static::DETECTOR_READABLE_MIMES, true)) {
            try {
                $converted = static::_upright_jpeg_copy($image_path);
            } catch (\Throwable $e) {
                // ImageMagick cannot read it either: the detector's own "could not read the
                // image" answer, which stores no face.
                return ['status' => 'failed', 'error' => "the image ({$mime}) could not be converted for the detector: " . $e->getMessage()];
            }
        }

        try {
            $process = new Process([$python, $script, $model, $converted ?? $image_path]);
            $process->setTimeout(null);
            $process->run();
        } finally {
            if ($converted !== null && is_file($converted)) {
                unlink($converted);
            }
        }

        return static::_judge_run($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * An upright JPEG copy of an image OpenCV cannot read, no larger than the detector would
     * scale it to anyway. The caller deletes it. Public for the tests.
     *
     * @param string $image_path
     * @return string The copy's path (a scratch file under tmp/)
     */
    public static function _upright_jpeg_copy(string $image_path): string
    {
        Imagick_Policy::assert_safe();

        $image = new \Imagick();
        $image->readImage($image_path . '[0]');
        $image->autoOrient();
        $image->stripImage();

        $long_side = max($image->getImageWidth(), $image->getImageHeight());
        if ($long_side > static::DETECTOR_MAX_SIDE) {
            $scale = static::DETECTOR_MAX_SIDE / $long_side;
            $image->resizeImage(
                max(1, (int) round($image->getImageWidth() * $scale)),
                max(1, (int) round($image->getImageHeight() * $scale)),
                \Imagick::FILTER_LANCZOS,
                1
            );
        }

        $image->setImageBackgroundColor('white');
        $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(92);

        $copy = Rsx_Project_Paths::scratch_file('face_detection_input', 'jpg');
        try {
            $image->writeImage($copy);
        } finally {
            $image->destroy();
        }

        return $copy;
    }

    /**
     * Judge one detector run from its exit code and output alone (see the class docblock).
     * Public for the tests; production code calls detect().
     *
     * @param int|null $exit_code
     * @param string $stdout
     * @param string $stderr Quoted in the reason of an unavailable verdict; never judged.
     * @return array The verdict, as detect() documents it.
     */
    public static function _judge_run(?int $exit_code, string $stdout, string $stderr): array
    {
        $line = trim($stdout);
        $answer = ($line !== '' && !str_contains($line, "\n")) ? json_decode($line, true) : null;
        $status = is_array($answer) ? ($answer['status'] ?? null) : null;

        $evidence = static::__evidence($exit_code, $line, $stderr);

        if ($exit_code === static::EXIT_UNAVAILABLE) {
            $error = is_array($answer) && is_string($answer['error'] ?? null) ? $answer['error'] : 'no reason given';

            return static::__unavailable("the detector reported it cannot run: {$error}" . $evidence);
        }

        if ($exit_code !== 0 || !is_string($status)) {
            return static::__unavailable('the detector malfunctioned' . $evidence);
        }

        if ($status === 'none') {
            return ['status' => 'none'];
        }

        if ($status === 'failed') {
            return ['status' => 'failed', 'error' => is_string($answer['error'] ?? null) ? $answer['error'] : ''];
        }

        if ($status === 'found') {
            $x = $answer['x'] ?? null;
            $y = $answer['y'] ?? null;
            if (is_int($x) || is_float($x)) {
                $x = (float) $x;
            }
            if (is_int($y) || is_float($y)) {
                $y = (float) $y;
            }
            if (is_float($x) && is_float($y) && $x >= 0.0 && $x <= 1.0 && $y >= 0.0 && $y <= 1.0) {
                return ['status' => 'found', 'x' => $x, 'y' => $y];
            }

            return static::__unavailable('the detector answered a face centre outside the image' . $evidence);
        }

        return static::__unavailable("the detector answered an unknown status '{$status}'" . $evidence);
    }

    /**
     * The top-left corner of a box cut around a face from an image.
     *
     * The box is centred on the face horizontally and puts the face 40% of the way down
     * (a portrait reads best with a little more room below the face than above it), then is
     * clamped so it never leaves the image.
     *
     *   x = clamp(fx * sw - 0.5 * bw, 0, sw - bw)
     *   y = clamp(fy * sh - 0.4 * bh, 0, sh - bh)
     *
     * @param int $source_width sw: the (scaled) image the box is cut from
     * @param int $source_height sh
     * @param int $box_width bw: never larger than sw
     * @param int $box_height bh: never larger than sh
     * @param float $face_x fx: fraction of the image width
     * @param float $face_y fy: fraction of the image height
     * @return array{0: int, 1: int} [x, y] offsets in pixels
     */
    public static function crop_offset(int $source_width, int $source_height, int $box_width, int $box_height, float $face_x, float $face_y): array
    {
        $x = (int) round($face_x * $source_width - 0.5 * $box_width);
        $y = (int) round($face_y * $source_height - 0.4 * $box_height);

        $x = max(0, min($x, $source_width - $box_width));
        $y = max(0, min($y, $source_height - $box_height));

        return [$x, $y];
    }

    /**
     * Is the face detector installed AND working? Runs it on a small synthetic image with no
     * face in it and expects a well-formed "none" - which proves Python, OpenCV and the model
     * all load and run.
     *
     * WARN, not FAIL, when it does not: a face-centred thumbnail then degrades to the plain
     * centre crop, nothing breaks.
     *
     * @return array{status: string, detail: string, remediation: ?string}
     */
    #[Health_Check('Face Detection')]
    public static function health(): array
    {
        $probe = Rsx_Project_Paths::scratch_file('face_detection_probe', 'png');

        try {
            $image = new \Imagick();
            $image->newImage(64, 48, new \ImagickPixel('#7f9fbf'));
            $image->setImageFormat('png');
            $image->writeImage($probe);
            $image->destroy();

            $verdict = static::detect($probe);
        } finally {
            if (is_file($probe)) {
                unlink($probe);
            }
        }

        return static::_health_row($verdict, static::__opencv_version());
    }

    /**
     * The health row for one probe verdict. Public for the tests.
     *
     * @param array $verdict detect()'s answer on the synthetic no-face image
     * @param string|null $opencv_version
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _health_row(array $verdict, ?string $opencv_version): array
    {
        if ($verdict['status'] === 'none') {
            return [
                'status' => 'OK',
                'detail' => 'the detector runs' . ($opencv_version !== null ? " (OpenCV {$opencv_version})" : ''),
                'remediation' => null,
            ];
        }

        $reason = $verdict['status'] === 'unavailable'
            ? $verdict['reason']
            : "a face-free probe image was answered '{$verdict['status']}'" . (isset($verdict['error']) ? ": {$verdict['error']}" : '');

        return [
            'status' => 'WARN',
            'detail' => 'face-centred thumbnails fall back to the centre crop: ' . $reason,
            'remediation' => 'Rebuild the RSpade image and recreate the container (bash system/app/RSpade/resource/docker/build.sh, then docker compose up --detach --force-recreate), or point rsx.thumbnails.face_detection at a working install',
        ];
    }

    /**
     * The OpenCV version the detector's python loads, or null when it cannot be read.
     *
     * @return string|null
     */
    private static function __opencv_version(): ?string
    {
        $python = (string) config('rsx.thumbnails.face_detection.python');
        if ($python === '' || !is_file($python) || !is_executable($python)) {
            return null;
        }

        $process = new Process([$python, '-c', 'import cv2; print(cv2.__version__)']);
        $process->setTimeout(null);
        $process->run();

        $version = trim($process->getOutput());

        return ($process->getExitCode() === 0 && preg_match('/^[0-9][0-9A-Za-z.\-]*$/', $version)) ? $version : null;
    }

    private static function __unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason];
    }

    /** The exit code, stdout line and stderr of a run, for a warning's reason. */
    private static function __evidence(?int $exit_code, string $stdout_line, string $stderr): string
    {
        $text = ' (exit ' . ($exit_code === null ? 'none' : $exit_code)
            . '; stdout: ' . ($stdout_line === '' ? '(empty)' : mb_substr($stdout_line, 0, 300));

        $stderr = trim($stderr);
        if ($stderr !== '') {
            $text .= '; stderr: ' . mb_substr($stderr, 0, 500);
        }

        return $text . ')';
    }
}
