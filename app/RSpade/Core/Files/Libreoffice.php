<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Document_Sandbox;

/**
 * Libreoffice - shared helpers for the headless LibreOffice (soffice) integration.
 *
 * Home of the single soffice-discovery routine used by the render worker
 * (Document_Render_Service) and the text extractor (Libreoffice_Text_Extractor), so binary
 * discovery has exactly one implementation. Concurrency needs no primitive: the render worker
 * is #[Exclusive] and is the only caller, so soffice invocations are serialized by construction.
 */
class Libreoffice
{
    /**
     * Locate the soffice binary: explicit config path first, then PATH, then common install
     * locations. Returns null if unavailable.
     *
     * @return string|null
     */
    public static function find_soffice(): ?string
    {
        $configured = config('rsx.libreoffice.binary_path');
        if (!empty($configured)) {
            return is_executable($configured) ? $configured : null;
        }

        $found = trim((string) shell_exec('bash -c ' . escapeshellarg('command -v soffice 2>/dev/null')));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        foreach (['/usr/bin/soffice', '/usr/local/bin/soffice', '/opt/libreoffice/program/soffice'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * rsx:health probe: is headless LibreOffice available (or intentionally disabled)?
     *
     * A public static `#[Health_Check('label')]` (bare marker attribute - never a defined
     * class) returning a row per Health_Check_Runner's contract. When the feature is
     * switched off by config it is an INFO, not a FAIL - a box that never renders Office
     * docs does not need soffice.
     *
     * @return array
     */
    #[Health_Check('LibreOffice (soffice)')]
    public static function soffice_available(): array
    {
        return static::_availability_row(
            (bool) config('rsx.libreoffice.enabled', true),
            Document_Sandbox::is_docker(),
            Document_Sandbox::image(),
            Document_Sandbox::is_docker() ? null : static::find_soffice()
        );
    }

    /**
     * The row soffice_available() reports, built from what it reports.
     *
     * UNDER THE SANDBOX THIS ROW IS ABOUT THE IMAGE, NOT THE HOST. A sandboxed box has no reason
     * to carry soffice at all - the converter lives in the container - so looking for a host
     * binary there would FAIL a correctly-configured site. The question "does soffice actually
     * run" is answered by the Document Sandbox rows, which run it.
     *
     * @param bool $enabled rsx.libreoffice.enabled
     * @param bool $sandboxed Are document binaries spawned inside a container?
     * @param string $image The configured sandbox image.
     * @param string|null $binary The discovered host binary (null when absent, or when sandboxed).
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _availability_row(bool $enabled, bool $sandboxed, string $image, ?string $binary): array
    {
        if (!$enabled) {
            return [
                'status' => 'INFO',
                'detail' => 'disabled by config (rsx.libreoffice.enabled=false)',
                'remediation' => null,
            ];
        }

        if ($sandboxed) {
            return [
                'status' => 'INFO',
                'detail' => 'sandboxed - soffice runs inside ' . $image . ', not on this host'
                    . ' (the Document Sandbox rows prove it runs)',
                'remediation' => null,
            ];
        }

        if ($binary === null) {
            return [
                'status' => 'FAIL',
                'detail' => 'soffice not found (the document render worker needs it for PDF renditions, Office thumbnails and Office text extraction)',
                'remediation' => 'apt-get install -y --no-install-recommends libreoffice',
            ];
        }

        return ['status' => 'OK', 'detail' => 'soffice at ' . $binary, 'remediation' => null];
    }
}
