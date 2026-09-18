<?php

namespace App\RSpade\Core\Search;

use Exception;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use App\RSpade\Core\Files\Document_Sandbox;
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

            // ONE sanctioned timeout bounds every external document binary this pipeline invokes -
            // soffice and pdftotext alike. Both are the same shape (an external process that can wedge
            // on malformed input and never return), so they share one number rather than carrying a
            // second, separately-argued one. Justified in full at the config key.
            $command = Document_Sandbox::command([$binary, '-enc', 'UTF-8', $staged, '-'], $work_dir);
            $process = new Process($command);
            $process->setTimeout((int) config('rsx.libreoffice.timeout', 120));

            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                // Expiry kills the process Symfony started - which under the sandbox is the docker
                // CLIENT, leaving the wedged converter running in its container.
                $container = Document_Sandbox::container_name($command);
                if ($container !== null) {
                    Document_Sandbox::kill($container);
                }

                throw $e;
            }

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
            static::__rmdir_recursive($work_dir);
        }
    }

    /**
     * Recursively remove a directory tree (best-effort cleanup of the temp work dir).
     *
     * @param string $dir
     * @return void
     */
    protected static function __rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                static::__rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
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
}
