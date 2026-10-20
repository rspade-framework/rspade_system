<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\Document_Sandbox;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Preview_Controller;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Libreoffice_Document_Renderer;
use App\RSpade\Core\Files\Spreadsheet_Document_Renderer;
use App\RSpade\Core\Providers\Rsx_Framework_Provider;
use App\RSpade\Core\Search\Libreoffice_Text_Extractor;
use App\RSpade\Core\Search\Pdftotext_Text_Extractor;
use App\RSpade\Core\Search\Plain_Text_Extractor;
use App\RSpade\Core\Search\Search_Index_Service;
use App\RSpade\Core\Search\Spreadsheet_Text_Extractor;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Documents\Php\Document_Renderer_Fixture_Renderer;

/**
 * The document renderer registry (rsx.documents.renderers): which renderer a type resolves to,
 * that an application's renderer for a framework type wins and is what the worker runs, that the
 * rendition is cached where its format says, that the retired rsx.preview.convertible key is
 * refused, and the soft-failure checks every framework renderer and extractor declares.
 *
 * Soft-failure cases point the binary paths (rsx.libreoffice.binary_path,
 * rsx.search.pdftotext_binary_path) at files this test writes - one executable, one not - so the
 * answers do not depend on what the host has installed. The sandbox cases force the image answer
 * through Document_Sandbox::_testing_set_image_present() and never run docker.
 *
 * This class COMMITS attachments (the worker reads them back through its own queries), so it
 * declares the $requires_db_reset pair and removes what it created in teardown. KICK
 * SUPPRESSION: rsx.search.enabled=false for the whole class, so creating an attachment queues
 * nothing.
 */
class Document_Renderer_Registry_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;
    protected static $requires_db_reset = true;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * The retired rsx.preview.convertible list exactly as it last shipped - the parity reference
     * for is_convertible_mime().
     */
    private const RETIRED_CONVERTIBLE = [
        'application/msword',
        'application/vnd.ms-word*',
        'application/vnd.ms-excel*',
        'application/vnd.ms-powerpoint*',
        'application/vnd.openxmlformats-officedocument.*',
        'application/vnd.oasis.opendocument.*',
        'application/rtf',
    ];

    /** @var array<int> ids of attachments created during the class, removed in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    /** @var array The shipped renderer map, restored after every override. */
    private static $shipped_renderers = [];

    public static function setup()
    {
        config(['rsx.search.enabled' => false]);
        static::$shipped_renderers = config('rsx.documents.renderers');
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

        Document_Sandbox::_testing_set_image_present(null);
        config([
            'rsx.search.enabled' => true,
            'rsx.libreoffice.enabled' => true,
            'rsx.libreoffice.sandbox' => 'none',
            'rsx.libreoffice.binary_path' => null,
            'rsx.search.pdftotext_binary_path' => null,
            'rsx.documents.renderers' => static::$shipped_renderers,
        ]);
    }

    // ============================================================================================
    // HELPERS
    // ============================================================================================

    /**
     * Run $fn with config keys set, restoring each key's previous value afterwards.
     */
    private static function __with_config(array $values, callable $fn)
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = config($key);
        }

        config($values);

        try {
            return $fn();
        } finally {
            config($previous);
        }
    }

    /**
     * A file this test owns, with the given mode. Returns its absolute path.
     */
    private static function __scratch_file(string $content, int $mode): string
    {
        $path = sys_get_temp_dir() . '/rsx_renderer_registry_' . bin2hex(random_bytes(6));
        file_put_contents($path, $content);
        chmod($path, $mode);
        static::$created_files[] = $path;

        return $path;
    }

    /**
     * A tracked attachment (and its own blob) from unique bytes.
     */
    private static function __make_attachment(string $filename): File_Attachment_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        $attachment = File_Attachment_Model::create_from_string(
            'RSpade renderer registry test ' . bin2hex(random_bytes(8)),
            $filename,
            ['site_id' => $site_id]
        );
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    // ============================================================================================
    // RESOLUTION
    // ============================================================================================

    // DOCUMENTS-RENDERER-RESOLUTION: the shipped map sends word-processing and presentation types
    // to LibreOffice and workbooks to the spreadsheet renderer; a PDF and an image get none.
    public static function test_renderer_resolution_for_shipped_types()
    {
        static::__assert_equals(Libreoffice_Document_Renderer::class, Document_Render_Service::renderer_for_mime(self::DOCX_MIME), 'docx renders through LibreOffice');
        static::__assert_equals(Spreadsheet_Document_Renderer::class, Document_Render_Service::renderer_for_mime(self::XLSX_MIME), 'xlsx renders as a grid');
        static::__assert_equals(Spreadsheet_Document_Renderer::class, Document_Render_Service::renderer_for_mime('application/vnd.ms-excel'), 'xls renders as a grid');
        static::__assert_equals(Spreadsheet_Document_Renderer::class, Document_Render_Service::renderer_for_mime('application/vnd.oasis.opendocument.spreadsheet'), 'ods renders as a grid');
        static::__assert_equals(Libreoffice_Document_Renderer::class, Document_Render_Service::renderer_for_mime('application/vnd.oasis.opendocument.text'), 'odt renders through LibreOffice');
        static::__assert_null(Document_Render_Service::renderer_for_mime('application/pdf'), 'a PDF is its own rendition - no renderer');
        static::__assert_null(Document_Render_Service::renderer_for_mime('image/png'), 'an image gets no rendition');
        static::__assert_null(Document_Render_Service::renderer_for_mime(null), 'no mime, no renderer');

        static::__assert_equals('pdf', Document_Render_Service::rendition_format_for_mime(self::DOCX_MIME), 'docx renders to a PDF');
        static::__assert_equals('html', Document_Render_Service::rendition_format_for_mime(self::XLSX_MIME), 'xlsx renders to HTML');
        static::__assert_null(Document_Render_Service::rendition_format_for_mime('image/png'), 'no renderer, no format');
    }

    // DOCUMENTS-RENDERER-APP-WINS: an application entry for a framework type beats the framework's
    // entry - restating the exact pattern, or with a glob broader than the framework's own.
    public static function test_application_renderer_for_a_framework_type_wins()
    {
        static::__with_config([
            'rsx.documents.renderers' => static::$shipped_renderers + [self::DOCX_MIME => 'Document_Renderer_Fixture_Renderer'],
        ], function () {
            static::__assert_equals(Document_Renderer_Fixture_Renderer::class, Document_Render_Service::renderer_for_mime(self::DOCX_MIME), 'the app renderer takes docx');
            static::__assert_equals(Spreadsheet_Document_Renderer::class, Document_Render_Service::renderer_for_mime(self::XLSX_MIME), 'xlsx is untouched');
        });

        // An application glob is broader than the framework's spreadsheetml.* yet still wins.
        $shipped = static::$shipped_renderers;
        $shipped['application/vnd.openxmlformats-officedocument.*'] = 'Document_Renderer_Fixture_Renderer';
        static::__with_config(['rsx.documents.renderers' => $shipped], function () {
            static::__assert_equals(Document_Renderer_Fixture_Renderer::class, Document_Render_Service::renderer_for_mime(self::XLSX_MIME), 'an app glob beats the framework\'s more specific spreadsheet glob');
            static::__assert_equals('pdf', Document_Render_Service::rendition_format_for_mime(self::XLSX_MIME), 'the app renderer\'s rendition is a PDF');
        });
    }

    // DOCUMENTS-RENDERER-WORKER-USES-APP: the worker's unit of work runs the application renderer it
    // resolves, and caches its rendition at the PDF path.
    public static function test_worker_runs_the_application_renderer()
    {
        $attachment = static::__make_attachment('registry.docx');
        $storage = File_Storage_Model::find($attachment->file_storage_id);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_PENDING, (int) $storage->render_status_id, 'precondition: a rendition is owed');

        // Extraction is not the subject here.
        $storage->is_indexed = 1;
        $storage->save();

        $rendition_path = File_Preview_Controller::rendition_cache_path($storage);
        static::$created_files[] = $rendition_path;

        static::__with_config([
            'rsx.documents.renderers' => static::$shipped_renderers + [self::DOCX_MIME => 'Document_Renderer_Fixture_Renderer'],
        ], function () use ($storage) {
            static::__assert_equals(Document_Renderer_Fixture_Renderer::class, Document_Render_Service::renderer_for_storage($storage), 'the blob resolves to the app renderer');
            Document_Render_Service::render_storage($storage);
        });

        $reloaded = File_Storage_Model::find($storage->id);
        static::__assert_equals(File_Storage_Model::RENDER_STATUS_RENDERED, (int) $reloaded->render_status_id, 'the blob is RENDERED: ' . (string) $reloaded->render_error);
        static::__assert_equals(Document_Renderer_Fixture_Renderer::FIXTURE_BYTES, (string) file_get_contents($rendition_path), 'the rendition is the app renderer\'s');
    }

    // DOCUMENTS-RENDERER-CACHE-PATH: the rendition path follows the renderer's rendition format.
    public static function test_cache_path_follows_rendition_format()
    {
        $storage = new File_Storage_Model();
        $storage->hash = 'abc123def456';

        static::__assert_equals(
            File_Preview_Controller::rendition_cache_path($storage),
            Document_Render_Service::rendition_path($storage, Libreoffice_Document_Renderer::class),
            'a PDF renderer caches at the PDF path'
        );
        static::__assert_equals(
            File_Preview_Controller::sheet_rendition_cache_path($storage),
            Document_Render_Service::rendition_path($storage, Spreadsheet_Document_Renderer::class),
            'the html renderer caches at the grid path'
        );
        static::__assert_equals(
            File_Preview_Controller::rendition_cache_path($storage),
            Document_Render_Service::rendition_path($storage, Document_Renderer_Fixture_Renderer::class),
            'an application renderer caches at the PDF path'
        );
    }

    // DOCUMENTS-RENDERER-HTML-RESERVED: a renderer other than the framework spreadsheet renderer
    // that declares 'html' is refused at resolution.
    public static function test_html_format_is_reserved_to_the_spreadsheet_renderer()
    {
        static::__with_config([
            'rsx.documents.renderers' => static::$shipped_renderers + [self::DOCX_MIME => 'Document_Renderer_Html_Fixture_Renderer'],
        ], function () {
            static::__assert_throws(\RuntimeException::class, fn () => Document_Render_Service::renderer_for_mime(self::DOCX_MIME), "reserved to Spreadsheet_Document_Renderer");
        });
    }

    // DOCUMENTS-RENDERER-BOGUS-CLASS: a config entry naming a class that is not a renderer fails loud.
    public static function test_a_non_renderer_entry_fails_loud()
    {
        static::__with_config([
            'rsx.documents.renderers' => static::$shipped_renderers + [self::DOCX_MIME => 'Plain_Text_Extractor'],
        ], function () {
            static::__assert_throws(\RuntimeException::class, fn () => Document_Render_Service::renderer_for_mime(self::DOCX_MIME), 'not a Rsx_Document_Renderer_Abstract');
        });
    }

    // ============================================================================================
    // THE RETIRED KEY AND CONVERTIBLE PARITY
    // ============================================================================================

    // DOCUMENTS-RENDERER-RETIRED-KEY: an application config that still sets rsx.preview.convertible
    // is refused, naming the key that replaced it.
    public static function test_retired_convertible_key_is_refused()
    {
        $e = static::__assert_throws(
            \RuntimeException::class,
            fn () => Rsx_Framework_Provider::refuse_retired_config_keys(['preview' => ['convertible' => ['application/msword']]]),
            'rsx.preview.convertible'
        );
        static::__assert_contains('rsx.documents.renderers', $e->getMessage(), 'the refusal names the replacement');
        static::__assert_contains('rsx:man documents', $e->getMessage(), 'the refusal names the man page');

        // The booted config carries no retired key - this would have thrown at boot otherwise.
        Rsx_Framework_Provider::refuse_retired_config_keys(config('rsx'));
        static::__assert_false(config()->has('rsx.preview.convertible'), 'the framework config no longer ships the key');
    }

    // DOCUMENTS-RENDERER-CONVERTIBLE-PARITY: is_convertible_mime() answers exactly what the retired
    // list answered, for every shipped type and its neighbours.
    public static function test_is_convertible_mime_parity_with_the_retired_list()
    {
        $mimes = [
            'application/msword',
            self::DOCX_MIME,
            'application/vnd.ms-word.document.macroEnabled.12',
            'application/vnd.ms-excel',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            self::XLSX_MIME,
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            'application/rtf',
            'application/pdf',
            'image/png',
            'text/plain',
            'text/csv',
            'text/markdown',
            'application/zip',
            'application/json',
            'application/octet-stream',
        ];

        foreach ($mimes as $mime) {
            $was = false;
            foreach (self::RETIRED_CONVERTIBLE as $pattern) {
                if (fnmatch($pattern, $mime)) {
                    $was = true;
                }
            }

            static::__assert_equals($was, File_Attachment_Model::is_convertible_mime($mime), "is_convertible_mime parity for {$mime}");
        }

        static::__assert_false(File_Attachment_Model::is_convertible_mime(null), 'no mime is not convertible');
    }

    // ============================================================================================
    // SOFT-FAILURE CHECKS
    // ============================================================================================

    // DOCUMENTS-SOFT-CHECK-LIBREOFFICE: the LibreOffice renderer and extractor answer null with an
    // executable soffice and a readable source, and a reason when soffice is not executable or
    // the source is missing.
    public static function test_libreoffice_soft_failure()
    {
        $source = static::__scratch_file('source', 0644);
        $executable = static::__scratch_file("#!/bin/bash\nexit 0\n", 0755);
        $not_executable = static::__scratch_file("#!/bin/bash\nexit 0\n", 0644);
        $missing = sys_get_temp_dir() . '/rsx_renderer_registry_missing_' . bin2hex(random_bytes(6));

        foreach ([Libreoffice_Document_Renderer::class, Libreoffice_Text_Extractor::class] as $class) {
            static::__with_config(['rsx.libreoffice.sandbox' => 'none', 'rsx.libreoffice.binary_path' => $executable], function () use ($class, $source, $missing) {
                static::__assert_null($class::soft_failure($source), "{$class}: healthy");
                static::__assert_contains($missing, (string) $class::soft_failure($missing), "{$class}: a missing source names its path");
            });

            static::__with_config(['rsx.libreoffice.sandbox' => 'none', 'rsx.libreoffice.binary_path' => $not_executable], function () use ($class, $source) {
                static::__assert_contains('soffice', (string) $class::soft_failure($source), "{$class}: a non-executable soffice is a soft failure");
            });
        }
    }

    // DOCUMENTS-SOFT-CHECK-SANDBOX: under the docker sandbox the LibreOffice and pdftotext checks
    // look for the sandbox image (never building it) instead of a host binary.
    public static function test_sandbox_soft_failure()
    {
        $source = static::__scratch_file('source', 0644);

        static::__with_config(['rsx.libreoffice.sandbox' => 'docker', 'rsx.libreoffice.sandbox_image' => 'example/registry-test-image:tag'], function () use ($source) {
            Document_Sandbox::_testing_set_image_present(false);
            static::__assert_false(Document_Sandbox::image_present(), 'a forced absent image reads absent');

            foreach ([Libreoffice_Document_Renderer::class, Libreoffice_Text_Extractor::class, Pdftotext_Text_Extractor::class] as $class) {
                $reason = $class::soft_failure($source);
                static::__assert_not_null($reason, "{$class}: an absent sandbox image is a soft failure");
                if (Document_Sandbox::client_present()) {
                    static::__assert_contains("'example/registry-test-image:tag' is not present", (string) $reason, "{$class}: the reason names the image");
                }
            }

            Document_Sandbox::_testing_set_image_present(true);
            if (Document_Sandbox::client_present()) {
                foreach ([Libreoffice_Document_Renderer::class, Libreoffice_Text_Extractor::class, Pdftotext_Text_Extractor::class] as $class) {
                    static::__assert_null($class::soft_failure($source), "{$class}: client and image present - healthy");
                }
            }
        });

        // The FRAMEWORK image is different: the attempt builds it when it is absent
        // (Document_Sandbox::ensure_image()), so its absence is not a reason to stop.
        static::__with_config(['rsx.libreoffice.sandbox' => 'docker', 'rsx.libreoffice.sandbox_image' => Document_Sandbox::FRAMEWORK_IMAGE], function () use ($source) {
            Document_Sandbox::_testing_set_image_present(false);
            if (Document_Sandbox::client_present()) {
                foreach ([Libreoffice_Document_Renderer::class, Libreoffice_Text_Extractor::class, Pdftotext_Text_Extractor::class] as $class) {
                    static::__assert_null($class::soft_failure($source), "{$class}: an absent framework image is built by the attempt - not a soft failure");
                }
            }
        });

        Document_Sandbox::_testing_set_image_present(null);
    }

    // DOCUMENTS-SOFT-CHECK-PDFTOTEXT: the pdftotext extractor answers null with an executable
    // pdftotext and a readable source, and a reason otherwise.
    public static function test_pdftotext_soft_failure()
    {
        $source = static::__scratch_file('source', 0644);
        $executable = static::__scratch_file("#!/bin/bash\nexit 0\n", 0755);
        $not_executable = static::__scratch_file("#!/bin/bash\nexit 0\n", 0644);
        $missing = sys_get_temp_dir() . '/rsx_renderer_registry_missing_' . bin2hex(random_bytes(6));

        static::__with_config(['rsx.libreoffice.sandbox' => 'none', 'rsx.search.pdftotext_binary_path' => $executable], function () use ($source, $missing) {
            static::__assert_null(Pdftotext_Text_Extractor::soft_failure($source), 'healthy');
            static::__assert_contains($missing, (string) Pdftotext_Text_Extractor::soft_failure($missing), 'a missing source names its path');
        });

        static::__with_config(['rsx.libreoffice.sandbox' => 'none', 'rsx.search.pdftotext_binary_path' => $not_executable], function () use ($source) {
            static::__assert_contains('pdftotext', (string) Pdftotext_Text_Extractor::soft_failure($source), 'a non-executable pdftotext is a soft failure');
        });
    }

    // DOCUMENTS-SOFT-CHECK-IN-PROCESS: the in-process readers check only that the source is readable.
    public static function test_in_process_soft_failure()
    {
        $source = static::__scratch_file('source', 0644);
        $missing = sys_get_temp_dir() . '/rsx_renderer_registry_missing_' . bin2hex(random_bytes(6));

        foreach ([Spreadsheet_Document_Renderer::class, Plain_Text_Extractor::class, Spreadsheet_Text_Extractor::class] as $class) {
            static::__assert_null($class::soft_failure($source), "{$class}: a readable source is healthy");
            static::__assert_contains($missing, (string) $class::soft_failure($missing), "{$class}: a missing source names its path");
        }

        static::__assert_null(Document_Renderer_Fixture_Renderer::soft_failure($missing), 'a renderer that declares no checks has no soft failures');
    }

    // ============================================================================================
    // THE EXTRACTOR A PASS WILL USE
    // ============================================================================================

    // DOCUMENTS-EXTRACTOR-FOR-PASS: the extractor a pass will run, with and without a rendition,
    // and none when the matching one is switched off.
    public static function test_extractor_for_pass()
    {
        $docx = File_Storage_Model::find(static::__make_attachment('pass.docx')->file_storage_id);
        $txt = File_Storage_Model::find(static::__make_attachment('pass.txt')->file_storage_id);

        static::__assert_equals(Libreoffice_Text_Extractor::class, Search_Index_Service::extractor_for_pass($docx, false), 'docx without a rendition: LibreOffice');
        static::__assert_equals(Pdftotext_Text_Extractor::class, Search_Index_Service::extractor_for_pass($docx, true), 'docx with a rendition: pdftotext over it');
        static::__assert_equals(Plain_Text_Extractor::class, Search_Index_Service::extractor_for_pass($txt, true), 'txt: plain text, rendition or not');

        static::__with_config(['rsx.libreoffice.enabled' => false], function () use ($docx) {
            static::__assert_null(Search_Index_Service::extractor_for_pass($docx, false), 'LibreOffice switched off: no extractor runs');
        });
    }
}
