<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Exception;
use App\RSpade\Core\Files\Document_Sandbox;
use App\RSpade\Core\Files\Document_Soft_Checks;
use App\RSpade\Core\Files\Document_Stage_Bound;
use App\RSpade\Core\Files\Libreoffice;
use App\RSpade\Core\Files\Rsx_Document_Renderer_Abstract;

/**
 * Libreoffice_Document_Renderer - an Office or OpenDocument document (Word, PowerPoint,
 * OpenDocument text and presentations, RTF) to a PDF rendition through headless LibreOffice.
 *
 * Switched by rsx.libreoffice.enabled: off, a document that owes this rendition waits in the
 * queue, uncharged, for the day it is switched back on. soffice runs on the host or inside the
 * document sandbox (Document_Sandbox, rsx.libreoffice.sandbox) - the same argv either way.
 *
 * Soft failures: soffice not installed or not executable on the host; under the sandbox, the
 * docker client or the sandbox image missing; the source blob unreadable.
 */
class Libreoffice_Document_Renderer extends Rsx_Document_Renderer_Abstract
{
    /**
     * Convert the source document to PDF via headless LibreOffice, publishing atomically at
     * $target_path. Fail loud.
     *
     * @param string $source_path Resident blob path (extensionless, content-addressed).
     * @param string $target_path Destination path for the published PDF rendition.
     * @param string|null $extension The representative attachment's real extension.
     * @return void
     * @throws Exception
     */
    public static function render(string $source_path, string $target_path, ?string $extension): void
    {
        // Sandboxed, the binary name resolves on the image's PATH and there is nothing to
        // discover on the host; unsandboxed, it is the host path discovery finds.
        if (Document_Sandbox::is_docker()) {
            $soffice = 'soffice';
        } else {
            $soffice = Libreoffice::find_soffice();
            if ($soffice === null) {
                throw new Exception('LibreOffice (soffice) is not installed or not configured');
            }
        }

        // Lazy-create the rendition cache directory.
        $dir = dirname($target_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Private work dir - LibreOffice needs an isolated user profile and an output dir.
        $work_dir = sys_get_temp_dir() . '/rsx_soffice_pdf_' . bin2hex(random_bytes(8));
        if (!mkdir($work_dir, 0700, true) && !is_dir($work_dir)) {
            throw new Exception("Failed to create LibreOffice work dir: {$work_dir}");
        }

        try {
            // Stage the extensionless blob under its real extension so soffice reliably detects the
            // input format; soffice names its output after this basename.
            $safe_ext = preg_replace('/[^a-z0-9]/i', '', (string) $extension);
            if ($safe_ext === '') {
                $safe_ext = 'bin';
            }
            $staged = $work_dir . '/source.' . $safe_ext;
            if (!copy($source_path, $staged)) {
                throw new Exception('Failed to stage source document for PDF rendition');
            }

            // Every path here is inside the work dir, which the sandbox mounts at its own
            // absolute path - so ONE argv is correct both on the host and in the container.
            $command = Document_Sandbox::command([
                $soffice,
                '-env:UserInstallation=file://' . $work_dir . '/profile',
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $work_dir,
                $staged,
            ], $work_dir);

            // soffice runs inside the render stage and is bounded with it: a converter that
            // wedges on a malformed document is stopped when the stage's limit expires.
            $process = Document_Stage_Bound::run_process($command);

            if (!$process->isSuccessful()) {
                throw new Exception('LibreOffice PDF conversion failed: ' . trim($process->getErrorOutput()));
            }

            $pdf_path = $work_dir . '/source.pdf';
            if (!file_exists($pdf_path)) {
                throw new Exception('LibreOffice produced no PDF rendition');
            }

            // Atomic publish: copy into a temp file BESIDE the target (same filesystem), then
            // rename over the destination. The work dir may be on a different filesystem, so a
            // direct rename from there is not guaranteed atomic.
            $tmp = $target_path . '.tmp.' . bin2hex(random_bytes(4));
            if (!copy($pdf_path, $tmp)) {
                throw new Exception('Failed to write rendition temp file');
            }
            if (!rename($tmp, $target_path)) {
                @unlink($tmp);
                throw new Exception('Failed to publish rendition file');
            }
        } finally {
            rmdir_recursive($work_dir);
        }
    }

    /**
     * rsx.libreoffice.enabled - the master switch for every soffice rendition.
     *
     * @return bool
     */
    public static function is_enabled(): bool
    {
        return (bool) config('rsx.libreoffice.enabled', true);
    }

    /**
     * soffice cannot be run here (Document_Soft_Checks::soffice_unavailable()), or the source
     * cannot be read.
     *
     * @param string $source_path
     * @return string|null
     */
    public static function soft_failure(string $source_path): ?string
    {
        return Document_Soft_Checks::soffice_unavailable() ?? static::source_unreadable($source_path);
    }
}
