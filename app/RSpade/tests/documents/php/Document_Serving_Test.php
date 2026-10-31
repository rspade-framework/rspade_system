<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Icons;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * SERVING a document whose render state is not NOT_REQUIRED: the thumbnail endpoint, the rendition
 * endpoint, and the preview-info payload the viewer reads.
 *
 * The invariant every test here defends is the one the async pipeline exists for: a PLACEHOLDER
 * MUST NEVER BE CACHED. The thumbnail cache key is {type}_{w}x{h}_{hash}_{ext}.webp - derived from
 * the blob hash, which is exactly the key the real render will want - so an extension icon written
 * there before the document rendered is served forever after, because a cache hit is a cache hit.
 *
 * Each test uses UNIQUE bytes, so it owns its own deduplicated blob (and therefore its own cache
 * keys and rendition path) and cannot collide with the seeded baseline or with a sibling test.
 */
class Document_Serving_Test extends Rsx_Test_Abstract
{
    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    public static function setup()
    {
        // Document_Render_Service::kick() reads this - with it off, creating an attachment or
        // re-queueing a blob records state without spawning a detached worker.
        config(['rsx.search.enabled' => false]);
        config(['rsx.libreoffice.enabled' => true]);

        // The subject here is render-state-aware SERVING, not authorization, and these tests sign
        // nobody in. Stand in for the app's authorize handlers with the documented test seam - the
        // gate cascade itself is proved in tests/attachments.
        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => true]);
        Event_Registry::_set_test_handlers('file.download.authorize', [fn($data) => true]);
    }

    public static function teardown()
    {
        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::find($id);
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        foreach (static::$created_files as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        static::$created_files = [];

        Event_Registry::_clear_test_handlers();

        config(['rsx.search.enabled' => true]);
    }

    /**
     * A real seeded site id, set as the session site (satisfies the site-scoped save hook and the
     * site FK on _file_attachments in the CLI harness).
     */
    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    /**
     * A tracked attachment over bytes nothing else in the install shares.
     */
    private static function __make_attachment(int $site_id, string $marker, string $filename): File_Attachment_Model
    {
        $bytes = "RSpade serving test {$marker} " . bin2hex(random_bytes(12));
        $attachment = File_Attachment_Model::create_from_string($bytes, $filename, ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;
        static::$created_files[] = $attachment->resolve_storage()->get_full_path();

        return $attachment;
    }

    private static function __storage(File_Attachment_Model $attachment): File_Storage_Model
    {
        return File_Storage_Model::find($attachment->file_storage_id);
    }

    /**
     * Put a REAL PDF at this blob's rendition path (the sample report), so the thumbnail pipeline
     * has something Imagick can genuinely rasterize.
     */
    private static function __install_rendition(File_Storage_Model $storage): string
    {
        $path = File_Preview_Controller::rendition_cache_path($storage);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        copy(rsx_project_file_path('rsx/services/sample_data/resource/documents/reference/sample_report.pdf'), $path);
        static::$created_files[] = $path;

        return $path;
    }

    /**
     * Both cache filenames (preset + dynamic) this blob would use for a fit/{w}x{w} thumbnail.
     */
    private static function __dynamic_cache_path(File_Storage_Model $storage, string $extension, int $width): string
    {
        return File_Attachment_Controller::_get_cache_path(
            'dynamic',
            File_Attachment_Controller::_get_cache_filename_dynamic('fit', $width, $width, $storage->hash, $extension)
        );
    }

    /**
     * Drive the dynamic thumbnail endpoint in-process the way a URL from the model's builder
     * reaches it: with the attachment's CURRENT content version as v (the route's params carry
     * the query string). Pass $version to ask as a URL built at another moment would.
     */
    private static function __request_thumbnail(File_Attachment_Model $attachment, int $width, ?string $version = null)
    {
        $version ??= File_Attachment_Model::find($attachment->id)->content_version();
        $request = Request::create("/_thumbnail/dynamic/{$attachment->key}/fit/{$width}?v={$version}", 'GET');

        return File_Attachment_Controller::thumbnail($request, [
            'key' => $attachment->key,
            'type' => 'fit',
            'width' => $width,
            'v' => $version,
        ]);
    }

    // ============================================================================================
    // THUMBNAIL SERVING
    // ============================================================================================

    // DOCUMENTS-PLACEHOLDER-NO-STORE: a PENDING (and a FAILED) blob serves the extension icon with
    // Cache-Control: no-store and writes NOTHING to the thumbnail cache. This is the cache-poisoning
    // bug: the icon must never land under the key the real render will use.
    public static function test_pending_thumbnail_is_an_uncached_placeholder()
    {
        $site_id = static::__site_id();

        $attachment = static::__make_attachment($site_id, 'pending', 'pending.docx');
        $storage = static::__storage($attachment);
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_PENDING,
            (int) $storage->render_status_id,
            'a convertible document starts out queued'
        );

        $cache_path = static::__dynamic_cache_path($storage, 'docx', 120);
        static::$created_files[] = $cache_path;

        $response = static::__request_thumbnail($attachment, 120);

        static::__assert_equals(200, $response->getStatusCode(), 'a placeholder is a 200, not an error');
        // Symfony appends 'private' to a no-store response; the directive that matters is no-store.
        static::__assert_contains(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
            'the placeholder is uncacheable'
        );
        static::__assert_false(file_exists($cache_path), 'no cache file was written for a PENDING blob');

        $expected_icon = File_Attachment_Icons::render_icon_as_thumbnail('docx', 120, 120);
        static::__assert_equals($expected_icon, $response->getContent(), 'the placeholder IS the extension icon');

        // FAILED is the same answer - terminal, but still no real thumbnail to serve or cache.
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_FAILED;
        $storage->render_error = 'soffice said no';
        $storage->save();

        $failed_response = static::__request_thumbnail($attachment, 120);
        static::__assert_contains(
            'no-store',
            (string) $failed_response->headers->get('Cache-Control'),
            'a FAILED blob serves the placeholder too'
        );
        static::__assert_false(file_exists($cache_path), 'no cache file was written for a FAILED blob');
    }

    // DOCUMENTS-THUMBNAIL-FROM-RENDITION: a RENDERED document's thumbnail is rasterized from its PDF
    // rendition - the blob itself is a .docx that Imagick cannot read, so pixels that are NOT the
    // extension icon prove the rendition was the source. The result IS cached (real key, real image).
    public static function test_rendered_thumbnail_comes_from_the_rendition()
    {
        $site_id = static::__site_id();

        $attachment = static::__make_attachment($site_id, 'rendered', 'rendered.docx');
        $storage = static::__storage($attachment);

        static::__install_rendition($storage);
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->rendered_at = \App\RSpade\Core\Time\Rsx_Time::now_iso();
        $storage->save();

        $cache_path = static::__dynamic_cache_path($storage, 'docx', 120);
        static::$created_files[] = $cache_path;

        $response = static::__request_thumbnail($attachment, 120);
        $data = $response->getContent();

        static::__assert_equals(200, $response->getStatusCode(), 'the thumbnail serves');
        static::__assert_contains('WEBP', substr($data, 0, 16), 'the response is a WebP raster');
        static::__assert_contains(
            'max-age=604800, private',
            (string) $response->headers->get('Cache-Control'),
            'a real render is cacheable for a week, privately'
        );
        static::__assert_true(file_exists($cache_path), 'the real render IS written to the cache');

        $icon = File_Attachment_Icons::render_icon_as_thumbnail('docx', 120, 120);
        static::__assert_not_equals($icon, $data, 'the bytes are page 1 of the rendition, not the extension icon');
    }

    // DOCUMENTS-MISSING-RENDITION-REQUEUES: the rendition cache is LRU-evicted under quota while the
    // row still says RENDERED. That is not an error page - the blob goes back in the queue and this
    // request serves the uncached placeholder.
    public static function test_missing_rendition_requeues_and_serves_placeholder()
    {
        $site_id = static::__site_id();

        $attachment = static::__make_attachment($site_id, 'evicted', 'evicted.docx');
        $storage = static::__storage($attachment);

        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->rendered_at = \App\RSpade\Core\Time\Rsx_Time::now_iso();
        $storage->save();

        $rendition_path = File_Preview_Controller::rendition_cache_path($storage);
        if (file_exists($rendition_path)) {
            @unlink($rendition_path);
        }

        $cache_path = static::__dynamic_cache_path($storage, 'docx', 120);
        static::$created_files[] = $cache_path;

        $response = static::__request_thumbnail($attachment, 120);

        static::__assert_contains('no-store', (string) $response->headers->get('Cache-Control'), 'the placeholder is uncacheable');
        static::__assert_false(file_exists($cache_path), 'nothing was cached for the evicted rendition');
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_PENDING,
            (int) static::__storage($attachment)->render_status_id,
            'the blob is back in the render queue'
        );
        static::__assert_null(static::__storage($attachment)->rendered_at, 're-queueing clears the stale rendered_at');
    }

    // DOCUMENTS-URL-VERSION: a thumbnail URL carries the attachment's content version,
    // <storage id>-<rendered_at unix seconds or 0>, so the URL of a queued document's placeholder
    // and the URL of its real page-1 raster are different URLs. The route serves the current
    // picture for either, and keeps its one-year max-age only for the one that is current.
    public static function test_thumbnail_url_version_tracks_rendered_at()
    {
        $site_id = static::__site_id();

        $attachment = static::__make_attachment($site_id, 'version', 'version.docx');
        $storage = static::__storage($attachment);

        $queued_version = $storage->id . '-0';
        static::__assert_equals($queued_version, $attachment->content_version(), 'nothing rendered yet -> <storage id>-0');
        static::__assert_contains('?v=' . $queued_version, $attachment->get_thumbnail_url('fit', 120), 'the URL carries it');

        static::__install_rendition($storage);
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->rendered_at = \App\RSpade\Core\Time\Rsx_Time::now_iso();
        $storage->save();

        $rendered_version = $storage->id . '-' . intdiv(\App\RSpade\Core\Time\Rsx_Time::to_ms(static::__storage($attachment)->rendered_at), 1000);
        $url = File_Attachment_Model::find($attachment->id)->get_thumbnail_url('fit', 120);
        static::__assert_contains('?v=' . $rendered_version, $url, 'the render moves the version to the rendered_at unix timestamp');

        $preset_url = File_Attachment_Model::find($attachment->id)->get_thumbnail_url_preset('icon_small');
        static::__assert_contains('?v=' . $rendered_version, $preset_url, 'preset URLs carry the same version');

        $cache_path = static::__dynamic_cache_path($storage, 'docx', 120);
        static::$created_files[] = $cache_path;

        // The URL built after the render: the real raster, kept for a year.
        $response = static::__request_thumbnail($attachment, 120, $rendered_version);
        static::__assert_equals(200, $response->getStatusCode(), 'the endpoint serves the current version');
        static::__assert_contains('max-age=604800, private', (string) $response->headers->get('Cache-Control'), 'the current URL is cacheable for a week, privately');

        // The URL a page built while the document was queued: the same real raster, never kept -
        // a browser must not pin under the placeholder's URL what that URL no longer describes.
        $response = static::__request_thumbnail($attachment, 120, $queued_version);
        static::__assert_equals(200, $response->getStatusCode(), 'a superseded version is still served');
        static::__assert_contains('WEBP', substr($response->getContent() ?: file_get_contents($response->getFile()->getPathname()), 0, 16), 'with the current picture');
        static::__assert_contains('no-store', (string) $response->headers->get('Cache-Control'), 'and is uncacheable');
    }

    // ============================================================================================
    // RENDITION SERVING
    // ============================================================================================

    // DOCUMENTS-RENDITION-STATE: /_preview/pdf/:key serves ONLY a RENDERED blob. PENDING and FAILED
    // 404 with a body naming the render state - and nothing else, because render_error is soffice
    // stderr and belongs to the operator, not to the browser.
    public static function test_rendition_endpoint_is_render_state_aware()
    {
        $site_id = static::__site_id();

        $attachment = static::__make_attachment($site_id, 'rendition', 'rendition.docx');
        $storage = static::__storage($attachment);
        $request = Request::create("/_preview/pdf/{$attachment->key}", 'GET');

        $pending = static::__assert_throws(
            HttpException::class,
            fn() => File_Preview_Controller::pdf_rendition($request, ['key' => $attachment->key]),
            'render state: Pending'
        );
        static::__assert_equals(404, $pending->getStatusCode(), 'a queued document 404s');

        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_FAILED;
        $storage->render_error = 'soffice: cannot open /var/lib/secret/profile';
        $storage->save();

        $failed = static::__assert_throws(
            HttpException::class,
            fn() => File_Preview_Controller::pdf_rendition($request, ['key' => $attachment->key]),
            'render state: Failed'
        );
        static::__assert_equals(404, $failed->getStatusCode(), 'a failed document 404s');
        static::__assert_false(
            str_contains($failed->getMessage(), 'secret'),
            'the 404 body names the state label only - never the recorded render_error'
        );

        static::__install_rendition($storage);
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->rendered_at = \App\RSpade\Core\Time\Rsx_Time::now_iso();
        $storage->render_error = null;
        $storage->save();

        $response = File_Preview_Controller::pdf_rendition($request, ['key' => $attachment->key]);
        static::__assert_equals(200, $response->getStatusCode(), 'a rendered document serves its rendition');
        static::__assert_equals(
            'application/pdf',
            $response->headers->get('Content-Type'),
            'the rendition is served as a PDF'
        );
    }

    // DOCUMENTS-PREVIEW-INFO-RENDER-STATE: the payload Document_Preview reads carries the blob's
    // render_status_id, and urls.rendition is null until there is genuinely a rendition to fetch.
    public static function test_preview_info_carries_render_state()
    {
        $site_id = static::__site_id();

        $attachment = static::__make_attachment($site_id, 'info', 'info.docx');
        $request = Request::create('/x', 'POST');

        $info = File_Preview_Controller::get_preview_info($request, ['attachment_id' => $attachment->id]);
        static::__assert_array_has_key('render_status_id', $info, 'the payload carries the render state');
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_PENDING,
            $info['render_status_id'],
            'a queued document reports PENDING'
        );
        static::__assert_null($info['urls']['rendition'], 'no rendition URL until there is a rendition');
        static::__assert_array_has_key('inline', $info['urls'], 'the other URLs are unchanged');
        static::__assert_array_has_key('icon', $info['urls'], 'the other URLs are unchanged');
        static::__assert_equals('Pdf_Viewer', $info['viewer'], 'viewer resolution is unaffected by render state');

        $storage = static::__storage($attachment);
        static::__install_rendition($storage);
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->rendered_at = \App\RSpade\Core\Time\Rsx_Time::now_iso();
        $storage->save();

        $rendered_info = File_Preview_Controller::get_preview_info($request, ['attachment_id' => $attachment->id]);
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_RENDERED,
            $rendered_info['render_status_id'],
            'the rendered state is reported'
        );
        static::__assert_contains(
            $attachment->key,
            (string) $rendered_info['urls']['rendition'],
            'the rendition URL appears once the rendition exists'
        );

        // The rendition is served cacheable for a year, so its URL carries the attachment's
        // content version - the blob and its render time: a re-render must reach a browser
        // that already holds the earlier copy.
        $first_url = (string) $rendered_info['urls']['rendition'];
        static::__assert_contains(
            '?v=' . $storage->id . '-' . intdiv(\App\RSpade\Core\Time\Rsx_Time::to_ms(static::__storage($attachment)->rendered_at), 1000),
            $first_url,
            'the URL is versioned by the blob and its render time'
        );
        static::__assert_equals(
            File_Attachment_Model::find($attachment->id)->get_preview_url(),
            $first_url,
            'and is the model builder\'s own URL'
        );
        static::__assert_equals(
            File_Attachment_Model::find($attachment->id)->get_url(),
            (string) $rendered_info['urls']['inline'],
            'the inline URL carries the same version'
        );

        $storage = static::__storage($attachment);
        $storage->rendered_at = date('Y-m-d H:i:s', strtotime((string) $storage->rendered_at) + 60);
        $storage->save();

        $rerendered_info = File_Preview_Controller::get_preview_info($request, ['attachment_id' => $attachment->id]);
        static::__assert_not_equals(
            $first_url,
            (string) $rerendered_info['urls']['rendition'],
            'a later render has a different URL'
        );
    }
}
