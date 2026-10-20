<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Rsx_Document_Renderer_Abstract;
use App\RSpade\Core\Files\Spreadsheet_Rendition;

/**
 * Spreadsheet_Document_Renderer - a workbook to its HTML grid rendition (Spreadsheet_Rendition,
 * through PhpSpreadsheet, in-process).
 *
 * The one renderer whose rendition is HTML rather than a PDF: a spreadsheet has no pages, and a
 * PDF of one is LibreOffice's print view, not the grid - Spreadsheet_Rendition has the
 * argument. It is served by File_Preview_Controller::sheet_rendition and shown by
 * Spreadsheet_Viewer. No binary is spawned, so it needs no LibreOffice and is never switched
 * off by rsx.libreoffice.enabled.
 *
 * Soft failures: the source blob unreadable.
 */
class Spreadsheet_Document_Renderer extends Rsx_Document_Renderer_Abstract
{
    /**
     * @param string $source_path
     * @param string $target_path
     * @param string|null $extension Unused: the reader detects the workbook format from its bytes.
     * @return void
     * @throws \Exception
     */
    public static function render(string $source_path, string $target_path, ?string $extension): void
    {
        Spreadsheet_Rendition::render($source_path, $target_path);
    }

    /**
     * @return string
     */
    public static function rendition_format(): string
    {
        return 'html';
    }

    /**
     * @param string $source_path
     * @return string|null
     */
    public static function soft_failure(string $source_path): ?string
    {
        return static::source_unreadable($source_path);
    }
}
