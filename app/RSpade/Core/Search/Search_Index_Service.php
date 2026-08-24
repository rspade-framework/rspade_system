<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Search;

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
 * drains _file_storage.is_indexed = 0 alongside the rendition queue and calls extract_storage()
 * per blob, so one soffice-era document is handled by ONE pass instead of by two competing
 * background jobs. Extraction is keyed on the deduplicated blob, so identical bytes are extracted
 * exactly once regardless of how many attachments reference them.
 *
 * There is NO stored PENDING state: the absence of a _search_indexes row (with is_indexed=0) IS
 * the queue. A row is written only AFTER an attempt, always EXTRACTED / FAILED / UNSUPPORTED, and
 * is_indexed is ALWAYS set afterward so work never repeats and the worker's loop terminates.
 */
class Search_Index_Service extends Rsx_Service_Abstract
{
    /**
     * Extract (or record the non-extraction of) a single blob, writing its _search_indexes row
     * and marking the blob is_indexed=1. This is the unit of work - callable directly (tests,
     * per-blob reindex) or from the drain loop.
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
        // sniff (unchanged behavior).
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
        // second soffice run - the expensive half of the old three-conversions-per-document story.
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
            return static::__write_result($storage, Search_Index_Model::STATUS_UNSUPPORTED, '', null, 'none');
        }

        // LibreOffice master switch, mirroring the thumbnail registry: check BEFORE invoking, so a
        // disabled LibreOffice yields UNSUPPORTED, never a soffice call.
        if ($extractor_class === 'Libreoffice_Text_Extractor' && !config('rsx.libreoffice.enabled', true)) {
            return static::__write_result(
                $storage,
                Search_Index_Model::STATUS_UNSUPPORTED,
                '',
                'LibreOffice disabled',
                $extractor_class
            );
        }

        $fqcn = static::__resolve_extractor_fqcn($extractor_class);

        try {
            $text = (string) $fqcn::extract($extract_path, $extract_mime);
        } catch (Rsx_Extraction_Unsupported_Exception $e) {
            // Structurally unextractable by design (encrypted / password-protected): UNSUPPORTED
            // with the reason, NOT FAILED - a retry can never help, so it must not pollute the
            // failed-audit signal. An UNSUPPORTED row MAY carry a reason in the error column.
            return static::__write_result(
                $storage,
                Search_Index_Model::STATUS_UNSUPPORTED,
                '',
                $e->getMessage(),
                $extractor_class
            );
        } catch (\Throwable $e) {
            return static::__write_result(
                $storage,
                Search_Index_Model::STATUS_FAILED,
                '',
                $e->getMessage(),
                $extractor_class
            );
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

        return static::__write_result(
            $storage,
            Search_Index_Model::STATUS_EXTRACTED,
            $text,
            null,
            $extractor_class,
            $metadata
        );
    }

    /**
     * Apply a non-null result from the document.extract_text filter chain, enforcing its contract.
     *
     * @param File_Storage_Model $storage
     * @param mixed $resolved
     * @return Search_Index_Model
     */
    protected static function __apply_resolved(File_Storage_Model $storage, $resolved): Search_Index_Model
    {
        if (is_string($resolved)) {
            return static::__write_result($storage, Search_Index_Model::STATUS_EXTRACTED, $resolved, null, 'app_filter');
        }

        if (is_array($resolved) && isset($resolved['status'])) {
            if ($resolved['status'] === 'unsupported') {
                return static::__write_result($storage, Search_Index_Model::STATUS_UNSUPPORTED, '', $resolved['error'] ?? null, 'app_filter');
            }

            if ($resolved['status'] === 'failed') {
                return static::__write_result(
                    $storage,
                    Search_Index_Model::STATUS_FAILED,
                    '',
                    $resolved['error'] ?? 'app filter reported failure',
                    'app_filter'
                );
            }
        }

        // The chain returned something outside the contract - a handler bug. Fail loud.
        shouldnt_happen(
            "document.extract_text handler returned an out-of-contract value for storage #{$storage->id}: "
            . 'expected null | string | [status=>unsupported|failed], got ' . gettype($resolved)
        );
    }

    /**
     * Write (upsert) the _search_indexes row for this blob and mark it is_indexed=1.
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

        // ALWAYS mark the blob processed so the drain loop terminates and work never repeats,
        // regardless of the outcome.
        $storage->is_indexed = 1;
        $storage->save();

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
