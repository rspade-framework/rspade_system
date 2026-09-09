<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\Spreadsheet_Rendition;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A workbook previews as a GRID, not as a print-out.
 *
 * A spreadsheet has no pages. Asked for a PDF, LibreOffice applies its PRINT model - page
 * breaks through the middle of the data, the print area only, and by default neither gridlines
 * nor row and column headers - so the result is faithful to a print-out nobody wanted and
 * unrecognisable as the grid it is previewing. Exporting an image instead changes nothing: the
 * print model survives the output format, because the format was never the problem.
 *
 * So the spreadsheet mimes route to Spreadsheet_Viewer over an HTML rendition PhpSpreadsheet
 * produces in-process, and this pins the three properties that makes it safe and honest to
 * serve: it is routed there at all, it carries the cell values, and hostile cell content cannot
 * execute.
 */
class Spreadsheet_Preview_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var string|null */
    private static $workbook_path = null;

    /** @var string|null */
    private static $rendition_path = null;

    public static function setup(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Figures');

        $sheet->fromArray([
            ['Region', 'Revenue'],
            ['Northwest', 1234.50],
        ], null, 'A1');

        // Cell values are other people's input rendered into markup. These are the shapes that
        // matter: a script element, an event-handler attribute, and a javascript: URL.
        $sheet->setCellValue('A4', '<script>alert(1)</script>');
        $sheet->setCellValue('A5', '<img src=x onerror=alert(2)>');
        $sheet->setCellValue('A6', '<a href="javascript:alert(3)">click</a>');

        self::$workbook_path = sys_get_temp_dir() . '/rsx_preview_probe_' . uniqid() . '.xlsx';
        self::$rendition_path = self::$workbook_path . '.html';

        (new Xlsx($spreadsheet))->save(self::$workbook_path);
        $spreadsheet->disconnectWorksheets();

        Spreadsheet_Rendition::render(self::$workbook_path, self::$rendition_path);
    }

    public static function teardown(): void
    {
        foreach ([self::$workbook_path, self::$rendition_path] as $path) {
            if ($path !== null && file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The registry is an ORDERED fnmatch map and the generic Office patterns would otherwise
     * swallow these, so the ordering is the assertion.
     */
    public static function test_spreadsheet_mimes_route_to_the_spreadsheet_viewer()
    {
        $spreadsheet_mimes = [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
        ];

        foreach ($spreadsheet_mimes as $mime) {
            static::__assert_equals(
                'Spreadsheet_Viewer',
                File_Preview_Controller::viewer_for_mime($mime),
                $mime . ' previews as a grid, not as a PDF of its print layout'
            );
        }

        // The neighbours must be untouched - a word-processing document IS a page, and the PDF
        // rendition is exactly right for it.
        static::__assert_equals(
            'Pdf_Viewer',
            File_Preview_Controller::viewer_for_mime('application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'a .docx still previews through the PDF rendition'
        );
    }

    /**
     * The point of the whole exercise: the rendition is a table carrying the cell values.
     */
    public static function test_the_rendition_is_a_grid_carrying_the_values()
    {
        $html = file_get_contents(self::$rendition_path);

        static::__assert_true(str_contains($html, '<table'), 'the rendition is a table');
        static::__assert_true(str_contains($html, 'Region'), 'a header cell is present');
        static::__assert_true(str_contains($html, 'Northwest'), 'a label cell is present');

        // Unlike the search index, the PREVIEW shows numbers - it is a picture of the document.
        static::__assert_true(str_contains($html, '1234.5'), 'a numeric cell is present - a preview shows the figures');
    }

    /**
     * Hostile cell content is neutralised at GENERATION time, so the file on disk is already
     * safe. The endpoint's CSP and Spreadsheet_Viewer's sandboxed iframe are the second and
     * third layers; this pins the first.
     */
    public static function test_hostile_cell_content_cannot_execute()
    {
        $html = file_get_contents(self::$rendition_path);

        static::__assert_false(
            str_contains($html, '<script'),
            'no script element survives - the cell text is escaped, not interpreted'
        );

        // The strings themselves DO appear, as escaped text, because that is genuinely what the
        // cells contain and displaying it is correct. What must not appear is a live attribute
        // or a live href, so the test is for the markup, not for the substring.
        static::__assert_false(
            (bool) preg_match('/<[a-z]+[^>]*\son[a-z]+\s*=/i', $html),
            'no element carries an event-handler attribute'
        );

        static::__assert_false(
            (bool) preg_match('/<a\b[^>]*href/i', $html),
            'no anchor survives at all - the allow-list carries no <a>, so a javascript: URL has nothing to live on'
        );
    }
}
