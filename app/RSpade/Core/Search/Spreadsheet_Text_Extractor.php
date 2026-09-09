<?php

namespace App\RSpade\Core\Search;

use PhpOffice\PhpSpreadsheet\Reader\Exception as Reader_Exception;
use Exception;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\RSpade\Core\Search\Rsx_Extraction_Unsupported_Exception;
use App\RSpade\Core\Search\Rsx_Text_Extractor_Abstract;

/**
 * Spreadsheet_Text_Extractor - the WORDS in a workbook, without LibreOffice.
 *
 * Replaces Libreoffice_Text_Extractor for spreadsheet mimes. Two reasons, and the second is
 * the one that mattered:
 *
 *   - PhpSpreadsheet is a DECLARED framework dependency, so this runs in-process. No soffice
 *     spawn, no #[Exclusive] worker slot, no 120-second external-binary timeout.
 *   - It decouples a spreadsheet's TEXT from its RENDITION. Spreadsheets no longer produce a
 *     PDF (a paginated print view of a grid is not a preview of a grid - see
 *     Spreadsheet_Rendition), and if extraction still went through soffice, dropping that
 *     rendition would have cost the search index.
 *
 * WHAT IS EXCLUDED, AND WHY (owner ruling 2026-09-09): numbers, dates and currency. A search
 * index is for WORDS. The numeric content of a workbook is the part a full-text index serves
 * worst - thousands of figures no one will ever search for by value, diluting the terms that
 * do matter and inflating every row in _search_indexes. The labels are the searchable part:
 * sheet names, headers, row captions, notes.
 *
 * A DATE OR A CURRENCY AMOUNT IS ALREADY A NUMBER underneath - a spreadsheet stores both as a
 * numeric serial with a display FORMAT - so excluding numerics excludes them by construction,
 * with no format inspection and no locale guessing. The only extra work is a string that was
 * TYPED to look like a number ("$1,234.00" entered as text), which the predicate below catches.
 *
 * FORMULAS ARE NEVER EVALUATED. A formula cell yields its CACHED result - the value the
 * spreadsheet application last computed and stored in the file - through
 * getOldCalculatedValue(). Evaluating instead would mean running a calculation engine over
 * untrusted input during an indexing pass, to produce a value the file already contains.
 */
class Spreadsheet_Text_Extractor extends Rsx_Text_Extractor_Abstract
{
    /**
     * Hard ceiling on cells VISITED, so a pathological workbook cannot turn an indexing pass
     * into an unbounded walk. It is not a text cap - max_text_bytes below is - it bounds the
     * WALK. Generous enough that a real document is never truncated by it.
     */
    protected const MAX_CELLS_SCANNED = 500000;

    /**
     * @param string $source_path
     * @param string $mime
     * @return string
     * @throws Exception
     */
    public static function extract(string $source_path, string $mime): string
    {
        $max_bytes = (int) config('rsx.search.max_text_bytes', 2 * 1024 * 1024);

        try {
            $reader = IOFactory::createReaderForFile($source_path);

            // Values only: no styles, no drawings, no charts. Nothing below reads a format,
            // and not building the style tables is the difference between a workbook that
            // indexes in memory and one that does not.
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($source_path);
        } catch (Reader_Exception $e) {
            // An encrypted or password-protected workbook is structurally unextractable: a
            // retry can never help, so it is UNSUPPORTED with its reason rather than FAILED.
            throw new Rsx_Extraction_Unsupported_Exception(
                'Spreadsheet could not be read: ' . $e->getMessage()
            );
        }

        $parts = [];
        $bytes = 0;
        $cells_scanned = 0;

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // The sheet NAME is authored text and is often the most useful term in the file.
            $title = trim((string) $sheet->getTitle());
            if ($title !== '' && !static::__looks_numeric($title)) {
                $parts[] = $title;
                $bytes += strlen($title) + 1;
            }

            foreach ($sheet->getRowIterator() as $row) {
                $cell_iterator = $row->getCellIterator();

                // Skip empty cells outright - iterating them is the bulk of the walk on a
                // sparse sheet and none of them can contribute a word.
                $cell_iterator->setIterateOnlyExistingCells(true);

                foreach ($cell_iterator as $cell) {
                    if (++$cells_scanned > static::MAX_CELLS_SCANNED || $bytes >= $max_bytes) {
                        break 3;
                    }

                    $value = $cell->getDataType() === DataType::TYPE_FORMULA
                        ? $cell->getOldCalculatedValue()
                        : $cell->getValue();

                    if (!is_string($value)) {
                        // int, float, bool, null - and therefore every date and every currency
                        // amount, which are numbers wearing a format.
                        continue;
                    }

                    $value = trim($value);

                    if ($value === '' || static::__looks_numeric($value) || static::__looks_like_date($value)) {
                        continue;
                    }

                    $parts[] = $value;
                    $bytes += strlen($value) + 1;
                }
            }
        }

        // Release the workbook before the caller does anything else with the result: a large
        // one is the biggest allocation in the indexing pass.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $text = implode("\n", $parts);

        if (strlen($text) > $max_bytes) {
            // The service compares filesize() against the cap to record truncation; this keeps
            // the returned text within it either way.
            $text = substr($text, 0, $max_bytes);
        }

        return static::__to_utf8($text);
    }

    /**
     * Is this string just a number wearing punctuation?
     *
     * Covers what a person types into a cell and what a cached formula result looks like when
     * it arrives as a string: a currency symbol either side, thousands separators, a percent,
     * a leading sign, and the accountant's parenthesised negative. Deliberately CONSERVATIVE -
     * anything carrying a letter is a word and is kept, so "Q1 2026" and "12 boxes" survive.
     *
     * @param string $value Already trimmed.
     * @return bool
     */
    protected static function __looks_numeric(string $value): bool
    {
        if (is_numeric($value)) {
            return true;
        }

        // A leading or trailing currency symbol, optional sign, digits with optional grouping
        // and decimals, an optional trailing percent - and the whole thing optionally wrapped
        // in parentheses to mean negative.
        $pattern = '/^\(?\s*[-+]?\s*[\p{Sc}]?\s*\d[\d,\s\']*(?:[.,]\d+)?\s*[\p{Sc}%]?\s*\)?$/u';

        return (bool) preg_match($pattern, $value);
    }

    /**
     * Is this string a bare date?
     *
     * A cell FORMATTED as a date holds a numeric serial and is already excluded by
     * __looks_numeric(). This is the other case: a date TYPED as text, which arrives as a
     * string and would otherwise be indexed as a word.
     *
     * Digits and separators ONLY, so nothing carrying a letter can match - "Q1 2026",
     * "March", "15 Jan 2026" and "FY2026" are all words and are all kept. No attempt is made
     * to decide whether the parts form a real calendar date, or which of them is the month:
     * either reading is a date and both are excluded, so the ambiguity that makes date
     * parsing hard does not arise here.
     *
     * @param string $value Already trimmed.
     * @return bool
     */
    protected static function __looks_like_date(string $value): bool
    {
        // 2026-01-15 | 15/01/2026 | 1.15.26 - two separators of one kind, digits between.
        if (preg_match('/^\d{1,4}([-\/.])\d{1,2}\1\d{1,4}$/', $value)) {
            return true;
        }

        // The same with a clock time after it, which is what a datetime cell pasted as text
        // looks like: 2026-01-15 14:30 | 2026-01-15T14:30:00.
        return (bool) preg_match('/^\d{1,4}([-\/.])\d{1,2}\1\d{1,4}[ T]\d{1,2}:\d{2}(:\d{2})?/', $value);
    }

    /**
     * Scrub to valid UTF-8 - a workbook can carry any byte sequence a cell was pasted from.
     *
     * @param string $text
     * @return string
     */
    protected static function __to_utf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
