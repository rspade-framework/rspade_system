<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Database\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Models\Country_Model;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The DELETION stamp: deleted_by_id/deleted_by_type, the third audit pair.
 *
 * It differs from its two authorship siblings in three ways, and each one is under test here:
 *
 *   1. IT ONLY EXISTS WHERE deleted_at DOES. Every table is created and updated; only a
 *      soft-deleting table has a deleter. A model whose table lacks the pair must still be
 *      stamped for authorship - the two column-presence memos are independent.
 *   2. IT IS WRITTEN ON A PATH save() NEVER SEES. Laravel's SoftDeletes::runSoftDelete()
 *      writes its own [deleted_at, updated_at] UPDATE and discards dirty attributes, so the
 *      pair is merged into that statement at the builder. The test proves it is ONE statement.
 *   3. IT IS CLEARED, NOT MOVED. A restored record is not deleted by anybody.
 *
 * A HARD delete is deliberately absent from the matrix: the row it would be stamped on ceases
 * to exist. That is asserted rather than assumed.
 *
 * Fixtures: Portal_User_Model (framework-core, site-scoped, soft-deletes), Country_Model
 * (framework-core, NOT site-scoped, does NOT soft-delete) and a real File_Attachment_Model -
 * the highest-traffic soft-delete surface in the framework, and the one with its own
 * delete()/undelete()/force_destroy() vocabulary.
 */
class Audit_Delete_Stamp_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private const USER_ID = 1;

    public static function setup(): void
    {
        static::__acting_as_user(self::USER_ID);
    }

    public static function teardown(): void
    {
        static::__reset_session();
    }

    /**
     * A site-scoped, soft-deleting fixture row.
     */
    private static function __make_portal_user(): Portal_User_Model
    {
        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = 'audit_delete_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        return $portal_user;
    }

    /**
     * A NON-site-scoped fixture row on a table that does NOT soft-delete.
     */
    private static function __make_country(): Country_Model
    {
        $suffix = substr(str_replace('.', '', uniqid('', true)), -5);

        $country = new Country_Model();
        $country->alpha2 = strtoupper(substr($suffix, 0, 2));
        $country->alpha3 = strtoupper(substr($suffix, 0, 3));
        $country->numeric = substr($suffix, 0, 3);
        $country->name = 'Audit Delete Test ' . $suffix;
        $country->enabled = false;
        $country->save();

        return $country;
    }

    /**
     * A NON-site-scoped soft-deleting fixture. Used for every arm that has to move the session
     * off the record's site (or off any identity at all): a site-scoped model refuses such a
     * write outright, long before the audit stamp is reached.
     */
    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'audit_delete_' . uniqid() . '@example.com';
        $login_user->password = 'not-a-real-hash';
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * The persisted row, read past the soft-delete scope and past the type-ref cast.
     */
    private static function __raw_row(string $table, int $id): object
    {
        return DB::select(
            "SELECT deleted_at, deleted_by_id, deleted_by_type FROM {$table} WHERE id = ?",
            [$id]
        )[0];
    }

    /**
     * A soft delete stamps the deleter, per the same actor matrix save() uses - and it lands
     * in the DATABASE, not merely on the instance.
     */
    public static function test_a_soft_delete_stamps_the_deleter()
    {
        $portal_user = static::__make_portal_user();
        $portal_user->delete();

        static::__assert_equals('User_Model', $portal_user->deleted_by_type);
        static::__assert_equals(self::USER_ID, (int) $portal_user->deleted_by_id);

        $row = static::__raw_row('portal_users', (int) $portal_user->id);
        static::__assert_not_empty($row->deleted_at, 'the row must actually be soft-deleted');
        static::__assert_equals(self::USER_ID, (int) $row->deleted_by_id);
        static::__assert_equals(
            \App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::class_to_id('User_Model'),
            (int) $row->deleted_by_type,
            'the type column stores the type-ref integer, not a class name'
        );
    }

    /**
     * THE ONE-STATEMENT GUARANTEE. The pair rides the soft delete's own UPDATE; it is never a
     * follow-up write against a row that has already been deleted.
     */
    public static function test_the_stamp_rides_the_soft_delete_statement()
    {
        $portal_user = static::__make_portal_user();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $portal_user->delete();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $updates = [];
        foreach ($log as $entry) {
            if (stripos($entry['query'], 'update `portal_users`') !== false) {
                $updates[] = $entry['query'];
            }
        }

        static::__assert_count(1, $updates, 'a soft delete must be exactly ONE update statement');
        static::__assert_contains('deleted_at', $updates[0]);
        static::__assert_contains('deleted_by_id', $updates[0]);
        static::__assert_contains('deleted_by_type', $updates[0]);
    }

    /**
     * A restore CLEARS the pair. A record that is no longer deleted was not deleted by anybody,
     * exactly as its deleted_at is no longer set.
     */
    public static function test_a_restore_clears_the_pair()
    {
        $portal_user = static::__make_portal_user();
        $portal_user->delete();
        static::__assert_equals('User_Model', $portal_user->deleted_by_type);

        $portal_user->restore();

        static::__assert_null($portal_user->deleted_by_type);
        static::__assert_null($portal_user->deleted_by_id);

        $row = static::__raw_row('portal_users', (int) $portal_user->id);
        static::__assert_null($row->deleted_at);
        static::__assert_null($row->deleted_by_id);
        static::__assert_null($row->deleted_by_type);
    }

    /**
     * The pair is cleared even when NOBODY is signed in - clearing is not an attribution, so
     * it must not depend on there being an actor to name.
     */
    public static function test_a_restore_by_nobody_still_clears_the_pair()
    {
        $login_user = static::__make_login_user();
        $login_user->delete();
        static::__assert_not_empty(static::__raw_row('login_users', (int) $login_user->id)->deleted_by_id);

        static::__reset_session();
        $login_user->restore();
        static::__acting_as_user(self::USER_ID);

        $row = static::__raw_row('login_users', (int) $login_user->id);
        static::__assert_null($row->deleted_at);
        static::__assert_null($row->deleted_by_id);
        static::__assert_null($row->deleted_by_type);
    }

    /**
     * An explicitly assigned pair wins, as one unit - and carrying it is also what keeps
     * runSoftDelete() from silently discarding the caller's assignment.
     */
    public static function test_an_explicit_pair_wins_over_the_delete_stamp()
    {
        $portal_user = static::__make_portal_user();

        $portal_user->deleted_by_type = 'Login_User_Model';
        $portal_user->deleted_by_id = 9091;
        $portal_user->delete();

        $row = static::__raw_row('portal_users', (int) $portal_user->id);
        static::__assert_equals(9091, (int) $row->deleted_by_id);
        static::__assert_equals(
            \App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::class_to_id('Login_User_Model'),
            (int) $row->deleted_by_type
        );
    }

    /**
     * Nobody signed in leaves the pair NULL. An unattributed deletion is the honest record.
     */
    public static function test_no_actor_leaves_the_delete_pair_null()
    {
        $login_user = static::__make_login_user();

        static::__reset_session();
        $login_user->delete();
        static::__acting_as_user(self::USER_ID);

        $row = static::__raw_row('login_users', (int) $login_user->id);
        static::__assert_not_empty($row->deleted_at, 'the delete itself still happens');
        static::__assert_null($row->deleted_by_id);
        static::__assert_null($row->deleted_by_type);
    }

    /**
     * A HARD delete cannot be stamped: there is no row left to carry the stamp. The delete
     * simply succeeds.
     */
    public static function test_a_hard_delete_stamps_nothing_because_the_row_is_gone()
    {
        $country = static::__make_country();
        $id = (int) $country->id;

        static::__assert_true((bool) $country->delete());
        static::__assert_null(Country_Model::find($id), 'a hard delete removes the row');
        static::__assert_count(
            0,
            DB::select('SELECT id FROM countries WHERE id = ?', [$id]),
            'nothing survives a hard delete to be stamped'
        );
    }

    /**
     * THE MEMO DISTINCTION. countries carries the authorship pairs but no deletion pair (it
     * has no deleted_at). Its authorship stamp must be entirely unaffected - a single fused
     * presence check would have switched it off for every hard-deleting table in the schema.
     */
    public static function test_a_table_without_the_deletion_pair_is_still_stamped_for_authorship()
    {
        $country = static::__make_country();

        static::__assert_equals('Login_User_Model', $country->created_by_type);
        static::__assert_equals(self::USER_ID, (int) $country->created_by_id);
        static::__assert_equals('Login_User_Model', $country->updated_by_type);

        // And the column genuinely is not there - the test would be vacuous otherwise.
        static::__assert_count(
            0,
            DB::select(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'countries' AND COLUMN_NAME = 'deleted_by_id'"
            )
        );
    }

    /**
     * The stamp reads back as a stock morph relation and as a display-ready author, the same
     * way created_by/updated_by do.
     */
    public static function test_the_deleted_by_relation_resolves_the_actor()
    {
        $portal_user = static::__make_portal_user();
        $portal_user->delete();

        $reloaded = Portal_User_Model::withTrashed()->find($portal_user->id);

        static::__assert_instance_of(User_Model::class, $reloaded->deleted_by);
        static::__assert_equals(self::USER_ID, (int) $reloaded->deleted_by->id);

        $author = $reloaded->get_deleted_by_author();
        static::__assert_not_empty($author);
        static::__assert_not_empty($author['name']);
    }

    /**
     * A live record has no deleter, so the relation and the display pair are null.
     */
    public static function test_a_live_record_has_no_deleter()
    {
        $portal_user = static::__make_portal_user();

        static::__assert_null($portal_user->deleted_by);
        static::__assert_null($portal_user->get_deleted_by_author());
    }

    /**
     * Deleting outside the actor's site attributes the cross-site identity, exactly as the
     * authorship stamp does - the deletion pair reuses the one actor matrix, not a copy of it.
     */
    public static function test_the_delete_stamp_uses_the_same_actor_matrix()
    {
        // login_users is not site-scoped, so no record site can match the actor's - the
        // cross-site arm of the one shared matrix.
        $login_user = static::__make_login_user();
        $login_user->delete();

        $row = static::__raw_row('login_users', (int) $login_user->id);
        static::__assert_equals(
            \App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::class_to_id('Login_User_Model'),
            (int) $row->deleted_by_type
        );
        static::__assert_equals(Session::get_login_user_id(), (int) $row->deleted_by_id);
    }

    /**
     * THE HIGHEST-TRAFFIC SURFACE. An attachment's retention round trip carries the stamp in
     * and clears it on the way back out.
     */
    public static function test_an_attachment_delete_and_undelete_round_trip()
    {
        $attachment = File_Attachment_Model::create_from_string(
            'audit-delete-' . uniqid(),
            'audit_delete.txt',
            ['site_id' => Session::get_site_id()]
        );

        $attachment->delete();

        $row = static::__raw_row('_file_attachments', (int) $attachment->id);
        static::__assert_not_empty($row->deleted_at, 'delete() enters the retention window');
        static::__assert_equals(Session::get_user_id(), (int) $row->deleted_by_id);
        static::__assert_equals(
            \App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::class_to_id('User_Model'),
            (int) $row->deleted_by_type
        );

        $attachment->undelete();

        $restored = static::__raw_row('_file_attachments', (int) $attachment->id);
        static::__assert_null($restored->deleted_at);
        static::__assert_null($restored->deleted_by_id, 'undelete() clears the deleter');
        static::__assert_null($restored->deleted_by_type);
    }

    /**
     * force_destroy() reaches the deleted state by assigning deleted_at and saving (never
     * through SoftDeletes::delete()), so it is the save()-path arm of the stamp. It must
     * record the deleter just the same - a permanent erasure is the LAST thing that should
     * be unattributed.
     */
    public static function test_force_destroy_stamps_the_deleter()
    {
        $attachment = File_Attachment_Model::create_from_string(
            'audit-destroy-' . uniqid(),
            'audit_destroy.txt',
            ['site_id' => Session::get_site_id()]
        );

        $attachment->force_destroy();

        $row = static::__raw_row('_file_attachments', (int) $attachment->id);
        static::__assert_not_empty($row->deleted_at);
        static::__assert_equals(Session::get_user_id(), (int) $row->deleted_by_id);
        static::__assert_equals(
            \App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::class_to_id('User_Model'),
            (int) $row->deleted_by_type
        );
    }

    /**
     * An already-deleted record that is saved again keeps its ORIGINAL deleter. The stamp
     * fires on the TRANSITION into the deleted state, never on every subsequent write.
     */
    public static function test_a_later_save_does_not_rewrite_the_deleter()
    {
        $portal_user = static::__make_portal_user();
        $portal_user->delete();
        $deleter_id = (int) static::__raw_row('portal_users', (int) $portal_user->id)->deleted_by_id;

        // A later write to the already-deleted row.
        $portal_user->email = 'audit_delete_renamed_' . uniqid() . '@example.com';
        $portal_user->save();

        $row = static::__raw_row('portal_users', (int) $portal_user->id);
        static::__assert_equals(
            \App\RSpade\Core\Database\TypeRefs\Type_Ref_Registry::class_to_id('User_Model'),
            (int) $row->deleted_by_type,
            'the deleter is whoever deleted it, not whoever touched it last'
        );
        static::__assert_equals($deleter_id, (int) $row->deleted_by_id);
        static::__assert_not_empty($row->deleted_at, 'the record is still deleted');
    }
}
