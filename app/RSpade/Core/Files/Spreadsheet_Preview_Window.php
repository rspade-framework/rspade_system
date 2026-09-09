<?php

namespace App\RSpade\Core\Files;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Spreadsheet_Preview_Window - bound the read BEFORE it happens.
 *
 * A PhpSpreadsheet read filter is consulted per cell as the file is parsed, so a cell outside
 * the window is never materialized at all. That is the difference between previewing a large
 * workbook and running the indexing pass out of memory: cost is proportional to the WINDOW,
 * not to the file.
 *
 * Reading everything and truncating afterwards would allocate the whole workbook first, which
 * is exactly the failure this avoids.
 *
 * #[Instantiatable] because PhpSpreadsheet's contract is an OBJECT - the reader calls
 * readCell() on the instance it was given - and each instance carries its own window. That is
 * a distinct object, not the utility-class-with-a-constructor shape MANIFEST-INST-01 exists to
 * refuse.
 */
#[Instantiatable]
class Spreadsheet_Preview_Window implements IReadFilter
{
    /** @var int */
    protected $max_rows;

    /** @var int Highest column INDEX kept (1-based, as PhpSpreadsheet numbers them). */
    protected $max_column_index;

    /**
     * @param int $max_rows
     * @param int $max_columns
     */
    public function __construct(int $max_rows, int $max_columns)
    {
        $this->max_rows = $max_rows;
        $this->max_column_index = $max_columns;
    }

    /**
     * @param string $columnAddress Column letter(s) - 'A', 'AB', …
     * @param int $row
     * @param string $worksheetName
     * @return bool
     */
    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        if ($row > $this->max_rows) {
            return false;
        }

        return static::__column_index($columnAddress) <= $this->max_column_index;
    }

    /**
     * Column letters to a 1-based index: A=1, Z=26, AA=27.
     *
     * Done here rather than through Coordinate::columnIndexFromString() because this runs once
     * per cell of the file being parsed - the hottest path in the read - and the conversion is
     * three lines.
     *
     * @param string $column_address
     * @return int
     */
    protected static function __column_index(string $column_address): int
    {
        $index = 0;

        for ($i = 0, $length = strlen($column_address); $i < $length; $i++) {
            $index = ($index * 26) + (ord(strtoupper($column_address[$i])) - 64);
        }

        return $index;
    }
}
