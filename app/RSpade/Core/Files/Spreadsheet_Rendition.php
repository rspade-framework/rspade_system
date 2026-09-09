<?php

namespace App\RSpade\Core\Files;

use PhpOffice\PhpSpreadsheet\Reader\Exception as Reader_Exception;
use PhpOffice\PhpSpreadsheet\Writer\Html as Html_Writer;
use Exception;
use HTMLPurifier;
use HTMLPurifier_Config;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\RSpade\Core\Files\Spreadsheet_Preview_Window;

/**
 * Spreadsheet_Rendition - a workbook previewed as a GRID, not as a print-out.
 *
 * WHY THIS EXISTS. Every other document type renders through headless LibreOffice to a PDF,
 * and for a word-processing document that is exactly right - a page is what the document IS.
 * A spreadsheet has no pages. Asked for a PDF, LibreOffice applies its PRINT model: page
 * breaks through the middle of the data, the print area only, and by default no gridlines and
 * no row or column headers. The result is faithful to a print-out nobody wanted and looks
 * nothing like the thing being previewed. Exporting a PNG instead changes nothing - the print
 * model survives the output format, because the format was never the problem.
 *
 * So a spreadsheet renders to HTML instead, through PhpSpreadsheet, which is a declared
 * framework dependency and reads the workbook IN-PROCESS: no soffice spawn, no external
 * binary that can wedge, no 120-second timeout.
 *
 * FORMULAS ARE NEVER EVALUATED (setPreCalculateFormulas(false)). A spreadsheet file stores
 * the CACHED RESULT of every formula next to the formula itself - it is what the application
 * that saved the file last computed. A preview shows those values, which is exactly what the
 * author last saw. Evaluating instead would mean running a calculation engine over untrusted
 * input to reproduce a number the file already contains, and getting it subtly wrong for every
 * function PhpSpreadsheet implements differently from Excel.
 *
 * THE WINDOW IS BOUNDED BEFORE THE READ, not after. A read filter (Spreadsheet_Preview_Window)
 * tells the reader which cells to materialize, so a 200 MB workbook costs the memory of the
 * window rather than the memory of the workbook. A preview does not need row 40,000.
 *
 * THE OUTPUT IS SANITIZED AND IS SERVED SANDBOXED. Cell values are untrusted input that
 * arrives inside generated markup, so the HTML is purified at GENERATION time - the file on
 * disk is already safe - and Spreadsheet_Viewer additionally renders it in a sandboxed iframe
 * with no script and no same-origin access. Either measure alone would do; a preview surface
 * that displays other people's files gets both.
 *
 * WHAT IT DOES NOT RENDER: charts. PhpSpreadsheet needs a chart renderer (JpGraph) that this
 * framework does not vendor, so a chart is absent rather than wrong. Images ARE embedded, as
 * base64 data URIs, which is what keeps the rendition a SINGLE self-contained file and lets
 * the store keep its one-file-per-blob shape.
 */
class Spreadsheet_Rendition
{
    /**
     * Rows and columns materialized for the preview. A grid this size is more than a reader
     * takes in at a glance and is the point at which "preview" stops being the right word.
     */
    public const MAX_ROWS = 200;
    public const MAX_COLUMNS = 60;

    /** Worksheets rendered. A workbook with more is previewed by its first few. */
    public const MAX_SHEETS = 10;

    /**
     * Is this blob one this class renders? The mime list mirrors rsx.preview.viewers, which
     * routes the same set to Spreadsheet_Viewer.
     *
     * @param string $mime
     * @return bool
     */
    public static function handles_mime(string $mime): bool
    {
        foreach (static::mime_patterns() as $pattern) {
            if (fnmatch($pattern, $mime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this the extension of a workbook? The render worker asks with the blob's
     * representative extension, which is what it already resolves to choose a converter.
     *
     * @param string|null $extension
     * @return bool
     */
    public static function handles_extension(?string $extension): bool
    {
        if ($extension === null || $extension === '') {
            return false;
        }

        $mime = config('rsx.files.document_mime_by_extension', [])[strtolower($extension)] ?? null;

        return $mime !== null && static::handles_mime($mime);
    }

    /**
     * The spreadsheet mimes, in ONE place - the render worker, the preview controller and the
     * thumbnail suppression all ask here rather than each carrying a copy of the list.
     *
     * @return array<int,string>
     */
    public static function mime_patterns(): array
    {
        return [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.*',
            'application/vnd.oasis.opendocument.spreadsheet*',
        ];
    }

    /**
     * Render a workbook to a self-contained, sanitized HTML file.
     *
     * @param string $source_path Absolute path to the workbook.
     * @param string $target_path Absolute path to write (…/rsx-renditions/{hash}.html).
     * @return void
     * @throws Exception on a workbook that cannot be read or a rendition that cannot be written.
     */
    public static function render(string $source_path, string $target_path): void
    {
        try {
            $reader = IOFactory::createReaderForFile($source_path);
            $reader->setReadFilter(new Spreadsheet_Preview_Window(static::MAX_ROWS, static::MAX_COLUMNS));
            $spreadsheet = $reader->load($source_path);
        } catch (Reader_Exception $e) {
            throw new Exception('Spreadsheet could not be read: ' . $e->getMessage());
        }

        try {
            // Only the first few sheets are worth a preview, and each one costs a full table.
            while ($spreadsheet->getSheetCount() > static::MAX_SHEETS) {
                $spreadsheet->removeSheetByIndex($spreadsheet->getSheetCount() - 1);
            }

            $writer = new Html_Writer($spreadsheet);
            $writer->writeAllSheets();

            // Cached results, never a calculation pass - see the class docblock.
            $writer->setPreCalculateFormulas(false);

            // Inline styles rather than a <style> block: a stylesheet cannot be purified
            // attribute by attribute, and inline declarations can.
            $writer->setUseInlineCss(true);

            // Pictures as base64 data URIs, so the rendition is ONE file.
            $writer->setEmbedImages(true);

            $html = $writer->generateHtmlAll();
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        $safe = static::__purify($html);

        if (file_put_contents($target_path, $safe) === false) {
            throw new Exception('Failed to write spreadsheet rendition to ' . $target_path);
        }
    }

    /**
     * Strip everything executable while keeping everything that makes it look like a
     * spreadsheet.
     *
     * safe_html() is the WRONG tool here and is deliberately not reused: its allow-list carries
     * no `style` attribute and no `data:` scheme, so it would remove every fill, border, font
     * and embedded picture - which is the entire fidelity this class exists to deliver.
     *
     * @param string $html
     * @return string
     */
    protected static function __purify(string $html): string
    {
        require_once base_path('vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php');

        $config = HTMLPurifier_Config::createDefault();

        $cache_dir = storage_path('rsx-tmp/htmlpurifier');
        ensure_directory($cache_dir);
        $config->set('Cache.SerializerPath', $cache_dir);
        $config->set('Cache.SerializerPermissions', null);

        // The grid, and nothing else. No script, no form, no iframe, no link, no object.
        $config->set('HTML.Allowed', implode(',', [
            'table[style]',
            'thead', 'tbody', 'tfoot',
            'tr[style]',
            'th[style|colspan|rowspan]',
            'td[style|colspan|rowspan]',
            'col[style|span]', 'colgroup[style|span]',
            'div[style]', 'span[style]',
            'b', 'strong', 'i', 'em', 'u', 's', 'sub', 'sup', 'br', 'p[style]',
            'h1', 'h2', 'h3',
            'img[src|alt|width|height|style]',
        ]));

        // Presentation only - no positioning, so nothing can be moved out of its cell or over
        // the surrounding page.
        $config->set('CSS.AllowedProperties', [
            'color', 'background-color',
            'font-family', 'font-size', 'font-weight', 'font-style',
            'text-align', 'vertical-align', 'text-decoration', 'white-space',
            'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
            'border-color', 'border-style', 'border-width', 'border-collapse',
            'width', 'height', 'max-width',
            'padding', 'margin',
        ]);

        // data: is what carries the embedded pictures; http(s) so an externally referenced one
        // still resolves rather than becoming a broken element.
        $config->set('URI.AllowedSchemes', ['data' => true, 'http' => true, 'https' => true]);

        return (new HTMLPurifier($config))->purify($html);
    }
}
