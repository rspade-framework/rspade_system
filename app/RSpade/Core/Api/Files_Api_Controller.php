<?php

namespace App\RSpade\Core\Api;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Rsx_Api;
use App\RSpade\Core\Api\Rsx_Api_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Upload;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Search\Search_Index_Model;
use App\RSpade\Core\Session\Session;

/**
 * Files_Api_Controller - the framework's file surface on the external REST API.
 *
 * THREE ENDPOINTS, AND DELIBERATELY NOT A FOURTH. The framework owns getting bytes IN
 * (POST /api/v1/files), telling a client what became of them (GET /api/v1/files/:key) and
 * handing back what was read out of them (GET /api/v1/files/:key/text). It does NOT own
 * "attach this file to that record": which record, which category and who may do it are
 * APPLICATION policy, and an app writes its own endpoint for it using the key returned here
 * plus File_Attachment_Model::find_by_key() / can_user_assign_this_file() / attach_to().
 *
 * GETTING BYTES BACK OUT IS NOT AN ENDPOINT EITHER. The existing /_download, /_inline,
 * /_thumbnail/* and /_preview/pdf routes accept an Authorization: Bearer key exactly as they
 * accept a cookie (see Rsx_Api_Bearer::authenticate_web_request), so there is ONE URL per
 * file for a browser and an integration alike. Those URLs are documented on the upload
 * endpoint below, because they cannot appear as cards of their own in an /api/vN catalog.
 *
 * UPLOAD SHARES THE BROWSER'S INGEST PATH VERBATIM. Rsx_File_Upload carries the mandatory
 * file.upload.authorize gate, the size ceiling, the server-derived site_id and the fileable_*
 * strip; POST /_upload and POST /api/v1/files are two transports over one sequence, so an
 * app's upload gate protects both with no extra work.
 */
#[Auth('is_logged_in')]
class Files_Api_Controller extends Rsx_Api_Controller_Abstract
{
    // The four-value status vocabulary both status fields speak. One vocabulary, so a client
    // writes one switch: 'pending' means ask again later, 'available' means go and fetch it,
    // 'error' and 'unsupported' both mean stop asking (the first is a failure, the second is
    // a file this pipeline was never going to handle).
    private const STATUS_PENDING = 'pending';
    private const STATUS_AVAILABLE = 'available';
    private const STATUS_ERROR = 'error';
    private const STATUS_UNSUPPORTED = 'unsupported';

    /**
     * Upload a file and receive its key. The key is the handle every other file operation
     * takes - metadata, extracted text, and the four byte-serving URLs described below.
     *
     * Send multipart/form-data with a single part named `file`. Optional `filename_override`
     * renames the stored file. The upload is UNATTACHED: it belongs to your site and to
     * nobody's record until your application's own endpoint claims it.
     *
     * DOWNLOADING THE FILE AGAIN. There are no /api/vN download endpoints, because the four
     * routes that already serve file bytes accept your `Authorization: Bearer` header exactly
     * as they accept a browser cookie. Use them directly, with the `key` from this response
     * and the absolute URLs echoed back in `urls`:
     *
     *   GET /_download/{key}
     *     The original bytes, Content-Disposition: attachment. Always available.
     *
     *   GET /_inline/{key}
     *     The same bytes, Content-Disposition: inline, for rendering in place.
     *
     *   GET /_thumbnail/dynamic/{key}/{type}/{width}
     *   GET /_thumbnail/dynamic/{key}/{type}/{width}/{height}
     *     A generated raster thumbnail. {type} is `fit` (contain, no crop) or `cover` (fill,
     *     cropped); dimensions are pixels, 10 to rsx.thumbnails.max_dynamic_size. There is
     *     also a named-preset form, GET /_thumbnail/preset/{key}/{preset_name}, for the
     *     presets an install defines in config rsx.thumbnails.presets. A file with no picture
     *     yet is served a generic extension icon, uncacheable, so a later request gets the
     *     real thumbnail once one exists.
     *
     *   GET /_preview/pdf/{key}
     *     The PDF rendition - the file itself when it is already a PDF, and the converted
     *     rendition for an Office document. This is what a viewer embeds.
     *
     * WHICH OF THOSE ARE WORTH REQUESTING is what the two status fields on
     * GET /api/v1/files/{key} tell you, and they are the reason to poll that endpoint after
     * an upload rather than to retry a URL:
     *
     *   preview_status  'pending'     the rendition is still being produced; /_preview/pdf and
     *                                 any thumbnail will serve a placeholder until it lands.
     *                   'available'   /_preview/pdf and /_thumbnail/* serve real content.
     *                   'error'       conversion failed and is never retried automatically.
     *                   'unsupported' this file has no preview at all.
     *   text_status     'pending' | 'available' | 'error' | 'unsupported', the same way, for
     *                                 GET /api/v1/files/{key}/text.
     *
     * Both start at 'pending' for an Office document: extraction and rendering happen in one
     * background pass a few seconds after the upload, never during it. /_download is
     * unaffected and serves the original bytes immediately.
     *
     * @api-response
     * {
     *   "key": "9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6",
     *   "id": 4210,
     *   "file_name": "Q3-forecast.xlsx",
     *   "file_extension": "xlsx",
     *   "mime_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
     *   "file_type": "Document",
     *   "size": 28451,
     *   "width": null,
     *   "height": null,
     *   "is_animated": false,
     *   "duration": null,
     *   "has_thumbnail": false,
     *   "preview_unavailable": false,
     *   "urls": {
     *     "download": "https://example.com/_download/9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6",
     *     "inline": "https://example.com/_inline/9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6",
     *     "thumbnail": "https://example.com/_thumbnail/dynamic/9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6/fit/400?v=0",
     *     "preview": "https://example.com/_preview/pdf/9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6"
     *   }
     * }
     */
    #[Api_Endpoint('/api/v1/files', methods: ['POST'])]
    #[Api_Param('file', type: 'file', required: true, description: 'The file to upload, as a multipart/form-data part.')]
    #[Api_Param('filename_override', type: 'string', description: 'Store the file under this name instead of the uploaded part\'s own filename.')]
    public static function upload(Request $request, array $params = [])
    {
        // Same mandatory precondition POST /_upload enforces, for the same reason: an app that
        // registered no file.upload.authorize handler has an anonymous upload endpoint, and
        // that is a misconfigured application, not a bad request.
        Rsx_File_Upload::require_authorize_gate();

        // An API key is a STAFF credential and its session is a staff identity, so the realm
        // is never the portal here - the site comes from Session::get_site_id(), which the
        // bearer identity set from the key's user.
        $outcome = Rsx_File_Upload::accept(
            $request,
            $params['file'],
            false,
            $params['filename_override'] ?? null,
            $params
        );

        if (!$outcome['ok']) {
            // The application's own gate refusal is returned verbatim - the framework does not
            // rewrite a response an app authored.
            if ($outcome['gate_denied']) {
                return $outcome['gate_response'];
            }

            return Rsx_Api::error($outcome['code'], $outcome['error'], $outcome['status']);
        }

        return Rsx_Api::created(static::__file_payload($outcome['attachment']));
    }

    /**
     * Metadata for one uploaded file, plus the preview and text pipeline states.
     *
     * Poll this after an upload to learn when GET /api/v1/files/{key}/text, /_preview/pdf and
     * /_thumbnail/* become worth requesting: both `preview_status` and `text_status` speak the
     * same four-value vocabulary - `pending` (ask again), `available` (fetch it), `error`
     * (failed, never retried), `unsupported` (this file will never have one). See the upload
     * endpoint for the full download URL reference.
     *
     * @api-response
     * {
     *   "key": "9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6",
     *   "id": 4210,
     *   "file_name": "Q3-forecast.xlsx",
     *   "file_extension": "xlsx",
     *   "mime_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
     *   "file_type": "Document",
     *   "size": 28451,
     *   "width": null,
     *   "height": null,
     *   "is_animated": false,
     *   "duration": null,
     *   "has_thumbnail": true,
     *   "preview_unavailable": false,
     *   "preview_status": "available",
     *   "text_status": "available",
     *   "urls": {
     *     "download": "https://example.com/_download/9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6",
     *     "inline": "https://example.com/_inline/9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6",
     *     "thumbnail": "https://example.com/_thumbnail/dynamic/9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6/fit/400?v=1",
     *     "preview": "https://example.com/_preview/pdf/9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6"
     *   }
     * }
     */
    #[Api_Endpoint('/api/v1/files/:key', methods: ['GET'])]
    #[Api_Param('key', type: 'string', required: true, description: 'The attachment key returned by POST /api/v1/files.')]
    public static function info(Request $request, array $params = [])
    {
        $attachment = static::__readable_attachment($params['key']);
        if ($attachment === null) {
            return Rsx_Api::not_found('File not found');
        }

        return static::__file_payload($attachment) + [
            'preview_status' => static::__preview_status($attachment),
            'text_status' => static::__text_status($attachment),
        ];
    }

    /**
     * The text extracted from an uploaded document.
     *
     * Extraction is asynchronous: it happens in the same background pass that produces the
     * preview rendition, a few seconds after the upload, and there is no OCR. This endpoint
     * therefore answers with the STATE rather than with a blank:
     *
     *   200  text is available - {"key", "text", "text_status": "available"}. `text` may be
     *        the empty string for a document that genuinely contains no text; that is an
     *        answer, and it is distinguishable from the three below because the call succeeded.
     *   409  text is not available, and error.code says which state: `text_pending` (extraction
     *        has not run yet - retry), `text_error` (extraction failed, and it is never retried
     *        automatically), `text_unsupported` (this file type carries no extractable text).
     *   404  no such file, or you may not read it.
     *
     * 409 rather than 404 or an empty 200: the file exists and you may read it, so it is not
     * not-found; and returning "" as though it were the text would make a pending extraction
     * indistinguishable from an empty document, which is the one mistake this endpoint exists
     * to prevent.
     *
     * @api-response
     * {
     *   "key": "9f2c41b7ae0d6538c1a7e94b2d3f80516ec7a4d92b18f60c35ae719d4c82b0f6",
     *   "text": "Q3 FORECAST\nRevenue ...",
     *   "text_status": "available"
     * }
     */
    #[Api_Endpoint('/api/v1/files/:key/text', methods: ['GET'])]
    #[Api_Param('key', type: 'string', required: true, description: 'The attachment key returned by POST /api/v1/files.')]
    public static function text(Request $request, array $params = [])
    {
        // The bytes' CONTENT is what this returns, so it clears the download gate as well as
        // the thumbnail one - the same pair /_download runs.
        $attachment = static::__readable_attachment($params['key'], true);
        if ($attachment === null) {
            return Rsx_Api::not_found('File not found');
        }

        $status = static::__text_status($attachment);

        if ($status !== self::STATUS_AVAILABLE) {
            $messages = [
                self::STATUS_PENDING => 'Text extraction has not completed for this file yet.',
                self::STATUS_ERROR => 'Text extraction failed for this file.',
                self::STATUS_UNSUPPORTED => 'This file type carries no extractable text.',
            ];

            return Rsx_Api::error('text_' . $status, $messages[$status], 409);
        }

        return [
            'key' => $attachment->key,
            'text' => (string) $attachment->get_extracted_text(),
            'text_status' => $status,
        ];
    }

    // ============================================================================================
    // INTERNALS
    // ============================================================================================

    /**
     * Resolve a key to an attachment the CALLER MAY READ, or null.
     *
     * Runs the application's own file.thumbnail.authorize gate - the same gate
     * File_Attachment_Model::fetch() and every thumbnail route run - and, when $need_bytes,
     * the file.download.authorize gate on top of it, exactly as /_download does.
     *
     * A denial and a missing row are BOTH null, and both surface as 404: an API key must not
     * be able to enumerate which attachment keys exist by the difference between a 403 and a
     * 404. That is the same anti-enumeration rule fetch() follows.
     */
    private static function __readable_attachment(string $key, bool $need_bytes = false): ?File_Attachment_Model
    {
        $attachment = File_Attachment_Model::find_by_key($key);
        if (!$attachment) {
            return null;
        }

        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => request(),
        ]);

        if ($thumbnail_auth !== true) {
            return null;
        }

        if ($need_bytes) {
            $download_auth = Rsx::trigger_gate('file.download.authorize', [
                'attachment' => $attachment,
                'user' => Session::get_user(),
                'request' => request(),
            ]);

            if ($download_auth !== true) {
                return null;
            }
        }

        return $attachment;
    }

    /**
     * The metadata shape both the upload response and the info response are built from, so a
     * client parses one object. URLs are ABSOLUTE: an API consumer has no page to resolve a
     * relative path against.
     */
    private static function __file_payload(File_Attachment_Model $attachment): array
    {
        return [
            'key' => $attachment->key,
            'id' => (int) $attachment->id,
            'file_name' => $attachment->file_name,
            'file_extension' => $attachment->file_extension,
            'mime_type' => $attachment->mime_type,
            'file_type' => $attachment->file_type_id__label,
            'size' => $attachment->get_size(),
            'width' => $attachment->width,
            'height' => $attachment->height,
            // Cast: both columns default to 0 in the database, but a model freshly created in
            // memory has not read those defaults back, so an uncast read would answer null on
            // the upload response and false on the info response for the same file.
            'is_animated' => (bool) $attachment->is_animated,
            'duration' => $attachment->duration,
            'has_thumbnail' => $attachment->has_thumbnail(),
            // Set when an image's bytes could not be parsed and it was degraded to a generic,
            // non-previewable file. The upload succeeded; the file is just not usable as one.
            'preview_unavailable' => (bool) $attachment->preview_unavailable,
            'urls' => [
                'download' => static::__absolute($attachment->get_download_url()),
                'inline' => static::__absolute($attachment->get_url()),
                'thumbnail' => static::__absolute($attachment->get_thumbnail_url()),
                'preview' => static::__absolute('/_preview/pdf/' . $attachment->key),
            ],
        ];
    }

    /**
     * Absolutize a framework-relative file URL against APP_URL, the one hostname source.
     */
    private static function __absolute(string $relative_url): string
    {
        return rtrim((string) config('app.url', ''), '/') . $relative_url;
    }

    /**
     * The preview pipeline's state, in the shared four-value vocabulary.
     *
     * preview_unavailable is consulted FIRST and wins: it marks a file the ingest already
     * decided has no picture at all (unparseable image bytes), which no render status can
     * undo.
     *
     * A NULL render status means there is no resident blob - an external attachment whose
     * bytes have never been materialized - so nothing has been rendered and nothing has
     * failed. That is reported as 'pending', the same as a queued render: in both cases the
     * honest instruction to a client is "ask again", and requesting the preview URL is itself
     * what materializes the bytes.
     */
    private static function __preview_status(File_Attachment_Model $attachment): string
    {
        if ($attachment->preview_unavailable) {
            return self::STATUS_UNSUPPORTED;
        }

        $render_status = $attachment->get_render_status();

        return match ($render_status) {
            // An image or a PDF is its own preview - there was never anything to convert.
            File_Storage_Model::RENDER_STATUS_NOT_REQUIRED => self::STATUS_AVAILABLE,
            File_Storage_Model::RENDER_STATUS_RENDERED => self::STATUS_AVAILABLE,
            File_Storage_Model::RENDER_STATUS_FAILED => self::STATUS_ERROR,
            File_Storage_Model::RENDER_STATUS_PENDING => self::STATUS_PENDING,
            default => self::STATUS_PENDING,
        };
    }

    /**
     * The extraction pipeline's state, in the shared four-value vocabulary. A null status is
     * "no index row yet", which is pending by definition.
     */
    private static function __text_status(File_Attachment_Model $attachment): string
    {
        return match ($attachment->get_extraction_status()) {
            Search_Index_Model::STATUS_EXTRACTED => self::STATUS_AVAILABLE,
            Search_Index_Model::STATUS_FAILED => self::STATUS_ERROR,
            Search_Index_Model::STATUS_UNSUPPORTED => self::STATUS_UNSUPPORTED,
            default => self::STATUS_PENDING,
        };
    }
}
