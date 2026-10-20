<?php

namespace App\RSpade\Core\Search;

use App\RSpade\Core\Files\Document_Soft_Checks;

/**
 * Rsx_Text_Extractor_Abstract - base for a "bytes on disk -> plain text" extractor.
 *
 * One concrete extractor per format family (PDF, Office, plain text). Search_Index_Service
 * resolves an extractor by mime via config('rsx.search.extractors') and calls extract() with
 * the resident blob's absolute path AND its detected mime (the extractor may sub-route by mime
 * family - e.g. LibreOffice handles Writer/Calc/Impress documents differently).
 *
 * FAIL LOUD: extract() throws on any failure - the service catches the throw and records the
 * blob as FAILED (with the exception message). An extractor NEVER returns partial/degraded
 * output or an empty string to mask a failure. An empty string is a VALID result only when the
 * document genuinely contains no text (e.g. a PDF that is all images) - that is EXTRACTED, not
 * FAILED (OCR is out of scope).
 *
 * A blob that is structurally impossible to extract by design (encrypted / password-protected)
 * is NOT a failure: throw Rsx_Extraction_Unsupported_Exception with a human reason and the
 * service records it UNSUPPORTED (with the reason), keeping the failed-audit signal clean.
 *
 * A failure is HARD by default: charged against the blob's attempts in the document worker's
 * queue. soft_failure() is the opt-in exception - a check made BEFORE an attempt, answering that
 * the attempt cannot succeed on this box right now for a reason that is not the document's (the
 * tool is missing, the sandbox image is absent, the source cannot be read). A soft failure is
 * recorded as a failure, with its reason, but is not charged against the attempt count.
 * extract() never reports one: a throw from it is hard.
 */
#[Monoprogenic]
abstract class Rsx_Text_Extractor_Abstract
{
    /**
     * Extract plain text from the file at $source_path.
     *
     * @param string $source_path Absolute path to the resident blob.
     * @param string $mime The detected mime type of the blob (for format-family sub-routing).
     * @return string Extracted UTF-8 text (may be empty for a text-free document).
     * @throws Rsx_Extraction_Unsupported_Exception when the source cannot be extracted by design.
     * @throws \Throwable on any other extraction failure.
     */
    abstract public static function extract(string $source_path, string $mime): string;

    /**
     * The opt-in soft-failure check, asked before an attempt: null when an attempt may proceed,
     * otherwise the human reason it cannot succeed on this box right now. The reason is
     * recorded like any failure's, and the attempt is NOT charged.
     *
     * A check only LOOKS: it must never run the tool, build or pull an image, or parse the
     * document (Document_Soft_Checks spells the framework's checks). Anything that can only be
     * learned by trying belongs in extract(), and is hard.
     *
     * The default declares no checks.
     *
     * @param string $source_path Absolute path to the file extract() would be handed.
     * @return string|null
     */
    #[Replaceable]
    public static function soft_failure(string $source_path): ?string
    {
        return null;
    }

    /**
     * The reason when the source file is missing or cannot be opened for reading, else null.
     * Opens the file and closes it; parses nothing.
     *
     * @param string $path
     * @return string|null
     */
    public static function source_unreadable(string $path): ?string
    {
        return Document_Soft_Checks::source_unreadable($path);
    }
}
