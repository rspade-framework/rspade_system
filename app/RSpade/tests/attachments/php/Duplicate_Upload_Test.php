<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Attachments\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\Rsx_Duplicate_Upload;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Attachments\Php\Attachment_Fixture_Handler;

/**
 * DUPLICATE UPLOADS: File_Attachment_Model::duplicates_on() / has_same_content_as() and the
 * standard question, Rsx_Duplicate_Upload.
 *
 * The lookup is an exact byte comparison because identical bytes share one storage row, so
 * it finds a copy under another name and nothing else: not a file in an excluded category,
 * not an embed, not a soft-deleted row, not the upload itself, and nothing at all for an
 * upload with no resident bytes. The helper turns it into one question per duplicate upload,
 * keyed per upload: unanswered asks, false skips, true files.
 *
 * The parent record is a Site_Model row created per test (rolled back with it): any saved
 * record can carry attachments, and a fresh one holds nothing it did not get here.
 */
class Duplicate_Upload_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        config(['rsx.attachments.handlers' => ['Attachment_Fixture_Handler']]);
    }

    private static function __site_id(): int
    {
        $id = (int) Site_Model::where('id', '>', 0)->orderBy('id')->value('id');
        Session::set_site_id($id);

        return $id;
    }

    /** A fresh record to attach to. */
    private static function __parent(): Site_Model
    {
        $parent = new Site_Model();
        $parent->slug = 'duplicate-upload-' . uniqid();
        $parent->name = 'Duplicate Upload Test';
        $parent->save();

        return $parent;
    }

    /** An unclaimed upload of $bytes named $name. */
    private static function __upload(string $bytes, string $name): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string($bytes, $name, ['site_id' => static::__site_id()]);
    }

    private static function __bytes(): string
    {
        return 'duplicate-upload-' . uniqid('', true);
    }

    // --- the lookup -------------------------------------------------------------------------------

    public static function test_the_same_bytes_under_another_name_are_found()
    {
        $parent = static::__parent();
        $bytes = static::__bytes();
        $existing = static::__upload($bytes, 'report.txt');
        $existing->add_to($parent, 'documents');

        $upload = static::__upload($bytes, 'report (renamed).txt');
        $found = $upload->duplicates_on($parent);

        static::__assert_equals(1, $found->count(), 'one copy is already on the record');
        static::__assert_equals((int) $existing->id, (int) $found->first()->id);
        static::__assert_true($upload->has_same_content_as($existing));
    }

    public static function test_different_bytes_under_the_same_name_are_not_found()
    {
        $parent = static::__parent();
        static::__upload(static::__bytes(), 'report.txt')->add_to($parent, 'documents');

        $upload = static::__upload(static::__bytes(), 'report.txt');

        static::__assert_true($upload->duplicates_on($parent)->is_empty(), 'a name is not content');
    }

    public static function test_the_same_bytes_on_another_record_are_not_found()
    {
        $bytes = static::__bytes();
        static::__upload($bytes, 'report.txt')->add_to(static::__parent(), 'documents');

        $upload = static::__upload($bytes, 'report.txt');

        static::__assert_true($upload->duplicates_on(static::__parent())->is_empty());
    }

    public static function test_an_excluded_category_is_not_found()
    {
        $parent = static::__parent();
        $bytes = static::__bytes();
        static::__upload($bytes, 'v1.txt')->add_to($parent, 'pinned_version');

        $upload = static::__upload($bytes, 'v1.txt');

        static::__assert_true($upload->duplicates_on($parent, ['pinned_version'])->is_empty(), 'excluded by the caller');
        static::__assert_false($upload->duplicates_on($parent)->is_empty(), 'found when not excluded');
    }

    public static function test_an_embed_is_not_found()
    {
        $parent = static::__parent();
        $bytes = static::__bytes();
        static::__upload($bytes, 'inline.txt')->add_to($parent, File_Attachment_Model::CATEGORY_INLINE_EMBED);

        $upload = static::__upload($bytes, 'inline.txt');

        static::__assert_true($upload->duplicates_on($parent)->is_empty(), 'an embed belongs to the text, not the file list');
    }

    public static function test_a_soft_deleted_copy_is_not_found()
    {
        $parent = static::__parent();
        $bytes = static::__bytes();
        $existing = static::__upload($bytes, 'report.txt');
        $existing->add_to($parent, 'documents');
        $existing->delete();

        $upload = static::__upload($bytes, 'report.txt');

        static::__assert_true($upload->duplicates_on($parent)->is_empty());
    }

    public static function test_an_attachment_never_finds_itself()
    {
        $parent = static::__parent();
        $attachment = static::__upload(static::__bytes(), 'report.txt');
        $attachment->add_to($parent, 'documents');

        static::__assert_true($attachment->duplicates_on($parent)->is_empty());
    }

    public static function test_an_upload_with_no_resident_bytes_finds_nothing()
    {
        $parent = static::__parent();
        $bytes = Attachment_Fixture_Handler::png_bytes('a');
        static::__upload($bytes, 'external.png')->add_to($parent, 'documents');

        $external = File_Attachment_Model::create_external('Attachment_Fixture_Handler', ['ref' => 'duplicate'], [
            'site_id' => static::__site_id(),
            'file_name' => 'external.png',
            'file_extension' => 'png',
            'mime_type' => 'image/png',
            'file_size' => strlen($bytes),
        ]);

        static::__assert_true($external->duplicates_on($parent)->is_empty(), 'no bytes to compare');
        static::__assert_false($external->has_same_content_as($external));
    }

    // --- the question -----------------------------------------------------------------------------

    public static function test_an_upload_with_no_duplicate_is_filed_without_asking()
    {
        $parent = static::__parent();
        $upload = static::__upload(static::__bytes(), 'report.txt');

        static::__assert_equals(Rsx_Duplicate_Upload::FILE, Rsx_Duplicate_Upload::decide([], $upload, $parent));
    }

    public static function test_unanswered_asks_false_skips_true_files()
    {
        $parent = static::__parent();
        $bytes = static::__bytes();
        static::__upload($bytes, 'report.txt')->add_to($parent, 'documents');
        $upload = static::__upload($bytes, 'copy.txt');
        $key = Rsx_Duplicate_Upload::question_key($upload);

        static::__assert_equals('duplicate_upload:' . $upload->id, $key);
        static::__assert_equals(Rsx_Duplicate_Upload::ASK, Rsx_Duplicate_Upload::decide([], $upload, $parent));
        static::__assert_equals(
            Rsx_Duplicate_Upload::SKIP,
            Rsx_Duplicate_Upload::decide(['_answers' => [$key => false]], $upload, $parent)
        );
        static::__assert_equals(
            Rsx_Duplicate_Upload::FILE,
            Rsx_Duplicate_Upload::decide(['_answers' => [$key => true]], $upload, $parent)
        );
    }

    public static function test_the_question_names_both_files_and_offers_the_three_answers()
    {
        $parent = static::__parent();
        $bytes = static::__bytes();
        $existing = static::__upload($bytes, 'report.txt');
        $existing->add_to($parent, 'documents');
        $upload = static::__upload($bytes, 'copy.txt');

        $response = Rsx_Duplicate_Upload::question($upload, $parent);
        $metadata = $response->get_metadata();
        $question = $metadata['question'];

        static::__assert_equals(Ajax::ERROR_QUESTION, $response->get_error_code());
        static::__assert_equals(Rsx_Duplicate_Upload::question_key($upload), $metadata['key']);
        static::__assert_equals('confirm', $question['kind']);
        static::__assert_equals('Upload Again', $question['confirm_label']);
        static::__assert_equals('Skip This File', $question['cancel_label']);
        static::__assert_equals('Cancel Upload', $question['back_label']);
        static::__assert_contains('copy.txt', $question['body']);
        static::__assert_contains('report.txt', $question['body']);
        static::__assert_equals('copy.txt', $question['file_name']);
        static::__assert_equals((int) $existing->id, $question['existing']['file_attachment_id']);
        static::__assert_equals('report.txt', $question['existing']['file_name']);
    }

    public static function test_a_batch_asks_about_the_first_unanswered_duplicate_and_sorts_the_rest()
    {
        $parent = static::__parent();
        $bytes_a = static::__bytes();
        $bytes_b = static::__bytes();
        static::__upload($bytes_a, 'a.txt')->add_to($parent, 'documents');
        static::__upload($bytes_b, 'b.txt')->add_to($parent, 'documents');

        $new = static::__upload(static::__bytes(), 'new.txt');
        $dup_a = static::__upload($bytes_a, 'a again.txt');
        $dup_b = static::__upload($bytes_b, 'b again.txt');
        $batch = [$new, $dup_a, $dup_b];

        $first = Rsx_Duplicate_Upload::screen([], $parent, $batch);
        static::__assert_equals(Rsx_Duplicate_Upload::question_key($dup_a), $first['question']->get_metadata()['key']);
        static::__assert_equals([], $first['file'], 'nothing is sorted while a question is pending');

        $answers = ['_answers' => [Rsx_Duplicate_Upload::question_key($dup_a) => false]];
        $second = Rsx_Duplicate_Upload::screen($answers, $parent, $batch);
        static::__assert_equals(Rsx_Duplicate_Upload::question_key($dup_b), $second['question']->get_metadata()['key']);

        $answers['_answers'][Rsx_Duplicate_Upload::question_key($dup_b)] = true;
        $done = Rsx_Duplicate_Upload::screen($answers, $parent, $batch);
        static::__assert_null($done['question']);
        static::__assert_equals([(int) $new->id, (int) $dup_b->id], array_map(fn ($a) => (int) $a->id, $done['file']));
        static::__assert_equals([(int) $dup_a->id], array_map(fn ($a) => (int) $a->id, $done['skip']));
    }
}
