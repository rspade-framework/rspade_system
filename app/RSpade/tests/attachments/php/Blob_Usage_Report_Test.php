<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * File_Storage_Model::usage_report() - how much disk the blob store holds.
 *
 * The store is content-addressed, so the sum of attachment sizes is not the disk in use:
 * it counts a blob once per reference. The report's whole job is to give the deduplicated
 * figure, show the naive one beside it, and split it by category without charging a
 * category for bytes another category would keep on disk anyway.
 *
 * The test database already holds blobs, so every assertion is on the CHANGE four new
 * attachments make - and on two categories nothing else uses, which can be asserted
 * outright:
 *
 *     probe_docs      two attachments of content X          (one blob)
 *     probe_versions  one of content X (shared), one of Y   (two blobs)
 */
class Blob_Usage_Report_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private static function __attach(string $content, string $category): File_Attachment_Model
    {
        $attachment = File_Attachment_Model::create_from_string($content, 'usage_probe.txt', ['site_id' => self::SITE_ID]);
        $attachment->fileable_category = $category;
        $attachment->save();

        return $attachment;
    }

    public static function test_the_report_deduplicates_and_shows_the_naive_sum_beside_it()
    {
        static::__acting_as_user(1);

        $x = 'usage probe X ' . bin2hex(random_bytes(40));
        $y = 'usage probe Y ' . bin2hex(random_bytes(90));

        $before = File_Storage_Model::usage_report();

        static::__attach($x, 'probe_docs');
        static::__attach($x, 'probe_docs');
        static::__attach($x, 'probe_versions');
        static::__attach($y, 'probe_versions');

        $after = File_Storage_Model::usage_report();

        static::__assert_equals(2, $after['stored']['blobs'] - $before['stored']['blobs'], 'four attachments are two blobs');
        static::__assert_equals(strlen($x) + strlen($y), $after['stored']['bytes'] - $before['stored']['bytes'], 'the disk grew by each content once');

        static::__assert_equals(4, $after['attachments']['count'] - $before['attachments']['count']);
        static::__assert_equals(2, $after['attachments']['blobs'] - $before['attachments']['blobs']);
        static::__assert_equals(strlen($x) + strlen($y), $after['attachments']['bytes'] - $before['attachments']['bytes'], 'deduplicated bytes');
        static::__assert_equals(3 * strlen($x) + strlen($y), $after['attachments']['naive_bytes'] - $before['attachments']['naive_bytes'], 'the per-reference sum counts X three times');
    }

    /**
     * A category's `bytes` are deduplicated within it; its `exclusive_bytes` are only the
     * blobs no other category references. X is shared by both categories, so neither is
     * charged for it exclusively - it would be on disk for the other regardless.
     */
    public static function test_a_category_is_charged_exclusively_only_for_blobs_nothing_else_holds()
    {
        static::__acting_as_user(1);

        $x = 'usage probe X ' . bin2hex(random_bytes(40));
        $y = 'usage probe Y ' . bin2hex(random_bytes(90));

        static::__attach($x, 'probe_docs');
        static::__attach($x, 'probe_docs');
        static::__attach($x, 'probe_versions');
        static::__attach($y, 'probe_versions');

        $by_category = File_Storage_Model::usage_report()['by_category'];

        static::__assert_equals(
            ['count' => 2, 'blobs' => 1, 'bytes' => strlen($x), 'naive_bytes' => 2 * strlen($x), 'exclusive_bytes' => 0],
            $by_category['probe_docs']
        );
        static::__assert_equals(
            ['count' => 2, 'blobs' => 2, 'bytes' => strlen($x) + strlen($y), 'naive_bytes' => strlen($x) + strlen($y), 'exclusive_bytes' => strlen($y)],
            $by_category['probe_versions']
        );
    }

    /**
     * A deleted attachment is inside the retention window: it leaves the live figures and
     * its blob is still on disk. What it alone keeps there is reported as retained.
     */
    public static function test_a_deleted_attachment_moves_from_live_to_retained()
    {
        static::__acting_as_user(1);

        $y = 'usage probe Y ' . bin2hex(random_bytes(90));
        $attachment = static::__attach($y, 'probe_versions');

        $before = File_Storage_Model::usage_report();

        $attachment->delete();

        $after = File_Storage_Model::usage_report();

        static::__assert_equals(-1, $after['attachments']['count'] - $before['attachments']['count']);
        static::__assert_equals(-strlen($y), $after['attachments']['bytes'] - $before['attachments']['bytes']);
        static::__assert_equals(1, $after['retained']['count'] - $before['retained']['count']);
        static::__assert_equals(strlen($y), $after['retained']['exclusive_bytes'] - $before['retained']['exclusive_bytes']);
        static::__assert_equals($before['stored']['bytes'], $after['stored']['bytes'], 'the bytes are still on disk');
        static::__assert_false(isset($after['by_category']['probe_versions']), 'a category with no live attachment is not listed');
    }
}
