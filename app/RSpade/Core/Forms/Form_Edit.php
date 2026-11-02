<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Forms;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Forms\Form_Questions;
use App\RSpade\Core\Revisions\Revision_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Form_Edit - the write side of an add/edit form's endpoint.
 *
 * Two people with the same record open must not lose each other's work, and a submission
 * that changed one field must not rewrite the other twenty. Rsx_Form tells the endpoint
 * what it needs for both (`_changed`, `_loaded_updated_at`); this class acts on it:
 *
 *     public static function save(Request $request, array $params = [])
 *     {
 *         // ...validate: every field arrives, changed or not...
 *
 *         $client = $params['id'] ? Client_Model::find($params['id']) : new Client_Model();
 *         if (!$client) {
 *             return response_error(Ajax::ERROR_NOT_FOUND, 'Client not found');
 *         }
 *
 *         return Form_Edit::run($client, $params, function (Form_Edit $edit) use ($client, $params) {
 *             $edit->set('name', $params['name']);
 *             $edit->set('email', $params['email'] ?? null);
 *             $client->save();
 *
 *             return ['id' => $client->id];
 *         });
 *     }
 *
 * run() holds a row lock and a database transaction around the callback and refreshes the
 * record inside them, so what the callback reads is current and no other editor can land
 * between the check and the write. Saves of the same record queue on that lock.
 *
 * WHEN IT ASKS. Only when another save changed a field this submission also changed. A
 * save that touched other fields is not a conflict: this submission writes only its own
 * fields, so both people's work stands and nobody is asked anything. The question has
 * kind 'field_conflict'; the application's question handler shows it. "Yes" writes this
 * submission's values, "No" keeps the other person's and still writes the rest.
 *
 * The other person's changes come from revision history, so the model must declare
 * `$revisions = true`. A write that recorded no revision (raw_bulk(), raw SQL, a change
 * only to excluded columns) moves updated_at and names no field; that is asked as a plain
 * 'confirm', and "No" writes nothing.
 *
 * COVERS the record's own columns. A pivot or a detail-table field is guarded by
 * changed() - it is written only when it changed - but is not checked for a conflict.
 *
 * NEVER call run() inside a DB::transaction() of your own: a snapshot taken before the
 * lock is granted would hide the save the lock was waiting for.
 *
 * See: php artisan rsx:man form_conventions (EDITING A RECORD)
 */
#[Instantiatable]
class Form_Edit
{
    /** Rounds of the question are filed under this, plus what was asked about. */
    private const QUESTION_KEY = 'edit_conflict';

    private Rsx_Model_Abstract $record;

    /** @var array<int, string>|null The submitted field names; null writes everything (a new record). */
    private ?array $changed;

    /** @var array<int, string> Fields the user chose to leave as the other person saved them. */
    private array $kept = [];

    private function __construct(Rsx_Model_Abstract $record, ?array $changed)
    {
        $this->record = $record;
        $this->changed = $changed;
    }

    /**
     * Write one add/edit submission.
     *
     * @param Rsx_Model_Abstract $record The record being edited, or a new instance
     * @param array $params The endpoint's $params
     * @param callable $write fn(Form_Edit $edit) - set() the fields, save the record, do the
     *     rest of the work, return the endpoint's result. A throw rolls everything back.
     * @return mixed The callback's result, with `updated_at` added when it is an array, or
     *     the response asking the question or reporting the record gone
     */
    public static function run(Rsx_Model_Abstract $record, array $params, callable $write)
    {
        $class = get_class($record);

        if (!$class::$revisions) {
            throw new RuntimeException(
                'Form_Edit needs revision history to tell what another editor changed. '
                . 'Declare public static $revisions = true; on ' . class_basename($class) . '.'
            );
        }

        return DB::transaction(function () use ($class, $record, $params, $write) {
            if (!$record->exists) {
                return static::__finish($record, $write(new static($record, null)));
            }

            $changed = $params['_changed'] ?? null;
            $loaded = $params['_loaded_updated_at'] ?? null;

            if (!is_array($changed) || !is_string($loaded) || $loaded === '') {
                throw new RuntimeException(
                    'Form_Edit: this submission does not say what it was edited from. Edits '
                    . 'arrive from an <Rsx_Form> whose $data seed carries the record\'s updated_at.'
                );
            }

            // A locking read first, so the snapshot this transaction reads from is taken
            // after the lock is granted and includes the save it may have waited for.
            if (!$class::query()->whereKey($record->getKey())->lockForUpdate()->first()) {
                return response_error(Ajax::ERROR_NOT_FOUND, 'This record no longer exists.');
            }

            $record->refresh();

            $edit = new static($record, array_values(array_filter($changed, 'is_string')));
            $question = $edit->__resolve_conflicts(Rsx_Time::to_database($loaded), $params);

            return $question ?? static::__finish($record, $write($edit));
        });
    }

    /**
     * Assign a value to a field of the record, if this submission is writing that field.
     *
     * @param string $field The column, named as the form's input is
     * @param mixed $value The value, after whatever normalising the endpoint does
     */
    public function set(string $field, $value): self
    {
        if ($this->changed($field)) {
            $this->record->{$field} = $value;
        }

        return $this;
    }

    /**
     * Is this submission writing $field? True for every field of a new record. The test for
     * what set() cannot do: a pivot, a detail-table field, a value derived from several fields.
     */
    public function changed(string $field): bool
    {
        if ($this->changed === null) {
            return true;
        }

        return in_array($field, $this->changed, true) && !in_array($field, $this->kept, true);
    }

    /**
     * Put the version the record is now at on the result, so a form that stays open after
     * saving is not in conflict with its own save.
     */
    private static function __finish(Rsx_Model_Abstract $record, $result)
    {
        if (is_array($result) && !array_key_exists('updated_at', $result)) {
            $result['updated_at'] = $record->updated_at;
        }

        return $result;
    }

    /**
     * Compare what was saved since the form loaded with what this submission changed. Answers
     * the question to ask, or null when the write may go ahead, with $kept settled.
     *
     * @return \App\RSpade\Core\Response\Error_Response|null
     */
    private function __resolve_conflicts(string $loaded, array $params)
    {
        $current = Rsx_Time::to_database($this->record->updated_at);

        if ($current === $loaded) {
            return null;
        }

        $revisions = Revision_Model::where('record_type', class_basename($this->record))
            ->where('record_id', (int) $this->record->getKey())
            ->where('record_updated_at', '>', $loaded)
            ->result_set();

        $any_revision = false;
        $conflicts = [];
        $newest = 0;

        // Oldest first: a field changed twice reports its latest editor.
        foreach ($revisions as $revision) {
            $any_revision = true;
            $newest = max($newest, (int) $revision->id);

            foreach ($revision->diff() as $field => $values) {
                if (in_array($field, $this->changed, true)) {
                    $conflicts[$field] = $revision;
                }
            }
        }

        if (!$any_revision) {
            return $this->__resolve_unattributed($current, $params);
        }

        if (empty($conflicts)) {
            return null;
        }

        // Keyed on what was asked about: a further save between rounds is a new question,
        // and an answer given about the earlier one does not reach it.
        $key = self::QUESTION_KEY . '_' . $newest;
        $answer = Form_Questions::answer($params, $key);

        if ($answer === null) {
            return response_form_question($key, [
                'kind' => 'field_conflict',
                'title' => 'This record changed while you were editing',
                'body' => 'Someone else saved a new value in a field you changed. Your other changes are not affected.',
                'confirm_label' => 'Use my values',
                'cancel_label' => 'Keep theirs',
                'fields' => $this->__describe_conflicts($conflicts, $params),
            ]);
        }

        if ($answer === false) {
            $this->kept = array_keys($conflicts);
        }

        return null;
    }

    /**
     * The record moved and no revision says how: nothing can be compared, so the user decides
     * for the whole submission.
     *
     * @return \App\RSpade\Core\Response\Error_Response|null
     */
    private function __resolve_unattributed(string $current, array $params)
    {
        $key = self::QUESTION_KEY . '_' . preg_replace('/\D/', '', $current);
        $answer = Form_Questions::answer($params, $key);

        if ($answer === null) {
            return response_form_question($key, [
                'kind' => 'confirm',
                'title' => 'This record changed while you were editing',
                'body' => 'It was updated after you opened it, and the change cannot be listed. Save your changes anyway?',
                'confirm_label' => 'Save anyway',
                'cancel_label' => 'Discard my changes',
            ]);
        }

        if ($answer === false) {
            $this->kept = $this->changed;
        }

        return null;
    }

    /**
     * The rows of the question's table: each field, its stored value and the submitted one,
     * in words a person can compare, and who saved it. A never-exported column says only
     * that it changed.
     *
     * @param array<string, Revision_Model> $conflicts
     * @return array<int, array>
     */
    private function __describe_conflicts(array $conflicts, array $params): array
    {
        $fields = [];

        foreach ($conflicts as $field => $revision) {
            $diff = $revision->diff();
            $redacted = Revision_Model::is_redacted($diff[$field][1] ?? null);
            $author = $revision->get_created_by_author();

            $fields[] = [
                'field' => $field,
                'theirs' => $redacted ? null : $this->__display($field, $this->record->{$field}),
                'yours' => $redacted ? null : $this->__display($field, $params[$field] ?? null),
                'redacted' => $redacted,
                'changed_by' => $author ? $author->get_printed_name() : null,
                'changed_at' => Rsx_Time::format_timestamp($revision->created_at),
            ];
        }

        return $fields;
    }

    /** One value as a person reads it: an enum's label, a referenced record's name, plain text. */
    private function __display(string $field, $value): string
    {
        if ($value instanceof Rsx_Text_Abstract) {
            return $value->to_plain_text();
        }

        if ($value === null || $value === '') {
            return '';
        }

        $class = get_class($this->record);
        $enum = $class::$enums[$field] ?? null;

        if ($enum !== null) {
            return (string) ($enum[$value]['label'] ?? $value);
        }

        $relation = substr($field, -3) === '_id' ? substr($field, 0, -3) : null;

        if ($relation !== null && method_exists($this->record, $relation)) {
            $related = $this->record->{$relation}()->getRelated()->find($value);

            if ($related) {
                return (string) (method_exists($related, 'get_printed_name') ? $related->get_printed_name() : ($related->name ?? $value));
            }
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
