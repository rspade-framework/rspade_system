<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE RECORD-SIDE ATTACHMENT READERS on Rsx_Model_Abstract.
 *
 * find_attachment() is the OWNERSHIP RE-VERIFICATION step: an endpoint handed an attachment
 * id by the CALLER must resolve it through the owning record, because File_Attachment_Model::
 * find()/find_by_key() are tenant-scoped and nothing more - within one site they will happily
 * return an attachment hanging off a DIFFERENT record, or off the same record under a
 * different category. Without this, "remove attachment 52 from client 1" would delete any
 * attachment in the tenant.
 *
 * get_all_attachments() is the category-less reader - every attachment on a record, across
 * every category, with no limiter.
 *
 * Proves: resolution by id and by key; null for a foreign record, a wrong category, a
 * soft-deleted row and a garbage id; that an all-digits key is read as a KEY and not as an
 * id; and that get_all_attachments() spans categories while get_attachments() does not.
 */
class Attachment_Record_Readers_Test extends Rsx_Test_Abstract
{
    private const CATEGORY = 'reader_test';
    private const OTHER_CATEGORY = 'reader_test_other';

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

    private static function __make(): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string(
            'readers-' . uniqid(),
            'doc.txt',
            ['site_id' => static::__site_id()]
        );
    }

    /**
     * Two sites are not needed here - the owning RECORD is what varies. Site_Model rows are
     * the convenient stand-in for "a record that can own attachments", as in the sibling
     * ownership test.
     */
    private static function __owner(): Site_Model
    {
        return Site_Model::find(static::__site_id());
    }

    private static function __other_owner(int $not_this): Site_Model
    {
        $existing = Site_Model::where('id', '>', 0)->where('id', '!=', $not_this)->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        $site = new Site_Model();
        $site->slug = 'readers-test-' . uniqid();
        $site->name = 'Readers Test Site';
        $site->save();

        return $site;
    }

    // --- find_attachment: the hits ---------------------------------------------------------------

    /** The ordinary resolution, both spellings of "which attachment". */
    public static function test_resolves_by_id_and_by_key()
    {
        $owner = static::__owner();
        $attachment = static::__make();
        $attachment->add_to($owner, self::CATEGORY);

        $by_id = $owner->find_attachment($attachment->id, self::CATEGORY);
        $by_key = $owner->find_attachment($attachment->key, self::CATEGORY);

        static::__assert_not_null($by_id, 'resolves by numeric id');
        static::__assert_not_null($by_key, 'resolves by 64-hex key');
        static::__assert_equals((int) $attachment->id, (int) $by_id->id, 'id lookup returns the right row');
        static::__assert_equals((int) $attachment->id, (int) $by_key->id, 'key lookup returns the right row');
    }

    // --- find_attachment: the misses, which are the point ----------------------------------------

    /**
     * THE DEFECT THIS METHOD EXISTS TO PREVENT. The attachment is real, live, in this tenant,
     * and the id is valid - it simply belongs to someone else's record.
     */
    public static function test_foreign_record_is_not_found()
    {
        $owner = static::__owner();
        $attachment = static::__make();
        $attachment->add_to($owner, self::CATEGORY);

        $other = static::__other_owner((int) $owner->id);

        static::__assert_null(
            $other->find_attachment($attachment->id, self::CATEGORY),
            'an attachment of ANOTHER record is not found, even by a valid id in the same tenant'
        );
    }

    /** Category is part of the identity, not a filter applied afterwards. */
    public static function test_wrong_category_is_not_found()
    {
        $owner = static::__owner();
        $attachment = static::__make();
        $attachment->add_to($owner, self::CATEGORY);

        static::__assert_null(
            $owner->find_attachment($attachment->id, self::OTHER_CATEGORY),
            'the right record but the wrong category is not found'
        );
    }

    /** An unclaimed upload belongs to no record, so no record finds it. */
    public static function test_unattached_is_not_found()
    {
        $owner = static::__owner();
        $attachment = static::__make();

        static::__assert_null(
            $owner->find_attachment($attachment->id, self::CATEGORY),
            'an unattached upload is not found through any record'
        );
    }

    /** Live records only - a soft-deleted attachment is the recovery flow's business. */
    public static function test_soft_deleted_is_not_found()
    {
        $owner = static::__owner();
        $attachment = static::__make();
        $attachment->add_to($owner, self::CATEGORY);
        $attachment->delete();

        static::__assert_null(
            $owner->find_attachment($attachment->id, self::CATEGORY),
            'a soft-deleted attachment is not found (get_deleted_attachments covers retention)'
        );
    }

    /** Garbage in, null out - never an exception, and never someone else's row. */
    public static function test_garbage_identifiers_are_not_found()
    {
        $owner = static::__owner();

        static::__assert_null($owner->find_attachment(0, self::CATEGORY), 'id 0 is not found');
        static::__assert_null($owner->find_attachment('not-an-id', self::CATEGORY), 'a non-numeric non-key is not found');
        static::__assert_null($owner->find_attachment(str_repeat('a', 64), self::CATEGORY), 'an unused 64-hex key is not found');
    }

    /**
     * A key drawn entirely from the digits 0-9 is NUMERIC. Testing is_numeric() first would
     * read it as an id and silently look up an unrelated row, so the 64-hex test comes first.
     * An id is never 64 digits long, which is what makes that ordering safe.
     */
    public static function test_all_digit_key_is_read_as_a_key_not_an_id()
    {
        $owner = static::__owner();
        $attachment = static::__make();
        $attachment->add_to($owner, self::CATEGORY);

        $digit_key = str_repeat('7', 64);
        $attachment->key = $digit_key;
        $attachment->save();

        $found = $owner->find_attachment($digit_key, self::CATEGORY);

        static::__assert_not_null($found, 'an all-digits 64-char key resolves as a key');
        static::__assert_equals((int) $attachment->id, (int) $found->id, 'and it resolves to the right row');
    }

    // --- get_all_attachments ---------------------------------------------------------------------

    /**
     * The category-less reader. get_attachments() answers "the documents on this record";
     * this answers "everything hanging off it" - the shape a delete cascade or an export
     * needs, neither of which can enumerate the categories in advance.
     */
    public static function test_get_all_attachments_spans_every_category()
    {
        $owner = static::__owner();

        $one = static::__make();
        $one->add_to($owner, self::CATEGORY);

        $two = static::__make();
        $two->add_to($owner, self::CATEGORY);

        $three = static::__make();
        $three->add_to($owner, self::OTHER_CATEGORY);

        $in_category = count($owner->get_attachments(self::CATEGORY));
        $in_other = count($owner->get_attachments(self::OTHER_CATEGORY));
        $all = count($owner->get_all_attachments());

        static::__assert_equals(2, $in_category, 'the category reader sees only its own category');
        static::__assert_equals(1, $in_other, 'and only its own category');
        static::__assert_true($all >= 3, 'the category-less reader spans categories');
        static::__assert_equals($in_category + $in_other, $all, 'and sees exactly the union of them');
    }

    /** A soft-deleted attachment leaves the live set, in both readers. */
    public static function test_get_all_attachments_excludes_soft_deleted()
    {
        $owner = static::__owner();

        $attachment = static::__make();
        $attachment->add_to($owner, self::CATEGORY);

        $before = count($owner->get_all_attachments());
        $attachment->delete();
        $after = count($owner->get_all_attachments());

        static::__assert_equals($before - 1, $after, 'a soft-deleted attachment leaves the live set');
    }
}
