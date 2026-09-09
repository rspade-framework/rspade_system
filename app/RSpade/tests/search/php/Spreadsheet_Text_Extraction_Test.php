<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Search\Php;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\RSpade\Core\Search\Spreadsheet_Text_Extractor;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Spreadsheet_Text_Extractor - the WORDS of a workbook, and only the words.
 *
 * A workbook is indexed for its LABELS: sheet names, headers, row captions, notes. Its numeric
 * content is the part a full-text index serves worst - thousands of figures nobody searches by
 * value, diluting the terms that do matter (owner ruling 2026-09-09).
 *
 * A DATE AND A CURRENCY AMOUNT ARE ALREADY NUMBERS underneath - a spreadsheet stores both as a
 * numeric serial with a display format - so excluding numerics excludes them by construction.
 * What needs its own handling, and is pinned here, is the value TYPED as text that only looks
 * like a number or a date.
 *
 * It also replaces LibreOffice for these mimes, which is what lets a spreadsheet skip the PDF
 * rendition entirely without costing the search index.
 */
class Spreadsheet_Text_Extraction_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** @var string|null */
    private static $workbook_path = null;

    public static function setup(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Q1 Revenue');

        $sheet->fromArray([
            ['Region', 'Units', 'Revenue', 'Booked', 'Notes'],
            ['Northwest', 120, 1234.50, '2026-01-15', 'Renewal pending'],
            ['Southeast', 90, 987.25, '15/02/2026', 'New logo'],
            ['Total', 210, '$2,221.75', '', '12 boxes'],
        ], null, 'A1');

        $spreadsheet->createSheet()->setTitle('Assumptions')->setCellValue('A1', 'Discount policy');

        self::$workbook_path = sys_get_temp_dir() . '/rsx_extract_probe_' . uniqid() . '.xlsx';
        (new Xlsx($spreadsheet))->save(self::$workbook_path);
        $spreadsheet->disconnectWorksheets();
    }

    public static function teardown(): void
    {
        if (self::$workbook_path !== null && file_exists(self::$workbook_path)) {
            unlink(self::$workbook_path);
        }
    }

    /**
     * @return array<int,string> The extracted lines.
     */
    private static function __lines(): array
    {
        $text = Spreadsheet_Text_Extractor::extract(
            self::$workbook_path,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
    }

    public static function test_labels_and_sheet_names_are_indexed()
    {
        $lines = static::__lines();

        foreach (['Q1 Revenue', 'Assumptions', 'Region', 'Northwest', 'Renewal pending', 'Discount policy'] as $expected) {
            static::__assert_true(
                in_array($expected, $lines, true),
                'the label "' . $expected . '" is indexed - labels are what a workbook is searched by'
            );
        }
    }

    /**
     * A sheet NAME is authored text and is often the single most useful term in the file, so it
     * is indexed alongside the cells rather than dropped with the structure.
     */
    public static function test_a_value_carrying_letters_survives()
    {
        static::__assert_true(
            in_array('12 boxes', static::__lines(), true),
            '"12 boxes" is a word, not a number - the numeric filter is conservative on purpose'
        );
    }

    public static function test_numbers_currency_and_dates_are_excluded()
    {
        $lines = static::__lines();

        $excluded = [
            '120',          // a plain number
            '90',
            '1234.5',       // a decimal
            '987.25',
            '$2,221.75',    // currency TYPED as text - a formatted cell is numeric anyway
            '2026-01-15',   // an ISO date typed as text
            '15/02/2026',   // and the slash spelling
        ];

        foreach ($excluded as $value) {
            static::__assert_false(
                in_array($value, $lines, true),
                'the value "' . $value . '" is excluded - a full-text index is for words'
            );
        }
    }
}
