<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Forms;

use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Money\Rsx_Money;
use App\RSpade\Core\Database\Money\Rsx_Money_Exception;
use App\RSpade\Core\Database\TextTypes\Rsx_Text_Abstract;
use App\RSpade\Core\Forms\Form_Questions;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Lib\Revisions\Revision_Model;

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
 * COLUMNS AND FIELDS. A revision names columns; a submission names form fields. A record's
 * own column is the field of the same name unless run(..., fields: [column => field]) says
 * otherwise - a field the form names differently ('entry_date' => 'activity_date'), several
 * columns stored from one field (a polymorphic pair), or one column feeding several fields
 * ('time_start' => ['activity_time', 'activity_date']).
 *
 * FACT GROUPS. A model's $fact_groups names columns that hold ONE fact (an activity's date
 * and times). A change saved to any column of a group is a change to every field stored in
 * any column of it, on the record and on each coupled record through its map; such a field
 * is always asked about, since one submitted value cannot be compared with a group.
 *
 * COUPLED RECORDS. An edit that stores some of its form's fields on ANOTHER record (an entry
 * married to its activity) names that record with its column-to-field map
 * (run(..., coupled: [[$activity, ['time_start' => 'activity_time']]])). The record and its
 * coupled records are then ONE version - the newest updated_at among them, which the read
 * publishes with version_of() - and are row-locked together, record first. A coupled
 * record's revisions are read through its map, so a change saved on it through its own
 * screen is a conflict with a submission that sends the mapped field.
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

    /** Where the record's current version can be viewed, for the question's link; null for none. */
    private ?string $view_url = null;

    /**
     * The records this edit also writes, each with the map from its columns to the form's
     * field names.
     *
     * @var array<int, array{record: Rsx_Model_Abstract, map: array<string, array<int, string>>}>
     */
    private array $coupled = [];

    /**
     * The record's own columns the form names otherwise: column => the fields stored in it.
     * An unmapped column is the field of the same name.
     *
     * @var array<string, array<int, string>>
     */
    private array $fields = [];

    /** The kinds of field a question row can be: how its values may be shown. */
    public const FIELD_VALUE = 'value';
    public const FIELD_ENUM = 'enum';
    public const FIELD_REFERENCE = 'reference';
    public const FIELD_FORMATTED_TEXT = 'formatted_text';
    public const FIELD_STRUCTURED = 'structured';
    public const FIELD_SECRET = 'secret';
    public const FIELD_MONEY = 'money';

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
     * @param array<int, array{0: Rsx_Model_Abstract, 1: array<string, string|array<int, string>>}> $coupled
     *     Records this edit also writes, each as [$record, [column => form field]]: the
     *     columns of it the form edits, named as the form names them. Several columns may
     *     map to one field (a polymorphic pair), and one column to several fields (a list).
     *     Row-locked after $record, in this order.
     * @param array<string, string|array<int, string>> $fields The record's own columns the
     *     form names otherwise, as [column => form field] with the same many-to-one and
     *     one-to-many forms. An unmapped column is the field of the same name.
     * @return mixed The callback's result, with `updated_at` added when it is an array (the
     *     composite version_of() when there are coupled records), or the response asking the
     *     question or reporting a record gone
     */
    public static function run(Rsx_Model_Abstract $record, array $params, callable $write, ?string $view_url = null, array $coupled = [], array $fields = [])
    {
        $class = get_class($record);

        if (!$class::$revisions) {
            throw new RuntimeException(
                'Form_Edit needs revision history to tell what another editor changed. '
                . 'Declare public static $revisions = true; on ' . class_basename($class) . '.'
            );
        }

        // The record's column declarations are checked against its table before anything is
        // written through them (memoized: once per process).
        $class::_assert_columns_exist();

        $coupled = static::__coupled($coupled);
        $fields = static::__map($fields, 'fields');

        return DB::transaction(function () use ($class, $record, $params, $write, $view_url, $coupled, $fields) {
            if (!$record->exists) {
                $edit = new static($record, null);
                $edit->coupled = $coupled;
                $edit->fields = $fields;

                return static::__finish($edit, $write($edit));
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

            // The coupled records after it, in the order given: every edit of this pair takes
            // the locks in the same order, so two of them queue instead of deadlocking.
            foreach ($coupled as $entry) {
                $other = $entry['record'];

                if (!get_class($other)::query()->whereKey($other->getKey())->lockForUpdate()->first()) {
                    return response_error(Ajax::ERROR_NOT_FOUND, 'A record this edit also changes no longer exists.');
                }

                $other->refresh();
            }

            $edit = new static($record, array_values(array_filter($changed, 'is_string')));
            $edit->view_url = $view_url;
            $edit->coupled = $coupled;
            $edit->fields = $fields;
            $question = $edit->__resolve_conflicts(Rsx_Time::to_database($loaded), $params);

            return $question ?? static::__finish($edit, $write($edit));
        });
    }

    /**
     * The version an edit of these records is made from: the newest updated_at among them.
     * The read that seeds a form, an API payload and a delete's precondition publish THIS for
     * a record edited with coupled records, so the version a caller sends back covers every
     * record as of its read - a coupled save before the read is not reported as a conflict.
     *
     * @param Rsx_Model_Abstract $record
     * @param Rsx_Model_Abstract ...$coupled
     * @return string Database format (Y-m-d H:i:s.v)
     */
    public static function version_of(Rsx_Model_Abstract $record, Rsx_Model_Abstract ...$coupled): string
    {
        $newest = null;

        foreach ([$record, ...$coupled] as $each) {
            $version = Rsx_Time::to_database($each->updated_at);

            if ($version === null) {
                throw new RuntimeException('Form_Edit::version_of(): ' . class_basename($each) . ' has no updated_at - a saved record has one.');
            }

            if ($newest === null || $version > $newest) {
                $newest = $version;
            }
        }

        return $newest;
    }

    /**
     * Check and normalize run()'s $coupled: each entry a saved record of a model with revision
     * history, and a non-empty map of its column names to form field names.
     *
     * @return array<int, array{record: Rsx_Model_Abstract, map: array<string, array<int, string>>}>
     */
    private static function __coupled(array $coupled): array
    {
        $normalized = [];

        foreach ($coupled as $index => $entry) {
            $other = $entry[0] ?? null;
            $map = $entry[1] ?? null;

            if (!$other instanceof Rsx_Model_Abstract || !is_array($map) || $map === []) {
                throw new RuntimeException(
                    "Form_Edit: coupled entry {$index} must be [\$record, [column => form field, ...]] with at least one column."
                );
            }

            $map = static::__map($map, "coupled entry {$index}");

            if (!get_class($other)::$revisions) {
                throw new RuntimeException(
                    'Form_Edit needs revision history on a coupled record too, to tell what another editor changed on it. '
                    . 'Declare public static $revisions = true; on ' . class_basename($other) . '.'
                );
            }

            if (!$other->exists) {
                throw new RuntimeException('Form_Edit: coupled entry ' . $index . ' (' . class_basename($other) . ') is not a saved record.');
            }

            $normalized[] = ['record' => $other, 'map' => $map];
        }

        return $normalized;
    }

    /**
     * Check and normalize a column-to-field map: column => one field name, or a list of them.
     *
     * @param array $map As given to run()
     * @param string $where What the map is, for the message
     * @return array<string, array<int, string>> column => the fields stored in it
     */
    private static function __map(array $map, string $where): array
    {
        $normalized = [];

        foreach ($map as $column => $fields) {
            $fields = is_array($fields) ? $fields : [$fields];

            $valid = is_string($column) && $column !== '' && $fields !== [] && array_is_list($fields);
            foreach ($fields as $field) {
                $valid = $valid && is_string($field) && $field !== '';
            }

            if (!$valid) {
                throw new RuntimeException(
                    "Form_Edit: {$where} maps column names to form field names - a field name, or a list of them."
                );
            }

            $normalized[$column] = array_values(array_unique($fields));
        }

        return $normalized;
    }

    /** The records this edit writes, each with its map: null for the record itself. */
    private function __sources(): array
    {
        $sources = [['record' => $this->record, 'map' => null]];

        foreach ($this->coupled as $entry) {
            $sources[] = $entry;
        }

        return $sources;
    }

    /**
     * Every form field a map places: the record's own fields: map and each coupled record's.
     * A record's own column of one of these names is not that field.
     */
    private function __placed_fields(): array
    {
        $fields = [];

        foreach ([$this->fields, ...array_column($this->coupled, 'map')] as $map) {
            foreach ($map as $column_fields) {
                foreach ($column_fields as $field) {
                    $fields[$field] = true;
                }
            }
        }

        return array_keys($fields);
    }

    /**
     * The form fields stored in one column of a source: through its map, or - for the
     * record's own unmapped column - the field of the same name, unless a map places that
     * field elsewhere.
     *
     * @param array<string, array<int, string>>|null $map Null for the record itself
     * @return array<int, string>
     */
    private function __fields_in(?array $map, string $column): array
    {
        if ($map !== null) {
            return $map[$column] ?? [];
        }

        if (isset($this->fields[$column])) {
            return $this->fields[$column];
        }

        return in_array($column, $this->__placed_fields(), true) ? [] : [$column];
    }

    /**
     * Where a form field is stored: every [record, column] holding it. The record's own
     * column of the same name when no map places it.
     *
     * @return array<int, array{0: Rsx_Model_Abstract, 1: string}>
     */
    private function __homes(string $field): array
    {
        $homes = [];

        foreach ($this->fields as $column => $fields) {
            if (in_array($field, $fields, true)) {
                $homes[] = [$this->record, $column];
            }
        }

        foreach ($this->coupled as $entry) {
            foreach ($entry['map'] as $column => $fields) {
                if (in_array($field, $fields, true)) {
                    $homes[] = [$entry['record'], $column];
                }
            }
        }

        if ($homes === [] && !in_array($field, $this->__placed_fields(), true)) {
            $homes[] = [$this->record, $field];
        }

        return $homes;
    }

    /**
     * A model's fact groups by column: column => ['columns' => every column of its group,
     * 'label' => the group's label].
     *
     * @return array<string, array{columns: array<int, string>, label: string}>
     */
    private static function __groups_by_column(Rsx_Model_Abstract $model): array
    {
        $by_column = [];

        foreach (get_class($model)::fact_groups() as $group) {
            foreach ($group['columns'] as $column) {
                $by_column[$column] = $group;
            }
        }

        return $by_column;
    }

    /**
     * Assign a value to a field of the record, if this submission is writing that field.
     * The column is the one run()'s fields: map stores the field in, else the field's own
     * name. A field stored in several columns, or in a column that also holds other fields,
     * is composed by the callback itself, guarded by changed(). A field stored in a DERIVED
     * column throws a LogicException whatever the submission changed: a form writes the
     * column's sources, and the record's save() derives it.
     *
     * @param string $field The field, named as the form's input is
     * @param mixed $value The value, after whatever normalising the endpoint does
     */
    public function set(string $field, $value): self
    {
        $columns = [];
        foreach ($this->fields as $column => $fields) {
            if (in_array($field, $fields, true)) {
                $columns[] = $column;
            }
        }

        if (count($columns) > 1 || count($columns) === 1 && count($this->fields[$columns[0]]) > 1) {
            throw new RuntimeException(
                "Form_Edit::set('{$field}'): the fields: map stores it in a column shared with other fields, or in several columns. "
                . "Write those columns in the callback, guarded by \$edit->changed('{$field}')."
            );
        }

        $column = $columns[0] ?? $field;
        $class = get_class($this->record);
        $derived = $class::derived_columns()[$column] ?? null;

        if ($derived !== null) {
            throw new LogicException(
                "Form_Edit::set('{$field}'): " . class_basename($class) . "::{$column} is a derived column "
                . "({$derived['method']}(), from [" . implode(', ', $derived['from']) . ']); a form never writes it. '
                . 'Write its source column(s).'
            );
        }

        if ($this->changed($field)) {
            $this->record->{$column} = $value;
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
     * saving is not in conflict with its own save. With coupled records that is the
     * composite version.
     */
    private static function __finish(Form_Edit $edit, $result)
    {
        if (is_array($result) && !array_key_exists('updated_at', $result)) {
            $result['updated_at'] = $edit->coupled === []
                ? $edit->record->updated_at
                : static::version_of($edit->record, ...array_column($edit->coupled, 'record'));
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
        $current = static::version_of($this->record, ...array_column($this->coupled, 'record'));

        if ($current === $loaded) {
            return null;
        }

        $unattributed = false;
        $changes = [];
        $newest = 0;

        foreach ($this->__sources() as $source) {
            $model = $source['record'];
            $map = $source['map'];

            // A record that has not moved since the version has nothing to say.
            if (Rsx_Time::to_database($model->updated_at) <= $loaded) {
                continue;
            }

            $groups = static::__groups_by_column($model);

            $revisions = Revision_Model::where('record_type', class_basename($model))
                ->where('record_id', (int) $model->getKey())
                ->where('record_updated_at', '>', $loaded)
                ->result_set();

            $any_revision = false;

            // Oldest first, so each field's changes read in the order they were saved.
            foreach ($revisions as $revision) {
                $any_revision = true;
                $newest = max($newest, (int) $revision->id);

                foreach ($revision->diff() as $column => $values) {
                    // A column of a fact group changes the whole group: every field stored in
                    // any of its columns.
                    $group = $groups[$column] ?? null;
                    $fields = [];

                    foreach ($group['columns'] ?? [$column] as $each) {
                        foreach ($this->__fields_in($map, $each) as $field) {
                            $fields[$field] = true;
                        }
                    }

                    foreach (array_keys($fields) as $field) {
                        if (!in_array($field, $this->changed, true)) {
                            continue;
                        }

                        $changes[$field][] = [
                            'revision' => $revision,
                            'record' => $model,
                            'column' => $column,
                            'group' => $group['label'] ?? null,
                            'from' => $values[0] ?? null,
                            'to' => $values[1] ?? null,
                        ];
                    }
                }
            }

            // It moved and no revision says how.
            if (!$any_revision) {
                $unattributed = true;
            }
        }

        if ($unattributed) {
            return $this->__resolve_unattributed($current, $params);
        }

        // A field this submission sets to the value the other editor already saved overwrites
        // nothing, so it is not asked about. That comparison needs one column holding the
        // field and every change made to that column: a field stored in several columns, or
        // changed through another column of its fact group, is always asked about.
        $conflicts = array_filter(
            $changes,
            function ($field_changes, $field) use ($params) {
                $home = $this->__single_home($field);

                if ($home === null) {
                    return true;
                }

                [$model, $column] = $home;

                foreach ($field_changes as $change) {
                    if ($change['record'] !== $model || $change['column'] !== $column) {
                        return true;
                    }
                }

                return !$this->__same_value($model, $column, $model->{$column}, $params[$field] ?? null);
            },
            ARRAY_FILTER_USE_BOTH
        );

        if (empty($conflicts)) {
            return null;
        }

        // Keyed on what was asked about: a further save between rounds is a new question,
        // and an answer given about the earlier one does not reach it.
        $key = self::QUESTION_KEY . '_' . $newest;
        $answer = Form_Questions::answer($params, $key);

        if ($answer === null) {
            return response_form_question($key, $this->__question($conflicts, $params));
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
     * The field_conflict question: what will be overwritten, by whom it was saved, and what
     * this submission writes instead. Editors are references - {type, id}, null for a write
     * nobody signed in made - and times are ISO: showing a person and a time is the
     * application's job, with its own components and formatters. Each field carries its KIND,
     * so a renderer knows which values can be shown as text (rsx:man form_conventions,
     * EDITING A RECORD).
     *
     * @param array<string, array<int, array{revision: Revision_Model, from: mixed, to: mixed}>> $conflicts
     */
    private function __question(array $conflicts, array $params): array
    {
        $editors = [];
        $fields = [];

        foreach ($conflicts as $field => $field_changes) {
            // One value to show only for a field stored in one column, every change made to
            // that column. Otherwise (several columns, a change through another column of its
            // fact group) the row is structured and its changes say what each column held.
            $home = $this->__single_home($field);
            foreach ($field_changes as $change) {
                if ($home !== null && ($change['record'] !== $home[0] || $change['column'] !== $home[1])) {
                    $home = null;
                }
            }

            [$model, $column] = $home ?? [$field_changes[0]['record'], null];
            $kind = $column === null ? self::FIELD_STRUCTURED : $this->__kind($model, $column);
            $rows = [];
            $groups = [];

            foreach ($field_changes as $change) {
                $by = $this->__editor($change['revision']);
                $editors[$by === null ? 'null' : $by['type'] . ':' . $by['id']] = $by;
                $changed_kind = $this->__kind($change['record'], $change['column']);

                if ($change['group'] !== null) {
                    $groups[$change['group']] = true;
                }

                $rows[] = [
                    'by' => $by,
                    'at' => Rsx_Time::to_iso($change['revision']->created_at),
                    'record' => ['type' => class_basename($change['record']), 'id' => (int) $change['record']->getKey()],
                    'column' => $change['column'],
                    'group' => $change['group'],
                    'from' => $this->__shown($changed_kind, $change['record'], $change['column'], $change['from']),
                    'to' => $this->__shown($changed_kind, $change['record'], $change['column'], $change['to']),
                ];
            }

            $fields[] = [
                'field' => $field,
                'kind' => $kind,
                'record' => ['type' => class_basename($model), 'id' => (int) $model->getKey()],
                'groups' => array_keys($groups),
                'loaded' => $column === null ? null : $rows[0]['from'],
                'current' => $column === null ? null : $this->__shown($kind, $model, $column, $model->{$column}),
                'yours' => $column === null ? null : $this->__shown($kind, $model, $column, $params[$field] ?? null),
                'changes' => $rows,
            ];
        }

        return [
            'kind' => 'field_conflict',
            'title' => 'This record changed while you were editing',
            'body' => 'Saving will overwrite values another editor saved after you opened this form. Your other changes are not affected.',
            'confirm_label' => 'Overwrite',
            'cancel_label' => 'Keep theirs',
            'record' => ['type' => class_basename($this->record), 'id' => (int) $this->record->getKey()],
            'view_url' => $this->view_url,
            'editors' => array_values($editors),
            'fields' => $fields,
        ];
    }

    /** The entity a revision names as its author, as a reference; null when nobody acted. */
    private function __editor(Revision_Model $revision): ?array
    {
        // An empty pair is a write nobody signed in made; asking the morph for it would query
        // the revision's own table.
        if (empty($revision->created_by_type) || empty($revision->created_by_id)) {
            return null;
        }

        $actor = $revision->created_by()->withTrashed()->first();

        return $actor ? ['type' => class_basename($actor), 'id' => (int) $actor->getKey()] : null;
    }

    /**
     * The one [record, column] a form field is stored in, or null when it is stored in
     * several.
     *
     * @return array{0: Rsx_Model_Abstract, 1: string}|null
     */
    private function __single_home(string $field): ?array
    {
        $homes = $this->__homes($field);

        return count($homes) === 1 ? $homes[0] : null;
    }

    /**
     * What a column holds, for a renderer: a secret, an amount of money, a structured value
     * (an array or JSON document), a declared text type, an enum, a reference to another
     * record (a foreign key, or either half of a polymorphic pair), or a plain value.
     */
    private function __kind(Rsx_Model_Abstract $model, string $column): string
    {
        $class = get_class($model);

        if (in_array($column, $class::never_exported_columns(), true)) {
            return self::FIELD_SECRET;
        }

        // Before the object branch below, which would call an Rsx_Money structured.
        if ($class::is_money_column($column) || $model->{$column} instanceof Rsx_Money) {
            return self::FIELD_MONEY;
        }

        if ($class::text_type_or_null($column) !== null) {
            return self::FIELD_FORMATTED_TEXT;
        }

        if (isset($class::enum_columns()[$column])) {
            return self::FIELD_ENUM;
        }

        if (preg_match('/^(.+)_(id|type)$/', $column, $match) && method_exists($model, $match[1])) {
            return self::FIELD_REFERENCE;
        }

        $value = $model->{$column};
        if (is_array($value) || is_object($value) && !$value instanceof Rsx_Text_Abstract
            || is_string($value) && $value !== '' && in_array($value[0], ['[', '{'], true) && json_decode($value) !== null) {
            return self::FIELD_STRUCTURED;
        }

        return self::FIELD_VALUE;
    }

    /**
     * A value as the row carries it: text for a kind that has some, null for a secret, a
     * structure, or a field stored in several columns ($column null).
     */
    private function __shown(string $kind, Rsx_Model_Abstract $model, ?string $column, $value): ?string
    {
        if ($column === null || $kind === self::FIELD_SECRET || $kind === self::FIELD_STRUCTURED || Revision_Model::is_redacted($value)) {
            return null;
        }

        return $this->__display($model, $column, $value);
    }

    /**
     * Does the submission set the column to what is stored now? Compared stored-form and as
     * shown; an amount of money by its exact value.
     */
    private function __same_value(Rsx_Model_Abstract $model, string $column, $stored, $submitted): bool
    {
        if ($stored instanceof Rsx_Money || $submitted instanceof Rsx_Money || get_class($model)::is_money_column($column)) {
            return $this->__same_money($stored, $submitted);
        }

        $plain = fn ($value) => $value instanceof Rsx_Text_Abstract ? $value->to_plain_text() : $value;

        if (is_scalar($plain($stored)) || $stored === null) {
            if ((string) $plain($stored) === (string) (is_scalar($plain($submitted)) ? $plain($submitted) : json_encode($plain($submitted)))) {
                return true;
            }
        }

        return $this->__kind($model, $column) !== self::FIELD_SECRET
            && $this->__display($model, $column, $stored) === $this->__display($model, $column, $submitted);
    }

    /**
     * Two amounts of money, compared exactly. A submitted value that is not an Rsx_Money is
     * read as the endpoint would read it (from_submitted(): blank is null); one that is not an
     * amount at all differs from anything stored - assigning it throws, and the endpoint
     * reports that.
     */
    private function __same_money($stored, $submitted): bool
    {
        if (!$submitted instanceof Rsx_Money) {
            try {
                $submitted = Rsx_Money::from_submitted($submitted);
            } catch (Rsx_Money_Exception $e) {
                return false;
            }
        }

        if ($stored === null || $submitted === null) {
            return $stored === null && $submitted === null;
        }

        return $stored instanceof Rsx_Money && $stored->equals($submitted);
    }

    /** One value as a person reads it: an enum's label, a referenced record's name, plain text. */
    private function __display(Rsx_Model_Abstract $model, string $column, $value): string
    {
        if ($value instanceof Rsx_Text_Abstract) {
            return $value->to_plain_text();
        }

        if ($value instanceof Rsx_Money) {
            return $value->format();
        }

        if (is_string($value) && $value !== '' && get_class($model)::is_money_column($column)) {
            return $this->__display_money_string($value);
        }

        if ($value === null || $value === '') {
            return '';
        }

        $class = get_class($model);
        $enum = $class::enum_columns()[$column] ?? null;

        if ($enum !== null) {
            return (string) ($enum[$value]['label'] ?? $value);
        }

        $relation = substr($column, -3) === '_id' ? substr($column, 0, -3) : null;

        if ($relation !== null && method_exists($model, $relation)) {
            $related = $model->{$relation}()->getRelated()->find($value);

            if ($related) {
                return (string) (method_exists($related, 'get_printed_name') ? $related->get_printed_name() : ($related->name ?? $value));
            }
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    /**
     * A money column's value held as a string: a revision records the canonical stored
     * string ("1234.50"); a submission that did not parse carries the text as typed, shown
     * as it was typed.
     */
    private function __display_money_string(string $value): string
    {
        try {
            $money = Rsx_Money::from_storage($value);
        } catch (Rsx_Money_Exception $e) {
            $money = null;
        }

        if ($money === null) {
            try {
                $money = Rsx_Money::from_submitted($value);
            } catch (Rsx_Money_Exception $e) {
                return $value;
            }
        }

        return $money === null ? '' : $money->format();
    }
}
