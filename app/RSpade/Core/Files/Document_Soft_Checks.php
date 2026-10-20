<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Document_Sandbox;
use App\RSpade\Core\Files\Libreoffice;

/**
 * Document_Soft_Checks - the environment checks behind a document renderer's or text
 * extractor's soft_failure(), each spelled once.
 *
 * Every method answers null when the thing it checks is fine, and otherwise a human reason
 * naming what is missing. Each one only LOOKS: it never runs the tool, never builds or pulls a
 * sandbox image, and never parses the document - a check is cheap enough to ask before every
 * attempt. See Rsx_Document_Renderer_Abstract::soft_failure() for what a soft failure means.
 */
class Document_Soft_Checks
{
    /**
     * Is the source file missing, or impossible to open for reading? Opens it and closes it
     * again; reads no byte of it.
     *
     * @param string $path Absolute path to the source file.
     * @return string|null The reason, or null when the file can be read.
     */
    public static function source_unreadable(string $path): ?string
    {
        if (!is_file($path)) {
            return "the source file is missing or unreadable: {$path}";
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return "the source file is missing or unreadable: {$path}";
        }
        fclose($handle);

        return null;
    }

    /**
     * When document binaries are spawned inside the sandbox (rsx.libreoffice.sandbox =
     * 'docker'): is the docker client executable, and - for an operator's own image - is the
     * image on this host? The image is looked for, never built (Document_Sandbox::image_present()).
     * The framework's own image is not looked for: the attempt builds it when it is absent.
     *
     * @return string|null The reason, or null when the sandbox is off or ready.
     */
    public static function sandbox_unavailable(): ?string
    {
        if (!Document_Sandbox::is_docker()) {
            return null;
        }

        if (!Document_Sandbox::client_present()) {
            return "the docker client is not installed or not executable (rsx.libreoffice.sandbox is 'docker')";
        }

        // An absent FRAMEWORK image is not a reason to stop: the attempt builds it
        // (Document_Sandbox::ensure_image()), and a failed build is that attempt's hard failure.
        // An operator's own image is never built, so its absence is one.
        $image = Document_Sandbox::image();
        if (!Document_Sandbox::is_framework_image($image) && !Document_Sandbox::image_present()) {
            return "the document sandbox image '{$image}' is not present on this host";
        }

        return null;
    }

    /**
     * Can soffice be run? Sandboxed, that is the sandbox's readiness (the binary lives in the
     * image); on the host, it is Libreoffice::find_soffice() finding an executable.
     *
     * @return string|null The reason, or null when soffice can be run.
     */
    public static function soffice_unavailable(): ?string
    {
        if (Document_Sandbox::is_docker()) {
            return static::sandbox_unavailable();
        }

        if (Libreoffice::find_soffice() === null) {
            return 'LibreOffice (soffice) is not installed or not executable';
        }

        return null;
    }
}
