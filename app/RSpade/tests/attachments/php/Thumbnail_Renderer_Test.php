<?php

namespace App\RSpade\Tests\Attachments\Php;

use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Attachments\Php\Attachment_Fixture_Handler;
use App\RSpade\Tests\Attachments\Php\Attachment_Fixture_Renderer;

/**
 * Thumbnail Renderer Registry (spec section 11, criterion 10).
 *
 * Proves: the mime->renderer registry dispatches to the registered renderer; a renderer failure
 * substitutes the extension icon (explicitly, in the pipeline); has_thumbnail() reflects the
 * registry (true for image/*); and Office mimes are deliberately absent from the registry because
 * their pixels come from the background-rendered PDF (see tests/documents).
 */
class Thumbnail_Renderer_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        config(['rsx.attachments.handlers' => ['Attachment_Fixture_Handler']]);

        // Register the fixture renderer for a synthetic mime, alongside the shipped defaults.
        $renderers = config('rsx.thumbnails.renderers', []);
        $renderers['application/x-fixture'] = 'Attachment_Fixture_Renderer';
        config(['rsx.thumbnails.renderers' => $renderers]);
        config(['rsx.libreoffice.enabled' => true]);
    }

    /**
     * Resolve a real seeded site id and set it as the session site (satisfies the site-scoped save
     * hook and the site FK in the CLI test harness).
     */
    private static function __site_id(): int
    {
        $id = (int) \Illuminate\Support\Facades\DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        \App\RSpade\Core\Session\Session::set_site_id($id);
        return $id;
    }

    /**
     * A resident attachment whose mime_type is forced to $mime (a real PNG blob backs it, so the
     * source path exists, but the synthetic mime drives renderer selection). Avoids materialization
     * re-deriving the mime from bytes.
     */
    private static function __make_resident_with_mime(string $mime, string $ext): File_Attachment_Model
    {
        $bytes = Attachment_Fixture_Handler::png_bytes('a');
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_rmime_');
        file_put_contents($tmp, $bytes);
        $storage = \App\RSpade\Core\Files\File_Storage_Model::store_blob($tmp);
        @unlink($tmp);

        $a = new File_Attachment_Model();
        $a->key = File_Attachment_Model::generate_key();
        $a->file_storage_id = $storage->id;
        $a->file_name = 'doc.' . $ext;
        $a->file_extension = $ext;
        $a->mime_type = $mime;
        $a->file_size = $storage->size;
        $a->file_type_id = File_Attachment_Model::determine_file_type($mime);
        $a->site_id = static::__site_id();
        $a->save();

        return $a;
    }

    // Criterion 10: the registered renderer is dispatched for its mime.
    public static function test_registered_renderer_is_used()
    {
        Attachment_Fixture_Renderer::reset();

        $a = static::__make_resident_with_mime('application/x-fixture', 'fix');
        $storage = $a->resolve_storage();

        $data = File_Attachment_Controller::_render_thumbnail_data($a, $storage->get_full_path(), 'fit', 32, 32);

        static::__assert_equals(1, Attachment_Fixture_Renderer::$render_count, 'fixture renderer dispatched');
        static::__assert_contains('WEBP', substr($data, 0, 16), 'renderer output encoded to WebP');
    }

    // Criterion 10: a renderer failure falls back to the extension icon (no throw escapes).
    public static function test_renderer_failure_falls_back_to_icon()
    {
        Attachment_Fixture_Renderer::reset();
        Attachment_Fixture_Renderer::$should_throw = true;

        $a = static::__make_resident_with_mime('application/x-fixture', 'fix');
        $storage = $a->resolve_storage();

        $data = File_Attachment_Controller::_render_thumbnail_data($a, $storage->get_full_path(), 'fit', 32, 32);

        static::__assert_equals(1, Attachment_Fixture_Renderer::$render_count, 'renderer was attempted');
        static::__assert_true(strlen($data) > 0, 'icon substitution produced bytes');
        static::__assert_contains('WEBP', substr($data, 0, 16), 'icon substitution is a WebP');
    }

    // Criterion 10: has_thumbnail() reflects the registry (image/* included).
    public static function test_has_thumbnail_reflects_registry()
    {
        $img = new File_Attachment_Model();
        $img->mime_type = 'image/png';
        static::__assert_true($img->has_thumbnail(), 'image/* has a renderer');

        $fix = new File_Attachment_Model();
        $fix->mime_type = 'application/x-fixture';
        static::__assert_true($fix->has_thumbnail(), 'registered custom mime has a renderer');

        $zip = new File_Attachment_Model();
        $zip->mime_type = 'application/zip';
        static::__assert_false($zip->has_thumbnail(), 'unregistered mime has no renderer');
    }

    // image/* always resolves to the Imagick renderer (the byte-identical historic path).
    public static function test_image_uses_imagick_renderer()
    {
        static::__assert_equals(
            'Imagick_Thumbnail_Renderer',
            File_Attachment_Controller::renderer_class_for_mime('image/jpeg'),
            'image mimes map to the Imagick renderer'
        );
    }

    // Office documents have NO registered renderer: their pixels come from the PDF rendition the
    // background render worker produces, which the pipeline rasterizes as an ordinary PDF. A
    // renderer entry here would mean converting a document inside a web request.
    public static function test_office_mimes_have_no_registered_renderer()
    {
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        static::__assert_null(
            File_Attachment_Controller::renderer_class_for_mime($docx),
            'office mimes are not in the renderer registry - they render from the rendition'
        );

        static::__assert_equals(
            'Imagick_Thumbnail_Renderer',
            File_Attachment_Controller::renderer_class_for_mime('application/pdf'),
            'the rendition itself is rasterized as a PDF by Imagick'
        );
    }
}
