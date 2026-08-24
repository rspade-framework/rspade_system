<?php

namespace App\RSpade\Core\Files;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Session\Session;

/**
 * File_Preview_Controller
 *
 * Serves the document PREVIEW surface consumed by the Document_Preview component: a PDF rendition
 * of an attachment (for pdf.js), the lazily-loaded pdf.js module bytes, and the server-side viewer
 * resolution (get_preview_info). Byte content still routes through File_Attachment_Model:
 * resolve_storage() materializes external bytes; renditions are content-addressed and cached.
 *
 * AUTHORIZATION: the class gate is 'public'; per-file authorization runs through the
 * file.thumbnail.authorize / file.download.authorize event gates documented below. Those
 * hooks are the record layer (may this caller touch THIS attachment) and stay inline.
 *
 * ================================================================================================
 * ENDPOINTS
 * ================================================================================================
 *
 * GET /_preview/pdf/:key       - PDF rendition of the attachment (application/pdf, inline)
 * GET /_preview/pdfjs.mjs       - pdf.js main module bytes (lazy-loaded; NOT in any bundle)
 * GET /_preview/pdf_worker.mjs  - pdf.js worker module bytes (lazy-loaded)
 * POST (Ajax) get_preview_info  - {viewer, mime, file_name, extension, preview_unavailable,
 *                                  render_status_id, urls{rendition|null, inline, icon}} for a Document_Preview
 *
 * ================================================================================================
 * FILTER / GATE CHAINS
 * ================================================================================================
 *
 * The rendition endpoint participates in TWO chains, in this order:
 *
 * 1. AUTHORIZATION GATE CASCADE (file.thumbnail.authorize -> file.download.authorize)
 *    A PDF rendition exposes the FULL document content, so it is gated exactly like inline() /
 *    download_file(): BOTH gates must return true. The thumbnail gate runs first (so thumbnail
 *    security automatically protects the rendition), then the download gate (stricter download-only
 *    rules). First non-true response denies. Payload for each:
 *        ['attachment' => File_Attachment_Model, 'user' => User|null, 'request' => Request]
 *    get_preview_info runs ONLY the thumbnail gate (metadata, not full content).
 *
 * 2. document.preview_rendition (RESOLVE - Rsx::trigger_resolve, first non-null handler wins)
 *    Lets an app override or extend how an attachment becomes a PDF rendition (e.g. an external
 *    conversion service, a per-tenant renderer). Runs AFTER the auth gates. Payload:
 *        ['attachment' => File_Attachment_Model, 'request' => Request]
 *    Return contract (enforced; anything else fails loud via shouldnt_happen):
 *        null                      DECLINE - fall through to the framework rendition pipeline.
 *        Response                  TAKEOVER - the handler serves the response directly.
 *        ['path' => <abs pdf>]     SERVE - the framework serves that PDF file inline.
 *        ['unsupported' => true]   415 - no PDF rendition available for this attachment.
 *    Example:
 *        #[OnEvent('document.preview_rendition')]
 *        public static function convert_cad($data) {
 *            if ($data['attachment']->file_extension !== 'dwg') return null; // decline
 *            return ['path' => Cad_Service::to_pdf($data['attachment'])];    // serve
 *        }
 *
 * FRAMEWORK RENDITION PIPELINE (when the resolve chain declines):
 *   - mime application/pdf                              -> serve the resident blob as-is.
 *   - mime in rsx.preview.convertible AND LibreOffice enabled
 *                                                       -> serve the rendition Document_Render_Service
 *                                                          already produced (storage/rsx-renditions/
 *                                                          {hash}.pdf) when the blob is RENDERED;
 *                                                          404 naming the render state otherwise. A
 *                                                          RENDERED blob whose file was LRU-evicted
 *                                                          is re-queued and 404s as Pending.
 *                                                          Renditions are NEVER converted inside a
 *                                                          web request.
 *   - anything else                                     -> 415.
 */
#[Auth('public')]
#[Auth_Realm('any')]
class File_Preview_Controller extends Rsx_Controller_Abstract
{
    // ============================================================================================
    // PDF RENDITION
    // ============================================================================================

    /**
     * Serve a PDF rendition of the attachment (the pdf.js document source).
     *
     * Route: /_preview/pdf/:key
     * Security: BOTH file.thumbnail.authorize AND file.download.authorize (rendition = full content).
     *
     * @param Request $request
     * @param array $params
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/_preview/pdf/:key', methods: ['GET'])]
    public static function pdf_rendition(Request $request, array $params = [])
    {
        $key = $params['key'] ?? null;
        if (!$key) {
            abort(404, 'File not found');
        }

        $attachment = File_Attachment_Model::where('key', $key)->first();
        if (!$attachment) {
            abort(404, 'File not found');
        }

        // Dual authorization gate cascade - identical to inline()/download_file().
        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);
        if ($thumbnail_auth !== true) {
            return $thumbnail_auth;
        }

        $download_auth = Rsx::trigger_gate('file.download.authorize', [
            'attachment' => $attachment,
            'user' => Session::get_user(),
            'request' => $request,
        ]);
        if ($download_auth !== true) {
            return $download_auth;
        }

        // App resolve chain (override/extend the rendition). Runs AFTER auth.
        $resolved = Rsx::trigger_resolve('document.preview_rendition', [
            'attachment' => $attachment,
            'request' => $request,
        ]);
        if ($resolved !== null) {
            return static::__apply_rendition_resolution($resolved, $attachment);
        }

        // Framework rendition pipeline. Route on the PIPELINE mime (extension-first for documents)
        // so a zip-sniffed OOXML doc is still recognized as convertible, not 415'd to Icon_Viewer.
        $mime = $attachment->pipeline_mime();

        // Already a PDF -> serve the resident (content-addressed) blob as-is.
        if ($mime === 'application/pdf') {
            $storage = $attachment->resolve_storage();
            $path = $storage->get_full_path();
            if (!file_exists($path)) {
                abort(404, 'File not found on disk');
            }

            return static::__serve_pdf($path, $attachment->file_name);
        }

        // Convertible office document + LibreOffice enabled -> cached soffice->PDF rendition.
        if (static::__is_convertible($mime) && config('rsx.libreoffice.enabled', true)) {
            $rendition_path = static::__resolve_rendition($attachment);

            return static::__serve_pdf($rendition_path, static::__pdf_filename($attachment->file_name));
        }

        // No PDF rendition is possible for this file type.
        abort(415, "No PDF preview available for this file type ({$mime}). Download the file to view it.");
    }

    /**
     * Apply a non-null result from the document.preview_rendition resolve chain, enforcing its
     * contract. See the class docblock for the full contract.
     *
     * @param mixed $resolved
     * @param File_Attachment_Model $attachment
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static function __apply_rendition_resolution($resolved, File_Attachment_Model $attachment)
    {
        // TAKEOVER: the handler produced a full response (stream, redirect, etc).
        if ($resolved instanceof \Symfony\Component\HttpFoundation\Response) {
            return $resolved;
        }

        if (is_array($resolved)) {
            // SERVE: the handler produced a PDF file on disk for the framework to serve.
            if (isset($resolved['path'])) {
                $path = $resolved['path'];
                if (!is_string($path) || !file_exists($path)) {
                    shouldnt_happen('document.preview_rendition returned a path that does not exist: ' . var_export($path, true));
                }

                return static::__serve_pdf($path, static::__pdf_filename($attachment->file_name));
            }

            // 415: the handler declares this attachment has no rendition.
            if (($resolved['unsupported'] ?? null) === true) {
                abort(415, 'No PDF preview available for this file.');
            }
        }

        // Out of contract - a handler bug. Fail loud.
        shouldnt_happen(
            'document.preview_rendition handler returned an out-of-contract value for attachment '
            . "{$attachment->key}: expected null | Response | [path=>...] | [unsupported=>true], got " . gettype($resolved)
        );
    }

    /**
     * Return the absolute path of this attachment's already-rendered PDF, or abort with a body
     * naming the render state.
     *
     * NOTHING CONVERTS HERE ANY MORE. The soffice run belongs to Document_Render_Service, which
     * produces the rendition in the background and publishes it atomically into this same
     * content-addressed cache path. A web request that finds no rendition is a request that
     * arrived before the worker finished (or after it failed), and the honest answer is to say so
     * rather than to spend 30 seconds of somebody's page load converting a document.
     *
     * @param File_Attachment_Model $attachment
     * @return string Absolute path to the cached PDF rendition.
     */
    protected static function __resolve_rendition(File_Attachment_Model $attachment): string
    {
        $storage = $attachment->resolve_storage();

        // Only a RENDERED blob has a rendition to serve. PENDING means "not ready" and FAILED
        // means "never"; both answer with the state LABEL and nothing else - render_error is
        // operator information (soffice's stderr, a path on the box) and never leaves the server.
        if ((int) $storage->render_status_id !== File_Storage_Model::RENDER_STATUS_RENDERED) {
            $state = $storage->render_status_id__label;
            abort(404, "No PDF rendition available yet for this document (render state: {$state}).");
        }

        $cache_path = static::rendition_cache_path($storage);

        if (file_exists($cache_path)) {
            // LRU: keep recently served renditions from being evicted.
            touch($cache_path);

            return $cache_path;
        }

        // RENDERED, but the file is gone: the rendition cache is LRU-evicted under quota while the
        // row keeps saying RENDERED. Same self-heal as the thumbnail path - back in the queue, and
        // this request tells the truth rather than showing an error over a cache eviction.
        error_log(
            "Rendition missing for RENDERED storage #{$storage->id} ({$storage->hash}) - re-queued for rendering"
        );
        $storage->requeue_render();

        $state = $storage->render_status_id__label;
        abort(404, "No PDF rendition available yet for this document (render state: {$state}).");
    }

    /**
     * The content-addressed cache path for an attachment's PDF rendition, derived from the
     * deduplicated blob hash so identical bytes convert exactly once. The same path is globbed by
     * File_Rendition_Service for LRU cleanup.
     *
     * @param File_Storage_Model $storage
     * @return string Absolute path (storage/rsx-renditions/{hash}.pdf).
     */
    public static function rendition_cache_path(File_Storage_Model $storage): string
    {
        return Rsx_File_Paths::renditions_root() . '/' . $storage->hash . '.pdf';
    }

    /**
     * Serve a PDF file inline with immutable long-cache headers (content-addressed renditions never
     * change for a given URL).
     *
     * @param string $path Absolute path to a PDF file.
     * @param string $file_name Display filename for the Content-Disposition header.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static function __serve_pdf(string $path, string $file_name)
    {
        return Response::file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $file_name . '"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * True if this mime is listed in rsx.preview.convertible (fnmatch globs).
     *
     * @param string|null $mime
     * @return bool
     */
    protected static function __is_convertible(?string $mime): bool
    {
        return File_Attachment_Model::is_convertible_mime($mime);
    }

    /**
     * Derive a ".pdf" display filename from the source filename (swap/append the extension).
     *
     * @param string $file_name
     * @return string
     */
    protected static function __pdf_filename(string $file_name): string
    {
        $base = pathinfo($file_name, PATHINFO_FILENAME);
        if ($base === '') {
            $base = 'document';
        }

        return $base . '.pdf';
    }

    // ============================================================================================
    // PDF.JS MODULE BYTES (lazy-loaded - NOT in any bundle)
    // ============================================================================================

    /**
     * Stream the pdf.js main module from node_modules. Clients append ?v=build_key for cache-busting.
     *
     * Route: /_preview/pdfjs.mjs
     *
     * @param Request $request
     * @param array $params
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/_preview/pdfjs.mjs', methods: ['GET'])]
    public static function pdfjs(Request $request, array $params = [])
    {
        return static::__serve_module(base_path('node_modules/pdfjs-dist/build/pdf.min.mjs'));
    }

    /**
     * Stream the pdf.js worker module from node_modules. Clients append ?v=build_key.
     *
     * Route: /_preview/pdf_worker.mjs
     *
     * @param Request $request
     * @param array $params
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/_preview/pdf_worker.mjs', methods: ['GET'])]
    public static function pdf_worker(Request $request, array $params = [])
    {
        return static::__serve_module(base_path('node_modules/pdfjs-dist/build/pdf.worker.min.mjs'));
    }

    /**
     * Serve an ES module file with a long cache, or a 404 with actionable remediation when the
     * pdfjs-dist package is not installed.
     *
     * @param string $path
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected static function __serve_module(string $path)
    {
        if (!file_exists($path)) {
            return Response::make(
                'pdfjs-dist not installed - run npm install in system/',
                404,
                ['Content-Type' => 'text/plain']
            );
        }

        return Response::file($path, [
            'Content-Type' => 'text/javascript',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    // ============================================================================================
    // PREVIEW INFO (server-side viewer resolution)
    // ============================================================================================

    /**
     * Resolve everything the Document_Preview component needs to render a viewer for an attachment:
     * the viewer component name (first fnmatch match in rsx.preview.viewers), the mime/name/
     * extension, and the URLs it will fetch. Viewer resolution is server-side; no config is exported.
     *
     * Security: file.thumbnail.authorize gate (metadata, not full content - same as thumbnails).
     *
     * @param Request $request
     * @param array $params Requires attachment_id.
     * @return array
     */
    #[Ajax_Endpoint]
    public static function get_preview_info(Request $request, array $params = [])
    {
        $attachment_id = $params['attachment_id'] ?? null;
        if (!$attachment_id) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_VALIDATION, 'attachment_id is required');
        }

        $attachment = File_Attachment_Model::find($attachment_id);
        if (!$attachment) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_NOT_FOUND, 'Attachment not found');
        }

        // Authorization: thumbnail gate (same payload shape as the thumbnail handlers).
        //
        // 'user' is realm-honest. This endpoint is #[Auth_Realm('any')], so a portal page's
        // <Document_Preview> reaches it as a genuine PORTAL request; handing the app's gate
        // a staff-facade read there gives it either null or - in prefix mode, where the
        // browser still carries the staff cookie - the STAFF user, which would authorize a
        // portal viewer against staff permissions. Same idiom as /_upload. See
        // docs.dev/audits/portal_realm_session_audit_2026_08_09.md.
        $thumbnail_auth = Rsx::trigger_gate('file.thumbnail.authorize', [
            'attachment' => $attachment,
            'user' => \App\RSpade\Core\Portal\Rsx_Portal::is_portal_request()
                ? \App\RSpade\Core\Portal\Portal_Session::get_portal_user()
                : Session::get_user(),
            'request' => $request,
        ]);
        if ($thumbnail_auth !== true) {
            return response_error(\App\RSpade\Core\Ajax\Ajax::ERROR_UNAUTHORIZED, 'Not authorized to preview this file');
        }

        // The rendition URL is present ONLY when the endpoint behind it would actually serve
        // bytes, so the viewer never has to request it "early" to find out:
        //   - a PDF serves its own resident blob (its blob is NOT_REQUIRED - nothing to render);
        //   - a convertible document serves the rendition, which exists only once the background
        //     render finished AND the LRU cache still holds the file.
        // Anything else is null, and Document_Preview renders its preparing / failed / icon state
        // from render_status_id instead. The id is exported raw - the JS enum helpers on
        // File_Storage_Model turn it into a label; a *_label field here would be an alias.
        $render_status = $attachment->get_render_status();
        $rendition_url = null;

        if ($attachment->pipeline_mime() === 'application/pdf') {
            $rendition_url = Rsx::Route('File_Preview_Controller::pdf_rendition', ['key' => $attachment->key]);
        } elseif ($render_status === File_Storage_Model::RENDER_STATUS_RENDERED) {
            $storage = File_Storage_Model::find($attachment->file_storage_id);
            if ($storage && file_exists(static::rendition_cache_path($storage))) {
                $rendition_url = Rsx::Route('File_Preview_Controller::pdf_rendition', ['key' => $attachment->key]);
            }
        }

        return [
            // Viewer routes on the PIPELINE mime (extension-first for documents) so a zip-sniffed
            // docx resolves to Pdf_Viewer, not Icon_Viewer. 'mime' echoes the raw sniff (metadata).
            'viewer' => static::viewer_for_mime($attachment->pipeline_mime()),
            'mime' => $attachment->mime_type,
            'file_name' => $attachment->file_name,
            'extension' => $attachment->file_extension,
            // An unparseable image degraded to a generic file: the viewer already resolves to
            // Icon_Viewer (pipeline_mime -> octet-stream), but this lets Document_Preview render an
            // explicit "Preview unavailable" state rather than a bare file icon.
            'preview_unavailable' => $attachment->preview_unavailable,
            // File_Storage_Model::RENDER_STATUS_* on this attachment's blob, or null when there is
            // no resident blob at all (an external attachment never materialized).
            'render_status_id' => $render_status,
            'urls' => [
                'rendition' => $rendition_url,
                'inline' => $attachment->get_url(),
                'icon' => Rsx::Route('File_Attachment_Controller::icon_by_extension', ['extension' => $attachment->file_extension]),
            ],
        ];
    }

    /**
     * Resolve the viewer component (simple name) for a mime from rsx.preview.viewers (fnmatch, first
     * match wins). The config's trailing '*' entry (Icon_Viewer) guarantees a terminal result.
     *
     * @param string|null $mime
     * @return string Viewer component simple name.
     */
    public static function viewer_for_mime(?string $mime): string
    {
        $mime = ($mime === null || $mime === '') ? 'application/octet-stream' : $mime;

        foreach (config('rsx.preview.viewers', []) as $pattern => $viewer) {
            if (fnmatch($pattern, $mime)) {
                return $viewer;
            }
        }

        // The config ships a terminal '*' entry, so this is only reached on a misconfigured map.
        return 'Icon_Viewer';
    }
}
