<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use RuntimeException;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * The ImageMagick security policy, verified by experiment.
 *
 * ImageMagick's SVG/MSVG coder follows references inside a document (an
 * <image href="text:/etc/passwd"> rasterises a local file), MVG and MSL are its own drawing
 * and scripting languages, and TEXT/LABEL turn a file or a string into an image. The
 * framework never asks for any of them, so the shipped policy
 * (system/app/RSpade/resource/docker/imagemagick/policy.xml) allows raster coders only.
 *
 * A policy cannot be inspected from PHP - Imagick::queryFormats() lists a coder whether or
 * not the policy lets it read - so each forbidden coder is PROBED with a harmless input: a
 * read the policy refuses throws "not allowed by the security policy", and any other
 * outcome means the coder is live.
 *
 * assert_safe() is the guard every framework Imagick call site runs first. The probe costs
 * a few milliseconds and runs once per process; a process that never touches Imagick never
 * pays for it. rsx:health's "ImageMagick Coder Policy" row reports the same probe.
 */
class Imagick_Policy
{
    /** The coders the policy must refuse. */
    public const FORBIDDEN_CODERS = ['SVG', 'MSVG', 'MVG', 'MSL', 'TEXT', 'LABEL'];

    /** This process's verdict: null until probed, then the readable forbidden coders. */
    private static ?array $verdict = null;

    /** Test seam: a verdict to use instead of probing. */
    public static ?array $verdict_for_tests = null;

    /**
     * Throw unless the loaded ImageMagick policy refuses every forbidden coder.
     *
     * @throws RuntimeException naming the readable coders and the remedy
     */
    public static function assert_safe(): void
    {
        $readable = static::$verdict_for_tests ?? (static::$verdict ??= static::readable_forbidden_coders());

        if (empty($readable)) {
            return;
        }

        throw new RuntimeException(
            'Refusing to process an image: the loaded ImageMagick security policy permits the '
            . implode(', ', $readable) . ' coder(s), through which an uploaded document can '
            . 'rasterise local files. Install system/app/RSpade/resource/docker/imagemagick/policy.xml '
            . 'as the ImageMagick policy (the RSpade docker image does this). '
            . 'See: php artisan rsx:man file_upload'
        );
    }

    /**
     * Probe every forbidden coder and return the ones the loaded policy lets read.
     *
     * @return string[] Empty when the policy is correct
     */
    public static function readable_forbidden_coders(): array
    {
        $dir = Rsx_Project_Paths::tmp_path('imagick_policy_' . bin2hex(random_bytes(6)));
        ensure_directory($dir);

        $inputs = [
            'SVG' => ['svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'],
            'MSVG' => ['msvg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'],
            'MVG' => ['mvg', "viewbox 0 0 1 1\n"],
            // MSL is probed with an ABSENT script: the coder's policy check runs before the
            // file is opened, and a real MSL script is a program - one ImageMagick 6 build
            // segfaults on the smallest well-formed script there is.
            'MSL' => ['msl', false],
            'TEXT' => ['text', "x\n"],
            'LABEL' => ['label', null],
        ];

        $readable = [];

        try {
            foreach ($inputs as $coder => [$prefix, $body]) {
                if ($body === null) {
                    $spec = $prefix . ':x';
                } elseif ($body === false) {
                    $spec = $prefix . ':' . $dir . '/absent.' . $prefix;
                } else {
                    $path = $dir . '/probe.' . $prefix;
                    file_put_contents($path, $body);
                    $spec = $prefix . ':' . $path;
                }

                try {
                    $image = new \Imagick();
                    $image->readImage($spec);
                    $image->clear();
                    $readable[] = $coder;
                } catch (\Throwable $e) {
                    if (!str_contains($e->getMessage(), 'security policy')) {
                        $readable[] = $coder;
                    }
                }
            }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }

        return $readable;
    }
}
