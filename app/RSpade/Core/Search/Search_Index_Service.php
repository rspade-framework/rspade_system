<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Search;

use App\RSpade\Core\Files\Document_Stage_Bound;
use App\RSpade\Core\Files\Document_Stage_Timeout_Exception;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Search\Rsx_Extraction_Unsupported_Exception;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Search_Index_Service - document text extraction (framework core).
 *
 * The unit of work, not the queue. Document_Render_Service owns the queue and the worker: it
 * takes each blob that owes work (_file_storage.process_due_at) and calls extract_storage()
 * for the ones whose text is owed (is_indexed = 0), in the same pass that produces the preview
 * rendition. Extraction is keyed on the deduplicated blob, so identical bytes are extracted
 * exactly once regardless of how many attachments reference them.
 *
 * WHAT THE TWO FACTS MEAN. is_indexed on the blob says whether extraction is OWED; the
 * _search_indexes row says what the last attempt FOUND.
 *   - no row, is_indexed = 0      never attempted.
 *   - EXTRACTED / UNSUPPORTED     a definitive answer. is_indexed = 1, nothing repeats.
 *   - FAILED, is_indexed = 0      an attempt failed (threw, ran past its stage bound, or died
 *                                 with its process) and the worker will try again, under the
 *                                 same attempt counter as the render.
 *   - FAILED, is_indexed = 1      failed for good: the attempts are spent. Re-queued only by
 *                                 an operator (rsx:search:reindex).
 */
class Search_Index_Service extends Rsx_Service_Abstract
{
    /**
     * Extract (or record the non-extraction of) a single blob, writing its _search_indexes row.
     * This is the unit of work - called by the document worker's child process, and directly by
     * a test. A definitive answer (EXTRACTED, UNSUPPORTED) marks the blob is_indexed=1; a FAILED
     * attempt leaves is_indexed alone, so a blob that owed its text still owes it.
     *
     * Command line only: the computation runs under the stage bound (Document_Stage_Bound).
     *
     * Resolution order:
     *   1. App filter chain document.extract_text (first non-null handler wins).
     *   2. The PDF rendition shortcut, when the worker has one (see $rendition_path).
     *   3. Framework extractor registry (config rsx.search.extractors, fnmatch by mime).
     *   4. No match -> UNSUPPORTED (NOT failed - e.g. an image, a zip; OCR is out of scope).
     *
     * @param File_Storage_Model $storage
     * @param string|null $rendition_path Absolute path to this blob's PDF rendition when the render
     *                                    worker has just produced (or found) one. Word-processing
     *                                    documents are then extracted from the PDF with pdftotext
     *                                    instead of paying for a SECOND soffice run - the rendition
     *                                    is the same document and it is already on disk. Sheets and
     *                                    decks are NOT: their text lives in a grid or on slides
     *                                    that a PDF flattens badly, so they keep their dedicated
     *                                    fods/fodp conversion (spreadsheet_extraction_fix_07_21).
     * @return Search_Index_Model The written index row.
     */
    public static function extract_storage(File_Storage_Model $storage, ?string $rendition_path = null): Search_Index_Model
    {
        Document_Stage_Bound::require_cli('Search_Index_Service::extract_storage()');

        $path = $storage->get_full_path();

        // The blob should always be resident (content-addressed local cache). A missing file is a
        // genuine fault, not an unsupported format -> FAILED (fail loud, surfaced for audit).
        if (!file_exists($path)) {
            return static::__write_result(
                $storage,
                Search_Index_Model::STATUS_FAILED,
                '',
                'source blob missing on disk: ' . $path,
                'none'
            );
        }

        // ONE STAGE, ONE BOUND (rsx.documents.stage_seconds, justified at its config key): the
        // whole computation of this blob's text - the application's own handlers, the external
        // binaries, the in-process readers - runs inside it. Only the computation: the row is
        // written after the bound is released, so a database write is never interrupted.
        try {
            $outcome = Document_Stage_Bound::run(
                'text extraction',
                fn () => static::__extract($storage, $path, $rendition_path)
            );
        } catch (Document_Stage_Timeout_Exception $e) {
            return static::__write_result($storage, Search_Index_Model::STATUS_FAILED, '', $e->getMessage(), 'none');
        }

        return static::__write_result($storage, ...$outcome);
    }

    /**
     * Work out what this blob's text is, without writing anything.
     *
     * @param File_Storage_Model $storage
     * @param string $path The resident blob.
     * @param string|null $rendition_path See extract_storage().
     * @return array{0: int, 1: string, 2: ?string, 3: string, 4: ?array} The arguments of
     *         __write_result() after the blob: status, content, error, method, metadata.
     */
    protected static function __extract(File_Storage_Model $storage, string $path, ?string $rendition_path): array
    {
        $sniffed = mime_content_type($path);
        if ($sniffed === false) {
            $sniffed = 'application/octet-stream';
        }

        // Extraction routes on the PIPELINE mime (extension-first for documents), mirroring the
        // viewer/rendition/thumbnail registries, so a per-file-flaky OOXML sniff of application/zip
        // does not silently leave a document un-indexed. The blob carries no extension of its own,
        // so we borrow one from a representative attachment referencing this blob - preferring an
        // attachment whose extension is a known document type (a blob shared by a .docx and a .zip
        // upload extracts as the docx). An orphan blob with no attachment falls through to the raw
        // sniff.
        $representative_extension = static::__representative_extension($storage);
        $mime = File_Attachment_Model::resolve_pipeline_mime($sniffed, $representative_extension);

        // 1) App filter chain. Contract (enforced below, fail loud on anything else):
        //    null = decline (fall through) | string = extracted text
        //    ['status'=>'unsupported'] | ['status'=>'failed','error'=>...]
        $resolved = Rsx::trigger_resolve('document.extract_text', [
            'storage' => $storage,
            'path' => $path,
            'mime' => $mime,
        ]);

        if ($resolved !== null) {
            return static::__apply_resolved($storage, $resolved);
        }

        // What we actually hand the extractor. Normally the blob itself; the rendition shortcut
        // below swaps in the already-produced PDF.
        $extract_path = $path;
        $extract_mime = $mime;

        // 2) The rendition shortcut: a word-processing document whose PDF the render worker has
        // already produced. Extracting from that PDF with pdftotext is the SAME document without a
        // second soffice run.
        if ($rendition_path !== null && file_exists($rendition_path) && static::__is_word_processing_mime($mime)) {
            $extractor_class = 'Pdftotext_Text_Extractor';
            $extract_path = $rendition_path;
            $extract_mime = 'application/pdf';
        } else {
            // 3) Framework extractor registry (fnmatch by mime).
            $extractor_class = static::__extractor_for_mime($mime);
        }

        if ($extractor_class === null) {
            // 4) Nothing handles this mime -> UNSUPPORTED (not a failure).
            return [Search_Index_Model::STATUS_UNSUPPORTED, '', null, 'none', null];
        }

        // LibreOffice master switch, mirroring the thumbnail registry: check BEFORE invoking, so a
        // disabled LibreOffice yields UNSUPPORTED, never a soffice call.
        if ($extractor_class === 'Libreoffice_Text_Extractor' && !config('rsx.libreoffice.enabled', true)) {
            return [Search_Index_Model::STATUS_UNSUPPORTED, '', 'LibreOffice disabled', $extractor_class, null];
        }

        $fqcn = static::__resolve_extractor_fqcn($extractor_class);

        try {
            $text = (string) $fqcn::extract($extract_path, $extract_mime);
        } catch (Rsx_Extraction_Unsupported_Exception $e) {
            // Structurally unextractable by design (encrypted / password-protected): UNSUPPORTED
            // with the reason, NOT FAILED - a retry can never help, so it must not pollute the
            // failed-audit signal. An UNSUPPORTED row MAY carry a reason in the error column.
            return [Search_Index_Model::STATUS_UNSUPPORTED, '', $e->getMessage(), $extractor_class, null];
        } catch (\Throwable $e) {
            // Every other failure, the stage bound's Document_Stage_Timeout_Exception included.
            return [Search_Index_Model::STATUS_FAILED, '', $e->getMessage(), $extractor_class, null];
        }

        // Oversize handling is CENTRALIZED here (never in the extractors) so recorded truncation
        // is uniform. Two distinct shapes of "too big":
        //   (a) the extracted TEXT itself exceeds the cap (e.g. a huge multi-sheet workbook) -
        //       cut the text on a UTF-8 character boundary; original_bytes = pre-cut text length.
        //   (b) a plain-text SOURCE larger than the cap - Plain_Text_Extractor already returns
        //       only a byte-prefix (<= cap), so detect this via the source filesize and record
        //       the TRUE original size.
        // Recorded truncation is not silent truncation: a partial index is discoverable via the
        // row's metadata rather than returning wrong search results with no signal.
        $max_bytes = (int) config('rsx.search.max_text_bytes', 2 * 1024 * 1024);
        $metadata = null;

        if (strlen($text) > $max_bytes) {
            $original_bytes = strlen($text);
            $text = mb_strcut($text, 0, $max_bytes, 'UTF-8');
            $metadata = ['truncated' => true, 'original_bytes' => $original_bytes];
        } elseif ($extractor_class === 'Plain_Text_Extractor') {
            $source_size = filesize($extract_path);
            if ($source_size !== false && $source_size > $max_bytes) {
                $metadata = ['truncated' => true, 'original_bytes' => (int) $source_size];
            }
        }

        return [Search_Index_Model::STATUS_EXTRACTED, $text, null, $extractor_class, $metadata];
    }

    /**
     * Record that an extraction attempt failed WITHOUT having run to an answer of its own -
     * its process was killed, or ended before writing a row. Written by the worker's parent
     * process when it reconciles a child that never reported; the blob stays owed
     * (is_indexed is not touched), so the worker's attempt counter decides what happens next.
     *
     * @param File_Storage_Model $storage
     * @param string $error
     * @return Search_Index_Model
     */
    public static function record_failure(File_Storage_Model $storage, string $error): Search_Index_Model
    {
        return static::__write_result($storage, Search_Index_Model::STATUS_FAILED, '', $error, 'none');
    }

    /**
     * Extraction of this blob has failed for good: stop owing it.
     *
     * The FAILED row the last attempt wrote is kept with its reason; a blob that has no
     * FAILED row gets one, so "not owed, no text" is never a state without an explanation.
     * Called by the worker when a blob's attempts are spent.
     *
     * @param File_Storage_Model $storage
     * @param string $error The reason to record when the last attempt left none.
     * @return void
     */
    public static function settle_failure(File_Storage_Model $storage, string $error): void
    {
        $index = Search_Index_Model::forModel('File_Storage_Model', $storage->id)->first();

        if (!$index || (int) $index->status_id !== Search_Index_Model::STATUS_FAILED) {
            static::__write_result($storage, Search_Index_Model::STATUS_FAILED, '', $error, 'none');
        }

        $storage->is_indexed = 1;
        $storage->save();
    }

    /**
     * Apply a non-null result from the document.extract_text filter chain, enforcing its contract.
     *
     * @param File_Storage_Model $storage
     * @param mixed $resolved
     * @return array The arguments of __write_result() after the blob.
     */
    protected static function __apply_resolved(File_Storage_Model $storage, $resolved): array
    {
        if (is_string($resolved)) {
            return [Search_Index_Model::STATUS_EXTRACTED, $resolved, null, 'app_filter', null];
        }

        if (is_array($resolved) && isset($resolved['status'])) {
            if ($resolved['status'] === 'unsupported') {
                return [Search_Index_Model::STATUS_UNSUPPORTED, '', $resolved['error'] ?? null, 'app_filter', null];
            }

            if ($resolved['status'] === 'failed') {
                return [
                    Search_Index_Model::STATUS_FAILED,
                    '',
                    $resolved['error'] ?? 'app filter reported failure',
                    'app_filter',
                    null,
                ];
            }
        }

        // The chain returned something outside the contract - a handler bug. Fail loud.
        shouldnt_happen(
            "document.extract_text handler returned an out-of-contract value for storage #{$storage->id}: "
            . 'expected null | string | [status=>unsupported|failed], got ' . gettype($resolved)
        );
    }

    /**
     * Write (upsert) the _search_indexes row for this blob, and mark the blob is_indexed=1 when
     * the status is a definitive answer.
     *
     * @param File_Storage_Model $storage
     * @param int $status_id
     * @param string $content
     * @param string|null $error
     * @param string $method Extraction method label (extractor class name, 'app_filter', 'none').
     * @param array|null $metadata Row metadata to record (e.g. the truncation signal), or null to
     *                             leave the metadata column null (the common, un-truncated case).
     * @return Search_Index_Model
     */
    protected static function __write_result(
        File_Storage_Model $storage,
        int $status_id,
        string $content,
        ?string $error,
        string $method,
        ?array $metadata = null
    ): Search_Index_Model {
        $index = Search_Index_Model::find_or_create_for_model('File_Storage_Model', $storage->id);

        $index->status_id = $status_id;
        $index->content = $content;
        $index->error = $error;
        $index->extraction_method = $method;
        $index->extractor_version = (int) config('rsx.search.extractor_version', 1);
        $index->indexed_at = Rsx_Time::now_iso();

        if ($metadata !== null) {
            $index->set_metadata($metadata);
        }

        $index->save();

        // A definitive answer ends the owing. A FAILED attempt does not: the blob still owes its
        // text, and the worker's attempt counter - not this row - decides whether it is tried
        // again (settle_failure() is what stops owing a failure).
        if ($status_id !== Search_Index_Model::STATUS_FAILED) {
            $storage->is_indexed = 1;
            $storage->save();
        }

        return $index;
    }

    /**
     * True for the Writer (word-processing) mime family: .doc, .docx, .odt (incl. -template) and
     * .rtf. These are the documents whose text survives a PDF flattening intact, which is what
     * makes the rendition shortcut in extract_storage() correct for them and wrong for a
     * spreadsheet (a grid) or a presentation (slides).
     *
     * @param string $mime
     * @return bool
     */
    protected static function __is_word_processing_mime(string $mime): bool
    {
        return $mime === 'application/msword'
            || $mime === 'application/rtf'
            || str_contains($mime, 'wordprocessingml')
            || str_contains($mime, 'opendocument.text');
    }

    /**
     * The file extension to feed resolve_pipeline_mime() for a blob, borrowed from an attachment
     * that references it (the blob itself has no extension). Prefers an attachment whose extension
     * is a known document type - so a blob shared by a .docx and a .zip upload extracts as the
     * docx - then falls back to the lowest-id attachment with any extension. Returns null for an
     * orphan blob (no referencing attachment), leaving the raw sniff to route extraction.
     *
     * @param File_Storage_Model $storage
     * @return string|null
     */
    protected static function __representative_extension(File_Storage_Model $storage): ?string
    {
        $document_extensions = array_keys(config('rsx.files.document_mime_by_extension', []));

        // The blob is cross-site (one deduplicated blob may back attachments in several sites, and
        // this task carries no meaningful site context), so bypass the site read-scope to see EVERY
        // referencing attachment - mirroring the index itself being keyed on the blob, not a site.
        return File_Attachment_Model::without_site_scope(function () use ($storage, $document_extensions) {
            // withTrashed(): the extraction is keyed on the deduplicated blob, so a
            // representative extension is valid regardless of an attachment's soft-delete
            // state (a blob whose only referrers are in the retention window must still
            // resolve an extractor on re-index).
            $preferred = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereIn('file_extension', $document_extensions)
                ->orderBy('id')
                ->value('file_extension');
            if ($preferred !== null && $preferred !== '') {
                return $preferred;
            }

            $any = File_Attachment_Model::withTrashed()
                ->where('file_storage_id', $storage->id)
                ->whereNotNull('file_extension')
                ->where('file_extension', '!=', '')
                ->orderBy('id')
                ->value('file_extension');

            return ($any === null || $any === '') ? null : $any;
        });
    }

    /**
     * First fnmatch match in config('rsx.search.extractors') for this mime, or null.
     *
     * @param string $mime
     * @return string|null Simple extractor class name.
     */
    protected static function __extractor_for_mime(string $mime): ?string
    {
        $map = config('rsx.search.extractors', []);

        foreach ($map as $pattern => $class) {
            if (fnmatch($pattern, $mime)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Resolve a simple extractor class name to its FQCN via the manifest, validating it is a
     * registered Rsx_Text_Extractor_Abstract. Fail loud on a bogus config entry.
     *
     * @param string $simple_name
     * @return string FQCN
     */
    protected static function __resolve_extractor_fqcn(string $simple_name): string
    {
        if (!Manifest::php_is_subclass_of($simple_name, 'Rsx_Text_Extractor_Abstract')) {
            shouldnt_happen(
                "rsx.search.extractors names '{$simple_name}', which is not a Rsx_Text_Extractor_Abstract"
            );
        }

        $metadata = Manifest::php_get_metadata_by_class($simple_name);
        $fqcn = $metadata['fqcn'] ?? null;
        if ($fqcn === null) {
            shouldnt_happen("Extractor class '{$simple_name}' not found in manifest");
        }

        return $fqcn;
    }
}
