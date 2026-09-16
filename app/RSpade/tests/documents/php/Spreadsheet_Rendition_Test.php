<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Documents\Php;

use App\RSpade\Core\Files\Spreadsheet_Rendition;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
}
