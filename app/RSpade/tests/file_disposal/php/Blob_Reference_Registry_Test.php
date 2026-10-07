<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FileDisposal\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Blob_References;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The declared blob-reference registry (#[Blob_Reference] + File_Blob_References) and the
 * central blob API (File_Storage_Model::store_bytes / read_* / *_response).
 *
 * Proves: the framework's reference tables are declared from the manifest; a declared
 * where_null column decides whether a row pins its blob; the deep sweep's "unreferenced"
 * narrowing asks every declaration; a table with a foreign key to _file_storage and no
 * declaration is reported (the health row's source); bytes stored from memory deduplicate
 * with bytes stored from a file and read back through the blob API, never a path.
 *
 * Commits (the undeclared-column probe creates and drops a table, which is DDL), so the class
 * opts out of the per-test transaction and runs on a fresh migrated DB.
 */
class Blob_Reference_Registry_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__reset();
    }

    public static function teardown()
    {
        DB::statement('DROP TABLE IF EXISTS _blob_reference_probe');
        static::__reset();
    }

    private static function __reset(): void
    {
        DB::statement('DELETE FROM _email_queue');
        DB::statement('DELETE FROM _file_attachments');
        DB::statement('DELETE FROM _file_storage');
        File_Blob_References::_reset();
    }

    private static function __attachment(string $content): File_Attachment_Model
    {
        $site_id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($site_id);

        return File_Attachment_Model::create_from_string($content, 'probe.txt', ['site_id' => $site_id]);
    }

    private static function __orphan(string $content): File_Storage_Model
    {
        return File_Storage_Model::store_bytes($content, fn () => null);
    }

    /** br-01 - the framework's reference tables are declared, with the attachment tombstone rule */
    public static function test_framework_references_are_declared()
    {
        $by_table = [];
        foreach (File_Blob_References::declarations() as $declaration) {
            $by_table[$declaration['table']] = $declaration;
        }

        static::__assert_true(isset($by_table['_file_attachments']), 'attachments declared');
        static::__assert_equals('file_storage_id', $by_table['_file_attachments']['column']);
        static::__assert_equals('destroyed_at', $by_table['_file_attachments']['where_null']);
        static::__assert_true(isset($by_table['_email_attachments']), 'email parts declared');
        static::__assert_null($by_table['_email_attachments']['where_null']);
    }

    /** br-02 - a live row pins its blob; a destroyed tombstone (where_null) does not */
    public static function test_where_null_decides_whether_a_row_pins()
    {
        $attachment = static::__attachment('pinned bytes ' . uniqid());
        $storage_id = (int) $attachment->file_storage_id;

        static::__assert_true(File_Blob_References::is_referenced($storage_id), 'live attachment pins');

        DB::table('_file_attachments')->where('id', $attachment->id)->update(['deleted_at' => now(), 'destroyed_at' => now()]);
        static::__assert_false(File_Blob_References::is_referenced($storage_id), 'a destroyed tombstone does not pin');
    }

    /** br-03 - the sweep's narrowing keeps referenced blobs out and orphans in */
    public static function test_where_unreferenced_selects_only_orphans()
    {
        $attachment = static::__attachment('referenced ' . uniqid());
        $orphan = static::__orphan('orphan ' . uniqid());

        $ids = File_Blob_References::where_unreferenced(DB::table('_file_storage as s'), 's')
            ->pluck('s.id')->map(fn ($id) => (int) $id)->all();

        static::__assert_true(in_array((int) $orphan->id, $ids, true), 'the orphan is selected');
        static::__assert_false(in_array((int) $attachment->file_storage_id, $ids, true), 'the referenced blob is not');
    }

    /** br-04 - a table with a foreign key to _file_storage and no declaration is reported */
    public static function test_undeclared_reference_is_reported()
    {
        static::__assert_equals([], File_Blob_References::undeclared_referencing_columns(), 'the shipped schema is fully declared');

        DB::statement('CREATE TABLE _blob_reference_probe (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, file_storage_id BIGINT NULL,'
            . ' CONSTRAINT fk_blob_reference_probe FOREIGN KEY (file_storage_id) REFERENCES _file_storage(id)) ENGINE=InnoDB');

        static::__assert_equals(['_blob_reference_probe.file_storage_id'], File_Blob_References::undeclared_referencing_columns());
        static::__assert_equals('FAIL', File_Blob_References::health_check()['status']);
    }

    /** br-05 - bytes stored from memory dedupe with a file and read back through the blob API */
    public static function test_store_bytes_and_read_api()
    {
        $bytes = "central blob store\n" . uniqid();
        $from_bytes = static::__orphan($bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'rsx_blob_ref_');
        file_put_contents($tmp, $bytes);
        try {
            $from_file = File_Storage_Model::store_blob($tmp, fn () => null);
        } finally {
            @unlink($tmp);
        }

        static::__assert_equals((int) $from_bytes->id, (int) $from_file->id, 'identical bytes share one blob');
        static::__assert_equals($bytes, $from_bytes->read_bytes());

        $stream = $from_bytes->read_stream();
        static::__assert_equals($bytes, stream_get_contents($stream));
        fclose($stream);

        $response = $from_bytes->download_response('report.txt', 'text/plain');
        static::__assert_contains('attachment', (string) $response->headers->get('Content-Disposition'));
        static::__assert_contains('report.txt', (string) $response->headers->get('Content-Disposition'));
        static::__assert_equals('nosniff', $response->headers->get('X-Content-Type-Options'));

        $inline = $from_bytes->inline_response('report.txt', 'text/plain');
        static::__assert_contains('inline', (string) $inline->headers->get('Content-Disposition'));
    }
}
