<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Rsx;

/**
 * Rsx_File_Gates - the three file authorization gates, asked FAIL-CLOSED.
 *
 * Rsx::trigger_gate() defaults OPEN when nothing is listening - correct for an optional gate,
 * catastrophic for these. An application that never wrote a handler would be running an
 * anonymous upload endpoint (file.upload.authorize), or serving every stored file - its bytes,
 * its thumbnail, its rendition, its extracted text - to anybody who can name it by key or
 * sequential id (file.thumbnail.authorize / file.download.authorize). Who may upload and who
 * may see a file are APPLICATION decisions the framework cannot guess, so an unregistered gate
 * is a MISCONFIGURED APPLICATION, not a request to refuse politely: every surface that asks one
 * of these gates throws (5xx) naming the missing handler, before it answers anything.
 *
 * Every framework surface that serves, describes or accepts a file asks through authorize()
 * below, never through Rsx::trigger_gate() directly. Registering one handler per gate (even a
 * bare "return true" for a site whose files are deliberately public) satisfies it.
 *
 * @see rsx:man file_upload
 */
class Rsx_File_Gates
{
    /** May this caller upload this file? Asked by every upload transport. */
    const UPLOAD = 'file.upload.authorize';

    /** May this caller see this attachment at all (thumbnail, metadata, preview info)? */
    const THUMBNAIL = 'file.thumbnail.authorize';

    /** May this caller have this attachment's full content (bytes, rendition, extracted text)? */
    const DOWNLOAD = 'file.download.authorize';

    /**
     * Throw unless the application registered a handler for this gate.
     *
     * Called by authorize(), and directly by an upload transport FIRST, before the request is
     * examined at all.
     *
     * @param string $event One of the three gate constants.
     * @return void
     * @throws \RuntimeException
     */
    public static function require_handler(string $event): void
    {
        if (Event_Registry::has_handlers($event)) {
            return;
        }

        if ($event === self::UPLOAD) {
            throw new \RuntimeException(
                'File uploads are disabled: no ' . self::UPLOAD . ' gate handler is registered. '
                . 'Uploading is an application authorization decision the framework will not make '
                . 'for you (at minimum, require a logged-in user), so an upload endpoint refuses to '
                . 'accept a file until the application registers a #[OnEvent(\'' . self::UPLOAD . '\')] '
                . 'handler in /rsx/handlers/. See: php artisan rsx:man file_upload'
            );
        }

        if ($event === self::THUMBNAIL || $event === self::DOWNLOAD) {
            throw new \RuntimeException(
                'File reads are disabled: no ' . $event . ' gate handler is registered. Who may see '
                . 'a stored file is an application authorization decision the framework will not make '
                . 'for you, so every file download, thumbnail, preview and extracted-text read refuses '
                . 'until the application registers a #[OnEvent(\'' . $event . '\')] handler in '
                . '/rsx/handlers/ (return true to allow - a site whose files are deliberately public '
                . 'says so there). See: php artisan rsx:man file_upload'
            );
        }

        shouldnt_happen("Rsx_File_Gates::require_handler() asked about '{$event}', which is not a file gate");
    }

    /**
     * Ask a file gate: true when every handler allowed, otherwise the first non-true answer (the
     * handler's own refusal, returned verbatim by the caller). Throws when nothing is listening.
     *
     * @param string $event One of the three gate constants.
     * @param array $data The gate's data (see each surface's docblock for its keys).
     * @return true|mixed
     * @throws \RuntimeException
     */
    public static function authorize(string $event, array $data)
    {
        static::require_handler($event);

        return Rsx::trigger_gate($event, $data);
    }
}
