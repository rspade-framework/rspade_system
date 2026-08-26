<?php

namespace App\RSpade\Core\Files;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Unparseable_Upload_Exception;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_File_Upload - the ONE ingest path a received file travels, whatever transport carried it.
 *
 * Two surfaces accept an upload: the browser transport POST /_upload (File_Attachment_Controller,
 * staff and portal) and the external REST endpoint POST /api/v1/files (Files_Api_Controller).
 * They differ ONLY in how a file arrives and how a failure is spelled back to the caller - a
 * browser gets {success:false,error} JSON, an API client gets {"error":{"code","message"}}. The
 * security-bearing middle - the mandatory authorize gate, the size ceiling, the SERVER-DERIVED
 * site_id, the fileable_* strip, the params/complete events - is identical, and lives here so it
 * cannot drift between them. A gate an app wrote for /_upload protects the API endpoint with no
 * extra work, because it is literally the same call.
 *
 * TRANSPORT STAYS WITH THE TRANSPORT. Deciding whether a file part is present at all, and
 * diagnosing a body PHP discarded over post_max_size, is a fact about the HTTP request the
 * caller made; each surface handles it in its own vocabulary before calling accept().
 *
 * accept() returns an OUTCOME, never a Response: the caller renders it. The one exception is
 * gate_response - a gate handler's own refusal object, which is passed back verbatim because
 * the application authored it and neither surface may rewrite it.
 */
class Rsx_File_Upload
{
    /**
     * MANDATORY GATE PRECONDITION. Rsx::trigger_gate() defaults OPEN when nothing is listening -
     * correct for an optional gate, catastrophic for this one (an app that never wrote a handler
     * would be running an anonymous upload endpoint). Who may upload is an APPLICATION decision
     * the framework cannot guess, so an unregistered gate is a MISCONFIGURED APPLICATION, not a
     * bad request: fail loud (5xx), before the request is examined at all.
     *
     * Called by EVERY upload surface, first, before the request is touched.
     */
    public static function require_authorize_gate(): void
    {
        if (Event_Registry::has_handlers('file.upload.authorize')) {
            return;
        }

        throw new \RuntimeException(
            'File uploads are disabled: no file.upload.authorize gate handler is registered. '
            . 'Uploading is an application authorization decision the framework will not make '
            . 'for you (at minimum, require a logged-in user), so an upload endpoint refuses to '
            . 'accept a file until the application registers a #[OnEvent(\'file.upload.authorize\')] '
            . 'handler in /rsx/handlers/. See: php artisan rsx:man file_upload'
        );
    }

    /**
     * Authorize, scope and ingest one received file.
     *
     * $is_portal is the REALM OF THE REQUEST, not who is signed in - it selects both the user
     * handed to the gate and the site the attachment is stamped into. $event_params is passed
     * through to the file.upload.* events as their 'params'.
     *
     * @return array{
     *   ok: bool,
     *   attachment: ?File_Attachment_Model,
     *   gate_denied: bool,
     *   gate_response: mixed,
     *   code: ?string,
     *   error: ?string,
     *   status: ?int
     * }
     *   ok=true carries the attachment. ok=false carries EITHER a gate refusal (gate_denied,
     *   whose gate_response the caller returns verbatim) OR code/error/status for the caller
     *   to render. gate_denied is a FLAG rather than "gate_response is not null" because a
     *   handler that denies by returning nothing at all denies with null, which is a refusal
     *   and not the absence of one.
     */
    public static function accept(
        Request $request,
        UploadedFile $file,
        bool $is_portal,
        ?string $filename_override = null,
        array $event_params = []
    ): array {
        // Size ceiling. Checked BEFORE the authorize gate: the gate is application code that
        // may read the real bytes, and there is no reason to run it over a file we have
        // already decided to refuse. 0 or null disables the framework ceiling entirely, which
        // leaves only PHP's ini limits.
        $max_file_size = (int) config('rsx.files.max_file_size', 0);
        if ($max_file_size > 0 && $file->getSize() > $max_file_size) {
            return static::__failure(
                'file_too_large',
                'File is too large: ' . bytes_to_human((int) $file->getSize())
                    . ' exceeds the ' . bytes_to_human($max_file_size) . ' limit.',
                413
            );
        }

        // Event: file.upload.authorize (gate) - first non-true response halts.
        // 'user' is realm-honest: a portal request reports the portal user, never a staff
        // facade read that would be null for a logged-in portal uploader. The fork is on the
        // REALM OF THE REQUEST, not on who is signed in - see the note in
        // File_Attachment_Controller::upload().
        $auth_result = Rsx::trigger_gate('file.upload.authorize', [
            'request' => $request,
            'user' => $is_portal
                ? Portal_Session::get_portal_user()
                : Session::get_user(),
            'params' => $event_params,
            'file' => $file,
            'filename' => $file->getClientOriginalName(),
            'size' => (int) $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => strtolower($file->getClientOriginalExtension()),
            'tmp_path' => $file->getRealPath(),
        ]);

        if ($auth_result !== true) {
            return [
                'ok' => false,
                'attachment' => null,
                'gate_denied' => true,
                'gate_response' => $auth_result,
                'code' => null,
                'error' => null,
                'status' => null,
            ];
        }

        // site_id is derived ENTIRELY server-side - client input is never consulted. Trusting a
        // posted site_id was a cross-tenant write primitive (a logged-in caller could stamp an
        // attachment into another tenant). Portal REQUEST -> Portal_Session's site (declared by
        // the app in Portal_Main::init(), and it throws rather than guess), otherwise the staff
        // Session's site - the same realm fork as the gate payload above, and for the same reason.
        $site_id = $is_portal
            ? Portal_Session::get_site_id()
            : Session::get_site_id();

        if (!$site_id) {
            return static::__failure('site_unresolved', 'Unable to resolve a site for this upload', 400);
        }

        $upload_params = [
            'site_id' => $site_id,
            'filename_override' => $filename_override,
        ];

        // Remove null values
        $upload_params = array_filter($upload_params, fn($v) => $v !== null);

        // Event: file.upload.params (filter) - allow handlers to modify params.
        // Note: handlers can still add fileable_* params if needed for programmatic uploads.
        $upload_params = Rsx::trigger_filter('file.upload.params', $upload_params);

        // Security: files uploaded through a request endpoint are NEVER pre-assigned to a
        // record. Caller-provided fileable_* params are ignored; use attach_to()/add_to()
        // after the upload. This prevents attaching files to records the caller does not own.
        unset($upload_params['fileable_type']);
        unset($upload_params['fileable_id']);
        unset($upload_params['fileable_category']);
        unset($upload_params['fileable_type_meta']);
        unset($upload_params['fileable_order']);

        try {
            $attachment = File_Attachment_Model::create_from_upload($file, $upload_params);
        } catch (Unparseable_Upload_Exception $e) {
            // Strict reject-mode (config rsx.attachments.reject_unparseable_images): the image
            // bytes could not be parsed. create_from_upload() already cleaned up the orphan row
            // and blob, so this is a plain 4xx for the caller.
            return static::__failure(
                'unparseable_image',
                'This image could not be processed and was not accepted.',
                422
            );
        } catch (\Exception $e) {
            return static::__failure('upload_failed', 'File upload failed: ' . $e->getMessage(), 500);
        }

        // Event: file.upload.complete (action) - logging, notifications, etc.
        Rsx::trigger_action('file.upload.complete', [
            'attachment' => $attachment,
            'request' => $request,
            'params' => $event_params,
        ]);

        return [
            'ok' => true,
            'attachment' => $attachment,
            'gate_denied' => false,
            'gate_response' => null,
            'code' => null,
            'error' => null,
            'status' => null,
        ];
    }

    /**
     * A framework-authored refusal the calling surface renders in its own error vocabulary.
     */
    private static function __failure(string $code, string $message, int $status): array
    {
        return [
            'ok' => false,
            'attachment' => null,
            'gate_denied' => false,
            'gate_response' => null,
            'code' => $code,
            'error' => $message,
            'status' => $status,
        ];
    }
}
