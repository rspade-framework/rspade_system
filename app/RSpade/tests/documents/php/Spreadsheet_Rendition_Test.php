<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Documents\Php;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\RSpade\Core\Files\Spreadsheet_Rendition;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A workbook renders to its HTML grid even when the rendition cache directory does not
 * exist yet - the state of every box after rsx:clean, and of a fresh install before its
 * first render. Pure logic, no DB.
 */
class Spreadsheet_Rendition_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_the_rendition_directory_is_created_on_first_render()
    {
        $root = sys_get_temp_dir() . '/rsx_sheet_' . bin2hex(random_bytes(6));
        $source = $root . '/book.xlsx';
        $target = $root . '/renditions/missing/book.html';

        mkdir($root, 0755, true);

        $book = new Spreadsheet();
        $book->getActiveSheet()->setCellValue('A1', 'Segment');
        $book->getActiveSheet()->setCellValue('B1', 'Country');
        $book->getActiveSheet()->setCellValue('A2', 'Government');
        $book->getActiveSheet()->setCellValue('B2', 'Canada');
        (new Xlsx($book))->save($source);
        $book->disconnectWorksheets();

        try {
            static::__assert_false(is_dir(dirname($target)), 'the rendition directory does not exist before the render');

            Spreadsheet_Rendition::render($source, $target);

            static::__assert_true(is_file($target), 'the rendition was written into a directory the render created');

            $html = (string) file_get_contents($target);
            static::__assert_contains('Government', $html, 'the grid carries the cell values');
            static::__assert_contains('<table', $html, 'the rendition is a table');
            static::__assert_false(str_contains(strtolower($html), '<script'), 'the rendition carries no script');
        } finally {
            foreach ([$target, $source] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ([dirname($target), dirname(dirname($target)), $root] as $dir) {
                if (is_dir($dir)) {
                    rmdir($dir);
                }
            }
        }
    }

    /**
     * Render a workbook built by $build and return the rendition's HTML.
     */
    private static function __rendition(callable $build): string
    {
        $root = sys_get_temp_dir() . '/rsx_sheet_' . bin2hex(random_bytes(6));
        $source = $root . '/book.xlsx';
        $target = $root . '/book.html';

        mkdir($root, 0755, true);

        $book = new Spreadsheet();
        $build($book);
        (new Xlsx($book))->save($source);
        $book->disconnectWorksheets();

        try {
            Spreadsheet_Rendition::render($source, $target);

            return (string) file_get_contents($target);
        } finally {
            foreach ([$target, $source] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($root);
        }
    }

    public static function test_a_formula_shows_its_stored_result_never_its_text()
    {
        $html = static::__rendition(function (Spreadsheet $book) {
            $sheet = $book->getActiveSheet();
            $sheet->setCellValue('A1', 40);
            $sheet->setCellValue('A2', 2);
            // The writer stores the computed result beside the formula, as Excel does.
            $sheet->setCellValue('A3', '=SUM(A1:A2)');
            $sheet->getStyle('A3')->getNumberFormat()->setFormatCode('"$"#,##0.00');
        });

        static::__assert_contains('$42.00', $html, 'the stored result, in the cell\'s number format');
        static::__assert_false(str_contains($html, '=SUM'), 'the formula text is not shown');
    }

    public static function test_a_formula_with_no_stored_result_shows_the_placeholder()
    {
        $root = sys_get_temp_dir() . '/rsx_sheet_' . bin2hex(random_bytes(6));
        $source = $root . '/book.xlsx';
        $target = $root . '/book.html';

        mkdir($root, 0755, true);

        $book = new Spreadsheet();
        $book->getActiveSheet()->setCellValue('A1', '=SUM(B1:B2)');
        $writer = new Xlsx($book);
        // A generator that computes nothing writes the formula with no result beside it.
        $writer->setPreCalculateFormulas(false);
        $writer->save($source);
        $book->disconnectWorksheets();

        try {
            Spreadsheet_Rendition::render($source, $target);
            $html = (string) file_get_contents($target);

            static::__assert_false(str_contains($html, '=SUM'), 'the formula text is not shown');
            static::__assert_contains(
                '<div>' . Spreadsheet_Rendition::NO_RESULT_PLACEHOLDER . '</div>',
                $html,
                'the cell shows the placeholder'
            );
        } finally {
            foreach ([$target, $source] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($root);
        }
    }

    public static function test_columns_keep_the_workbook_widths_and_the_table_states_their_sum()
    {
        $html = static::__rendition(function (Spreadsheet $book) {
            $sheet = $book->getActiveSheet();
            $sheet->setCellValue('A1', 'Date');
            $sheet->setCellValue('B1', 'Description');
            $sheet->setCellValue('C1', 'Amount');
            // The sheet-wide default sizes A and C; B states its own.
            $sheet->getDefaultColumnDimension()->setWidth(10);
            $sheet->getColumnDimension('B')->setWidth(60);
        });

        preg_match_all('/<col style="width:([0-9.]+)pt;">/', $html, $cols);
        $widths = array_map('floatval', $cols[1]);

        static::__assert_count(3, $widths, 'one sized column per sheet column');
        static::__assert_equals($widths[0], $widths[2], 'the default-width columns agree');
        static::__assert_greater_than($widths[0] * 5, $widths[1], 'the wide column is drawn wide, not narrowed');

        preg_match('/<table style="[^"]*width:([0-9.]+)pt;/', $html, $table);
        static::__assert_equals_approx(array_sum($widths), (float) $table[1], 0.02, 'the table is as wide as its columns');
        static::__assert_contains('table-layout: fixed', $html, 'and its layout is fixed, so the viewer cannot refit them');
    }

    public static function test_a_sized_row_clips_its_cells_at_the_workbook_height()
    {
        $html = static::__rendition(function (Spreadsheet $book) {
            $sheet = $book->getActiveSheet();
            $sheet->setCellValue('A1', 'One line');
            $sheet->setCellValue('A2', 'Tall row');
            $sheet->setCellValue('A3', 'Unsized row');
            $sheet->getRowDimension(1)->setRowHeight(15);
            $sheet->getRowDimension(2)->setRowHeight(60);
        });

        static::__assert_contains('<div style="max-height:15pt;">One line</div>', $html, 'clipped at the 15pt row');
        static::__assert_contains('<div style="max-height:60pt;">Tall row</div>', $html, 'clipped at the 60pt row');
        static::__assert_contains('<div>Unsized row</div>', $html, 'an unsized row is left to the stylesheet cap');
        static::__assert_contains('max-height: ' . Spreadsheet_Rendition::AUTO_ROW_MAX_PIXELS . 'px', $html);
    }

    public static function test_the_rendition_is_a_document_with_grid_lines_and_no_sheet_index()
    {
        $html = static::__rendition(function (Spreadsheet $book) {
            $book->getActiveSheet()->setTitle('Ledger')->setCellValue('A1', 'Value');
            $book->createSheet()->setTitle('Archive')->setCellValue('A1', 'Old value');
        });

        static::__assert_true(str_starts_with($html, '<!DOCTYPE html>'), 'a complete document');
        static::__assert_contains('td, th { border: 1px solid #d4d4d4;', $html, 'light grid lines behind every cell');
        static::__assert_equals(2, substr_count($html, '<table'), 'one grid per sheet');
        static::__assert_false(str_contains($html, 'Ledger'), 'the sheet index is not left behind as stray text');
        static::__assert_false(str_contains(strtolower($html), '<script'), 'and it carries no script');
    }

    public static function test_a_cell_wraps_only_when_the_workbook_wraps_it()
    {
        $html = static::__rendition(function (Spreadsheet $book) {
            $sheet = $book->getActiveSheet();
            $sheet->setCellValue('A1', 'Wrapped note');
            $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
            $sheet->setCellValue('A2', 'Plain note');
        });

        static::__assert_contains('<div class="wrap">Wrapped note</div>', $html, 'the wrapping cell is marked, its text intact');
        static::__assert_contains('<div>Plain note</div>', $html, 'every other cell stays on one line');
        static::__assert_contains('white-space: nowrap', $html, 'which is the stylesheet default');
        static::__assert_false(str_contains($html, "\u{2063}"), 'no marker character reaches the document');
    }

    public static function test_one_line_text_runs_on_over_empty_cells_and_stops_at_content()
    {
        $long = 'A description far longer than its own narrow column can hold';

        $html = static::__rendition(function (Spreadsheet $book) use ($long) {
            $sheet = $book->getActiveSheet();
            $sheet->getDefaultColumnDimension()->setWidth(10);

            // Row 1: empty cells to the right, then content in D.
            $sheet->setCellValue('A1', $long);
            $sheet->setCellValue('D1', 'Stop');

            // Row 2: the very next cell holds something.
            $sheet->setCellValue('A2', $long);
            $sheet->setCellValue('B2', 'Next');

            // Row 3: a long number never runs on.
            $sheet->setCellValue('A3', 123456789012345);
        });

        preg_match('/<col style="width:([0-9.]+)pt;">/', $html, $col);
        $column = (float) $col[1];

        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);
        [$first, $second, $third] = $rows[1];

        preg_match('/class="run"><div style="max-width:([0-9.]+)pt;">/', $first, $run);
        static::__assert_equals_approx($column * 3, (float) ($run[1] ?? 0), 0.05, 'reaches across B and C and stops at D');
        static::__assert_equals(2, substr_count($first, 'class="under"'), 'the two cells it crosses lose the grid line under it');

        static::__assert_false(str_contains($second, 'class="run"'), 'a filled neighbour leaves the text cut at its own cell');
        static::__assert_false(str_contains($third, 'class="run"'), 'a number is cut at its own cell');
    }

    public static function test_a_named_font_gets_a_sans_serif_fallback()
    {
        $html = static::__rendition(function (Spreadsheet $book) {
            $sheet = $book->getActiveSheet();
            $sheet->setCellValue('A1', 'Value');
            $sheet->getStyle('A1')->getFont()->setName('Aptos Narrow');
        });

        static::__assert_contains(
            "font-family:'Aptos Narrow',Arial,Helvetica,sans-serif",
            $html,
            'the workbook font first, then the sans-serif fallback'
        );
        static::__assert_contains('font-family: Arial,Helvetica,sans-serif', $html, 'and the document default is sans-serif too');
    }
}
