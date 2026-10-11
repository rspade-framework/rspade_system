<?php

namespace App\RSpade\Core\Files;

use PhpOffice\PhpSpreadsheet\Reader\Exception as Reader_Exception;
use PhpOffice\PhpSpreadsheet\Writer\Html as Html_Writer;
use DOMDocument;
use DOMElement;
use Exception;
use HTMLPurifier;
use HTMLPurifier_Config;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use App\RSpade\Core\Files\Spreadsheet_Preview_Window;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

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
 * FORMULAS ARE NEVER EVALUATED. A spreadsheet file stores the CACHED RESULT of every formula
 * next to the formula itself - it is what the application that saved the file last computed.
 * Before the grid is written, every formula cell is replaced by that stored result
 * (__show_cached_results), so a preview shows exactly what the author last saw. Evaluating
 * instead would mean running a calculation engine over untrusted input to reproduce a number
 * the file already contains, and getting it subtly wrong for every function PhpSpreadsheet
 * implements differently from Excel. A formula the file stores no result for (some
 * generators write none) shows NO_RESULT_PLACEHOLDER rather than its formula text.
 *
 * THE WORKBOOK'S GEOMETRY IS THE GEOMETRY. Every column is drawn at exactly the width the
 * workbook gives it and every row the workbook sizes at exactly that height; the grid is
 * never fitted to the viewer, and the document scrolls in both directions instead. A row the
 * workbook leaves to size itself grows with its content up to AUTO_ROW_MAX_PIXELS, so one
 * long note cannot push the rest of the grid out of sight. Light grid lines sit behind every
 * cell, and the workbook's own borders draw over them.
 *
 * TEXT BEHAVES AS IT DOES ON THE SHEET. A cell wraps only when the workbook turns wrapping on
 * for it. Otherwise its content stays on one line: left-aligned text runs on across the
 * empty cells to its right and stops at the first cell that holds something, and anything
 * else is cut at its own cell's edge. The writer does not say which cells wrap, so
 * __mark_wrapping_cells() carries that across in the cell's own text (WRAP_MARKER) and
 * __lay_out_grid() takes it out again.
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

    /** The tallest, in CSS pixels, a row grows when the workbook states no height for it. */
    public const AUTO_ROW_MAX_PIXELS = 100;

    /**
     * Prefixed to the text of a cell the workbook wraps, between reading the workbook and
     * laying out the grid. Three invisible characters no real cell begins with.
     */
    private const WRAP_MARKER = "\u{2063}\u{2060}\u{2063}";

    /**
     * Average glyph width as a fraction of the font size, for estimating how far a line of
     * text runs. Only decides which grid lines a run-on line hides; a little generous, so a
     * line is never struck through.
     */
    private const GLYPH_WIDTH_EM = 0.6;

    /** Font size, in points, assumed for a cell that states none. */
    private const DEFAULT_FONT_POINTS = 10;

    /** The generic sans-serif stack placed after every font the workbook names; also the document's own font. */
    public const FONT_STACK = 'Arial,Helvetica,sans-serif';

    /** Shown in a formula cell whose file carries no stored result. */
    public const NO_RESULT_PLACEHOLDER = "\u{2014}";

    /**
     * The stylesheet of the rendition document. Authored here and added AFTER purification,
     * so it is the one part of the document that is not derived from the workbook. A cell's
     * inline style (the workbook's own border, fill, font) outranks every rule in it.
     */
    private const DOCUMENT_STYLE = <<<'CSS'
        html, body { margin: 0; padding: 0; background: #ffffff; color: #000000; }
        body { padding: 8px; font-family: {font_stack}; font-size: 10pt; }
        table { border-collapse: collapse; table-layout: fixed; margin: 0 0 16px 0; }
        td, th { border: 1px solid #d4d4d4; padding: 0; overflow: hidden; }
        td > div, th > div {
            max-height: {auto_row_max}px; padding: 2px 4px; overflow: hidden;
            white-space: nowrap; box-sizing: border-box;
        }
        td > div.wrap, th > div.wrap { white-space: normal; overflow-wrap: break-word; }
        td.run, th.run { overflow: visible; }
        td.run > div, th.run > div { width: max-content; }
        td.under, th.under { border-left-style: hidden; }
        img { max-width: 100%; }
        CSS;

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
            // The glob takes the macro-enabled and template variants with the classic .xls.
            'application/vnd.ms-excel*',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.*',
            'application/vnd.oasis.opendocument.spreadsheet*',
        ];
    }

    /**
     * Render a workbook to a self-contained, sanitized HTML file.
     *
     * @param string $source_path Absolute path to the workbook.
     * @param string $target_path Absolute path to write (…/renditions/{hash}.html).
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

            static::__show_cached_results($spreadsheet);
            static::__apply_sheet_defaults($spreadsheet);
            static::__mark_wrapping_cells($spreadsheet);

            $writer = new Html_Writer($spreadsheet);
            $writer->writeAllSheets();

            // Never a calculation pass - see the class docblock. Every formula cell already
            // holds its stored result as a plain value, so there is nothing left to compute.
            $writer->setPreCalculateFormulas(false);

            // The writer's sheet index is a list of in-page links; the sanitizer keeps no links,
            // which would leave the sheet names behind as stray text above the first grid.
            $writer->setGenerateSheetNavigationBlock(false);

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

        $safe = static::__as_document(static::__lay_out_grid(static::__with_font_stack(static::__purify($html))));

        // The rendition cache is a regenerable tree under tmp/ and may not exist yet - the
        // first render after rsx:clean is the ordinary case.
        ensure_directory(dirname($target_path));

        if (file_put_contents($target_path, $safe) === false) {
            throw new Exception('Failed to write spreadsheet rendition to ' . $target_path);
        }
    }

    /**
     * Replace every formula cell with the result the file stores for it.
     *
     * The stored result keeps the cell's number format, so a currency formula still prints as
     * currency. A formula with no stored result becomes NO_RESULT_PLACEHOLDER.
     *
     * @param Spreadsheet $spreadsheet
     * @return void
     */
    protected static function __show_cached_results(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getCoordinates() as $coordinate) {
                $cell = $sheet->getCell($coordinate);

                if ($cell->getDataType() !== DataType::TYPE_FORMULA) {
                    continue;
                }

                $result = $cell->getOldCalculatedValue();

                if ($result === null || $result === '') {
                    $cell->setValueExplicit(static::NO_RESULT_PLACEHOLDER, DataType::TYPE_STRING);
                } elseif (is_bool($result)) {
                    $cell->setValueExplicit($result, DataType::TYPE_BOOL);
                } elseif (is_int($result) || is_float($result) || (is_string($result) && is_numeric($result))) {
                    $cell->setValueExplicit($result, DataType::TYPE_NUMERIC);
                } else {
                    $cell->setValueExplicit((string) $result, DataType::TYPE_STRING);
                }
            }
        }
    }

    /**
     * State each sheet's default column width and row height on the columns and rows that
     * rely on it.
     *
     * A workbook sizes most of its grid by ONE default ("every column is this wide unless it
     * says otherwise"), and the writer draws only the sizes stated on a column or row itself,
     * substituting a width of its own for the rest. Copying the sheet's default onto each
     * unsized column and row is what makes the written grid the workbook's. A sheet with no
     * fixed default row height leaves its rows to size themselves.
     *
     * @param Spreadsheet $spreadsheet
     * @return void
     */
    protected static function __apply_sheet_defaults(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $default_width = $sheet->getDefaultColumnDimension()->getWidth();

            if ($default_width > 0) {
                $last_column = Coordinate::columnIndexFromString($sheet->getHighestColumn());

                for ($index = 1; $index <= $last_column; $index++) {
                    $dimension = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index));

                    if ($dimension->getWidth() < 0) {
                        $dimension->setWidth($default_width);
                    }
                }
            }

            $default_height = $sheet->getDefaultRowDimension()->getRowHeight();

            if ($default_height > 0) {
                $last_row = $sheet->getHighestRow();

                for ($row = 1; $row <= $last_row; $row++) {
                    $dimension = $sheet->getRowDimension($row);

                    if ($dimension->getRowHeight() < 0) {
                        $dimension->setRowHeight($default_height);
                    }
                }
            }
        }
    }

    /**
     * Prefix WRAP_MARKER to every text cell the workbook wraps.
     *
     * Runs after __show_cached_results(), so a formula whose result is text is covered too.
     * Rich-text cells are left alone and so show on one line.
     *
     * @param Spreadsheet $spreadsheet
     * @return void
     */
    protected static function __mark_wrapping_cells(Spreadsheet $spreadsheet): void
    {
        $text_types = [DataType::TYPE_STRING, DataType::TYPE_STRING2, DataType::TYPE_INLINE];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getCoordinates() as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                $value = $cell->getValue();

                if (!is_string($value) || $value === '' || !in_array($cell->getDataType(), $text_types, true)) {
                    continue;
                }

                if ($cell->getStyle()->getAlignment()->getWrapText()) {
                    $cell->setValueExplicit(static::WRAP_MARKER . $value, DataType::TYPE_STRING);
                }
            }
        }
    }

    /**
     * Make the purified grid keep the workbook's geometry and its text behaviour.
     *
     * The writer already states each column's width (on <col>) and each sized row's height (on
     * <tr>). A browser honours neither on its own: an auto-layout table refits its columns to
     * the viewer, and a table cell always grows to its content. So each table gets the sum of
     * its columns as an explicit width - which is what makes a fixed-layout table keep them -
     * and each cell's content moves into a block that is clipped at its row's height, or at
     * AUTO_ROW_MAX_PIXELS in a row the workbook does not size.
     *
     * That block is also where a cell's text behaviour is set: `wrap` for a cell carrying
     * WRAP_MARKER, and for left-aligned one-line text a width reaching across the empty cells
     * to its right (see __run_on()).
     *
     * @param string $html The purified fragment.
     * @return string The fragment, re-serialized.
     */
    protected static function __lay_out_grid(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        // The fragment is already well-formed (the purifier wrote it); the wrapper names the
        // encoding and gives the parser one root to hand back.
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="rsx-grid">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        foreach (iterator_to_array($document->getElementsByTagName('table')) as $table) {
            /** @var DOMElement $table */
            $widths = [];

            foreach ($table->getElementsByTagName('col') as $col) {
                $widths[] = static::__points($col->getAttribute('style'), 'width');
            }

            $every_column_sized = $widths !== [] && !in_array(null, $widths, true);

            if ($every_column_sized) {
                $table->setAttribute('style', $table->getAttribute('style') . 'width:' . round(array_sum($widths), 2) . 'pt;');
            }

            // Columns still covered by a cell merged down from an earlier row: column => rows left.
            $covered = [];

            foreach (iterator_to_array($table->getElementsByTagName('tr')) as $row) {
                /** @var DOMElement $row */
                $row_points = static::__points($row->getAttribute('style'), 'height');
                $cells = [];
                $column = 0;

                foreach (iterator_to_array($row->childNodes) as $cell) {
                    if (!($cell instanceof DOMElement) || !in_array($cell->tagName, ['td', 'th'], true)) {
                        continue;
                    }

                    while (($covered[$column] ?? 0) > 0) {
                        $column++;
                    }

                    $colspan = max(1, (int) $cell->getAttribute('colspan'));
                    $rowspan = max(1, (int) $cell->getAttribute('rowspan'));

                    $content = $document->createElement('div');
                    $wraps = static::__take_wrap_marker($cell);

                    $styles = [];

                    // A cell merged down several rows is as tall as all of them; only a
                    // single-row cell is clipped at its own row's height.
                    if ($row_points !== null && $rowspan === 1) {
                        $styles[] = 'max-height:' . $row_points . 'pt';
                    }

                    if ($wraps) {
                        $content->setAttribute('class', 'wrap');
                    }

                    while ($cell->firstChild) {
                        $content->appendChild($cell->firstChild);
                    }

                    $cell->appendChild($content);

                    $cells[] = [
                        'cell' => $cell,
                        'content' => $content,
                        'styles' => $styles,
                        'column' => $column,
                        'colspan' => $colspan,
                        'wraps' => $wraps,
                    ];

                    for ($offset = 0; $offset < $colspan; $offset++) {
                        if ($rowspan > 1) {
                            $covered[$column + $offset] = $rowspan;
                        }
                    }

                    $column += $colspan;
                }

                if ($every_column_sized) {
                    static::__run_on($cells, $widths, $covered);
                }

                foreach ($cells as $entry) {
                    if ($entry['styles']) {
                        $entry['content']->setAttribute('style', implode(';', $entry['styles']) . ';');
                    }
                }

                foreach ($covered as $index => $rows_left) {
                    $covered[$index] = $rows_left - 1;
                }
            }
        }

        $root = $document->getElementById('rsx-grid');
        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * Remove WRAP_MARKER from a cell's text and say whether it was there.
     *
     * @param DOMElement $cell
     * @return bool True when the workbook wraps this cell.
     */
    protected static function __take_wrap_marker(DOMElement $cell): bool
    {
        if (!str_contains($cell->textContent, static::WRAP_MARKER)) {
            return false;
        }

        $pending = [$cell];

        while ($pending) {
            $node = array_pop($pending);

            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $child->nodeValue = str_replace(static::WRAP_MARKER, '', $child->nodeValue);
                } else {
                    $pending[] = $child;
                }
            }
        }

        return true;
    }

    /**
     * Let one-line, left-aligned text run on across the empty cells to its right.
     *
     * On a sheet, text that is not wrapped and does not fit its cell continues over the
     * neighbouring cells for as long as they are empty, and is cut at the first one that
     * holds something. Here the cell's content block is widened to that reach (and clipped
     * there), the cell is marked `run` so the block may leave it, and the empty cells the
     * text is estimated to cover are marked `under`, which hides the grid line it crosses.
     *
     * Right-aligned and centred content (every number among it) is left to be cut at its
     * own cell's edge.
     *
     * @param array $cells   The row's cells in order, as __lay_out_grid() collects them;
     *                       each entry's 'styles' is appended to.
     * @param array $widths  Column widths in points, by column index.
     * @param array $covered Columns occupied by a cell merged down from an earlier row.
     * @return void
     */
    protected static function __run_on(array &$cells, array $widths, array $covered): void
    {
        foreach ($cells as $index => &$entry) {
            /** @var DOMElement $cell */
            $cell = $entry['cell'];
            $text = trim(str_replace("\u{00A0}", ' ', $cell->textContent));
            $style = $cell->getAttribute('style');

            if ($entry['wraps'] || $text === '' || preg_match('/text-align\s*:\s*(right|center)/i', $style)) {
                continue;
            }

            if ($cell->getElementsByTagName('img')->length > 0) {
                continue;
            }

            $own = 0.0;

            for ($offset = 0; $offset < $entry['colspan']; $offset++) {
                $own += $widths[$entry['column'] + $offset] ?? 0.0;
            }

            $font = static::__points($style, 'font-size') ?? static::DEFAULT_FONT_POINTS;
            $needed = mb_strlen($text) * $font * static::GLYPH_WIDTH_EM;

            if ($needed <= $own) {
                continue;
            }

            // Walk right over empty cells, stopping at content, at a merged-down cell or at
            // the end of the row.
            $reach = $own;
            $expected_column = $entry['column'] + $entry['colspan'];
            $crossed = [];

            for ($next = $index + 1; $next < count($cells); $next++) {
                $neighbour = $cells[$next];

                if ($neighbour['column'] !== $expected_column || ($covered[$expected_column] ?? 0) > 0) {
                    break;
                }

                $neighbour_text = trim(str_replace("\u{00A0}", ' ', $neighbour['cell']->textContent));

                if ($neighbour_text !== '' || $neighbour['cell']->getElementsByTagName('img')->length > 0) {
                    break;
                }

                if ($reach < $needed) {
                    $crossed[] = $neighbour['cell'];
                }

                for ($offset = 0; $offset < $neighbour['colspan']; $offset++) {
                    $reach += $widths[$neighbour['column'] + $offset] ?? 0.0;
                }

                $expected_column += $neighbour['colspan'];
            }

            if ($reach <= $own) {
                continue;
            }

            $cell->setAttribute('class', trim($cell->getAttribute('class') . ' run'));
            $entry['styles'][] = 'max-width:' . round($reach, 2) . 'pt';

            foreach ($crossed as $neighbour_cell) {
                $neighbour_cell->setAttribute('class', trim($neighbour_cell->getAttribute('class') . ' under'));
            }
        }

        unset($entry);
    }

    /**
     * One length, in points, read out of an inline style.
     *
     * @param string $style    The element's style attribute ("width:42pt;").
     * @param string $property 'width' or 'height'.
     * @return float|null Null when the style does not state it in pt or px.
     */
    protected static function __points(string $style, string $property): ?float
    {
        if (!preg_match('/(?:^|;)\s*' . $property . '\s*:\s*([0-9.]+)\s*(pt|px)/i', $style, $match)) {
            return null;
        }

        return strtolower($match[2]) === 'px' ? (float) $match[1] * 72 / 96 : (float) $match[1];
    }

    /**
     * Follow every font the workbook names with FONT_STACK.
     *
     * A workbook names the font it was authored in, which the reader's machine often does not
     * have (the current Office default among them). With nothing after it in the list a
     * browser substitutes its own default, a serif face, and the grid stops looking like a
     * spreadsheet. The workbook's font still wins wherever it is installed.
     *
     * @param string $html The purified fragment.
     * @return string
     */
    protected static function __with_font_stack(string $html): string
    {
        return (string) preg_replace_callback(
            '/font-family\s*:\s*([^;"]+)/i',
            function (array $match) {
                if (preg_match('/\b(sans-serif|serif|monospace)\s*$/i', trim($match[1]))) {
                    return $match[0];
                }

                return 'font-family:' . trim($match[1]) . ',' . static::FONT_STACK;
            },
            $html
        );
    }

    /**
     * Wrap the grid in a complete HTML document carrying the rendition stylesheet.
     *
     * @param string $body
     * @return string
     */
    protected static function __as_document(string $body): string
    {
        return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n<style>\n"
            . strtr(static::DOCUMENT_STYLE, [
                '{auto_row_max}' => (string) static::AUTO_ROW_MAX_PIXELS,
                '{font_stack}' => static::FONT_STACK,
            ])
            . "\n</style>\n</head>\n<body>\n" . $body . "\n</body>\n</html>\n";
    }

    /**
     * Strip everything executable while keeping everything that makes it look like a
     * spreadsheet.
     *
     * sanitize_rich_text_html() is the WRONG tool here and is deliberately not reused: its allow-list carries
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

        $cache_dir = Rsx_Project_Paths::htmlpurifier_dir();
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
