<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Document_Soft_Checks;

/**
 * Rsx_Document_Renderer_Abstract - base for a "document bytes on disk -> preview rendition"
 * renderer, the per-type step of the background document worker (Document_Render_Service).
 *
 * One concrete renderer per family of types. The worker resolves a blob's renderer by its
 * pipeline mime through config('rsx.documents.renderers') (Rsx_Mime_Map: an application entry
 * beats a framework entry, and inside a layer the most specific pattern wins), so an
 * application replaces the framework's rendition for a type - or adds one for a type the
 * framework does not preview - by registering its own renderer in rsx/resource/config/rsx.php.
 * A type that matches the map is one the worker renders: an attachment of that type queues its
 * blob, and the rendition endpoint serves what the worker produced.
 *
 * THE RENDITION IS A PDF. It feeds Pdf_Viewer and the document thumbnail. 'html' is the
 * framework spreadsheet renderer's alone (Spreadsheet_Document_Renderer: a workbook previews
 * as a grid, served by Spreadsheet_Viewer); any other renderer that declares it is refused.
 *
 * FAIL LOUD: render() throws on any failure, and the worker records the attempt FAILED with
 * the exception's message. A failure is HARD by default - it is charged against the blob's
 * attempts (rsx.documents.max_attempts, a retry interval apart), after which the blob stays
 * FAILED for an operator. render() never reports anything else: a throw is a hard failure.
 *
 * A SOFT FAILURE is the opt-in exception, and soft_failure() is the whole of it: a check made
 * BEFORE an attempt, answering that the attempt cannot succeed on this box right now for a
 * reason that is not the document's - the tool is missing or not executable, the sandbox image
 * is absent, the source blob cannot be read. A soft failure is recorded as a failure, with its
 * reason, but is not charged against the attempt count, so a box that is repaired later still
 * renders the document. A renderer that declares no checks has no soft failures.
 *
 * Every method is static; a renderer holds no state.
 */
#[Monoprogenic]
abstract class Rsx_Document_Renderer_Abstract
{
    /**
     * Render the source document to its rendition at $target_path, creating the target's
     * directory when it does not exist. A rendition is published whole or not at all: a
     * renderer writes beside the target and renames over it, so a reader never sees a part.
     *
     * Runs inside the worker's render stage, which bounds it (rsx.documents.stage_seconds).
     *
     * @param string $source_path Absolute path to the resident blob (content-addressed, so it
     *                            has no extension of its own).
     * @param string $target_path Absolute path the rendition must be written to.
     * @param string|null $extension The real extension of an attachment that names this blob
     *                               (preferring a known document type), for a tool that detects
     *                               the input format from a file name; null for an orphan blob.
     * @return void
     * @throws \Throwable on any failure - a HARD failure, charged against the attempts.
     */
    abstract public static function render(string $source_path, string $target_path, ?string $extension): void;

    /**
     * The rendition's format: 'pdf'. The framework spreadsheet renderer alone answers 'html'.
     *
     * @return string
     */
    #[Replaceable]
    public static function rendition_format(): string
    {
        return 'pdf';
    }

    /**
     * Is this renderer switched on? False keeps a blob that owes this rendition WAITING in the
     * queue - deferred, no attempt charged, nothing recorded - until it is switched on again.
     *
     * @return bool
     */
    #[Replaceable]
    public static function is_enabled(): bool
    {
        return true;
    }

    /**
     * The opt-in soft-failure check, asked before an attempt: null when an attempt may proceed,
     * otherwise the human reason it cannot succeed on this box right now. The reason is
     * recorded on the blob like any failure's, and the attempt is NOT charged.
     *
     * A check only LOOKS: it must never run the tool, build or pull an image, or parse the
     * document. Tool executable, sandbox image present, source readable - Document_Soft_Checks
     * spells the framework's checks, and source_unreadable() is the one every renderer can use.
     * Anything that can only be learned by trying belongs in render(), and is hard.
     *
     * The default declares no checks.
     *
     * @param string $source_path Absolute path to the resident blob.
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
