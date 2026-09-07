<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Preview\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Server-side preview info: viewer resolution by mime, the content-addressed rendition cache path
 * derivation, and the get_preview_info Ajax endpoint (viewer + mime + type-safe URLs) behind the
 * thumbnail authorization gate.
 */
class Preview_Info_Test extends Rsx_Test_Abstract
{
    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    public static function setup()
    {
        // Never let a create-kick spawn a real extraction worker.
        config(['rsx.search.enabled' => false]);
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

        config(['rsx.search.enabled' => true]);
    }

    public static function test_viewer_resolution()
    {
        static::__assert_equals('Pdf_Viewer', File_Preview_Controller::viewer_for_mime('application/pdf'), 'pdf -> Pdf_Viewer');
        static::__assert_equals(
            'Pdf_Viewer',
            File_Preview_Controller::viewer_for_mime('application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'docx -> Pdf_Viewer (served as a cached PDF rendition)'
        );
        static::__assert_equals('Image_Viewer', File_Preview_Controller::viewer_for_mime('image/png'), 'image -> Image_Viewer');
        static::__assert_equals('Icon_Viewer', File_Preview_Controller::viewer_for_mime('application/x-unknown-xyz'), 'unknown -> Icon_Viewer (terminal)');
        static::__assert_equals('Icon_Viewer', File_Preview_Controller::viewer_for_mime(null), 'null mime -> Icon_Viewer (terminal)');
    }

    public static function test_rendition_cache_path_derivation()
    {
        $storage = new File_Storage_Model();
        $storage->hash = 'abc123def456';

        // Resolve the expected root through the choke point so this holds in both default mode
        // and under the test run's rsx.files.storage_root override (B-38 isolation).
        static::__assert_equals(
            Rsx_File_Paths::renditions_root() . '/abc123def456.pdf',
            File_Preview_Controller::rendition_cache_path($storage),
            'rendition cache path is content-addressed on the blob hash'
        );
    }

    public static function test_get_preview_info_for_pdf()
    {
        $user = DB::selectOne('SELECT id, site_id FROM users ORDER BY id LIMIT 1');
        if (!$user) {
            static::__skip('no seeded user to satisfy the thumbnail authorization gate');
            return;
        }

        Session::set_site_id((int) $user->site_id);
        static::__acting_as_user((int) $user->id);

        $pdf = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
        $attachment = File_Attachment_Model::create_from_string($pdf, 'preview_info.pdf', ['site_id' => (int) $user->site_id]);
        static::$created_attachment_ids[] = $attachment->id;

        $info = File_Preview_Controller::get_preview_info(Request::create('/x', 'POST'), ['attachment_id' => $attachment->id]);

        static::__assert_equals('Pdf_Viewer', $info['viewer'], 'a PDF resolves to the Pdf_Viewer');
        static::__assert_equals('application/pdf', $info['mime'], 'mime echoed');
        static::__assert_equals('preview_info.pdf', $info['file_name'], 'file_name echoed');
        static::__assert_equals('pdf', $info['extension'], 'extension echoed');
        static::__assert_array_has_key('rendition', $info['urls'], 'rendition url present');
        static::__assert_array_has_key('inline', $info['urls'], 'inline url present');
        static::__assert_array_has_key('icon', $info['urls'], 'icon url present');
        static::__assert_contains($attachment->key, (string) $info['urls']['rendition'], 'rendition url is type-safe (carries the key)');
        static::__assert_contains($attachment->key, (string) $info['urls']['inline'], 'inline url carries the key');
        static::__assert_contains('pdf', (string) $info['urls']['icon'], 'icon url carries the extension');
    }
}
