<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * ATTACHMENT OWNERSHIP after the session_id retirement.
 *
 * _file_attachments.session_id is GONE. Claimability is now a property of the attachment
 * (not already attached + site match), defended in practice by the unguessable 64-char key,
 * the single-claim rule, and a bounded claim window. created_by_ip_address replaces the
 * session stamp as AUDIT metadata only - it gates nothing.
 *
 * Proves: the guard passes for an unattached same-site row and the column it used to consult
 * no longer exists; fails once claimed; fails across sites; the IP column persists (NULL
 * with no request context, which is every CLI/task/programmatic creation); and the 6-hourly
 * claim-window sweep soft-deletes expired unattached uploads while sparing attached rows,
 * handler-backed rows, and in-window rows - and does nothing at all when disabled.
 */
class Attachment_Ownership_Test extends Rsx_Test_Abstract
{
    /**
     * A real seeded site id (> 0), set as the session site so the site-scoped save hook and
     * the site FK are both satisfied in the CLI harness.
     */
    private static function __site_id(): int
    {
        $id = (int) Site_Model::where('id', '>', 0)->orderBy('id')->value('id');
        Session::set_site_id($id);

        return $id;
    }

    /**
     * A second site id to test tenant isolation against - any other seeded site, else one
     * created for this test (the per-test transaction rolls it back).
     */
    private static function __other_site_id(int $not_this): int
    {
        $existing = (int) Site_Model::where('id', '>', 0)->where('id', '!=', $not_this)->orderBy('id')->value('id');
        if ($existing > 0) {
            return $existing;
        }

        $site = new Site_Model();
        $site->slug = 'ownership-test-' . uniqid();
        $site->name = 'Ownership Test Site';
        $site->save();

        return (int) $site->id;
    }

    private static function __make(string $suffix = ''): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string(
            'ownership-' . uniqid() . $suffix,
            'doc.txt',
            ['site_id' => static::__site_id()]
        );
    }

    /** Backdate created_at past the claim window (raw update - bypasses the ISO datetime cast). */
    private static function __backdate(File_Attachment_Model $attachment, int $hours): void
    {
        DB::table('_file_attachments')->where('id', $attachment->id)->update([
            'created_at' => Rsx_Time::to_database(Rsx_Time::subtract(Rsx_Time::now_iso(), $hours * 3600)),
        ]);
    }

    private static function __run_sweep(): array
    {
        return File_Disposal_Service::sweep_unclaimed_uploads(
            new Task_Instance(File_Disposal_Service::class, 'sweep_unclaimed_uploads')
        );
    }

    private static function __is_soft_deleted(int $id): bool
    {
        $row = File_Attachment_Model::withTrashed()->find($id);

        return $row !== null && $row->deleted_at !== null;
    }

    // --- the claim guard -------------------------------------------------------------------------

    /**
     * The guard no longer asks WHICH session uploaded the file - there is no longer anywhere to
     * record that. The old comparison read the STAFF session facade unconditionally, so a portal
     * upload matched only by accident; the column is gone and the guard is structural.
     */
    public static function test_unattached_same_site_is_claimable_and_no_session_column_remains()
    {
        $attachment = static::__make();

        static::__assert_true($attachment->can_user_assign_this_file(), 'freshly uploaded row is claimable');

        static::__assert_null(
            DB::selectOne("SHOW COLUMNS FROM _file_attachments LIKE 'session_id'"),
            '_file_attachments.session_id is gone - claimability cannot depend on a session'
        );
    }

    /** SINGLE CLAIM: once attached, the key is spent - it can never be re-pointed. */
    public static function test_already_attached_is_not_claimable()
    {
        $attachment = static::__make();
        $site = Site_Model::find($attachment->site_id);

        $attachment->attach_to($site, 'ownership_test');

        static::__assert_false($attachment->can_user_assign_this_file(), 'an attached file cannot be re-claimed');
    }

    /** Tenant isolation is the OTHER surviving check. */
    public static function test_site_mismatch_is_not_claimable()
    {
        $attachment = static::__make();
        $other = static::__other_site_id((int) $attachment->site_id);

        Session::set_site_id($other);

        static::__assert_false(
            $attachment->can_user_assign_this_file(),
            'a file from another tenant is never claimable'
        );
    }

    // --- created_by_ip_address (audit only) ------------------------------------------------------

    /**
     * No request context means no IP - and, critically, no error and no session minted. Every
     * CLI/task/programmatic creation lands here, so this is the common path, not an edge case.
     */
    public static function test_ip_is_null_without_a_request_context()
    {
        static::__assert_null(Session::get_client_ip(), 'CLI has no client IP');

        $attachment = static::__make();

        static::__assert_null($attachment->created_by_ip_address, 'programmatic creation stamps no IP');
        static::__assert_null(
            File_Attachment_Model::find($attachment->id)->created_by_ip_address,
            'and persists as NULL'
        );
    }

    /**
     * The column itself round-trips a real address (the stamp value under a web request). The
     * request-context branch of Session::get_client_ip() keys on php_sapi_name(), which the CLI
     * harness cannot fake, so the write path is exercised directly here.
     */
    public static function test_ip_column_persists_an_address()
    {
        $attachment = static::__make();
        $attachment->created_by_ip_address = '2001:db8:85a3:8d3:1319:8a2e:370:7348';
        $attachment->save();

        static::__assert_equals(
            '2001:db8:85a3:8d3:1319:8a2e:370:7348',
            File_Attachment_Model::find($attachment->id)->created_by_ip_address,
            'a full-length IPv6 address survives the VARCHAR(45) column'
        );
    }

    // --- the claim-window sweep ------------------------------------------------------------------

    public static function test_sweep_soft_deletes_an_expired_unattached_upload()
    {
        config(['rsx.attachments.unattached_claim_window_hours' => 24]);

        $attachment = static::__make();
        $storage_id = (int) $attachment->file_storage_id;
        static::__backdate($attachment, 48);

        static::__run_sweep();

        static::__assert_true(static::__is_soft_deleted($attachment->id), 'expired unattached upload is swept');
        static::__assert_null(
            File_Attachment_Model::withTrashed()->find($attachment->id)->destroyed_at,
            'swept into RETENTION, not destroyed - still recoverable'
        );
        static::__assert_not_null(
            File_Storage_Model::find($storage_id),
            'the blob is still pinned by the retained row'
        );
    }

    public static function test_sweep_spares_attached_in_window_and_handler_backed_rows()
    {
        config(['rsx.attachments.unattached_claim_window_hours' => 24]);

        // (a) attached but old - claimed, therefore not the sweep's business.
        $attached = static::__make('-attached');
        $attached->attach_to(Site_Model::find($attached->site_id), 'ownership_test');
        static::__backdate($attached, 48);

        // (b) unattached but still inside the window.
        $fresh = static::__make('-fresh');

        // (c) handler-backed (external) - never swept, whatever its age.
        config(['rsx.attachments.handlers' => array_merge(
            (array) config('rsx.attachments.handlers', []),
            ['Attachment_Fixture_Handler']
        )]);
        $external = File_Attachment_Model::create_external(
            'Attachment_Fixture_Handler',
            ['ref' => 'ownership-sweep'],
            [
                'site_id'        => static::__site_id(),
                'file_name'      => 'external.txt',
                'file_extension' => 'txt',
                'mime_type'      => 'text/plain',
                'file_size'      => 12,
            ]
        );
        static::__backdate($external, 48);

        static::__run_sweep();

        static::__assert_false(static::__is_soft_deleted($attached->id), 'an attached row is never swept');
        static::__assert_false(static::__is_soft_deleted($fresh->id), 'an in-window upload is never swept');
        static::__assert_false(static::__is_soft_deleted($external->id), 'a handler-backed row is never swept');
    }

    public static function test_sweep_is_disabled_by_a_zero_window()
    {
        config(['rsx.attachments.unattached_claim_window_hours' => 0]);

        $attachment = static::__make();
        static::__backdate($attachment, 24 * 365);

        $result = static::__run_sweep();

        static::__assert_equals(0, $result['swept'], 'a zero window sweeps nothing');
        static::__assert_false(static::__is_soft_deleted($attachment->id), 'the expired row survives');

        config(['rsx.attachments.unattached_claim_window_hours' => 24]);
    }
}
