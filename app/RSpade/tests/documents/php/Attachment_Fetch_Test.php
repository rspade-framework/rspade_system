<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Documents\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The JavaScript ORM's view of an attachment: File_Attachment_Model::fetch() and its portal twin.
 *
 * <Attachment_Thumbnail> is identified by an attachment id and nothing else - it builds its own URL
 * and reads its own render state - so this payload IS the component's whole input. Three properties
 * are load-bearing and each has a test here:
 *
 *   1. The blob rides along, EMBEDDED, because relationship loads are not batched: a page of forty
 *      thumbnails must cost one request, not forty-one.
 *   2. render_error never crosses the wire. It is soffice stderr - an operator diagnostic.
 *   3. A gate denial is indistinguishable from a missing row, so the endpoint cannot enumerate.
 *
 * And one negative property: reading metadata must never touch BYTES. resolve_storage() would
 * materialize an external blob and stamp blob_accessed_at; fetch() reads file_storage_id instead,
 * and the last test proves it by watching that column.
 */
class Attachment_Fetch_Test extends Rsx_Test_Abstract
{
    /** @var array<int> ids of attachments created during the class, cleaned up in teardown. */
    private static $created_attachment_ids = [];

    /** @var array<string> absolute paths written by a test, removed in teardown. */
    private static $created_files = [];

    public static function setup()
    {
        // Creating an attachment queues its blob; with the kick switch off no detached worker is
        // spawned and the row state is still the truth.
        config(['rsx.search.enabled' => false]);

        // These tests do not sign anybody in, so the app's real authorize handlers have nobody to
        // reason about (the baseline's one user is only an identity when a test acts as it). The
        // documented test seam stands in for them; each test that cares about the gate's ANSWER
        // overrides it for itself.
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

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);

        return $id;
    }

    private static function __make_attachment(string $marker, string $filename): File_Attachment_Model
    {
        $site_id = static::__site_id();
        $bytes = "RSpade fetch test {$marker} " . bin2hex(random_bytes(12));
        $attachment = File_Attachment_Model::create_from_string($bytes, $filename, ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;
        static::$created_files[] = $attachment->resolve_storage()->get_full_path();

        return $attachment;
    }

    // ============================================================================================
    // PAYLOAD SHAPE
    // ============================================================================================

    // DOCUMENTS-FETCH-SHAPE: the payload is toArray() plus the blob embedded under its own
    // relationship name, carrying exactly the five fields the client needs - and NOT render_error.
    public static function test_fetch_embeds_the_blob_and_withholds_the_render_error()
    {
        $attachment = static::__make_attachment('shape', 'fetch_shape.docx');

        $storage = File_Storage_Model::find($attachment->file_storage_id);
        $storage->render_status_id = File_Storage_Model::RENDER_STATUS_FAILED;
        $storage->render_error = 'soffice exploded: this text is for operators only';
        $storage->save();

        $data = File_Attachment_Model::fetch($attachment->id);

        static::__assert_true(is_array($data), 'fetch() returns an array for a visible attachment');
        static::__assert_equals($attachment->id, $data['id'], 'the record itself is the payload');
        static::__assert_array_has_key('key', $data, 'the key is present - the URL builder needs it');
        static::__assert_array_has_key('__MODEL', $data, 'toArray() supplies the ORM hydration marker');

        static::__assert_array_has_key('file_storage', $data, 'the blob is embedded under its relationship name');
        static::__assert_equals(
            ['id', 'hash', 'size', 'render_status_id', 'rendered_at'],
            array_keys($data['file_storage']),
            'exactly the five fields the client has business with, in order'
        );
        static::__assert_equals((int) $storage->id, $data['file_storage']['id'], 'it is THIS attachment\'s blob');
        static::__assert_equals(
            File_Storage_Model::RENDER_STATUS_FAILED,
            $data['file_storage']['render_status_id'],
            'the render state is what the component branches on'
        );

        static::__assert_false(
            array_key_exists('render_error', $data['file_storage']),
            'render_error is an operator diagnostic and NEVER reaches the browser'
        );
        static::__assert_false(
            str_contains(json_encode($data), 'soffice exploded'),
            'the error text appears nowhere in the payload'
        );
    }

    // DOCUMENTS-FETCH-NO-BLOB: an attachment with no resident blob still fetches - file_storage is
    // null, which the component reads as "no render state" rather than an error.
    public static function test_fetch_of_an_attachment_without_a_blob_returns_a_null_blob()
    {
        $attachment = static::__make_attachment('noblob', 'fetch_no_blob.txt');

        $attachment->file_storage_id = null;
        $attachment->save();

        $data = File_Attachment_Model::fetch($attachment->id);

        static::__assert_true(is_array($data), 'the attachment is still fetchable without bytes');
        static::__assert_true(
            array_key_exists('file_storage', $data),
            'the key is always present, so the client never has to test for its existence'
        );
        static::__assert_null($data['file_storage'], 'and it is null, not an empty object');
    }

    // ============================================================================================
    // AUTHORIZATION
    // ============================================================================================

    // DOCUMENTS-FETCH-GATE: a file.thumbnail.authorize denial returns false - the SAME answer a
    // missing row gives, so the endpoint cannot be walked to discover which ids exist.
    public static function test_gate_denial_is_indistinguishable_from_not_found()
    {
        $attachment = static::__make_attachment('gate', 'fetch_gate.docx');

        static::__assert_true(
            is_array(File_Attachment_Model::fetch($attachment->id)),
            'the permissive gate lets the record through'
        );

        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => false]);

        static::__assert_false(
            File_Attachment_Model::fetch($attachment->id),
            'a denied attachment answers exactly like a missing one'
        );
        static::__assert_false(
            File_Attachment_Model::fetch(0),
            'and a missing one answers the same way'
        );

        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => true]);
    }

    // DOCUMENTS-FETCH-PORTAL: portal_fetch() returns the SAME shape as fetch() (the component is
    // realm-agnostic), and portal_can_read() runs the same gate, fail-closed.
    public static function test_portal_fetch_mirrors_the_staff_payload()
    {
        $attachment = static::__make_attachment('portal', 'fetch_portal.docx');

        $staff = File_Attachment_Model::fetch($attachment->id);
        $portal = File_Attachment_Model::portal_fetch($attachment->id);

        static::__assert_true(is_array($portal), 'a portal user reaching a visible attachment gets the record');
        static::__assert_equals(
            $staff,
            $portal,
            'one component, one payload - the two realms may never drift'
        );

        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => false]);

        static::__assert_false(
            File_Attachment_Model::portal_fetch($attachment->id),
            'portal_can_read() is fail-closed on a gate denial'
        );

        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [fn($data) => true]);
    }

    // ============================================================================================
    // NO BYTE ACCESS
    // ============================================================================================

    // DOCUMENTS-FETCH-NO-BYTES: fetch() is a METADATA read. resolve_storage() would materialize an
    // external blob and stamp blob_accessed_at; the column proves it was never called.
    public static function test_fetch_does_not_touch_the_bytes()
    {
        $attachment = static::__make_attachment('bytes', 'fetch_bytes.docx');

        // create_from_string + the resolve_storage() in the helper have already stamped it; freeze
        // a known value so any further touch is visible.
        DB::update(
            'UPDATE _file_attachments SET blob_accessed_at = ? WHERE id = ?',
            ['2001-01-01 00:00:00.000', $attachment->id]
        );

        File_Attachment_Model::fetch($attachment->id);
        File_Attachment_Model::portal_fetch($attachment->id);

        $after = DB::selectOne(
            'SELECT blob_accessed_at FROM _file_attachments WHERE id = ?',
            [$attachment->id]
        )->blob_accessed_at;

        static::__assert_contains(
            '2001-01-01',
            (string) $after,
            'neither fetch touched blob_accessed_at - no resolve_storage() ran'
        );
    }
}
