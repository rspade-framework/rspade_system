<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use Normalizer;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Rsx_File_Name - the ONE rule for a file name a user can see, store or download.
 *
 * A file name arrives from whoever uploaded the file (the browser's original name, or a
 * filename_override) and leaves as the name another user saves it under. In between it has
 * to be a name every operating system accepts and a header can carry. safe() is that rule;
 * disposition() is the only way a Content-Disposition header is built from one.
 *
 * THE RULE IS A BLACKLIST, so accented and non-Latin names survive whole ("Résumé.pdf",
 * "報告書.xlsx"). Replaced with "_":
 *   - a character illegal on Windows, macOS or Linux:  / \ : * ? " < > |
 *   - control characters (U+0000-U+001F, U+007F-U+009F, U+2028, U+2029)
 *   - text-direction controls (U+202A-U+202E, U+2066-U+2069): legal everywhere, and the
 *     classic way to make "invoice<RLO>fdp.exe" display as "invoiceexe.pdf"
 * Then: Unicode is normalised to composed form (macOS stores names decomposed), leading and
 * trailing dots and spaces are trimmed (Windows drops trailing ones silently, and a leading dot
 * hides the file elsewhere), a Windows device name (CON, PRN, AUX, NUL, COM1-9, LPT1-9, with
 * any extension) gains a "_" prefix, the name is capped at 255 bytes keeping its extension, and
 * a name with nothing left becomes "file" (or the caller's own empty name).
 *
 * WHERE IT RUNS: File_Attachment_Model saves every file_name through it (so storage holds
 * safe names whatever the upload path), Rsx_Temp_Files names temp files with it, and
 * disposition() applies it again on the way out, which covers names stored before the rule.
 *
 * WHAT IT IS NOT: the file's type. The extension is kept as given - the application trusts it
 * as the declared type - and how a file is served is decided elsewhere.
 */
class Rsx_File_Name
{
    /** The most bytes a name may have: the per-component limit of all three filesystems. */
    public const MAX_BYTES = 255;

    /** The characters and ranges replaced with "_" (a preg character class, /u). */
    private const BLACKLIST = '[\/\\\\:*?"<>|\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]';

    /**
     * A file name safe to store, show and save on any operating system.
     *
     * @param string|null $name The name as given
     * @param string $empty_name What an empty or fully-trimmed name becomes
     * @return string
     */
    public static function safe(?string $name, string $empty_name = 'file'): string
    {
        $name = (string) $name;

        // Bytes that are not UTF-8 cannot be a name in any of the three systems' encodings;
        // each becomes a "?" here, and the blacklist then makes it "_".
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
        if ($normalized !== false) {
            $name = $normalized;
        }

        $name = preg_replace('/' . self::BLACKLIST . '/u', '_', $name);
        $name = trim($name, " .");

        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', $name)) {
            $name = '_' . $name;
        }

        if (strlen($name) > self::MAX_BYTES) {
            $name = static::__shorten($name);
        }

        return $name === '' ? $empty_name : $name;
    }

    /**
     * A Content-Disposition header value for a file name: the safe name as filename*, plus a
     * plain-ASCII filename for clients that read only that parameter (RFC 6266).
     *
     * @param string $type 'inline' or 'attachment'
     * @param string|null $name The file name
     * @param string $empty_name The name used when $name is empty
     * @return string
     */
    public static function disposition(string $type, ?string $name, string $empty_name = 'file'): string
    {
        $safe = static::safe($name, $empty_name);

        // The plain filename parameter is ASCII only, and HeaderUtils refuses a "%" in it. Letters are
        // transliterated ("Résumé" -> "Resume"); the blacklist runs again because
        // transliteration can produce a "/" or a quote from a full-width form; whatever is
        // still not ASCII becomes "_", one per character.
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII', $safe);
        if ($ascii === false) {
            shouldnt_happen('Transliteration failed for a file name that is valid UTF-8: ' . intl_get_error_message());
        }
        $ascii = preg_replace('/' . self::BLACKLIST . '/u', '_', $ascii);
        $ascii = preg_replace('/[^\x20-\x7E]|%/u', '_', $ascii);
        $ascii = trim($ascii, ' .');
        if ($ascii === '') {
            $ascii = $empty_name;
        }

        return HeaderUtils::makeDisposition($type, $safe, $ascii);
    }

    /**
     * Cut a name to MAX_BYTES on a character boundary, keeping a short extension.
     */
    private static function __shorten(string $name): string
    {
        $dot = strrpos($name, '.');
        $extension = ($dot !== false && $dot > 0 && strlen($name) - $dot <= 16) ? substr($name, $dot) : '';
        $stem = $extension === '' ? $name : substr($name, 0, $dot);

        return rtrim(mb_strcut($stem, 0, self::MAX_BYTES - strlen($extension), 'UTF-8'), ' .') . $extension;
    }
}
