<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Api\Files_Api_Controller;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * An attachment's CONTENT VERSION, the URLs that carry it, and the cache policy that reads it.
 *
 * An attachment's key is stable and its bytes are not: relink_storage() repoints it at another
 * blob under the same key. Every file route is addressed by the key, so the URL has to say WHICH
 * bytes it was built for, and the route has to refuse to let a browser keep a response under a
 * URL that says nothing or says something out of date. Three things are proved here:
 *
 *   - content_version() is `<file_storage_id>-<rendered_at unix seconds or 0>`, `0-0` with no
 *     resident blob; it moves on a relink and on rendered_at, and on nothing else;
 *   - every URL the framework builds for an attachment carries it as ?v=, in both realms and in
 *     the external API's file payload;
 *   - each attachment-addressed route keeps its long-lived Cache-Control only for a request whose
 *     v is CURRENT, and answers no-store for an absent or superseded one - while always serving
 *     the attachment's current bytes.
 *
 * The browser half - PHP and JavaScript producing the same string, and mounted components moving
 * to new URLs after a relink with no reload - is playwright/attachment_content_version_swap.js.
 */
class Attachment_Content_Version_Test extends Rsx_Test_Abstract
{
    private const LONG_LIVED = 'max-age=31536000';

    /** @var array<int> ids of attachments created during the class, destroyed in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    private static $saved_portal_url = null;

    private static $saved_presets = null;

    public static function setup()
    {
        // With the kick switch off, creating a convertible attachment records its PENDING state
        // without spawning a detached render worker.
        config(['rsx.search.enabled' => false]);
        config(['rsx.libreoffice.enabled' => true]);

        static::$saved_portal_url = config('rsx.portal.url');
        static::$saved_presets = config('rsx.thumbnails.presets');
        config(['rsx.thumbnails.presets' => ['content_version_probe' => ['type' => 'cover', 'width' => 24, 'height' => 24]]]);

        // The subject is versioning and cache policy, not authorization; the gate cascade is
        // proved in File_Read_Gate_Test.
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
        Rsx_Request_Channel::reset();

        config(['rsx.portal.url' => static::$saved_portal_url]);
        config(['rsx.thumbnails.presets' => static::$saved_presets]);
        config(['rsx.search.enabled' => true]);
    }

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    /** A PNG nothing else in the install shares: one random colour, so one blob of its own. */
    private static function __png_bytes(): string
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagesetpixel($image, 1, 1, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagesetpixel($image, 2, 2, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A one-page PDF with a unique line of text, so each call is a blob of its own. */
    private static function __pdf_bytes(): string
    {
        $stream = 'BT /F1 18 Tf 20 100 Td (content version ' . bin2hex(random_bytes(8)) . ') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    private static function __make_attachment(string $bytes, string $filename): File_Attachment_Model
    {
        $attachment = File_Attachment_Model::create_from_string($bytes, $filename, ['site_id' => static::__site_id()]);
        static::$created_attachment_ids[] = $attachment->id;
        static::$created_files[] = $attachment->resolve_storage()->get_full_path();

        return $attachment;
    }

    /** A second blob, referenced by nothing yet, for an attachment to be relinked to. */
    private static function __make_blob(string $bytes): File_Storage_Model
    {
        $path = tempnam(sys_get_temp_dir(), 'rsx_content_version_');
        file_put_contents($path, $bytes);
        $storage = File_Storage_Model::store_blob($path, fn () => null);
        @unlink($path);
        static::$created_files[] = $storage->get_full_path();

        return $storage;
    }

    private static function __fresh(File_Attachment_Model $attachment): File_Attachment_Model
    {
        return File_Attachment_Model::find($attachment->id);
    }

    private static function __cache_control($response): string
    {
        return (string) $response->headers->get('Cache-Control');
    }

    /**
     * Assert one route's three answers: the current version is long-lived, a superseded one and
     * an absent one are no-store. $call receives the params a dispatched request would carry.
     */
    private static function __assert_policy(string $route, File_Attachment_Model $attachment, string $stale_version, callable $call): void
    {
        $key = ['key' => $attachment->key];
        $current = static::__fresh($attachment)->content_version();

        $response = $call($key + ['v' => $current]);
        static::__assert_equals(200, $response->getStatusCode(), "{$route}: serves for the current version");
        static::__assert_contains(self::LONG_LIVED, static::__cache_control($response), "{$route}: the current version is cacheable for a year");
        static::__assert_false(str_contains(static::__cache_control($response), 'no-store'), "{$route}: the current version is not no-store");

        $response = $call($key + ['v' => $stale_version]);
        static::__assert_equals(200, $response->getStatusCode(), "{$route}: a superseded version still serves the current bytes");
        static::__assert_contains('no-store', static::__cache_control($response), "{$route}: a superseded version is uncacheable");
        static::__assert_false(str_contains(static::__cache_control($response), 'max-age'), "{$route}: a superseded version carries no max-age");

        $response = $call($key);
        static::__assert_equals(200, $response->getStatusCode(), "{$route}: serves with no version at all");
        static::__assert_contains('no-store', static::__cache_control($response), "{$route}: a URL with no version is uncacheable");

        $response = $call($key + ['v' => [$current]]);
        static::__assert_contains('no-store', static::__cache_control($response), "{$route}: a version that is not a string is not current");
    }

    // ============================================================================================
    // THE DEFINITION
    // ============================================================================================

    // ATTACH-VERSION-01
    public static function test_the_version_is_the_storage_id_and_the_render_time()
    {
        $attachment = static::__make_attachment(static::__png_bytes(), 'shape.png');

        static::__assert_equals(
            $attachment->file_storage_id . '-0',
            $attachment->content_version(),
            'an unrendered blob: its storage id, and 0'
        );

        $blobless = new File_Attachment_Model();
        $blobless->key = 'k';
        static::__assert_equals('0-0', $blobless->content_version(), 'no resident blob has a defined version');

        $storage = File_Storage_Model::find($attachment->file_storage_id);
        static::__assert_false(
            str_contains($attachment->content_version(), substr((string) $storage->hash, 0, 6)),
            'the version carries no part of the content hash'
        );

        static::__assert_true($attachment->is_current_content_version($attachment->content_version()));
        static::__assert_false($attachment->is_current_content_version(null), 'an absent version is not current');
        static::__assert_false($attachment->is_current_content_version(''), 'an empty version is not current');
        static::__assert_false($attachment->is_current_content_version((string) $attachment->file_storage_id), 'half a version is not current');
        static::__assert_false($attachment->is_current_content_version((int) $attachment->file_storage_id), 'only the exact string is current');
    }

    // ATTACH-VERSION-02
    public static function test_the_version_moves_on_a_relink_and_on_a_render_and_on_nothing_else()
    {
        $first_bytes = static::__png_bytes();
        $attachment = static::__make_attachment($first_bytes, 'moves.png');
        $first_storage_id = (int) $attachment->file_storage_id;
        $first = $attachment->content_version();

        // Nothing about the attachment row itself is in the version.
        $attachment->file_name = 'renamed.png';
        $attachment->save();
        static::__assert_equals($first, static::__fresh($attachment)->content_version(), 'a rename leaves it alone');

        $attachment->resolve_storage();
        static::__assert_equals($first, static::__fresh($attachment)->content_version(), 'reading the bytes leaves it alone');

        // Nor is anything about the blob but the moment it rendered.
        $storage = File_Storage_Model::find($first_storage_id);
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_PENDING;
        $storage->save();
        static::__assert_equals($first, static::__fresh($attachment)->content_version(), 'a render state with no render time leaves it alone');

        // The same bytes are the same blob, so storing them again is not a change.
        $again = static::__make_blob($first_bytes);
        static::__assert_equals($first_storage_id, (int) $again->id, 'identical bytes resolve to the same storage row');
        $attachment->relink_storage($again);
        static::__assert_equals($first, static::__fresh($attachment)->content_version(), 'a relink to the same bytes leaves it alone');

        // Different bytes under the same key.
        $key = $attachment->key;
        $other = static::__make_blob(static::__png_bytes());
        $attachment->relink_storage($other);
        $relinked = static::__fresh($attachment);

        static::__assert_equals($key, $relinked->key, 'the key is stable across a relink');
        static::__assert_not_equals($first, $relinked->content_version(), 'a relink to different bytes moves the version');
        $unrendered = $relinked->content_version();
        static::__assert_equals($other->id . '-0', $unrendered, 'to the new blob\'s storage id');

        // The picture of the SAME bytes changing: a render lands.
        $other = File_Storage_Model::find($other->id);
        $other->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $other->rendered_at = Rsx_Time::now_iso();
        $other->save();

        $rendered_at = File_Storage_Model::find($other->id)->rendered_at;
        $rendered = static::__fresh($attachment)->content_version();
        static::__assert_equals(
            $other->id . '-' . intdiv(Rsx_Time::to_ms($rendered_at), 1000),
            $rendered,
            'a render moves it: the storage id and rendered_at in unix seconds'
        );
        static::__assert_not_equals($unrendered, $rendered);

        // And a later render of the same blob moves it again.
        $other->rendered_at = Rsx_Time::add($rendered_at, 90);
        $other->save();
        static::__assert_not_equals($rendered, static::__fresh($attachment)->content_version(), 'a re-render moves it again');
    }

    // ============================================================================================
    // THE BUILDERS
    // ============================================================================================

    // ATTACH-VERSION-03
    public static function test_every_builder_carries_the_version()
    {
        Rsx_Request_Channel::reset();

        $image = static::__make_attachment(static::__png_bytes(), 'builders.png');
        $pdf = static::__make_attachment(static::__pdf_bytes(), 'builders.pdf');
        $sheet = static::__make_attachment('content version sheet ' . bin2hex(random_bytes(8)), 'builders.xlsx');

        $v = '?v=' . $image->content_version();
        $key = $image->key;

        static::__assert_equals("/_inline/{$key}{$v}", $image->get_url());
        static::__assert_equals("/_download/{$key}{$v}", $image->get_download_url());
        static::__assert_equals("/_thumbnail/dynamic/{$key}/fit/400{$v}", $image->get_thumbnail_url());
        static::__assert_equals("/_thumbnail/dynamic/{$key}/cover/64/48{$v}", $image->get_thumbnail_url('cover', 64, 48));
        static::__assert_equals("/_thumbnail/preset/{$key}/content_version_probe{$v}", $image->get_thumbnail_url_preset('content_version_probe'));

        static::__assert_equals("/_preview/pdf/{$pdf->key}?v=" . $pdf->content_version(), $pdf->get_preview_url(), 'a PDF previews at the PDF route');
        static::__assert_equals("/_preview/sheet/{$sheet->key}?v=" . $sheet->content_version(), $sheet->get_preview_url(), 'a workbook previews at the sheet route');

        // The payload <Document_Preview> reads is built from the same builders.
        $request = Request::create('/x', 'POST');
        $info = File_Preview_Controller::get_preview_info($request, ['attachment_id' => $pdf->id]);
        static::__assert_equals($pdf->get_preview_url(), $info['urls']['rendition'], 'a PDF\'s rendition URL is versioned');
        static::__assert_equals($pdf->get_url(), $info['urls']['inline'], 'the inline URL is versioned');

        $info = File_Preview_Controller::get_preview_info($request, ['attachment_id' => $image->id]);
        static::__assert_equals($image->get_url(), $info['urls']['inline'], 'what Image_Viewer loads is versioned');

        // A rendered workbook's rendition URL is the same builder's.
        $storage = File_Storage_Model::find($sheet->file_storage_id);
        $rendition = File_Preview_Controller::sheet_rendition_cache_path($storage);
        if (!is_dir(dirname($rendition))) {
            mkdir(dirname($rendition), 0755, true);
        }
        file_put_contents($rendition, '<table><tr><td>1</td></tr></table>');
        static::$created_files[] = $rendition;
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->rendered_at = Rsx_Time::now_iso();
        $storage->save();

        $info = File_Preview_Controller::get_preview_info($request, ['attachment_id' => $sheet->id]);
        static::__assert_equals(static::__fresh($sheet)->get_preview_url(), $info['urls']['rendition']);
        static::__assert_contains('/_preview/sheet/', (string) $info['urls']['rendition']);
        static::__assert_contains('?v=' . $storage->id . '-' . intdiv(Rsx_Time::to_ms(File_Storage_Model::find($storage->id)->rendered_at), 1000), (string) $info['urls']['rendition']);

        // After a relink every one of them is a different URL.
        $before = [$image->get_url(), $image->get_download_url(), $image->get_thumbnail_url(), $image->get_thumbnail_url_preset('content_version_probe'), $image->get_preview_url()];
        $image->relink_storage(static::__make_blob(static::__png_bytes()));
        $image = static::__fresh($image);
        $after = [$image->get_url(), $image->get_download_url(), $image->get_thumbnail_url(), $image->get_thumbnail_url_preset('content_version_probe'), $image->get_preview_url()];

        foreach ($before as $index => $url) {
            static::__assert_not_equals($url, $after[$index], 'a relink changes ' . strtok($url, '?'));
            static::__assert_equals(strtok($url, '?'), strtok($after[$index], '?'), 'and only its version');
        }
    }

    // ATTACH-VERSION-04
    public static function test_portal_realm_urls_carry_the_version_under_the_prefix()
    {
        config(['rsx.portal.url' => '']);
        $app = rtrim((string) config('app.url'), '/');

        $attachment = static::__make_attachment(static::__png_bytes(), 'portal.png');
        $v = '?v=' . $attachment->content_version();
        $key = $attachment->key;

        Rsx_Request_Channel::classify(Request::create($app . '/_portal/documents'));

        static::__assert_equals("/_portal/_inline/{$key}{$v}", $attachment->get_url());
        static::__assert_equals("/_portal/_download/{$key}{$v}", $attachment->get_download_url());
        static::__assert_equals("/_portal/_thumbnail/dynamic/{$key}/fit/120{$v}", $attachment->get_thumbnail_url('fit', 120));
        static::__assert_equals("/_portal/_thumbnail/preset/{$key}/content_version_probe{$v}", $attachment->get_thumbnail_url_preset('content_version_probe'));
        static::__assert_equals("/_portal/_preview/pdf/{$key}{$v}", $attachment->get_preview_url());

        Rsx_Request_Channel::reset();
    }

    // ATTACH-VERSION-05
    public static function test_both_fetch_payloads_carry_what_the_browser_derives_the_version_from()
    {
        Rsx_Request_Channel::reset();

        $attachment = static::__make_attachment('content version fetch ' . bin2hex(random_bytes(8)), 'fetch.docx');
        $storage = File_Storage_Model::find($attachment->file_storage_id);
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->rendered_at = Rsx_Time::now_iso();
        $storage->save();

        foreach (['fetch' => File_Attachment_Model::fetch($attachment->id), 'portal_fetch' => File_Attachment_Model::portal_fetch($attachment->id)] as $name => $payload) {
            static::__assert_true(is_array($payload), "{$name}() answers the record");
            static::__assert_equals((int) $attachment->file_storage_id, (int) $payload['file_storage_id'], "{$name}(): the storage id");
            static::__assert_equals((int) $attachment->file_storage_id, (int) $payload['file_storage']['id']);
            static::__assert_not_null($payload['file_storage']['rendered_at'], "{$name}(): the render time");

            // The derivation File_Attachment_Model.content_version(record) performs in JavaScript.
            $derived = ((int) $payload['file_storage_id']) . '-' . intdiv(Rsx_Time::to_ms($payload['file_storage']['rendered_at']), 1000);
            static::__assert_equals(static::__fresh($attachment)->content_version(), $derived, "{$name}(): the payload reproduces the version");
        }
    }

    // ATTACH-VERSION-06
    public static function test_the_external_api_payload_urls_carry_the_version()
    {
        Rsx_Request_Channel::reset();
        static::__acting_as_user(1);

        $attachment = static::__make_attachment(static::__pdf_bytes(), 'api.pdf');
        $v = '?v=' . $attachment->content_version();
        $origin = rtrim((string) config('app.url'), '/');

        $payload = Files_Api_Controller::info(Request::create('/api/v1/files/' . $attachment->key, 'GET'), ['key' => $attachment->key]);
        static::__assert_true(is_array($payload) && isset($payload['urls']), 'the info endpoint answers the file payload');

        static::__assert_equals("{$origin}/_download/{$attachment->key}{$v}", $payload['urls']['download']);
        static::__assert_equals("{$origin}/_inline/{$attachment->key}{$v}", $payload['urls']['inline']);
        static::__assert_equals("{$origin}/_thumbnail/dynamic/{$attachment->key}/fit/400{$v}", $payload['urls']['thumbnail']);
        static::__assert_equals("{$origin}/_preview/pdf/{$attachment->key}{$v}", $payload['urls']['preview']);
    }

    // ============================================================================================
    // THE CACHE POLICY
    // ============================================================================================

    // ATTACH-VERSION-07
    public static function test_inline_and_download_are_cacheable_only_for_the_current_version()
    {
        Rsx_Request_Channel::reset();

        $first_bytes = static::__png_bytes();
        $attachment = static::__make_attachment($first_bytes, 'policy.png');
        $stale = $attachment->content_version();

        $second_bytes = static::__png_bytes();
        $attachment->relink_storage(static::__make_blob($second_bytes));

        $request = Request::create('/x', 'GET');

        static::__assert_policy('/_inline', $attachment, $stale, fn (array $params) => File_Attachment_Controller::inline($request, $params));
        static::__assert_policy('/_download', $attachment, $stale, fn (array $params) => File_Attachment_Controller::download_file($request, $params));

        // Whatever v says, the bytes are the attachment's current ones.
        foreach ([$stale, null] as $version) {
            $response = File_Attachment_Controller::download_file($request, ['key' => $attachment->key] + ($version === null ? [] : ['v' => $version]));
            static::__assert_equals(
                hash('sha256', $second_bytes),
                hash_file('sha256', $response->getFile()->getPathname()),
                'the response is the current blob, not the one the URL was built for'
            );
        }
    }

    // ATTACH-VERSION-08
    public static function test_thumbnails_are_cacheable_only_for_the_current_version()
    {
        Rsx_Request_Channel::reset();

        $attachment = static::__make_attachment(static::__png_bytes(), 'thumb.png');
        $stale = $attachment->content_version();
        $attachment->relink_storage(static::__make_blob(static::__png_bytes()));

        $storage = File_Storage_Model::find(static::__fresh($attachment)->file_storage_id);
        static::$created_files[] = File_Attachment_Controller::_get_cache_path(
            'dynamic',
            File_Attachment_Controller::_get_cache_filename_dynamic('fit', 40, 40, $storage->hash, 'png')
        );
        static::$created_files[] = File_Attachment_Controller::_get_cache_path(
            'preset',
            File_Attachment_Controller::_get_cache_filename_preset('content_version_probe', $storage->hash, 'png')
        );

        $request = Request::create('/x', 'GET');

        // Asked twice per version on purpose: the first answer is rendered and the later ones are
        // read back from the thumbnail cache, and both paths obey the same rule.
        static::__assert_policy('/_thumbnail/dynamic', $attachment, $stale, fn (array $params) => File_Attachment_Controller::thumbnail($request, $params + ['type' => 'fit', 'width' => 40]));
        static::__assert_policy('/_thumbnail/dynamic (cached)', $attachment, $stale, fn (array $params) => File_Attachment_Controller::thumbnail($request, $params + ['type' => 'fit', 'width' => 40]));
        static::__assert_policy('/_thumbnail/preset', $attachment, $stale, fn (array $params) => File_Attachment_Controller::thumbnail_preset($request, $params + ['preset_name' => 'content_version_probe']));
    }

    // ATTACH-VERSION-09
    public static function test_a_placeholder_is_uncacheable_even_for_the_current_version()
    {
        Rsx_Request_Channel::reset();

        $attachment = static::__make_attachment('content version pending ' . bin2hex(random_bytes(8)), 'pending.docx');
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, $attachment->get_render_status(), 'a new convertible document is queued');

        $response = File_Attachment_Controller::thumbnail(Request::create('/x', 'GET'), [
            'key' => $attachment->key,
            'type' => 'fit',
            'width' => 40,
            'v' => $attachment->content_version(),
        ]);

        static::__assert_equals(200, $response->getStatusCode());
        static::__assert_contains('no-store', static::__cache_control($response), 'the extension icon of a queued render is never kept');
    }

    // ATTACH-VERSION-10
    public static function test_the_renditions_are_cacheable_only_for_the_current_version()
    {
        Rsx_Request_Channel::reset();

        $request = Request::create('/x', 'GET');

        // A PDF is its own rendition.
        $pdf = static::__make_attachment(static::__pdf_bytes(), 'rendition.pdf');
        $stale = $pdf->content_version();
        $pdf->relink_storage(static::__make_blob(static::__pdf_bytes()));

        static::__assert_policy('/_preview/pdf', $pdf, $stale, fn (array $params) => File_Preview_Controller::pdf_rendition($request, $params));
        static::__assert_contains(
            'immutable',
            static::__cache_control(File_Preview_Controller::pdf_rendition($request, ['key' => $pdf->key, 'v' => static::__fresh($pdf)->content_version()])),
            'the current rendition URL is immutable'
        );

        // A workbook's is the HTML grid - and here the superseded version is a RENDER, not a relink.
        $sheet = static::__make_attachment('content version workbook ' . bin2hex(random_bytes(8)), 'rendition.xlsx');
        $storage = File_Storage_Model::find($sheet->file_storage_id);
        $rendition = File_Preview_Controller::sheet_rendition_cache_path($storage);
        if (!is_dir(dirname($rendition))) {
            mkdir(dirname($rendition), 0755, true);
        }
        file_put_contents($rendition, '<table><tr><td>1</td></tr></table>');
        static::$created_files[] = $rendition;

        $before_render = $sheet->content_version();
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_RENDERED;
        $storage->rendered_at = Rsx_Time::now_iso();
        $storage->save();

        static::__assert_policy('/_preview/sheet', $sheet, $before_render, fn (array $params) => File_Preview_Controller::sheet_rendition($request, $params));
    }
}
