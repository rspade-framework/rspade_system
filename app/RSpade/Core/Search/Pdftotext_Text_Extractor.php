<?php

namespace App\RSpade\Core\Search;

use Exception;
use App\RSpade\Core\Files\Document_Sandbox;
use App\RSpade\Core\Files\Document_Soft_Checks;
use App\RSpade\Core\Files\Document_Stage_Bound;
use App\RSpade\Core\Search\Rsx_Extraction_Unsupported_Exception;
use App\RSpade\Core\Search\Rsx_Text_Extractor_Abstract;

/**
 * Pdftotext_Text_Extractor - extracts the text layer of a PDF via poppler's `pdftotext`.
 *
 * Runs `pdftotext -enc UTF-8 <path> -` (output to stdout). A PDF whose pages are all images has
 * no text layer and yields empty output - that is a VALID EXTRACTED result (OCR is out of v1
 * scope), NOT a failure. Only a missing binary or a non-zero pdftotext exit is a failure (throw).
 *
 * A password-protected (encrypted) PDF is UNSUPPORTED, not FAILED: pdftotext exits non-zero with
 * "Command Line Error: Incorrect password" on stderr. That is structural (a retry never helps),
 * so this extractor throws Rsx_Extraction_Unsupported_Exception and the service records it
 * UNSUPPORTED with the reason instead of polluting the failed-audit signal.
 */
class Pdftotext_Text_Extractor extends Rsx_Text_Extractor_Abstract
{
    /**
     * @param string $source_path
     * @param string $mime
     * @return string
     * @throws Rsx_Extraction_Unsupported_Exception
     * @throws Exception
     */
    public static function extract(string $source_path, string $mime): string
    {
        // Sandboxed, the binary name resolves on the image's PATH and there is nothing to
        // discover on the host; unsandboxed, it is the host path discovery finds.
        if (Document_Sandbox::is_docker()) {
            $binary = 'pdftotext';
        } else {
            $binary = static::__find_pdftotext();
            if ($binary === null) {
                throw new Exception('pdftotext (poppler-utils) is not installed or not configured');
            }
        }

        // Private work dir - the source is STAGED here rather than read where it lies, because
        // the sandbox is given this one directory and nothing else. Mounting the storage tree (or
        // the rendition cache) into the container would defeat the point of having one. The same
        // staging runs unsandboxed, so there is one code path.
        $work_dir = sys_get_temp_dir() . '/rsx_pdftotext_' . bin2hex(random_bytes(8));
        if (!mkdir($work_dir, 0700, true) && !is_dir($work_dir)) {
            throw new Exception("Failed to create pdftotext work dir: {$work_dir}");
        }

        try {
            $staged = $work_dir . '/' . basename($source_path);
            if (!copy($source_path, $staged)) {
                throw new Exception('Failed to stage PDF for text extraction');
            }

            // pdftotext runs inside the text-extraction stage and is bounded with it.
            $command = Document_Sandbox::command([$binary, '-enc', 'UTF-8', $staged, '-'], $work_dir);
            $process = Document_Stage_Bound::run_process($command);

            if (!$process->isSuccessful()) {
                $stderr = trim($process->getErrorOutput());

                // Encrypted / password-protected PDF: structurally unextractable, not a failure.
                if (stripos($stderr, 'incorrect password') !== false) {
                    throw new Rsx_Extraction_Unsupported_Exception('password-protected PDF');
                }

                throw new Exception('pdftotext failed for ' . basename($source_path) . ': ' . $stderr);
            }

            // Empty output = a PDF with no text layer (all-image). Valid EXTRACTED result.
            return $process->getOutput();
        } finally {
            rmdir_recursive($work_dir);
        }
    }

    /**
     * Locate the pdftotext binary: explicit config path first, then PATH, then common install
     * locations. Mirrors Libreoffice::find_soffice(). Returns null if unavailable.
     *
     * @return string|null
     */
    protected static function __find_pdftotext(): ?string
    {
        $configured = config('rsx.search.pdftotext_binary_path');
        if (!empty($configured)) {
            return is_executable($configured) ? $configured : null;
        }

        $found = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v pdftotext 2>/dev/null')));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * pdftotext cannot be run here - sandboxed, the docker client or the sandbox image is
     * missing (Document_Soft_Checks::sandbox_unavailable()); on the host, no executable
     * pdftotext is found - or the source cannot be read.
     *
     * @param string $source_path
     * @return string|null
     */
    public static function soft_failure(string $source_path): ?string
    {
        if (Document_Sandbox::is_docker()) {
            $tool = Document_Soft_Checks::sandbox_unavailable();
        } else {
            $tool = static::__find_pdftotext() === null
                ? 'pdftotext (poppler-utils) is not installed or not executable'
                : null;
        }

        return $tool ?? static::source_unreadable($source_path);
    }
}
