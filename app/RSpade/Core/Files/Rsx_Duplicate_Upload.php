<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\File_Attachment_Model_Abstract;
use App\RSpade\Core\Forms\Form_Questions;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Rsx_Duplicate_Upload - the one standard question an endpoint asks before it files an upload
 * whose bytes its record already holds.
 *
 * A user who uploads a file the record already has - by accident, or retrying an upload whose
 * answer was lost (a retry re-uploads and gets a NEW key, so a request reference cannot catch
 * it) - would otherwise get a second copy. The content-addressed store makes "is this exact
 * file already here" one lookup (File_Attachment_Model::duplicates_on()); this class turns the
 * answer into the server-driven question (rsx:man form_conventions, QUESTIONS), so every
 * endpoint words it and reads its answer the same way. The framework still draws nothing: the
 * application's registered question handler presents it.
 *
 * THE QUESTION. One per duplicate upload, keyed duplicate_upload:<the upload's attachment id>
 * so that a batch filed in one call asks about each file in turn. kind 'confirm', with three
 * outcomes:
 *
 *     Upload Again     true                    file it anyway             -> FILE
 *     Skip This File   false                   file nothing               -> SKIP
 *     Cancel Upload    Rsx_Form.CANCELLED      the call is never resent   (the whole batch stops)
 *
 * A skipped or cancelled upload is simply never claimed: the claim-window sweep
 * (rsx:man file_upload, THE CLAIM WINDOW) takes it like any other unclaimed upload.
 *
 * THE ENDPOINT. Validate, screen, then write - a question records and writes nothing:
 *
 *     $screen = Rsx_Duplicate_Upload::screen($params, $task, $uploads);
 *     if ($screen['question'] !== null) {
 *         return $screen['question'];
 *     }
 *     foreach ($screen['file'] as $upload) {
 *         $upload->add_to($task, 'attachments');
 *     }
 *
 * See: php artisan rsx:man file_upload (DUPLICATE UPLOADS)
 */
class Rsx_Duplicate_Upload
{
    /** The question key's prefix; the full key is QUESTION_KEY . ':' . <attachment id>. */
    const QUESTION_KEY = 'duplicate_upload';

    /** decide(): file the upload - it duplicates nothing, or the user said Upload Again. */
    const FILE = 'file';

    /** decide(): file nothing - the user said Skip This File. */
    const SKIP = 'skip';

    /** decide(): a duplicate exists and the user has not been asked yet. */
    const ASK = 'ask';

    /**
     * The question key for one upload.
     *
     * @param File_Attachment_Model_Abstract $upload
     * @return string
     */
    public static function question_key(File_Attachment_Model_Abstract $upload): string
    {
        return static::QUESTION_KEY . ':' . $upload->id;
    }

    /**
     * What to do with one upload bound for $parent: FILE, SKIP or ASK.
     *
     * FILE when nothing on $parent has these bytes, or the answer was true (Upload Again);
     * SKIP when the answer was false (Skip This File); ASK when a duplicate exists and the
     * question has not been answered.
     *
     * @param array $params The endpoint's $params (the answers ride in _answers)
     * @param File_Attachment_Model_Abstract $upload The unclaimed upload
     * @param \App\RSpade\Core\Database\Models\Rsx_Model_Abstract $parent The record it is bound for
     * @param string[] $exclude_categories As duplicates_on()
     * @return string static::FILE | static::SKIP | static::ASK
     */
    public static function decide(array $params, File_Attachment_Model_Abstract $upload, $parent, array $exclude_categories = []): string
    {
        if ($upload->duplicates_on($parent, $exclude_categories)->is_empty()) {
            return static::FILE;
        }

        $answer = Form_Questions::answer($params, static::question_key($upload));

        if ($answer === null) {
            return static::ASK;
        }

        return $answer === false ? static::SKIP : static::FILE;
    }

    /**
     * The question for one upload that duplicates a file on $parent, as the endpoint returns it.
     *
     * The question object follows the recommended 'confirm' contract plus back_label (the
     * label of the answer that stops the call), and carries the facts an application may draw
     * richer: file_name (the upload's) and existing {file_attachment_id, file_name,
     * created_at} - the oldest copy already on the record, created_at ISO.
     *
     * @param File_Attachment_Model_Abstract $upload
     * @param \App\RSpade\Core\Database\Models\Rsx_Model_Abstract $parent
     * @param string[] $exclude_categories As duplicates_on()
     * @return \App\RSpade\Core\Response\Error_Response
     */
    public static function question(File_Attachment_Model_Abstract $upload, $parent, array $exclude_categories = [])
    {
        $existing = $upload->duplicates_on($parent, $exclude_categories)->first();

        if ($existing === null) {
            shouldnt_happen('Rsx_Duplicate_Upload::question() was asked for an upload with no duplicate on its record.');
        }

        $uploaded = Rsx_Time::format_timestamp($existing->created_at);

        if ($existing->file_name === $upload->file_name) {
            $body = "{$upload->file_name} is already attached here (uploaded {$uploaded}).";
        } else {
            $body = "{$upload->file_name} has the same contents as {$existing->file_name}, "
                . "already attached here (uploaded {$uploaded}).";
        }

        return response_form_question(static::question_key($upload), [
            'kind' => 'confirm',
            'title' => 'This File Is Already Attached',
            'body' => $body . "\n\nUpload it again as a second copy?",
            'confirm_label' => 'Upload Again',
            'cancel_label' => 'Skip This File',
            'back_label' => 'Cancel Upload',
            'file_name' => $upload->file_name,
            'existing' => [
                'file_attachment_id' => (int) $existing->id,
                'file_name' => $existing->file_name,
                'created_at' => $existing->created_at,
            ],
        ]);
    }

    /**
     * Screen a batch of uploads bound for $parent, in order: the first one still to be asked
     * about becomes the question, and otherwise every upload is sorted into file or skip.
     *
     * @param array $params The endpoint's $params
     * @param \App\RSpade\Core\Database\Models\Rsx_Model_Abstract $parent
     * @param File_Attachment_Model_Abstract[] $uploads Unclaimed uploads, already found claimable
     * @param string[] $exclude_categories As duplicates_on()
     * @return array{question: ?\App\RSpade\Core\Response\Error_Response,
     *               file: File_Attachment_Model_Abstract[], skip: File_Attachment_Model_Abstract[]}
     *         When question is non-null, return it and write nothing; file and skip are then empty.
     */
    public static function screen(array $params, $parent, array $uploads, array $exclude_categories = []): array
    {
        $file = [];
        $skip = [];

        foreach ($uploads as $upload) {
            $decision = static::decide($params, $upload, $parent, $exclude_categories);

            if ($decision === static::ASK) {
                return [
                    'question' => static::question($upload, $parent, $exclude_categories),
                    'file' => [],
                    'skip' => [],
                ];
            }

            if ($decision === static::SKIP) {
                $skip[] = $upload;
            } else {
                $file[] = $upload;
            }
        }

        return ['question' => null, 'file' => $file, 'skip' => $skip];
    }
}
